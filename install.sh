#!/usr/bin/env bash
# APRS Object Beacon - instalator i aktualizacja
# Open source project by W9GIL / Group 312 Chicago
#
# Instalacja (i aktualizacja - to samo polecenie):
#   curl -fsSL https://raw.githubusercontent.com/woyteg/aprs-object-beacon/main/install.sh | sudo bash
#
# Ponowne uruchomienie NIE nadpisuje konfiguracji ani komentarzy.

set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/woyteg/aprs-object-beacon.git}"
BRANCH="${BRANCH:-main}"
APP_DIR="${APP_DIR:-/opt/aprs-beacon}"
WEB_ROOT="${WEB_ROOT:-/var/www/html}"
WEB_DIR="$WEB_ROOT/aprs"
SRC_DIR="$APP_DIR/src"
OLD_DIR="/opt/aprs312"            # wczesniejsza, reczna instalacja - przeniesiemy jej ustawienia
WEB_USER="www-data"
SKIP_APT="${SKIP_APT:-0}"         # 1 = nie instaluj pakietow (testy)
LOCAL_SRC="${LOCAL_SRC:-}"        # katalog z kodem zamiast pobierania z GitHuba (testy)

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m ok\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  !\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31mBLAD:\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "uruchom jako root, np.: curl -fsSL .../install.sh | sudo bash"

has_systemd()  { [ -d /run/systemd/system ]; }
svc_active()   { has_systemd && systemctl is-active --quiet "$1" 2>/dev/null; }
pkg_installed(){ dpkg -s "$1" >/dev/null 2>&1; }
apt_install()  {
    [ "$SKIP_APT" = 1 ] && return 0
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends "$@" >/dev/null
}

echo
echo "   APRS Object Beacon - instalacja"
echo "   open source project by W9GIL / Group 312 Chicago"
echo

# ---------- 1. pakiety i serwer WWW ----------
say "Sprawdzam serwer WWW"
if   svc_active apache2  || pkg_installed apache2;  then WEB=apache
elif svc_active lighttpd || pkg_installed lighttpd; then WEB=lighttpd
elif svc_active nginx    || pkg_installed nginx;    then WEB=nginx
else WEB=none
fi
ok "wykryto: $WEB"

say "Instaluje potrzebne pakiety (to moze potrwac kilka minut)"
[ "$SKIP_APT" = 1 ] || apt-get update -q >/dev/null
apt_install python3 git php-cli ca-certificates

case "$WEB" in
  apache|none)
    if compgen -G "/etc/apache2/mods-enabled/php*.load" >/dev/null \
       || compgen -G "/etc/apache2/conf-enabled/php*-fpm.conf" >/dev/null; then
        ok "Apache z PHP juz dziala"
    else
        apt_install apache2 libapache2-mod-php
        if [ "$SKIP_APT" != 1 ]; then
            for m in /etc/apache2/mods-available/php*.load; do
                [ -e "$m" ] || continue
                a2enmod -q "$(basename "$m" .load)" >/dev/null 2>&1 || true
                break
            done
        fi
        ok "Apache + PHP"
    fi
    has_systemd && systemctl reload-or-restart apache2 2>/dev/null || true
    WEB=apache
    ;;
  lighttpd)
    apt_install php-cgi
    if [ "$SKIP_APT" != 1 ]; then
        lighty-enable-mod fastcgi fastcgi-php >/dev/null 2>&1 || true
        has_systemd && systemctl force-reload lighttpd 2>/dev/null || true
    fi
    ok "lighttpd + PHP (fastcgi)"
    ;;
  nginx)
    apt_install php-fpm
    warn "nginx: sprawdz, czy w konfiguracji strony jest obsluga PHP (location ~ \\.php\$ -> php-fpm)."
    warn "Jesli /aprs/ pobiera plik zamiast otwierac strone, trzeba to dopisac recznie."
    ;;
esac
id "$WEB_USER" >/dev/null 2>&1 || die "brak uzytkownika $WEB_USER - serwer WWW nie jest poprawnie zainstalowany"

# ---------- 2. kod programu ----------
say "Pobieram APRS Object Beacon"
mkdir -p "$APP_DIR"
if [ -n "$LOCAL_SRC" ]; then
    rm -rf "$SRC_DIR"; mkdir -p "$SRC_DIR"
    cp -a "$LOCAL_SRC"/. "$SRC_DIR"/
    ok "z katalogu $LOCAL_SRC"
