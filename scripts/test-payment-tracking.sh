#!/usr/bin/env bash
# A fresh payment-tracking site on an internal Docker network; accepts no existing site/DB.
set -euo pipefail
test "${GITHUB_ACTIONS:-}" = true
test "${RUNNER_ENVIRONMENT:-}" = github-hosted
test -n "${GITHUB_SHA:-}"
repo=$(pwd -P)
test "$(git rev-parse HEAD)" = "$GITHUB_SHA"
python3 -B scripts/payment_tracking_ci.py "$GITHUB_SHA" || python3 -B scripts/finance_review_ci.py authorize --revision "$GITHUB_SHA" || python3 -B scripts/gateway_catalog_ci.py authorize --revision "$GITHUB_SHA" || python3 -B scripts/stage_visibility_ci.py authorize --revision "$GITHUB_SHA" || python3 -B scripts/stage_case_ci.py authorize --revision "$GITHUB_SHA"
runtime_tracking=$(mktemp -d /tmp/sn-tracking-XXXXXXXX)
suffix=${runtime_tracking##*/}
network="$suffix-net"; web="$suffix-web"; database="$suffix-db"; volume="$suffix-wp"
output_tracking="$repo/test-results/hr/tracking-number"
mkdir -p "$output_tracking" "$runtime_tracking/mu-plugins" "$runtime_tracking/browser"
wp_image='wordpress@sha256:848b03bb7b4b160e3a670454e8e928e309c9aaeb1c2584e917f42ab5197662e2'
db_image='mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b'
browser_image='mcr.microsoft.com/playwright@sha256:f1e7e01021efd65dd1a2c56064be399f3e4de00fd021ac561325f2bfbb2b837a'
cli_image='wordpress@sha256:ab5fb76caa861f32c21e1d95a057f52007f4af7130fb16a0f68874dabe0549a4'
cleanup_report() {
  for container in "$web" "$database"; do
    if test "$(docker inspect "$container" --format '{{index .Config.Labels "com.crmedits.payment-tracking"}}' 2>/dev/null || true)" = "$suffix"; then docker rm -f "$container" >/dev/null; fi
  done
  if test "$(docker volume inspect "$volume" --format '{{index .Labels "com.crmedits.payment-tracking"}}' 2>/dev/null || true)" = "$suffix"; then docker volume rm "$volume" >/dev/null; fi
  if test "$(docker network inspect "$network" --format '{{index .Labels "com.crmedits.payment-tracking"}}' 2>/dev/null || true)" = "$suffix"; then docker network rm "$network" >/dev/null; fi
}
trap cleanup_report EXIT
for image in "$wp_image" "$db_image" "$browser_image" "$cli_image"; do docker pull "$image" >/dev/null; done
docker run --rm --network none --entrypoint cat "$cli_image" /usr/local/bin/wp > "$runtime_tracking/wp-cli.phar"
npm install --prefix "$runtime_tracking/browser" --ignore-scripts --no-audit --no-fund playwright@1.56.1 >/dev/null
python3 - "$runtime_tracking" <<'PY'
import json,pathlib,secrets,sys
root=pathlib.Path(sys.argv[1]);password=secrets.token_hex(24)
db='sn_tracking_'+secrets.token_hex(5)+'_test'
(root/'private-state.json').write_text(json.dumps({'admin_password':secrets.token_hex(24)}))
(root/'db.env').write_text('MYSQL_RANDOM_ROOT_PASSWORD=1\nMYSQL_DATABASE='+db+'\nMYSQL_USER=synthetic_tracking\nMYSQL_PASSWORD='+password+'\n')
extra="define('WP_ENVIRONMENT_TYPE','local');define('SN_TRACKING_TEST_SANDBOX',true);define('DISABLE_WP_CRON',true);define('AUTOMATIC_UPDATER_DISABLED',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('DISALLOW_FILE_MODS',true);"
(root/'wp.env').write_text('WORDPRESS_DB_HOST=tracking-db:3306\nWORDPRESS_DB_NAME='+db+'\nWORDPRESS_DB_USER=synthetic_tracking\nWORDPRESS_DB_PASSWORD='+password+'\nWORDPRESS_CONFIG_EXTRA='+extra+'\n')
for name in ['private-state.json','db.env','wp.env']:(root/name).chmod(0o600)
# Apache may traverse to the ID-only page fixture; credentials stay owner-only.
root.chmod(0o711)
PY
cp tests/payments/tracking-sandbox.php "$runtime_tracking/mu-plugins/sandbox.php"
docker network create --internal --label "com.crmedits.payment-tracking=$suffix" "$network" >/dev/null
test "$(docker network inspect "$network" --format '{{.Internal}}')" = true
docker volume create --label "com.crmedits.payment-tracking=$suffix" "$volume" >/dev/null
docker run -d --name "$database" --label "com.crmedits.payment-tracking=$suffix" --network "$network" --network-alias tracking-db --env-file "$runtime_tracking/db.env" --tmpfs /var/lib/mysql:rw,size=768m "$db_image" >/dev/null
docker run -d --name "$web" --label "com.crmedits.payment-tracking=$suffix" --network "$network" --network-alias tracking-web --env-file "$runtime_tracking/wp.env" \
  --mount "type=volume,source=$volume,target=/var/www/html" \
  --mount "type=bind,source=$repo,target=/var/www/html/wp-content/plugins/sales-network-v4,readonly" \
  --mount "type=bind,source=$runtime_tracking/mu-plugins,target=/var/www/html/wp-content/mu-plugins,readonly" \
  --mount "type=bind,source=$runtime_tracking/wp-cli.phar,target=/tools/wp-cli.phar,readonly" \
  --mount "type=bind,source=$runtime_tracking,target=/evidence" "$wp_image" >/dev/null
ready=false
for attempt in $(seq 1 60); do
  if docker exec "$web" php -r 'try {if(!is_file("/var/www/html/wp-config.php")){exit(1);}$db=new mysqli("tracking-db",getenv("WORDPRESS_DB_USER"),getenv("WORDPRESS_DB_PASSWORD"),getenv("WORDPRESS_DB_NAME"),3306);exit($db->connect_errno?1:0);} catch(Throwable $e) {exit(1);}' >/dev/null 2>&1; then ready=true; break; fi
  sleep 1
done
test "$ready" = true
admin_password=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["admin_password"])' "$runtime_tracking/private-state.json")
wp_tracking() { docker exec "$web" php /tools/wp-cli.phar "$@" --path=/var/www/html --allow-root; }
wp_tracking core install --url=http://tracking-web --title='Synthetic payment tracking' --admin_user=synthetic_admin --admin_password="$admin_password" --admin_email=synthetic@example.invalid --skip-email
wp_tracking option update timezone_string Asia/Tehran
curl --fail --silent --show-error --location --retry 3 https://downloads.wordpress.org/plugin/woocommerce.10.4.3.zip -o "$runtime_tracking/woocommerce.zip"
printf '3b1bbe109a0ab653863a6c8b4ccbdf75c519fd5ebf51151c54b8ca37b325f242  %s\n' "$runtime_tracking/woocommerce.zip" | sha256sum --check -
python3 - "$runtime_tracking" <<'PYWOO'
import pathlib,sys,zipfile
root=pathlib.Path(sys.argv[1]);target=(root/'dependencies').resolve();target.mkdir()
with zipfile.ZipFile(root/'woocommerce.zip') as archive:
 for name in archive.namelist():
  assert name.startswith('woocommerce/') and (target/name).resolve().is_relative_to(target)
 archive.extractall(target)
