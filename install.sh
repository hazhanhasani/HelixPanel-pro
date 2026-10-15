#!/usr/bin/env bash
# HelixPanel Pro — public one-command installer.
# This script contains no proprietary application code or vendor private keys.
set -Eeuo pipefail
umask 077

readonly BRAND="HelixPanel Pro"
readonly RAW_BASE="https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main"
readonly CONFIG_URL="${RAW_BASE}/config.public.json"
readonly INSTALLATION_STATE="/var/lib/helixpanel"
MODE="${1:-install}"
TMP_DIR=""

cleanup() { if [[ -n "${TMP_DIR}" && -d "${TMP_DIR}" ]]; then rm -rf -- "${TMP_DIR}"; fi; }
trap cleanup EXIT
die() { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
ok() { printf '\033[32m✓ %s\033[0m\n' "$*"; }
info() { printf '\033[36m› %s\033[0m\n' "$*"; }

usage() {
  cat <<'HELP'
HelixPanel Pro — Secure Installer
Usage:
  curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash
  curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- update
  curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- status
  curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- identity

Commands: install (default), update, status, identity, help
Supports: systemd-based Linux x86_64/arm64 with Python >= 3.10.
The vendor's production distribution API and signed public configuration
must be operational before licensed installation can complete.
HELP
}

case "$MODE" in
  help|-h|--help) usage; exit 0 ;;
  install|update|status|identity) ;;
  *) usage; die "Unknown command: $MODE" ;;
esac

printf '\n\033[1;36m'
cat <<'BANNER'
  _   _      _ _      ____                  _
 | | | | ___| (_)_  _|  _ \ __ _ _ __   ___| |
 | |_| |/ _ \ | \ \/ / |_) / _` | '_ \ / _ \ |
 |  _  |  __/ | |>  <|  __/ (_| | | | |  __/ |
 |_| |_|\___|_|_/_/\_\_|   \__,_|_| |_|\___|_|
BANNER
printf '\033[0m\n'
info "Secure installation • Signed releases • Private source"

if [[ "${EUID}" -ne 0 ]]; then
  die "Root permission required. Use: curl -fsSL ${RAW_BASE}/install.sh | sudo bash"
fi
if [[ "$(uname -s)" != "Linux" ]]; then die "Only Linux is supported"; fi
if [[ ! -d /run/systemd/system ]] || ! command -v systemctl >/dev/null 2>&1; then
  die "This installation requires an active systemd-based Linux server"
fi
case "$(uname -m)" in
  x86_64|aarch64) ;;
  *) die "Supported CPU architectures: x86_64, aarch64" ;;
esac

# Refuse overlapping installations and upgrades.
if command -v flock >/dev/null 2>&1; then
  mkdir -p /run/lock
  exec 9>/run/lock/helixpanel-installer.lock
  flock -n 9 || die "Another HelixPanel installation is in progress"
fi

ensure_dependencies() {
  local missing=() cmd
  for cmd in curl python3 openssl useradd; do
    command -v "$cmd" >/dev/null 2>&1 || missing+=("$cmd")
  done
  if (( ${#missing[@]} > 0 )); then
    info "Installing prerequisites: ${missing[*]}"
    if command -v apt-get >/dev/null 2>&1; then
      apt-get update -qq
      DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl python3 openssl ca-certificates passwd
    elif command -v dnf >/dev/null 2>&1; then
      dnf install -y -q curl python3 openssl ca-certificates shadow-utils
    else
      die "Install the prerequisites manually: curl python3 openssl useradd"
    fi
  fi
  python3 -c 'import sys; assert sys.version_info >= (3,10), "Python 3.10+ is required"' ||
    die "Python 3.10 or newer is required (Ubuntu 22.04+ / Debian 12+ recommended)"
}

fetch() {
  local url="$1" dest="$2"
  curl --proto '=https' --tlsv1.2 -fLsS --retry 2 --connect-timeout 10 --max-time 45 -o "$dest" "$url"
}

ensure_dependencies
TMP_DIR="$(mktemp -d -t helixpanel-install.XXXXXXXX)"
chmod 700 "$TMP_DIR"
info "Downloading public installer from GitHub"
fetch "${RAW_BASE}/installer.py" "${TMP_DIR}/installer.py" ||
  die "Cannot retrieve installer.py. Check access to GitHub."

if [[ "$MODE" == "status" ]]; then
  python3 "${TMP_DIR}/installer.py" status
  systemctl --no-pager --full status helixpanel.service 2>/dev/null || true
  exit 0
fi

info "Preparing installation identity"
python3 "${TMP_DIR}/installer.py" identity ||
  die "Could not initialize installation identity"
if [[ "$MODE" == "identity" ]]; then
  printf '\n%s\n' "Give this ID to your HelixPanel license issuer. Keep any issued license key private."
  exit 0
fi

info "Checking signed-release service availability"
if ! fetch "$CONFIG_URL" "${TMP_DIR}/config.json"; then
  die "Public release config is unavailable. The vendor must first publish config.public.json."
fi

# The only approved default config is public and fetched from the official repo.
if ! python3 - "${TMP_DIR}/config.json" <<'PY'
import json, sys
from pathlib import Path
try:
    cfg = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    if cfg.get("status") != "production":
        print("Release service is not available yet (developer preview).", file=sys.stderr)
        sys.exit(2)
    if not all(cfg.get(key) for key in ("api_base", "release_public_key_pem", "license_public_key")):
        print("Vendor public configuration is incomplete.", file=sys.stderr)
        sys.exit(2)
except (OSError, ValueError, TypeError) as exc:
    print("Invalid public release configuration:", exc, file=sys.stderr)
    sys.exit(2)
PY
then
  die "Production releases are not active yet. No unverified software has been installed."
fi

license_path="${INSTALLATION_STATE}/license.key"
if [[ ! -f "$license_path" ]]; then
  if [[ "$MODE" == "update" ]]; then
    die "No installed license found. Run the default install command first."
  fi
  if [[ ! -r /dev/tty ]]; then
    die "A terminal is required to enter the license. Connect via SSH with TTY (-t)."
  fi
  printf '\n'
  info "Enter the license issued by the vendor (input hidden)"
  license_value=""
  IFS= read -r -s -p "License key: " license_value </dev/tty || die "Could not read license"
  printf '\n' >/dev/tty
  [[ -n "$license_value" && "${#license_value}" -le 8192 ]] || die "Invalid or empty license key"
  printf '%s\n' "$license_value" > "${TMP_DIR}/license.key"
  unset license_value
  license_path="${TMP_DIR}/license.key"
fi

info "Verifying license and downloading signed release"
python3 "${TMP_DIR}/installer.py" "$MODE" \
  --config "${TMP_DIR}/config.json" --license-file "$license_path" ||
  die "Install/update failed. Existing deployment has not been intentionally removed."

printf '\n'
ok "HelixPanel installation verified"
info "Service: systemctl status helixpanel"
info "Local admin endpoint: http://127.0.0.1:8080"
info "For public access, configure a trusted HTTPS reverse proxy."
