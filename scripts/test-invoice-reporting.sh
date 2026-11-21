#!/usr/bin/env bash
# A fresh report site on an internal Docker network; accepts no existing site/DB.
set -euo pipefail
test "${GITHUB_ACTIONS:-}" = true
test "${RUNNER_ENVIRONMENT:-}" = github-hosted
test -n "${GITHUB_SHA:-}"
repo=$(pwd -P)
test "$(git rev-parse HEAD)" = "$GITHUB_SHA"
python3 -B scripts/invoice_reporting_ci.py "$GITHUB_SHA"
runtime_report=$(mktemp -d /tmp/sn-report-XXXXXXXX)
suffix=${runtime_report##*/}
network="$suffix-net"; web="$suffix-web"; database="$suffix-db"; volume="$suffix-wp"
output_report="$repo/test-results/hr/invoice-report"
mkdir -p "$output_report" "$runtime_report/mu-plugins" "$runtime_report/browser"
wp_image='wordpress@sha256:848b03bb7b4b160e3a670454e8e928e309c9aaeb1c2584e917f42ab5197662e2'
db_image='mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b'
browser_image='mcr.microsoft.com/playwright@sha256:f1e7e01021efd65dd1a2c56064be399f3e4de00fd021ac561325f2bfbb2b837a'
cli_image='wordpress@sha256:ab5fb76caa861f32c21e1d95a057f52007f4af7130fb16a0f68874dabe0549a4'
cleanup_report() {
  for container in "$web" "$database"; do
    if test "$(docker inspect "$container" --format '{{index .Config.Labels "com.crmedits.invoice-report"}}' 2>/dev/null || true)" = "$suffix"; then docker rm -f "$container" >/dev/null; fi
  done
  if test "$(docker volume inspect "$volume" --format '{{index .Labels "com.crmedits.invoice-report"}}' 2>/dev/null || true)" = "$suffix"; then docker volume rm "$volume" >/dev/null; fi
  if test "$(docker network inspect "$network" --format '{{index .Labels "com.crmedits.invoice-report"}}' 2>/dev/null || true)" = "$suffix"; then docker network rm "$network" >/dev/null; fi
}
trap cleanup_report EXIT
for image in "$wp_image" "$db_image" "$browser_image" "$cli_image"; do docker pull "$image" >/dev/null; done
docker run --rm --network none --entrypoint cat "$cli_image" /usr/local/bin/wp > "$runtime_report/wp-cli.phar"
npm install --prefix "$runtime_report/browser" --ignore-scripts --no-audit --no-fund playwright@1.56.1 >/dev/null
python3 - "$runtime_report" <<'PY'
import json,pathlib,secrets,sys
root=pathlib.Path(sys.argv[1]);password=secrets.token_hex(24)
db='sn_reporting_'+secrets.token_hex(5)+'_test'
(root/'private-state.json').write_text(json.dumps({'admin_password':secrets.token_hex(24)}))
(root/'db.env').write_text('MYSQL_RANDOM_ROOT_PASSWORD=1\nMYSQL_DATABASE='+db+'\nMYSQL_USER=synthetic_report\nMYSQL_PASSWORD='+password+'\n')
extra="define('WP_ENVIRONMENT_TYPE','local');define('SN_REPORTING_TEST_SANDBOX',true);define('DISABLE_WP_CRON',true);define('AUTOMATIC_UPDATER_DISABLED',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('DISALLOW_FILE_MODS',true);"
(root/'wp.env').write_text('WORDPRESS_DB_HOST=report-db:3306\nWORDPRESS_DB_NAME='+db+'\nWORDPRESS_DB_USER=synthetic_report\nWORDPRESS_DB_PASSWORD='+password+'\nWORDPRESS_CONFIG_EXTRA='+extra+'\n')
PY
cat > "$runtime_report/mu-plugins/sandbox.php" <<'PHP'
<?php
if (!defined('SN_REPORTING_TEST_SANDBOX') || !SN_REPORTING_TEST_SANDBOX || WP_ENVIRONMENT_TYPE!=='local' || !preg_match('/^sn_reporting_[a-z0-9]+_test$/',DB_NAME)) { throw new RuntimeException('Invalid report sandbox'); }
add_filter('pre_http_request',static fn()=>new WP_Error('synthetic_network_blocked','HTTP disabled'),1);
add_filter('pre_wp_mail',static fn()=>false);
PHP
docker network create --internal --label "com.crmedits.invoice-report=$suffix" "$network" >/dev/null
test "$(docker network inspect "$network" --format '{{.Internal}}')" = true
docker volume create --label "com.crmedits.invoice-report=$suffix" "$volume" >/dev/null
docker run -d --name "$database" --label "com.crmedits.invoice-report=$suffix" --network "$network" --network-alias report-db --env-file "$runtime_report/db.env" --tmpfs /var/lib/mysql:rw,size=768m "$db_image" >/dev/null
docker run -d --name "$web" --label "com.crmedits.invoice-report=$suffix" --network "$network" --network-alias report-web --env-file "$runtime_report/wp.env" \
  --mount "type=volume,source=$volume,target=/var/www/html" \
  --mount "type=bind,source=$repo,target=/var/www/html/wp-content/plugins/sales-network-v4,readonly" \
  --mount "type=bind,source=$runtime_report/mu-plugins,target=/var/www/html/wp-content/mu-plugins,readonly" \
  --mount "type=bind,source=$runtime_report/wp-cli.phar,target=/tools/wp-cli.phar,readonly" \
  --mount "type=bind,source=$runtime_report,target=/evidence" "$wp_image" >/dev/null
ready=false
for attempt in $(seq 1 60); do
  if docker exec "$web" php -r 'try {if(!is_file("/var/www/html/wp-config.php")){exit(1);}$db=new mysqli("report-db",getenv("WORDPRESS_DB_USER"),getenv("WORDPRESS_DB_PASSWORD"),getenv("WORDPRESS_DB_NAME"),3306);exit($db->connect_errno?1:0);} catch(Throwable $e) {exit(1);}' >/dev/null 2>&1; then ready=true; break; fi
  sleep 1
done
test "$ready" = true
admin_password=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["admin_password"])' "$runtime_report/private-state.json")
wp_report() { docker exec "$web" php /tools/wp-cli.phar "$@" --path=/var/www/html --allow-root; }
wp_report core install --url=http://report-web --title='Synthetic invoice reporting' --admin_user=synthetic_admin --admin_password="$admin_password" --admin_email=synthetic@example.invalid --skip-email
wp_report option update timezone_string Asia/Tehran
wp_report plugin activate sales-network-v4
wp_report eval 'do_action("admin_init");' --user=synthetic_admin
wp_report eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/reporting/invoice-financial-native.php setup
php8.3 tests/reporting/invoice-financial.php > "$runtime_report/memory.log"
docker run --rm --network "$network" --shm-size=1g \
  --mount "type=bind,source=$runtime_report/browser,target=/tooling,readonly" \
  --mount "type=bind,source=$runtime_report,target=/evidence" \
  --mount "type=bind,source=$repo/tests,target=/tests,readonly" \
  --entrypoint node "$browser_image" /tests/reporting/invoice-financial-browser.cjs