PYWOO
docker cp "$runtime_tracking/dependencies/woocommerce" "$web:/var/www/html/wp-content/plugins/woocommerce" >/dev/null
wp_tracking plugin activate woocommerce sales-network-v4
wp_tracking eval 'do_action("admin_init");' --skip-plugins=woocommerce --user=synthetic_admin
wp_tracking eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/payments/tracking-number-native.php setup
python3 - "$runtime_tracking" <<'PYPERM'
import json,pathlib,stat,sys
root=pathlib.Path(sys.argv[1])
assert set(json.loads((root/'browser-page.json').read_text()))=={'invoice'}
for name in ['fixture.json','private-state.json','db.env','wp.env']:
 assert stat.S_IMODE((root/name).stat().st_mode)==0o600
assert stat.S_IMODE(root.stat().st_mode)==0o711
PYPERM
# Only the fresh task-owned volume: root CLI setup may create Woo upload directories.
docker exec "$web" chown -R www-data:www-data /var/www/html/wp-content/uploads
php8.3 tests/payments/tracking-number.php > "$runtime_tracking/memory.log"
docker run --rm --network "$network" --shm-size=1g \
  --mount "type=bind,source=$runtime_tracking/browser,target=/tooling,readonly" \
  --mount "type=bind,source=$runtime_tracking,target=/evidence" \
  --mount "type=bind,source=$repo/tests,target=/tests,readonly" \
  --entrypoint node "$browser_image" /tests/payments/tracking-number-browser.cjs
wp_tracking eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/payments/tracking-number-native.php verify > "$runtime_tracking/native.log"
wp_tracking eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/payments/tracking-number-native.php compatibility > "$runtime_tracking/compat.log"
docker inspect "$web" "$database" > "$runtime_tracking/containers.private.json"
python3 - "$runtime_tracking" "$output_tracking" "$GITHUB_SHA" "$repo" <<'PY'
import hashlib,json,pathlib,re,shutil,sys
root,out,merge,repo=pathlib.Path(sys.argv[1]),pathlib.Path(sys.argv[2]),sys.argv[3],pathlib.Path(sys.argv[4])
containers=json.loads((root/'containers.private.json').read_text())
assert all(not c['HostConfig']['PortBindings'] for c in containers)
assert any(m['Destination']=='/var/www/html/wp-content/plugins/sales-network-v4' and m['RW'] is False for m in containers[0]['Mounts'])
paths=['assets/js/payment-batch.js', 'assets/js/payment-transactions.js', 'assets/js/receipt-review.js', 'includes/class-sn-payment-batch.php', 'includes/class-sn-payment-tracking.php', 'includes/class-sn-payment-transactions.php', 'includes/class-sn-plugin.php', 'includes/class-sn-receipt-review.php']
fixtures=['tests/payments/tracking-number.php', 'tests/payments/tracking-number-native.php', 'tests/payments/tracking-number-browser.cjs', 'tests/payments/tracking-sandbox.php']
assert 'TRACKING_TOTAL 39' in (root/'memory.log').read_text().splitlines()
(out/'memory-summary.json').write_text(json.dumps({'passed':True,'synthetic':True,'assertions':39},indent=2)+'\n')
(out/'identity.json').write_text(json.dumps({'merge':merge,'runtime':{p:hashlib.sha256((repo/p).read_bytes()).hexdigest() for p in paths},'fixtures':{p:hashlib.sha256((repo/p).read_bytes()).hexdigest() for p in fixtures},'synthetic':True,'network_internal':True,'published_ports':False,'readonly_source':True},indent=2)+'\n')
for name in ['tracking-native-summary.json','tracking-browser-summary.json','tracking-integrity-summary.json','tracking-compat-summary.json','tracking-desktop.png','tracking-mobile.png']:
 shutil.copyfile(root/name,out/name)
print('TRACKING_NATIVE_EVIDENCE_COMPLETE')
PY
