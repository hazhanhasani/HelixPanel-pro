#!/usr/bin/env bash
# Protected automatic updater; installed only after successful signed deployment.
set -Eeuo pipefail
umask 077

readonly ROOT="/usr/local/lib/helixpanel"
readonly ETC="/etc/helixpanel"
readonly STATE="/var/lib/helixpanel"
readonly LOG_TAG="helixpanel-auto-update"

if [[ $EUID -ne 0 ]]; then echo "Run as root" >&2; exit 1; fi
exec 9>/run/lock/helixpanel-installer.lock
flock -n 9 || { echo "Updater already running — skip"; exit 0; }
[[ -f "${ROOT}/installer.py" && -f "${ETC}/update.json" ]] || {
  echo "Missing trusted installer/config, refusing update" >&2
  exit 1
}
tmp="$(mktemp -d -t helix-update.XXXXXXXX)"
trap 'rm -rf -- "$tmp"' EXIT

if [[ -s "${ETC}/activation.key" ]]; then
  python3 "${ROOT}/installer.py" activate \
    --config "${ETC}/update.json" \
    --activation-code-file "${ETC}/activation.key" \
    --output-license-file "$tmp/license.key"
  source_license="$tmp/license.key"
elif [[ -s "${STATE}/license.key" ]]; then
  # Backward compatibility for licenses issued before automatic activation.
  source_license="${STATE}/license.key"
else
  echo "No activation code or signed license is installed" >&2
  exit 1
fi

python3 "${ROOT}/installer.py" update \
  --config "${ETC}/update.json" \
  --license-file "$source_license"
