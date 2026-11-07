#!/usr/bin/env bash
# APRS Object Beacon - odinstalowanie
#   sudo bash /opt/aprs-beacon/src/uninstall.sh
# Ustawienia (config.json, comments.json) zostaja zapisane w /root jako kopia zapasowa.
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/aprs-beacon}"
WEB_DIR="${WEB_ROOT:-/var/www/html}/aprs"

[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root: sudo bash $0"; exit 1; }

if [ -d /run/systemd/system ]; then
    systemctl disable --now aprs-beacon.timer >/dev/null 2>&1 || true
    rm -f /etc/systemd/system/aprs-beacon.timer /etc/systemd/system/aprs-beacon.service
    systemctl daemon-reload
fi

if [ -f "$APP_DIR/config.json" ]; then
    BK="/root/aprs-beacon-backup-$(date +%Y%m%d-%H%M).tar.gz"
    tar -czf "$BK" -C "$APP_DIR" config.json comments.json 2>/dev/null || true
    echo "Kopia ustawien: $BK"
fi

rm -rf "$WEB_DIR" "$APP_DIR"
echo "APRS Object Beacon odinstalowany. Apache/PHP i Python zostaly na miejscu."
