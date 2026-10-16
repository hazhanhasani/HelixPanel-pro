#!/usr/bin/env python3
"""Bootstrap the self-updater on cPanel via authenticated HTTPS UAPI.

No password, API token, or customer data is printed or committed.
This runs after the exact source commit has passed the hosting packaging CI.
The updater itself performs future releases, backup, digest verification and rollback.
"""
from __future__ import annotations

import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

SAFE_USER = re.compile(r"^[a-z][a-z0-9_]{1,15}$")
SAFE_HOST = re.compile(r"^[A-Za-z0-9.-]{3,250}$")
SAFE_PHP = re.compile(r"^/(?:[A-Za-z0-9_.+-]+/)*[A-Za-z0-9_.+-]+$")
UPDATER = Path("hosting-master/public/auto-update.php")
TARGET = "public_html"
CRON_TAG = "/public_html/auto-update.php"


class DeployError(RuntimeError):
    pass


def settings(environ: dict) -> tuple[str, str, str, str]:
    host = environ.get("CPANEL_HOST", "").strip()
    username = environ.get("CPANEL_USERNAME", "").strip()
    token = environ.get("CPANEL_API_TOKEN", "").strip()
    php = environ.get("CPANEL_PHP_BIN", "/usr/local/bin/php").strip()
    if not SAFE_HOST.fullmatch(host) or host.startswith("-") or ".." in host:
        raise DeployError("Configure CPANEL_HOST with the verified cPanel server hostname only")
    if not SAFE_USER.fullmatch(username):
        raise DeployError("Invalid CPANEL_USERNAME")
    if len(token) < 20 or any(ch.isspace() for ch in token):
        raise DeployError("Missing or invalid CPANEL_API_TOKEN")
    if not SAFE_PHP.fullmatch(php) or not php.startswith(("/usr/", "/opt/cpanel/")):
        raise DeployError("Invalid CPANEL_PHP_BIN path")
    return host, username, token, php


def multipart_file(file_name: str, payload: bytes) -> tuple[bytes, str]:
    if file_name != "auto-update.php" or len(payload) > 1024 * 1024:
        raise DeployError("Unexpected bootstrap file")
    boundary = "HelixPanelAutoDeployBoundary2026"
    body = (
        ("--" + boundary + "\r\n").encode()
        + b'Content-Disposition: form-data; name="dir"\r\n\r\n'
        + TARGET.encode() + b"\r\n"
        + ("--" + boundary + "\r\n").encode()
        + ('Content-Disposition: form-data; name="file-1"; filename="' + file_name + '"\r\n').encode()
        + b"Content-Type: application/octet-stream\r\n\r\n"
        + payload + b"\r\n"
        + ("--" + boundary + "--\r\n").encode()
    )
    return body, "multipart/form-data; boundary=" + boundary


class CPanel:
    def __init__(self, host: str, user: str, token: str):
        self.base = f"https://{host}:2083"
        self.user = user
        self.authorization = f"cpanel {user}:{token}"
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
            NoRedirect,
        )

    def request(self, path: str, data: bytes | None = None, content_type: str = "application/x-www-form-urlencoded") -> dict:
        req = urllib.request.Request(
            self.base + path,
            data=data,
            headers={
                "Authorization": self.authorization,
                "Accept": "application/json",
                "Content-Type": content_type,
                "User-Agent": "HelixPanelTrustedAutoDeploy/1.0",
            },
            method="POST" if data is not None else "GET",
        )
        try:
            with self.opener.open(req, timeout=25) as resp:
                if resp.status != 200:
                    raise DeployError("cPanel returned non-200 HTTP status")
                raw = resp.read(512 * 1024 + 1)
                if len(raw) > 512 * 1024:
                    raise DeployError("cPanel response too large")
                response = json.loads(raw)
        except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError, ValueError) as exc:
            # Never expose arbitrary server error pages, request headers, or tokens.
            raise DeployError("cPanel HTTPS API request failed; check host, SSL, API token and firewall") from None
        if not isinstance(response, dict):
            raise DeployError("Invalid cPanel API response")
        return response

    def uapi(self, module: str, method: str, *, form: bytes | None = None, content_type: str = "application/x-www-form-urlencoded", **params: str) -> dict:
        query = urllib.parse.urlencode(params)
        path = f"/execute/{module}/{method}" + (f"?{query}" if query else "")
        result = self.request(path, form, content_type)
        if result.get("status") not in (1, True) or result.get("errors"):
            raise DeployError(f"cPanel {module}/{method} was rejected")
        return result

    def api2(self, module: str, method: str, **params: str) -> dict:
        query = urllib.parse.urlencode({
            "cpanel_jsonapi_user": self.user,
            "cpanel_jsonapi_apiversion": "2",
            "cpanel_jsonapi_module": module,
            "cpanel_jsonapi_func": method,
            **params,
        })
        result = self.request("/json-api/cpanel?" + query)
        item = result.get("cpanelresult")
        if not isinstance(item, dict) or item.get("event", {}).get("result") not in (1, True):
            raise DeployError(f"cPanel {module}/{method} was rejected")
        return item


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        # Never forward an API credential across a redirect.
        return None


def bootstrap(api: CPanel, php: str, payload: bytes) -> str:
    listing = api.uapi("Fileman", "list_files", dir=TARGET)
    entries = listing.get("data")
    if not isinstance(entries, list):
        raise DeployError("Cannot confirm cPanel target directory")
    file_names = {str(x.get("file", x.get("name", ""))) for x in entries if isinstance(x, dict)}
    if "index.php" not in file_names:
        raise DeployError("Expected installed HelixPanel index.php missing; refusing to initialize in wrong directory")
    if "auto-update.php" not in file_names:
        body, content_type = multipart_file("auto-update.php", payload)
        api.uapi("Fileman", "upload_files", form=body, content_type=content_type)
        # Confirm exact target filename rather than accepting a renamed upload.
        followup = api.uapi("Fileman", "list_files", dir=TARGET).get("data", [])
        if not any(isinstance(f, dict) and str(f.get("file", f.get("name", ""))) == "auto-update.php" for f in followup):
            raise DeployError("Updater not present at expected cPanel target after upload")

    cron = api.api2("Cron", "listcron")
    commands = [str(x.get("command", "")) for x in cron.get("data", []) if isinstance(x, dict)]
    desired = f"{php} -q /home/{api.user}/public_html/auto-update.php >/dev/null 2>&1"
    installed = [x for x in commands if CRON_TAG in x]
    if len(installed) > 1:
        raise DeployError("Multiple updater cron jobs detected; no duplicates will be added")
    if installed and desired not in installed[0]:
        raise DeployError("An updater cron already exists with different settings; requires review")
    if not installed:
        api.api2(
            "Cron", "add_line",
            minute="*/15", hour="*", day="*", month="*", weekday="*",
            command=desired,
        )
    confirmed = api.api2("Cron", "listcron")
    found = sum(
        desired == str(row.get("command", "")).strip()
        for row in confirmed.get("data", []) if isinstance(row, dict)
    )
    if found != 1:
        raise DeployError("Unable to verify updater cron installation")
    return "Updater bootstrap and verified 15-minute cPanel cron are installed"


def main() -> int:
    try:
        host, user, token, php = settings(os.environ)
        payload = UPDATER.read_bytes()
        if not payload.startswith(b"<?php") or len(payload) > 1024 * 1024:
            raise DeployError("Invalid local updater source")
        print(bootstrap(CPanel(host, user, token), php, payload))
        return 0
    except (DeployError, OSError) as error:
        print(f"Deployment not completed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
