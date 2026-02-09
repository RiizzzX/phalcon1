# Apache 403 (Forbidden) Quick Fix for this repo 🚑

This repo includes helper files to make it easy to fix common Apache 403 issues when deploying to a Debian/Ubuntu server running Apache 2.4.

Files added:
- `deploy/apache-8076.conf` — example vhost for port 8076 (replace DocumentRoot path)
- `public/.htaccess` — front-controller rewrite + no directory listing
- `scripts/fix-apache.sh` — helper script to set permissions, create vhost, enable port 8076, and reload Apache

Basic steps to run on your server (recommended):

1. Upload or copy repo to server and cd to repo root.
2. Find the real DocumentRoot used on the server (if unknown):
   - `sudo apache2ctl -S`  (shows vhosts and DocumentRoot)
   - or `sudo grep -R "DocumentRoot" /etc/apache2/sites-enabled -n`
3. Make the fix script executable and run with sudo:
   - `sudo chmod +x scripts/fix-apache.sh`
   - `sudo scripts/fix-apache.sh --docroot /path/to/your/public --servername 192.168.0.73`

Notes & troubleshooting:
- If `AllowOverride None` is set in the global vhost, `.htaccess` will be ignored. The vhost's `<Directory>` must include `AllowOverride All` or the rewrite rules must be added to the vhost.
- If you do not want to enable port 8076 globally, update `deploy/apache-8076.conf` accordingly and replace `DocumentRoot` with the correct path.
- Check logs: `sudo tail -n 200 /var/log/apache2/error.log` for the exact 403 reason.

If you want, after you pull these files on the server, run the script and paste any error output here — I'll provide the next steps.
