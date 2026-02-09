#!/bin/bash
set -euo pipefail

# fix-apache.sh - helper to configure Apache for this app and fix common 403 issues
# Usage: sudo ./fix-apache.sh --docroot /path/to/public --servername 192.168.0.73

DOCROOT=""
SERVERNAME="192.168.0.73"
VHOST_NAME="ilmu_pkl_8076.conf"

function usage() {
  echo "Usage: $0 --docroot /path/to/public [--servername name]"
  exit 1
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --docroot) DOCROOT="$2"; shift 2;;
    --servername) SERVERNAME="$2"; shift 2;;
    -h|--help) usage;;
    *) echo "Unknown arg: $1"; usage;;
  esac
done

if [[ -z "$DOCROOT" ]]; then
  echo "ERROR: --docroot is required. Example: sudo $0 --docroot /var/www/ilmu_pkl/new/public"
  exit 2
fi

if [[ ! -d "$DOCROOT" ]]; then
  echo "ERROR: DOCROOT does not exist: $DOCROOT"
  exit 3
fi

APP_ROOT=$(cd "$(dirname "$DOCROOT")/.." && pwd || true)

echo "Setting ownership and permissions for $DOCROOT"
chown -R www-data:www-data "$DOCROOT"
find "$DOCROOT" -type d -exec chmod 755 {} +
find "$DOCROOT" -type f -exec chmod 644 {} +

# Create vhost file in /etc/apache2/sites-available/
VHOST_PATH="/etc/apache2/sites-available/$VHOST_NAME"
cat > "$VHOST_PATH" <<EOF
Listen 8076

<VirtualHost *:8076>
    ServerName $SERVERNAME
    DocumentRoot $DOCROOT

    ErrorLog ">\${APACHE_LOG_DIR}/ilmu_pkl_error.log"
    CustomLog ">\\${APACHE_LOG_DIR}/ilmu_pkl_access.log" combined

    <Directory $DOCROOT>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

# Enable port in ports.conf if needed
if ! grep -q "^Listen 8076$" /etc/apache2/ports.conf; then
  echo "Enabling Listen 8076 in /etc/apache2/ports.conf"
  echo "Listen 8076" >> /etc/apache2/ports.conf
fi

# Enable site and reload
a2ensite "$VHOST_NAME" || true

# Test config and reload apache
apache2ctl configtest
systemctl reload apache2

echo "Apache reloaded. Tail of error log (last 50 lines):"
tail -n 50 /var/log/apache2/error.log || true

echo "Done. If you still see 403 Forbidden, check that the DocumentRoot path used above is the same as the vhost configured on the server (apache2ctl -S) and that no other vhost denies access."
