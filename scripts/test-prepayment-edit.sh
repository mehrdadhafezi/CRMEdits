#!/usr/bin/env bash
# A fresh editor site on an internal Docker network; accepts no existing site/DB.
set -euo pipefail
test "${GITHUB_ACTIONS:-}" = true
test "${RUNNER_ENVIRONMENT:-}" = github-hosted
test -n "${GITHUB_SHA:-}"
repo=$(pwd -P)
test "$(git rev-parse HEAD)" = "$GITHUB_SHA"
python3 -B scripts/prepayment_edit_ci.py "$GITHUB_SHA" || python3 -B scripts/payment_tracking_ci.py "$GITHUB_SHA" || python3 -B scripts/finance_review_ci.py authorize --revision "$GITHUB_SHA" || python3 -B scripts/gateway_catalog_ci.py authorize --revision "$GITHUB_SHA" || python3 -B scripts/stage_visibility_ci.py authorize --revision "$GITHUB_SHA" || python3 -B scripts/stage_case_ci.py authorize --revision "$GITHUB_SHA"
runtime_preedit=$(mktemp -d /tmp/sn-preedit-XXXXXXXX)
suffix=${runtime_preedit##*/}
network="$suffix-net"; web="$suffix-web"; database="$suffix-db"; volume="$suffix-wp"
output_preedit="$repo/test-results/hr/prepayment-edit"
mkdir -p "$output_preedit" "$runtime_preedit/mu-plugins" "$runtime_preedit/browser"
wp_image='wordpress@sha256:848b03bb7b4b160e3a670454e8e928e309c9aaeb1c2584e917f42ab5197662e2'
db_image='mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b'
browser_image='mcr.microsoft.com/playwright@sha256:f1e7e01021efd65dd1a2c56064be399f3e4de00fd021ac561325f2bfbb2b837a'
cli_image='wordpress@sha256:ab5fb76caa861f32c21e1d95a057f52007f4af7130fb16a0f68874dabe0549a4'
cleanup_report() {
  for container in "$web" "$database"; do
    if test "$(docker inspect "$container" --format '{{index .Config.Labels "com.crmedits.prepayment-edit"}}' 2>/dev/null || true)" = "$suffix"; then docker rm -f "$container" >/dev/null; fi
  done
  if test "$(docker volume inspect "$volume" --format '{{index .Labels "com.crmedits.prepayment-edit"}}' 2>/dev/null || true)" = "$suffix"; then docker volume rm "$volume" >/dev/null; fi
  if test "$(docker network inspect "$network" --format '{{index .Labels "com.crmedits.prepayment-edit"}}' 2>/dev/null || true)" = "$suffix"; then docker network rm "$network" >/dev/null; fi
}
trap cleanup_report EXIT
for image in "$wp_image" "$db_image" "$browser_image" "$cli_image"; do docker pull "$image" >/dev/null; done
docker run --rm --network none --entrypoint cat "$cli_image" /usr/local/bin/wp > "$runtime_preedit/wp-cli.phar"
npm install --prefix "$runtime_preedit/browser" --ignore-scripts --no-audit --no-fund playwright@1.56.1 >/dev/null
python3 - "$runtime_preedit" <<'PY'
import json,pathlib,secrets,sys
root=pathlib.Path(sys.argv[1]);password=secrets.token_hex(24)
db='sn_prepaid_'+secrets.token_hex(5)+'_test'
(root/'private-state.json').write_text(json.dumps({'admin_password':secrets.token_hex(24)}))
(root/'db.env').write_text('MYSQL_RANDOM_ROOT_PASSWORD=1\nMYSQL_DATABASE='+db+'\nMYSQL_USER=synthetic_preedit\nMYSQL_PASSWORD='+password+'\n')
extra="define('WP_ENVIRONMENT_TYPE','local');define('SN_PREPAYMENT_TEST_SANDBOX',true);define('DISABLE_WP_CRON',true);define('AUTOMATIC_UPDATER_DISABLED',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('DISALLOW_FILE_MODS',true);"
(root/'wp.env').write_text('WORDPRESS_DB_HOST=prepay-db:3306\nWORDPRESS_DB_NAME='+db+'\nWORDPRESS_DB_USER=synthetic_preedit\nWORDPRESS_DB_PASSWORD='+password+'\nWORDPRESS_CONFIG_EXTRA='+extra+'\n')
for name in ['private-state.json','db.env','wp.env']:(root/name).chmod(0o600)
# Apache may traverse to the ID-only page fixture; credentials stay owner-only.
root.chmod(0o711)
PY
cp tests/invoices/sandbox.php "$runtime_preedit/mu-plugins/sandbox.php"
docker network create --internal --label "com.crmedits.prepayment-edit=$suffix" "$network" >/dev/null
test "$(docker network inspect "$network" --format '{{.Internal}}')" = true
docker volume create --label "com.crmedits.prepayment-edit=$suffix" "$volume" >/dev/null
docker run -d --name "$database" --label "com.crmedits.prepayment-edit=$suffix" --network "$network" --network-alias prepay-db --env-file "$runtime_preedit/db.env" --tmpfs /var/lib/mysql:rw,size=768m "$db_image" >/dev/null
docker run -d --name "$web" --label "com.crmedits.prepayment-edit=$suffix" --network "$network" --network-alias prepay-web --env-file "$runtime_preedit/wp.env" \
  --mount "type=volume,source=$volume,target=/var/www/html" \
  --mount "type=bind,source=$repo,target=/var/www/html/wp-content/plugins/sales-network-v4,readonly" \
  --mount "type=bind,source=$runtime_preedit/mu-plugins,target=/var/www/html/wp-content/mu-plugins,readonly" \
  --mount "type=bind,source=$runtime_preedit/wp-cli.phar,target=/tools/wp-cli.phar,readonly" \
  --mount "type=bind,source=$runtime_preedit,target=/evidence" "$wp_image" >/dev/null
ready=false
for attempt in $(seq 1 60); do
  if docker exec "$web" php -r 'try {if(!is_file("/var/www/html/wp-config.php")){exit(1);}$db=new mysqli("prepay-db",getenv("WORDPRESS_DB_USER"),getenv("WORDPRESS_DB_PASSWORD"),getenv("WORDPRESS_DB_NAME"),3306);exit($db->connect_errno?1:0);} catch(Throwable $e) {exit(1);}' >/dev/null 2>&1; then ready=true; break; fi
  sleep 1
done
test "$ready" = true
admin_password=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["admin_password"])' "$runtime_preedit/private-state.json")
wp_preedit() { docker exec "$web" php /tools/wp-cli.phar "$@" --path=/var/www/html --allow-root; }
wp_preedit core install --url=http://prepay-web --title='Synthetic prepayment editor' --admin_user=synthetic_admin --admin_password="$admin_password" --admin_email=synthetic@example.invalid --skip-email
wp_preedit option update timezone_string Asia/Tehran
curl --fail --silent --show-error --location --retry 3 https://downloads.wordpress.org/plugin/woocommerce.10.4.3.zip -o "$runtime_preedit/woocommerce.zip"
printf '3b1bbe109a0ab653863a6c8b4ccbdf75c519fd5ebf51151c54b8ca37b325f242  %s\n' "$runtime_preedit/woocommerce.zip" | sha256sum --check -
python3 - "$runtime_preedit" <<'PYWOO'
import pathlib,sys,zipfile
root=pathlib.Path(sys.argv[1]);target=(root/'dependencies').resolve();target.mkdir()
with zipfile.ZipFile(root/'woocommerce.zip') as archive:
 for name in archive.namelist():
  assert name.startswith('woocommerce/') and (target/name).resolve().is_relative_to(target)
 archive.extractall(target)
