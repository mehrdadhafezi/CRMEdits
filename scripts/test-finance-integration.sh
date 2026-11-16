#!/usr/bin/env bash
# Runs only on a disposable GitHub-hosted Linux VM. Never targets an existing site.
set -euo pipefail
test "${GITHUB_ACTIONS:-}" = true
test "${RUNNER_ENVIRONMENT:-}" = github-hosted
case "${SN_TEST_BASELINE:-}" in
  2.0.235) baseline=bd9a68b4d03bf55ee4b9e33a44fc529d94a3268d ;;
  2.0.236) baseline=19ea18d ;;
  *) echo 'Unsupported synthetic upgrade baseline'; exit 2 ;;
esac
repo=$(pwd -P)
runtime=$(mktemp -d /tmp/sn-approval-XXXXXXXX)
output="$repo/test-results/finance"
mkdir -p "$output"
sudo apt-get update -qq
sudo apt-get install -y --no-install-recommends php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl php8.3-sqlite3 mysql-server
sudo service mysql start
sudo useradd --system --home-dir "$runtime" --shell /bin/bash sn-approval-test
uid=$(id -u sn-approval-test)
sudo chown sn-approval-test "$runtime"
sudo chmod 755 "$runtime"
db="sn_approval_${GITHUB_RUN_ID}_${SN_TEST_BASELINE//./}_test"
dbpass=$(openssl rand -hex 24)
printf '::add-mask::%s\n' "$dbpass"
# GitHub's disposable Ubuntu image documents root/root for its local MySQL.
sudo env MYSQL_PWD=root mysql --user=root --protocol=socket <<SQL
CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sn_approval'@'localhost' IDENTIFIED BY '$dbpass';
GRANT ALL PRIVILEGES ON \`$db\`.* TO 'sn_approval'@'localhost';
SQL
wp_version=7.1.4
curl --fail --silent --show-error --location --retry 3 "https://wordpress.org/wordpress-$wp_version.tar.gz" -o "$runtime/wordpress.tar.gz"
curl --fail --silent --show-error --location --retry 3 "https://wordpress.org/wordpress-$wp_version.tar.gz.sha1" -o "$runtime/wordpress.sha1"
expected=$(tr -d '[:space:]' < "$runtime/wordpress.sha1")
test "${#expected}" -eq 40
printf '%s  %s\n' "$expected" "$runtime/wordpress.tar.gz" | sha1sum --check -
tar -xzf "$runtime/wordpress.tar.gz" -C "$runtime"
mkdir -p "$runtime/browser"
npm install --prefix "$runtime/browser" --ignore-scripts --no-audit --no-fund playwright@1.58.2
node "$runtime/browser/node_modules/playwright/cli.js" install --with-deps chromium
python3 scripts/package.py
candidate=$(find "$repo/dist" -maxdepth 1 -name '*.zip' -type f)
test -f "$candidate"
mkdir -p "$runtime/wordpress/wp-content/plugins/sales-network-v4" "$runtime/wordpress/wp-content/mu-plugins" "$runtime/wordpress/wp-content/themes/sn-synthetic"
git archive "$baseline" sales-network.php includes assets templates | tar -x -C "$runtime/wordpress/wp-content/plugins/sales-network-v4"
cp tests/payments/integration/mu-sandbox.php "$runtime/wordpress/wp-content/mu-plugins/synthetic-sandbox.php"
cat > "$runtime/wordpress/wp-config.php" <<PHP
<?php
define('DB_NAME', '$db');
define('DB_USER', 'sn_approval');
define('DB_PASSWORD', '$dbpass');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('SN_APPROVAL_TEST_SANDBOX', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_HOME', 'http://127.0.0.1:8877');
define('WP_SITEURL', 'http://127.0.0.1:8877');
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', '$runtime/debug.log');
define('DISALLOW_FILE_MODS', true);
\$table_prefix = 'snt_';
PHP
for key in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
  printf "define('%s', '%s');\n" "$key" "$(openssl rand -hex 40)" >> "$runtime/wordpress/wp-config.php"
done
cat >> "$runtime/wordpress/wp-config.php" <<'PHP'
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-settings.php';
PHP
cat > "$runtime/wordpress/wp-content/themes/sn-synthetic/style.css" <<'CSS'
/* Theme Name: Synthetic CRM integration */
body { margin:0; background:#f6f8fc; } main { padding:16px; }
CSS
cat > "$runtime/wordpress/wp-content/themes/sn-synthetic/index.php" <<'PHP'
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?></head><body><main><?php while(have_posts()){the_post();the_content();} ?></main><?php wp_footer(); ?></body></html>
PHP
sudo chown -R sn-approval-test "$runtime" "$output"
sudo chmod 600 "$runtime/wordpress/wp-config.php"
# Deny external IPv4 and IPv6 for the actual PHP/browser OS account before any WP bootstrap.
sudo iptables -I OUTPUT 1 -m owner --uid-owner "$uid" ! -d 127.0.0.0/8 -j REJECT
sudo ip6tables -I OUTPUT 1 -m owner --uid-owner "$uid" ! -d ::1/128 -j REJECT
export SN_APPROVAL_ROOT="$runtime" SN_APPROVAL_OUTPUT="$output" SN_APPROVAL_ALLOW_SYNTHETIC=YES
export SN_PAYMENT_TEST_BOOTSTRAP="$runtime/wordpress/wp-load.php"
export SN_PLAYWRIGHT_MODULE="$runtime/browser/node_modules/playwright/index.mjs"
export PLAYWRIGHT_BROWSERS_PATH="$HOME/.cache/ms-playwright"
# Chromium was downloaded by runner; allow the isolated account to read that specific cache.
sudo chmod o+x "$HOME" "$HOME/.cache"
sudo chmod -R a+rX "$PLAYWRIGHT_BROWSERS_PATH"
run_test() { sudo --preserve-env=SN_APPROVAL_ROOT,SN_APPROVAL_OUTPUT,SN_APPROVAL_ALLOW_SYNTHETIC,SN_PAYMENT_TEST_BOOTSTRAP,SN_PLAYWRIGHT_MODULE,PLAYWRIGHT_BROWSERS_PATH -u sn-approval-test "$@"; }
run_php() { run_test php -d sendmail_path=/bin/false "$@"; }
run_php -r '$s=@stream_socket_client("tcp://1.1.1.1:443",$e,$m,2);if($s)exit(1);echo "PASS OS-level external egress blocked before bootstrap\n";'
run_php tests/payments/integration/install.php | tee "$output/install.txt"
run_php tests/payments/integration/fixtures.php | tee "$output/fixtures.txt"
mv "$runtime/wordpress/wp-content/plugins/sales-network-v4" "$runtime/baseline-plugin"
python3 - "$candidate" "$runtime/wordpress/wp-content/plugins" <<'PY'
import pathlib,sys,zipfile
target=pathlib.Path(sys.argv[2]).resolve()
with zipfile.ZipFile(sys.argv[1]) as z:
    for name in z.namelist():
        if not (target/name).resolve().is_relative_to(target): raise SystemExit('Unsafe package path')
    z.extractall(target)
PY
sudo chown -R sn-approval-test "$runtime/wordpress/wp-content/plugins/sales-network-v4"
run_php tests/payments/integration/verify.php upgrade | tee "$output/upgrade.txt"
mv "$runtime/wordpress/wp-content/plugins/sales-network-v4" "$runtime/candidate-plugin"
mv "$runtime/baseline-plugin" "$runtime/wordpress/wp-content/plugins/sales-network-v4"
run_php tests/payments/integration/verify.php rollback | tee "$output/rollback.txt"
mv "$runtime/wordpress/wp-content/plugins/sales-network-v4" "$runtime/baseline-plugin"
mv "$runtime/candidate-plugin" "$runtime/wordpress/wp-content/plugins/sales-network-v4"
run_php tests/payments/integration/verify.php restored | tee "$output/restored.txt"
run_php tests/payments/gateway-inquiry.php | tee "$output/gateway.txt"
run_php tests/payments/receipt-last4.php | tee "$output/last4.txt"
run_test env PHP_CLI_SERVER_WORKERS=4 php -d sendmail_path=/bin/false -S 127.0.0.1:8877 -t "$runtime/wordpress" > "$runtime/server.log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT
for attempt in $(seq 1 40); do
  if curl --silent --fail http://127.0.0.1:8877/wp-login.php > /dev/null; then break; fi
  sleep 1
done
run_test node tests/payments/integration/browser.cjs | tee "$output/browser.txt"
run_php tests/payments/integration/verify.php final | tee "$output/final.txt"
printf 'Tested exact artifact: %s\n' "$(basename "$candidate")"
cat "$candidate.sha256"
