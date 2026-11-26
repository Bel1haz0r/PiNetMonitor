#!/usr/bin/env bash
# Installs PiNetMonitor on a Raspberry Pi (Raspberry Pi OS / Debian 13 "trixie" or newer).
# Run from the project directory on the Pi:   sudo bash deploy/install.sh
#
# Build the frontend BEFORE copying the project to the Pi (npm run build on your
# computer) so Node.js is not needed on the Pi; the script builds it only if
# public/build is missing and npm is available.
set -euo pipefail

APP_DIR=/opt/pinetmonitor
APP_USER=pinet
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ $EUID -ne 0 ]]; then
    echo "Run as root: sudo bash deploy/install.sh" >&2
    exit 1
fi

echo "==> Installing packages"
apt-get update
apt-get install -y --no-install-recommends \
    php-cli php-sqlite3 php-curl php-xml php-mbstring php-zip php-gd \
    composer unzip rsync iputils-ping iproute2 iw sqlite3 ca-certificates

# Optional second speed-measurement method: WITH_SPEEDTEST=1 sudo -E bash deploy/install.sh
# installs Debian's open-source speedtest-cli. Ookla's own CLI comes from Ookla's
# repository and has its own licence; install it yourself if you prefer it (see README).
if [[ "${WITH_SPEEDTEST:-0}" == "1" ]]; then
    apt-get install -y --no-install-recommends speedtest-cli
fi

PHP_OK=$(php -r 'echo version_compare(PHP_VERSION, "8.3.0", ">=") ? "yes" : "no";')
if [[ "$PHP_OK" != "yes" ]]; then
    echo "PHP 8.3 or newer is required (found $(php -r 'echo PHP_VERSION;'))." >&2
    echo "Use Raspberry Pi OS based on Debian 13 (trixie), or install PHP from packages.sury.org." >&2
    exit 1
fi
for ext in sockets pcntl posix pdo_sqlite curl zip mbstring dom; do
    php -m | grep -qi "^${ext}$" || { echo "Missing PHP extension: ${ext}" >&2; exit 1; }
done

echo "==> Creating user and copying the application to ${APP_DIR}"
id -u "$APP_USER" >/dev/null 2>&1 || useradd --system --home-dir "$APP_DIR" --shell /usr/sbin/nologin "$APP_USER"
mkdir -p "$APP_DIR"
rsync -a --delete \
    --exclude .git --exclude node_modules --exclude vendor --exclude .env \
    --exclude 'storage/app/monitor' --exclude 'storage/logs/*' --exclude 'database/*.sqlite*' \
    "$SRC_DIR"/ "$APP_DIR"/

cd "$APP_DIR"
if [[ ! -f public/build/manifest.json ]]; then
    if command -v npm >/dev/null 2>&1; then
        echo "==> Building the frontend (this is slow on a Pi)"
        npm ci && npm run build
    else
        echo "public/build is missing. Run 'npm run build' on your computer and copy the project again." >&2
        exit 1
    fi
fi

echo "==> Configuring"
if [[ ! -f .env ]]; then
    cp .env.example .env
    sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/; s/^LOG_LEVEL=.*/LOG_LEVEL=warning/' .env
    sed -i 's|^# MONITOR_RUNTIME_DIR=.*|MONITOR_RUNTIME_DIR=/run/pinetmonitor|' .env
    sed -i "s|^APP_URL=.*|APP_URL=http://$(hostname).local:8080|" .env
fi
touch database/database.sqlite
mkdir -p storage/app/monitor storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R "$APP_USER:$APP_USER" "$APP_DIR"

run() { sudo -u "$APP_USER" -H env COMPOSER_HOME=/tmp/pinet-composer "$@"; }
run composer install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=base64:' .env || run php artisan key:generate --force
run php artisan migrate --force
run php artisan config:cache
run php artisan route:cache
run php artisan view:cache

echo "==> Installing systemd units"
install -m 0644 deploy/pinetmonitor.service /etc/systemd/system/pinetmonitor.service
install -m 0644 deploy/pinetmonitor-web.service /etc/systemd/system/pinetmonitor-web.service
systemctl daemon-reload
systemctl enable --now pinetmonitor.service pinetmonitor-web.service

sleep 3
systemctl --no-pager --lines=0 status pinetmonitor.service pinetmonitor-web.service || true
IP=$(hostname -I | awk '{print $1}')
echo
echo "PiNetMonitor is running. Dashboard: http://${IP}:8080  (or http://$(hostname).local:8080)"
echo "Logs:    journalctl -u pinetmonitor -f"
echo "Verify:  cd ${APP_DIR} && sudo -u ${APP_USER} php artisan evidence:verify"