PYWOO
docker cp "$runtime_preedit/dependencies/woocommerce" "$web:/var/www/html/wp-content/plugins/woocommerce" >/dev/null
wp_preedit plugin activate woocommerce sales-network-v4
wp_preedit eval 'do_action("admin_init");' --skip-plugins=woocommerce --user=synthetic_admin
wp_preedit eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/invoices/pre-payment-edit-native.php setup
python3 - "$runtime_preedit" <<'PYPERM'
import json,pathlib,stat,sys
root=pathlib.Path(sys.argv[1])
assert set(json.loads((root/'browser-page.json').read_text()))=={'browser_invoice','browser_sender_invoice','browser_locked_invoice'}
for name in ['fixture.json','private-state.json','db.env','wp.env']:
 assert stat.S_IMODE((root/name).stat().st_mode)==0o600
assert stat.S_IMODE(root.stat().st_mode)==0o711
PYPERM
docker exec "$web" php -n /var/www/html/wp-content/plugins/sales-network-v4/tests/invoices/pre-payment-edit-guards.php > "$runtime_preedit/guards.log"
docker exec "$web" php -n /var/www/html/wp-content/plugins/sales-network-v4/tests/invoices/pre-payment-edit-pricing.php > "$runtime_preedit/pricing.log"
docker run --rm --network "$network" --shm-size=1g \
  --mount "type=bind,source=$runtime_preedit/browser,target=/tooling,readonly" \
  --mount "type=bind,source=$runtime_preedit,target=/evidence" \
  --mount "type=bind,source=$repo/tests,target=/tests,readonly" \
  --entrypoint node "$browser_image" /tests/invoices/pre-payment-edit-browser.cjs
