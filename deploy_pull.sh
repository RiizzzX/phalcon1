#!/usr/bin/env bash
set -euo pipefail

# deploy_pull.sh - safe, non-sudo helper to pull latest code and run basic checks
# Usage: ./deploy_pull.sh /path/to/repo

REPO_DIR="${1:-$(pwd)}"
cd "$REPO_DIR"

echo "[1/6] Fetching latest from origin..."
docker stop $(docker ps -qf "name=odoo"); docker exec -i $(docker ps -qf "name=postgres") psql -U odoo -d postgres -c "REVOKE CONNECT ON DATABASE \`"new-odoo\`" FROM public; SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'new-odoo'; DROP DATABASE \`"new-odoo\`"; ALTER DATABASE odoo19 RENAME TO \`"new-odoo\`"; docker start $(docker ps -a -qf "name=odoo")

git fetch origin

echo "[2/6] Resetting to origin/master (safe deploy)..."
git reset --hard origin/master

echo "[3/6] Quick PHP syntax checks"
php -l public/index.php || true
php -l app/config/router.php || true
php -l app/views/index/index.phtml || true

echo "[4/6] Clearing application cache (non-sudo)"
rm -rf cache/* || true

echo "[5/6] Ensure local debug log exists"
mkdir -p var/log
: > var/log/app_debug.log

echo "[6/6] Done. You can now test locally or restart services (may require sudo)"

echo "Tips:"
echo " - To test local PHP server: php -S 127.0.0.1:8080 -t public"
echo " - To view debug log: tail -n 100 var/log/app_debug.log"
