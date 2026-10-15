#!/usr/bin/env python3
"""HelixPanel customer-side binary installer; Python 3 stdlib + OpenSSL.

Public code; it contains no proprietary panel source or vendor private keys.
Installer protocol v1: license-gated signed release envelope with temporary URL.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import re
import secrets
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.error
import urllib.request
from urllib.parse import urlparse

PRODUCT = "helixpanel"
ROOT = Path("/opt/helixpanel")
STATE = Path("/var/lib/helixpanel")
ETC = Path("/etc/helixpanel")
UNIT_PATH = Path("/etc/systemd/system/helixpanel.service")
MAX_ARTIFACT = 150 * 1024 * 1024
VERSION_RE = re.compile(r"^v?[0-9]+\.[0-9]+\.[0-9]+(?:[-.][a-zA-Z0-9.]+)?$")
HEX_32 = re.compile(r"^[0-9a-f]{32}$")

class InstallError(RuntimeError):
    pass


def b64url(value: str) -> bytes:
    return base64.urlsafe_b64decode(value + "=" * (-len(value) % 4))


def platform_id() -> str:
    machine = platform.machine().lower()
    arch = {"x86_64": "amd64", "aarch64": "arm64"}.get(machine)
    if sys.platform != "linux" or not arch:
        raise InstallError("supported platforms: linux-amd64, linux-arm64")
    return "linux-" + arch


def read_config(path: Path) -> dict:
    config = json.loads(path.read_text(encoding="utf-8"))
    api = str(config.get("api_base", "")).rstrip("/")
    if urlparse(api).scheme != "https" or not urlparse(api).hostname:
        raise InstallError("api_base must be a valid HTTPS URL")
    pem = config.get("release_public_key_pem", "")
    license_pub = config.get("license_public_key", "")
    try:
        valid_pub = len(b64url(license_pub)) == 32
    except Exception as exc:
        raise InstallError("configure a valid public licensing key") from exc
    if "-----BEGIN PUBLIC KEY-----" not in pem or not valid_pub:
        raise InstallError("configure the vendor release verification and licensing public keys")
    return {"api_base": api, "release_public_key_pem": pem, "license_public_key": license_pub}


def read_identity(state: Path = STATE) -> str:
    p = state / "installation.id"
    if p.exists():
        value = p.read_text().strip()
        if not HEX_32.fullmatch(value):
            raise InstallError("installation.id is corrupt; refusing to replace it")
        return value
    state.mkdir(parents=True, exist_ok=True)
    os.chmod(state, 0o700)
    value = secrets.token_hex(16)
    # O_EXCL prevents overwrites from a parallel installer.
    fd = os.open(p, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, "w") as f:
        f.write(value + "\n")
        f.flush()
        os.fsync(f.fileno())
    return value


def verify_release_envelope(envelope: dict, public_pem: str, *, now: int | None = None) -> dict:
    """Verify Ed25519 over exact raw manifest payload bytes, not reserialized JSON."""
    try:
        raw = b64url(envelope["payload"])
        signature = b64url(envelope["signature"])
    except (KeyError, ValueError, TypeError) as exc:
        raise InstallError("malformed signed release manifest") from exc
    if len(raw) > 8192 or len(signature) != 64:
        raise InstallError("manifest size or signature invalid")
    with tempfile.TemporaryDirectory(prefix="helix-verify-") as tmp:
        raw_file = Path(tmp) / "payload"
        sig_file = Path(tmp) / "signature"
        pub_file = Path(tmp) / "release-public.pem"
        raw_file.write_bytes(raw)
        sig_file.write_bytes(signature)
        pub_file.write_text(public_pem)
        cmd = ["openssl", "pkeyutl", "-verify", "-pubin", "-inkey", str(pub_file),
               "-rawin", "-in", str(raw_file), "-sigfile", str(sig_file)]
        try:
            result = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                    check=False, timeout=10)
        except (FileNotFoundError, subprocess.TimeoutExpired) as exc:
            raise InstallError("OpenSSL with Ed25519 support is required") from exc
        if result.returncode != 0:
            raise InstallError("invalid vendor release signature")
    try:
        body = json.loads(raw)
        if not isinstance(body, dict) or body["product"] != PRODUCT:
            raise ValueError("wrong product")
        if not VERSION_RE.fullmatch(body["version"]):
            raise ValueError("invalid version")
        if not re.fullmatch(r"[0-9a-f]{64}", body["sha256"]):
            raise ValueError("invalid hash")
        if int(body["expires_at"]) < (int(time.time()) if now is None else now):
            raise ValueError("expired manifest")
        return body
    except (ValueError, KeyError, TypeError) as exc:
        raise InstallError(f"invalid release manifest: {exc}") from exc



def verify_license_token(token: str, public_key: str, installation_id: str, *, now: int | None = None) -> dict:
    """Reject unsigned, expired or installation-mismatched vendor licenses."""
    try:
        payload, signature = token.strip().split(".")
        raw, sig, key = b64url(payload), b64url(signature), b64url(public_key)
        if len(raw) > 8192 or len(sig) != 64 or len(key) != 32:
            raise ValueError("invalid length")
        # ASN.1 SubjectPublicKeyInfo header for a 32-byte Ed25519 key.
        spki = bytes.fromhex("302a300506032b6570032100") + key
        with tempfile.TemporaryDirectory(prefix="helix-license-") as tmp:
            root = Path(tmp)
            (root / "public.der").write_bytes(spki)
            (root / "payload").write_bytes(raw)
            (root / "signature").write_bytes(sig)
            check = subprocess.run(
                ["openssl", "pkeyutl", "-verify", "-pubin", "-keyform", "DER",
                 "-inkey", str(root / "public.der"), "-rawin",
                 "-in", str(root / "payload"), "-sigfile", str(root / "signature")],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10,
            )
            if check.returncode != 0:
                raise ValueError("invalid Ed25519 signature")
        claims = json.loads(raw)
        current = int(time.time()) if now is None else now
        if (not isinstance(claims, dict) or claims.get("version") != 1
                or claims.get("product") != PRODUCT
                or claims.get("installation_id") != installation_id
                or not isinstance(claims.get("license_id"), str)
                or not isinstance(claims.get("customer_id"), str)
                or not isinstance(claims.get("max_users"), int)
                or not isinstance(claims.get("max_nodes"), int)
                or claims["max_users"] < 1 or claims["max_nodes"] < 1
                or not isinstance(claims.get("issued_at"), int)
                or not isinstance(claims.get("expires_at"), int)
                or claims["issued_at"] > current + 300
                or claims["expires_at"] <= current):
            raise ValueError("invalid or expired license claims")
        return claims
    except (ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError) as exc:
        raise InstallError("vendor license signature or installation binding is invalid") from exc


def request_activation(api: str, code: str, installation_id: str) -> str:
    if not re.fullmatch(r"HP-[A-Za-z0-9_-]{32}", code):
        raise InstallError("activation code is malformed")
    body = json.dumps({"activation_code": code, "installation_id": installation_id}).encode()
    req = urllib.request.Request(
        api + "/v1/licenses/activate", data=body, method="POST",
        headers={"Content-Type": "application/json", "User-Agent": "HelixPanelInstaller/1.1"},
    )
    try:
        with urllib.request.urlopen(req, timeout=25) as resp:
            if resp.status != 200:
                raise InstallError("activation server did not accept this code")
            response = json.loads(resp.read(16384))
        token = response.get("license_key")
        if not isinstance(token, str) or len(token) > 8192:
            raise InstallError("invalid activation server response")
        return token
    except urllib.error.HTTPError as exc:
        if exc.code == 403:
            raise InstallError("activation denied: invalid, expired or already bound code") from exc
        raise InstallError(f"activation service HTTP {exc.code}") from exc
    except (OSError, ValueError, TypeError, AttributeError) as exc:
        raise InstallError("activation service unavailable or returned invalid data") from exc


def request_release(api: str, license_key: str, installation_id: str, target: str) -> dict:
    body = json.dumps({"license_key": license_key.strip(), "installation_id": installation_id,
                       "platform": target}).encode()
    req = urllib.request.Request(api + "/v1/releases/resolve", data=body, method="POST",
                                 headers={"Content-Type": "application/json", "User-Agent": "HelixPanelInstaller/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=25) as resp:
            if resp.status != 200:
                raise InstallError(f"license server HTTP {resp.status}")
            return json.loads(resp.read(16 * 1024))
    except urllib.error.HTTPError as exc:
        if exc.code == 403:
            raise InstallError("license rejected, expired, or bound to another installation") from exc
        raise InstallError(f"license server HTTP {exc.code}") from exc
    except (OSError, ValueError) as exc:
        raise InstallError("cannot contact license distribution API") from exc


def fetch_verified_artifact(payload: dict, config: dict, destination: Path, identity: str, target: str) -> None:
    if payload["installation_id"] != identity or payload["platform"] != target:
        raise InstallError("release manifest does not match this installation")
    url = payload["artifact_url"]
    parsed, provider = urlparse(url), urlparse(config["api_base"])
    if parsed.scheme != "https" or parsed.hostname != provider.hostname or parsed.port != provider.port:
        raise InstallError("untrusted artifact download host")
    req = urllib.request.Request(url, headers={"User-Agent": "HelixPanelInstaller/1.0"})
    sha = hashlib.sha256()
    count = 0
    try:
        with urllib.request.urlopen(req, timeout=90) as src, destination.open("wb") as dst:
            while True:
                chunk = src.read(1024 * 1024)
                if not chunk:
                    break
                count += len(chunk)
                if count > MAX_ARTIFACT:
                    raise InstallError("artifact exceeds maximum size")
                sha.update(chunk)
                dst.write(chunk)
    except OSError as exc:
        raise InstallError("release download failed") from exc
    if sha.hexdigest() != payload["sha256"]:
        raise InstallError("release checksum mismatch; refusing to install")


def unpack_binary(archive: Path, output: Path) -> None:
    try:
        with tarfile.open(archive, "r:gz") as tf:
            members = tf.getmembers()
            if len(members) != 1 or members[0].name != "helixpanel" or not members[0].isfile():
                raise InstallError("artifact must contain only the regular file 'helixpanel'")
            if members[0].size > MAX_ARTIFACT:
                raise InstallError("binary exceeds maximum size")
            stream = tf.extractfile(members[0])
            if stream is None:
                raise InstallError("cannot extract panel binary")
            with output.open("wb") as dst:
                shutil.copyfileobj(stream, dst, 1024 * 1024)
            os.chmod(output, 0o750)
    except (tarfile.TarError, OSError) as exc:
        raise InstallError("invalid or corrupt binary archive") from exc


def system_call(*args: str) -> None:
    subprocess.run(args, check=True, stdout=subprocess.DEVNULL)


def ensure_account() -> None:
    import pwd
    try:
        pwd.getpwnam("helixpanel")
    except KeyError:
        system_call("useradd", "--system", "--user-group", "--home-dir", str(STATE),
                    "--shell", "/usr/sbin/nologin", "helixpanel")


def prepare_service(config: dict, state: Path = STATE, etc: Path = ETC, unit_path: Path = UNIT_PATH) -> None:
    import pwd
    ensure_account()
    user = pwd.getpwnam("helixpanel")
    os.chown(state, user.pw_uid, user.pw_gid)
    os.chmod(state, 0o700)
    for name in ("installation.id", "license.key"):
        file = state / name
        os.chown(file, user.pw_uid, user.pw_gid)
        os.chmod(file, 0o600)
    etc.mkdir(parents=True, exist_ok=True)
    os.chmod(etc, 0o700)
    env_file = etc / "panel.env"
    if env_file.exists():
        # Preserve the customer API token on upgrades.
        existing = env_file.read_text()
        lines = [line for line in existing.splitlines() if line.startswith("PANEL_API_TOKEN=")]
        if len(lines) != 1 or len(lines[0].split("=", 1)[1]) < 32:
            raise InstallError("invalid previous API token configuration")
        token = lines[0].split("=", 1)[1]
    else:
        token = secrets.token_hex(32)
    env_content = (f"PANEL_API_TOKEN={token}\n"
                   f"PANEL_STATE_DIR={state}\n"
                   "PANEL_LISTEN=127.0.0.1:8080\n"
                   f"PANEL_LICENSE_PUBLIC_KEY={config['license_public_key']}\n")
    env_file.write_text(env_content)
    os.chown(env_file, 0, user.pw_gid)
    os.chmod(env_file, 0o640)
    unit = """[Unit]