wp_preedit eval-file /var/www/html/wp-content/plugins/sales-network-v4/tests/invoices/pre-payment-edit-native.php verify > "$runtime_preedit/native.log"
docker inspect "$web" "$database" > "$runtime_preedit/containers.private.json"
python3 - "$runtime_preedit" "$output_preedit" "$GITHUB_SHA" "$repo" <<'PY'
import hashlib,json,pathlib,re,shutil,sys
root,out,merge,repo=pathlib.Path(sys.argv[1]),pathlib.Path(sys.argv[2]),sys.argv[3],pathlib.Path(sys.argv[4])
containers=json.loads((root/'containers.private.json').read_text())
assert all(not c['HostConfig']['PortBindings'] for c in containers)
assert any(m['Destination']=='/var/www/html/wp-content/plugins/sales-network-v4' and m['RW'] is False for m in containers[0]['Mounts'])
paths=['includes/class-sn-plugin.php','assets/js/public-shell.js','includes/class-sn-invoice-edit-quote.php']
fixtures=['tests/invoices/'+name for name in ['pre-payment-edit-guards.php','pre-payment-edit-pricing.php','pre-payment-edit-native.php','pre-payment-edit-browser.cjs','sandbox.php']]
assert 'TOTAL 34' in (root/'guards.log').read_text().splitlines()
assert 'TOTAL 40' in (root/'pricing.log').read_text().splitlines()
assert 'PREPAYMENT_PRICING_TOTAL 30' in (root/'pricing.log').read_text().splitlines()
(out/'memory-summary.json').write_text(json.dumps({'passed':True,'synthetic':True,'guards':34,'pricing':30,'catalog':40},indent=2)+'\n')
(out/'identity.json').write_text(json.dumps({'merge':merge,'runtime':{p:hashlib.sha256((repo/p).read_bytes()).hexdigest() for p in paths},'fixtures':{p:hashlib.sha256((repo/p).read_bytes()).hexdigest() for p in fixtures},'synthetic':True,'network_internal':True,'published_ports':False,'readonly_source':True},indent=2)+'\n')
for name in ['native-summary.json','browser-summary.json','post-browser-summary.json','prepayment-edit-desktop.png','prepayment-edit-mobile.png']:
 shutil.copyfile(root/name,out/name)
print('PREPAYMENT_NATIVE_EVIDENCE_COMPLETE')
PY
