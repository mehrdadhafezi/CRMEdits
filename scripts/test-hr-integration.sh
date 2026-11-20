#!/usr/bin/env bash
# Additive native coverage; never accepts an existing site, host or database.
set -euo pipefail
test "${GITHUB_ACTIONS:-}" = true
test "${RUNNER_ENVIRONMENT:-}" = github-hosted
test "${SN_TEST_WORDPRESS:-}" = 7.1.4
test "${SN_TEST_BASELINE:-}" = 2.0.237-installed
# Reuse guarded provisioning and the complete Finance/browser regression first.
# The generated VM, DB, OS account, kernel egress rules and loopback server remain.
source scripts/test-finance-integration.sh
test "$(pwd -P)" = "$repo"
case "$runtime" in /tmp/sn-approval-????????) ;; *) exit 2 ;; esac
# Provisioning hands this generated directory to PHP's isolated OS account.
# The hosted runner needs its own scratch entries; private files stay mode 600.
sudo setfacl -m "u:$(id -un):rwx" "$runtime"
output="$repo/test-results/hr"
mkdir -p "$output"
sudo setfacl -m u:sn-approval-test:rwx "$output"
export SN_APPROVAL_OUTPUT="$output" SN_HR_BROWSER_NATIVE=YES
export SN_PLAYWRIGHT_MODULE="$runtime/browser/node_modules/playwright"
export SN_MIS_TEST_ALLOW_RESET=YES SN_MIS_TEST_BOOTSTRAP="$repo/tests/hr/ci-mis-bootstrap.php"
export SN_MIS_TEST_OUTPUT="$runtime/mis/evidence"
run_test() {
  sudo --preserve-env=SN_APPROVAL_ROOT,SN_APPROVAL_OUTPUT,SN_APPROVAL_ALLOW_SYNTHETIC,SN_PAYMENT_TEST_BOOTSTRAP,SN_PLAYWRIGHT_MODULE,PLAYWRIGHT_BROWSERS_PATH,SN_HR_BROWSER_NATIVE,SN_MIS_TEST_ALLOW_RESET,SN_MIS_TEST_BOOTSTRAP,SN_MIS_TEST_OUTPUT,GITHUB_ACTIONS,RUNNER_ENVIRONMENT \
    -u sn-approval-test setpriv --no-new-privs "$@"
}
run_php() { run_test php8.3 -d sendmail_path=/bin/false -d zend.exception_ignore_args=1 "$@"; }
phase() {
  local label="$1"; shift
  printf 'RUNNING %s\n' "$label"
  (umask 077; : > "$runtime/hr-$label.private.log")
  if ! "$@" > "$runtime/hr-$label.private.log" 2>&1; then
    printf 'FAIL %s; detailed synthetic diagnostics stay inside the disposable VM\n' "$label"
    python3 - "$runtime/hr-$label.private.log" "$server_log" <<'PY'
import pathlib,re,sys
text=pathlib.Path(sys.argv[1]).read_text(errors='replace')
frames=re.findall(r'([A-Za-z0-9_.-]+\.(?:php|cjs))(?::| on line )(\d+)',text)
print('Diagnostic code locations: '+repr(frames[-8:]))
print('Completed assertions before failure: '+str(sum(x.startswith('PASS ') for x in text.splitlines())))
labels=re.findall(r'RuntimeException: FAIL ([A-Za-z0-9 _/()+,.:-]{1,180})',text)
if labels:print('Assertion label: '+labels[-1].split(' in /',1)[0])
for line in text.splitlines():
 if re.fullmatch(r'BROWSER_(?:ASSERTION|FAILURE_KIND|CODE_LINES) [A-Za-z0-9 _/()+,.:\[\]-]{1,200}',line):print(line)
server=pathlib.Path(sys.argv[2]).read_text(errors='replace')
print('Native HTTP input-limit warning recorded: '+str('Input variables exceeded' in server))
print('Native HTTP fatal/parse count: '+str(len(re.findall(r'(?:Fatal|Parse) error:',server))))
PY
    exit 1
  fi
  printf 'PASS %s\n' "$label"
}
phase hierarchy-memory run_php tests/hr/invoice-reissue.php
phase fixtures run_php tests/hr/native-fixtures.php
phase authorization-schema run_php tests/hr/native.php
curl --fail --silent --show-error --location --retry 3 https://downloads.wordpress.org/plugin/woocommerce.10.4.3.zip -o "$runtime/woocommerce.zip"
printf '3b1bbe109a0ab653863a6c8b4ccbdf75c519fd5ebf51151c54b8ca37b325f242  %s\n' "$runtime/woocommerce.zip" | sha256sum --check -
sudo python3 - "$runtime/woocommerce.zip" "$runtime/wordpress/wp-content/plugins" <<'PY'
import pathlib,sys,zipfile
target=pathlib.Path(sys.argv[2]).resolve()
with zipfile.ZipFile(sys.argv[1]) as archive:
    for name in archive.namelist():
        if not name.startswith('woocommerce/') or not (target/name).resolve().is_relative_to(target):
            raise SystemExit('Unsafe dependency archive')
    archive.extractall(target)