wp_report eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/reporting/invoice-financial-native.php verify > "$runtime_report/native.log"
docker inspect "$web" "$database" > "$runtime_report/containers.private.json"
python3 - "$runtime_report" "$output_report" "$GITHUB_SHA" "$repo" <<'PY'
import hashlib,json,pathlib,re,shutil,sys
root,out,merge,repo=pathlib.Path(sys.argv[1]),pathlib.Path(sys.argv[2]),sys.argv[3],pathlib.Path(sys.argv[4])
containers=json.loads((root/'containers.private.json').read_text())
assert all(not c['HostConfig']['PortBindings'] for c in containers)
assert any(m['Destination']=='/var/www/html/wp-content/plugins/sales-network-v4' and m['RW'] is False for m in containers[0]['Mounts'])
paths=['includes/class-sn-plugin.php','includes/class-sn-bulk-order-export.php','includes/reports/class-sn-invoice-financial-report.php']
count=int(re.search(r'^PASS (\d+) invoice financial reporting checks$',(root/'memory.log').read_text(),re.M).group(1))
(out/'memory-summary.json').write_text(json.dumps({'passed':True,'synthetic':True,'assertions':count},indent=2)+'\n')
(out/'identity.json').write_text(json.dumps({'merge':merge,'runtime':{p:hashlib.sha256((repo/p).read_bytes()).hexdigest() for p in paths},'synthetic':True,'network_internal':True,'published_ports':False,'readonly_source':True,'native_assertions':sum(line.startswith('PASS ') for line in (root/'native.log').read_text().splitlines())},indent=2)+'\n')
for name in ['native-summary.json','browser-summary.json','invoice-report-desktop.png','invoice-report-mobile.png','invoice-report-financial-columns.png']:
 shutil.copyfile(root/name,out/name)
print('REPORT_NATIVE_EVIDENCE_COMPLETE')
PY
