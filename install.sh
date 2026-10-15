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
  curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- paid
  curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- update
  curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- status
  curl -fsSL https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash -s -- identity

Commands: install (free default), paid (enter activation code), update, status, identity, help
Supports: systemd-based Linux x86_64/arm64 with Python >= 3.10.
The vendor's production distribution API and signed public configuration
must be operational before licensed installation can complete.
HELP
}

case "$MODE" in
  help|-h|--help) usage; exit 0 ;;
  install|paid|update|status|identity) ;;
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
activation_path="/etc/helixpanel/activation.key"
activation_source=""
operation="$MODE"
[[ "$MODE" == "paid" ]] && operation="install"

# Free mode is automatic: a fresh installation does not require an activation
# code, and the vendor still sends a signed release from a trusted endpoint.
# An existing paid installation always reuses its protected activation code.
if [[ -s "$activation_path" ]]; then
  activation_source="$activation_path"
elif [[ "$MODE" == "paid" ]]; then
  if [[ ! -r /dev/tty ]]; then
    die "A terminal is required to enter the paid activation code (use SSH -t)."
  fi
  info "Enter your paid license activation code (used only once)"
  code=""
  IFS= read -r -s -p "Activation code (HP-...): " code </dev/tty || die "Could not read code"
  printf '\n' >/dev/tty
  [[ "$code" =~ ^HP-[A-Za-z0-9_-]{32}$ ]] || die "Invalid activation code format"
  printf '%s\n' "$code" > "${TMP_DIR}/activation.key"
  unset code
  activation_source="${TMP_DIR}/activation.key"
fi

if [[ -n "$activation_source" ]]; then
  info "Refreshing vendor-signed commercial license"
  python3 "${TMP_DIR}/installer.py" activate \
    --config "${TMP_DIR}/config.json" \
    --activation-code-file "$activation_source" \
    --output-license-file "${TMP_DIR}/license.key" ||
    die "License activation failed; no unverified release installed"
  license_path="${TMP_DIR}/license.key"
fi

# Preserve a preexisting paid license. In a new Community install there is
# deliberately no license file and the Go core enforces 1 node / 25 users.
installer_license_arg=()
if [[ -s "$license_path" ]]; then
  installer_license_arg=(--license-file "$license_path")
else
  info "Community license: 1 node + 25 users, no activation code required"
fi

info "Verifying license and downloading signed release"
python3 "${TMP_DIR}/installer.py" "$operation" \
  --config "${TMP_DIR}/config.json" "${installer_license_arg[@]}" ||
  die "Install/update failed. Existing deployment has not been intentionally removed."

# Only after a successful signed installation: pin a root-owned updater and
# verification config on the host. Automatic renewal uses the original secret.
info "Enabling automatic license refresh and signed updates"
mkdir -p /usr/local/lib/helixpanel /etc/helixpanel
chmod 700 /usr/local/lib/helixpanel /etc/helixpanel
install -o root -g root -m 0600 "${TMP_DIR}/config.json" /etc/helixpanel/update.json
install -o root -g root -m 0700 "${TMP_DIR}/installer.py" /usr/local/lib/helixpanel/installer.py
if [[ -n "$activation_source" && "$activation_source" == "${TMP_DIR}/activation.key" ]]; then
  install -o root -g root -m 0600 "$activation_source" /etc/helixpanel/activation.key
fi
fetch "${RAW_BASE}/auto-update.sh" "${TMP_DIR}/auto-update.sh" ||
  die "Panel installed but the automatic updater could not be downloaded."
bash -n "${TMP_DIR}/auto-update.sh" || die "Automatic updater script is invalid."
install -o root -g root -m 0700 "${TMP_DIR}/auto-update.sh" /usr/local/lib/helixpanel/auto-update.sh

cat > /etc/systemd/system/helixpanel-update.service <<'UNIT'
[Unit]
Description=HelixPanel automatic license renewal and signed update
After=network-online.target
Wants=network-online.target
ConditionPathExists=/etc/helixpanel/update.json
ConditionPathExists=/usr/local/lib/helixpanel/auto-update.sh

[Service]
Type=oneshot
User=root
Group=root
ExecStart=/usr/local/lib/helixpanel/auto-update.sh
TimeoutStartSec=240
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/etc/helixpanel /var/lib/helixpanel /opt/helixpanel /run/lock /etc/systemd/system
Environment=PYTHONDONTWRITEBYTECODE=1
UNIT
cat > /etc/systemd/system/helixpanel-update.timer <<'TIMER'
[Unit]
Description=Check HelixPanel licenses and signed releases automatically

[Timer]
OnBootSec=15min
OnUnitActiveSec=6h
RandomizedDelaySec=20min
AccuracySec=5min
Persistent=true
Unit=helixpanel-update.service

[Install]
WantedBy=timers.target
TIMER
chmod 0644 /etc/systemd/system/helixpanel-update.service /etc/systemd/system/helixpanel-update.timer
systemctl daemon-reload
systemctl enable --now helixpanel-update.timer
ok "Automatic license and update timer enabled (every ~6 hours)"


printf '\n'
ok "HelixPanel installation verified"
info "Service: systemctl status helixpanel"
info "Local admin endpoint: http://127.0.0.1:8080"
info "For public access, configure a trusted HTTPS reverse proxy."