PY
sudo chown -R sn-approval-test "$runtime/wordpress/wp-content/plugins/woocommerce"
phase woocommerce run_php tests/hr/native-woo.php
phase invoice-creation run_php tests/hr/native-invoices.php
phase wallet-commission run_php tests/hr/native-ledger.php
phase finance-reporting run_php tests/hr/native-finance.php
phase native-http-browser run_test node tests/hr/ci-browser.cjs
# Retain all existing suites, then exercise product settings through native HTTP.
phase product-policy-memory run_php tests/products/invoice-product-policy.php
python3 - "$runtime/hr-product-policy-memory.private.log" "$output/product241-unit.json" <<'PY'
import json,pathlib,sys
text=pathlib.Path(sys.argv[1]).read_text(errors='replace')
count=sum(line.startswith('PASS ') for line in text.splitlines())
assert count==40 and 'TOTAL 40' in text.splitlines()
pathlib.Path(sys.argv[2]).write_text(json.dumps({'passed':True,'synthetic':True,'version':'2.0.241','assertions':count,'production_touched':False,'real_sms_sent':False},indent=2)+'\n')
PY
phase product-settings-native run_php tests/products/native-product-settings.php
phase product-sms-races run_test python3 -B tests/products/product-sms-races.py
sudo install -o sn-approval-test -m 600 tests/products/product-provider.php "$runtime/wordpress/wp-content/mu-plugins/product241-provider.php"
phase product-native-http-browser run_test node tests/products/ci-browser.cjs
# Verify code-only rollback after the policy and all financial fixtures exist.
phase snapshot-before-rollback run_php tests/hr/ci-code-state.php save
mkdir "$runtime/hr-238"
git archive 3660fe46dcb79f7a96f0b3f04326f119a4d78441 sales-network.php includes assets templates | tar -x -C "$runtime/hr-238"
sudo chown -R sn-approval-test "$runtime/hr-238"
sudo mv "$runtime/wordpress/wp-content/plugins/sales-network-v4" "$runtime/hr-candidate"
sudo mv "$runtime/hr-238" "$runtime/wordpress/wp-content/plugins/sales-network-v4"
phase rollback-238 run_php tests/hr/ci-code-state.php rollback
sudo mv "$runtime/wordpress/wp-content/plugins/sales-network-v4" "$runtime/hr-238"
sudo mv "$runtime/hr-candidate" "$runtime/wordpress/wp-content/plugins/sales-network-v4"
phase reinstall-241 run_php tests/hr/ci-code-state.php restored
# MIS resets its own separate fresh database. It cannot touch the HR/Finance DB.
mis_db="sn_approval_${GITHUB_RUN_ID}_hrmis_test"
sudo env MYSQL_PWD=root mysql --user=root --protocol=socket <<SQL
CREATE DATABASE \`$mis_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`$mis_db\`.* TO 'sn_approval'@'localhost';
SQL
mkdir -p "$runtime/mis/evidence"
tar -xzf "$runtime/wordpress.tar.gz" -C "$runtime/mis"
mkdir -p "$runtime/mis/wordpress/wp-content/mu-plugins"
cp tests/payments/integration/mu-sandbox.php "$runtime/mis/wordpress/wp-content/mu-plugins/synthetic-sandbox.php"
sudo python3 - "$runtime/wordpress/wp-config.php" "$runtime/mis/wordpress/wp-config.php" "$db" "$mis_db" <<'PY'
import pathlib,sys
text=pathlib.Path(sys.argv[1]).read_text()
old="define('DB_NAME', '%s');"%sys.argv[3]
assert text.count(old)==1 and text.count("$table_prefix = 'snt_';")==1
text=text.replace(old,"define('DB_NAME', '%s');"%sys.argv[4]).replace("$table_prefix = 'snt_';","$table_prefix = 'wp_';")
target=pathlib.Path(sys.argv[2]);target.write_text(text);target.chmod(0o600)
PY
sudo chown -R sn-approval-test "$runtime/mis"
phase mis-install run_php tests/hr/ci-mis-install.php
phase mis-ten-script-regression run_php tests/mis/run-all.php
python3 - "$runtime/hr-mis-ten-script-regression.private.log" "$output/mis-summary.json" <<'PY'
import json,pathlib,sys
text=pathlib.Path(sys.argv[1]).read_text(errors='replace')
count=sum(line.startswith('PASS ') for line in text.splitlines())
assert count>=100
pathlib.Path(sys.argv[2]).write_text(json.dumps({'passed':True,'synthetic':True,'assertions':count,'scripts':10,'isolated_database':True,'production_touched':False},indent=2)+'\n')
PY
printf 'HR_NATIVE_REGRESSION_COMPLETE\n'