elif [ -d "$SRC_DIR/.git" ]; then
    git -C "$SRC_DIR" fetch -q --depth 1 origin "$BRANCH"
    git -C "$SRC_DIR" reset -q --hard FETCH_HEAD
    ok "zaktualizowano ($(git -C "$SRC_DIR" log -1 --format=%h))"
else
    rm -rf "$SRC_DIR"
    git clone -q --depth 1 -b "$BRANCH" "$REPO_URL" "$SRC_DIR"
    ok "pobrano ($(git -C "$SRC_DIR" log -1 --format=%h))"
fi
[ -f "$SRC_DIR/beacon/beacon.py" ] || die "w pobranym kodzie brakuje beacon/beacon.py"

# ---------- 3. pliki ----------
say "Instaluje pliki"
install -m 755 "$SRC_DIR/beacon/beacon.py" "$APP_DIR/beacon.py"

MIGRATED=0
for f in config.json comments.json; do
    if [ -f "$APP_DIR/$f" ]; then
        ok "$f - zostawiam Twoje ustawienia"
    elif [ -f "$OLD_DIR/$f" ]; then
        cp "$OLD_DIR/$f" "$APP_DIR/$f"; MIGRATED=1
        ok "$f - przeniesiono z $OLD_DIR"
    else
        cp "$SRC_DIR/config/${f%.json}.example.json" "$APP_DIR/$f"
        ok "$f - utworzono domyslny"
    fi
done

mkdir -p "$WEB_DIR"
sed "s|^const APP_DIR .*|const APP_DIR   = '$APP_DIR';|" "$SRC_DIR/web/index.php" > "$WEB_DIR/index.php"
chmod 644 "$WEB_DIR/index.php"
# stary edytor z recznej instalacji -> przekierowanie na nowy
if [ -f "$WEB_DIR/editor.php" ]; then
    printf '<?php header("Location: ./"); exit;\n' > "$WEB_DIR/editor.php"
    ok "stary editor.php przekierowuje teraz na /aprs/"
fi
ok "edytor: $WEB_DIR/index.php"

chown root:root "$APP_DIR/beacon.py"
chown "$WEB_USER:$WEB_USER" "$APP_DIR" "$APP_DIR/config.json" "$APP_DIR/comments.json"
chmod 640 "$APP_DIR/config.json"
chmod 664 "$APP_DIR/comments.json"
chmod 755 "$APP_DIR"   # serwer WWW musi moc wejsc do katalogu

# ---------- 4. systemd ----------
if has_systemd; then
    say "Ustawiam automatyczne wysylanie co 10 minut"
    if [ -f /etc/systemd/system/aprs312-beacon.timer ]; then
        systemctl disable --now aprs312-beacon.timer >/dev/null 2>&1 || true
        rm -f /etc/systemd/system/aprs312-beacon.timer /etc/systemd/system/aprs312-beacon.service
        ok "wylaczono stary timer aprs312-beacon (zeby nie nadawac podwojnie)"
    fi
    for u in aprs-beacon.service aprs-beacon.timer; do
        sed "s|/opt/aprs-beacon|$APP_DIR|g" "$SRC_DIR/systemd/$u" > "/etc/systemd/system/$u"
    done
    systemctl daemon-reload
    systemctl enable --now aprs-beacon.timer >/dev/null 2>&1
    ok "timer aprs-beacon aktywny"
else
    warn "brak systemd - automatyczne wysylanie nie zostalo wlaczone"
fi

# ---------- 5. test ----------
say "Test (bez nadawania)"
if out=$(runuser -u "$WEB_USER" -- python3 "$APP_DIR/beacon.py" --dry-run 2>&1); then
    echo "$out" | sed 's/^/    /'
else
    warn "test sie nie udal:"; echo "$out" | sed 's/^/    /'
fi

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
echo
printf '\033[1;32mGotowe!\033[0m Otworz edytor w przegladarce:\n\n'
printf '    \033[1mhttp://%s/aprs/\033[0m\n\n' "${IP:-ADRES_PI}"
if grep -q '"N0CALL' "$APP_DIR/config.json" 2>/dev/null; then
    echo "  Wpisz swoj znak i pozycje przemiennika - do tego czasu beacon nic nie wysyla."
fi
[ "$MIGRATED" = 1 ] && echo "  Twoje ustawienia z $OLD_DIR zostaly przeniesione. Stary katalog mozesz usunac."
echo "  Aktualizacja: uruchom to samo polecenie instalacji jeszcze raz."
echo "  Odinstalowanie: sudo bash $SRC_DIR/uninstall.sh"
echo
