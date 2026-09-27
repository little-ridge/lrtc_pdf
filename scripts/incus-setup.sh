#!/bin/bash
# Install lrtc_pdf inside an Ubuntu Incus system container. Run as root there.
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this as root inside the Incus container." >&2
  exit 1
fi

APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y --no-install-recommends \
  php-cli \
  php-gd \
  php-mbstring \
  php-xml \
  php-zip \
  unzip \
  composer

cd "$APP_ROOT"
composer install --no-dev --optimize-autoloader --no-interaction
chown -R root:root "$APP_ROOT"
chmod -R a+rX "$APP_ROOT"

if ! id lrtc-pdf >/dev/null 2>&1; then
  useradd --system --user-group --home-dir /var/lib/lrtc-pdf --shell /usr/sbin/nologin lrtc-pdf
fi
mkdir -p /var/lib/lrtc-pdf /etc/lrtc-pdf
chown lrtc-pdf:lrtc-pdf /var/lib/lrtc-pdf
chmod 0750 /var/lib/lrtc-pdf
chown root:lrtc-pdf /etc/lrtc-pdf
chmod 0750 /etc/lrtc-pdf

sed "s#/opt/lrtc-pdf#${APP_ROOT}#g" "$APP_ROOT/incus/lrtc-pdf.service" > /etc/systemd/system/lrtc-pdf.service
chmod 0644 /etc/systemd/system/lrtc-pdf.service

if [[ -d /run/systemd/system ]]; then
  systemctl daemon-reload
  systemctl enable --now lrtc-pdf
else
  echo "systemd is not running in this container; start the service after enabling it." >&2
fi

echo "Create a token with: php ${APP_ROOT}/scripts/token.php add --name LRTC --owner lrtc"
echo "Or import an existing bearer: php ${APP_ROOT}/scripts/token.php add --name LRTC --owner lrtc --from /path/to/token.txt"