Description=HelixPanel Control Plane
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=helixpanel
Group=helixpanel
EnvironmentFile=/etc/helixpanel/panel.env
ExecStart=/opt/helixpanel/current/helixpanel
WorkingDirectory=/var/lib/helixpanel
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictSUIDSGID=true
ReadWritePaths=/var/lib/helixpanel
UMask=0077

[Install]
WantedBy=multi-user.target
"""
    unit_path.write_text(unit)
    os.chmod(unit_path, 0o644)


def switch_release(version: str, binary: Path, root: Path = ROOT) -> Path | None:
    releases = root / "releases"
    releases.mkdir(parents=True, exist_ok=True)
    dest = releases / version
    if dest.exists():
        raise InstallError("release already installed; refusing to overwrite version directory")
    dest.mkdir(mode=0o755)
    shutil.copy2(binary, dest / "helixpanel")
    os.chmod(dest / "helixpanel", 0o755)
    current = root / "current"
    old = current.resolve(strict=True) if current.is_symlink() else None
    link = root / (".current-" + secrets.token_hex(6))
    link.symlink_to(dest, target_is_directory=True)
    os.replace(link, current)
    return old


def wait_healthy(timeout: int = 15) -> bool:
    for _ in range(timeout):
        try:
            with urllib.request.urlopen("http://127.0.0.1:8080/healthz", timeout=1) as r:
                if r.status == 200 or r.status == 204:
                    return True
        except OSError:
            pass
        time.sleep(1)
    return False


def deploy(config: dict, input_file: Path, target: str, *, install_root: Path = ROOT, state: Path = STATE) -> None:
    if os.geteuid() != 0:
        raise InstallError("run installation as root (sudo)")
    identity = read_identity(state)
    license_key = input_file.read_text().strip()
    if not license_key:
        raise InstallError("empty license file")
    envelope = request_release(config["api_base"], license_key, identity, target)
    payload = verify_release_envelope(envelope, config["release_public_key_pem"])
    version = payload["version"]
    if not VERSION_RE.fullmatch(version):
        raise InstallError("invalid version")
    # Idempotent updates: a correctly activated release must not fail because
    # the same version is already installed. License renewal is still recorded.
    current = install_root / "current"
    active_version = install_root / "releases" / version
    if current.is_symlink() and active_version.is_dir() and current.resolve() == active_version.resolve():
        (state / "license.key").write_text(license_key + "\n")
        os.chmod(state / "license.key", 0o600)
        if not wait_healthy():
            system_call("systemctl", "restart", "helixpanel.service")
            if not wait_healthy():
                raise InstallError("installed release is unhealthy; see journalctl -u helixpanel")
        print("HelixPanel already up to date:", version)
        return
    with tempfile.TemporaryDirectory(prefix="helix-install-") as t:
        artifact = Path(t) / "release.tar.gz"
        binary = Path(t) / "helixpanel"
        fetch_verified_artifact(payload, config, artifact, identity, target)
        unpack_binary(artifact, binary)
        (state / "license.key").write_text(license_key + "\n")
        os.chmod(state / "license.key", 0o600)
        prepare_service(config, state=state)
        old = switch_release(version, binary, root=install_root)
        try:
            system_call("systemctl", "daemon-reload")
            system_call("systemctl", "enable", "helixpanel.service")
            system_call("systemctl", "restart", "helixpanel.service")
            if not wait_healthy():
                raise InstallError("health check failed after activation")
        except (subprocess.CalledProcessError, InstallError):
            current = install_root / "current"
            if old:
                rollback = install_root / ".rollback"
                rollback.symlink_to(old, target_is_directory=True)
                os.replace(rollback, current)
                subprocess.run(["systemctl", "restart", "helixpanel.service"], check=False)
            else:
                subprocess.run(["systemctl", "stop", "helixpanel.service"], check=False)
            raise InstallError("deployment failed; previous release restored where available")
        print("HelixPanel installed:", version)
        print("Operator API token (root readable): /etc/helixpanel/panel.env")
        print("Local panel: http://127.0.0.1:8080 (configure TLS reverse proxy for remote access)")


def main() -> int:
    parser = argparse.ArgumentParser(description="HelixPanel proprietary binary installer")
    parser.add_argument("command", choices=["identity", "install", "update", "status", "activate"])
    parser.add_argument("--config", type=Path, default=Path(__file__).with_name("config.json"))
    parser.add_argument("--license-file", type=Path, help="vendor-issued signed customer license")
    parser.add_argument("--activation-code-file", type=Path, help="root-only vendor activation secret")
    parser.add_argument("--output-license-file", type=Path, help="new license output for activation")
    args = parser.parse_args()
    try:
        if args.command == "identity":
            if os.geteuid() != 0:
                raise InstallError("sudo is required to create protected installation identity")
            print("Installation ID:", read_identity())
            return 0
        if args.command == "status":
            print("Installation ID:", read_identity() if (STATE / "installation.id").exists() else "not initialized")
            print("Current:", str((ROOT / "current").resolve()) if (ROOT / "current").is_symlink() else "not installed")
            return 0
        config = read_config(args.config)
        if args.command == "activate":
            if os.geteuid() != 0:
                raise InstallError("activation requires root to protect credentials")
            if not args.activation_code_file or not args.output_license_file:
                raise InstallError("--activation-code-file and --output-license-file required")
            identity = read_identity()
            code = args.activation_code_file.read_text(encoding="utf-8").strip()
            license_key = request_activation(config["api_base"], code, identity)
            claims = verify_license_token(license_key, config["license_public_key"], identity)
            args.output_license_file.parent.mkdir(parents=True, exist_ok=True)
            fd = os.open(args.output_license_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8") as out:
                out.write(license_key + "\n")
            print("License active through:", time.strftime("%Y-%m-%d", time.gmtime(claims["expires_at"])))
            return 0
        if not args.license_file:
            raise InstallError("--license-file required (run 'activate' first for automatic licensing)")
        deploy(config, args.license_file, platform_id())
        return 0
    except (InstallError, FileNotFoundError, PermissionError, subprocess.CalledProcessError) as exc:
        print("ERROR:", exc, file=sys.stderr)
        return 1

if __name__ == "__main__":
    sys.exit(main())
