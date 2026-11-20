"""Additive, exact-payload CI evidence for the reviewed 2.0.240 payment fix only."""
import hashlib,json
from pathlib import Path
from package import committed_members
from risk_report import git

BASE='b0f9db99be57411cae6f9497f95ea5b26730f99f'
RUNTIME={
    'assets/js/receipt-review.js':'9e4e5512b16e8b8b245bd19004da2663a29e08a7d823cd84cfd35eb092f58e1f',
    'assets/js/unified-invoice-payments.js':'1155e65afe4712eb80f5979e3be75b19bc176e9ddbc7233b5adee7c2d8181a1c',
    'includes/class-sn-invoice.php':'bfee10aea042b846d979370f966c5604f11067997f15cbc197dece983bf0b654',
    'includes/class-sn-payment-gateway-guard.php':'c66a0d441c837be8dd7593feae1bf3bfb71a47c074ff20803eedc0a7bc21bba5',
    'includes/class-sn-payment-transactions.php':'17b11b14c202e0dd2f563e727b579b4941a54ca244f775e5feb97fb1fd68488b',
    'includes/class-sn-plugin.php':'6bc432c31b3a72956c542c57acce8a813724258fc4c98beb6d3c267d67fe276d',
    'includes/class-sn-receipt-review.php':'711d281139c6c880410d28ad8e4b21e8d99a562a5d6bbf6737256403d6a97bff',
    'sales-network.php':'d2f37cd0fc13e0c7467f31934f111788786b96adc5170a770cf97f887d809794',
}
NON_RUNTIME={
    'docs/API_CONTRACTS.md','docs/modules/INVOICES.md','docs/modules/PAYMENTS_FINANCE.md',
    'docs/RELEASE_2.0.240_GATEWAY_MANUAL.md','docs/tasks/GATEWAY_MANUAL_PAYMENT_240.md',
    'docs/TESTING_STANDARDS.md','tests/payments/integration/README.md','tests/hr/README.md',
    'scripts/gateway_manual_ci.py','scripts/fast_track_ci.py','scripts/test-finance-integration.sh',
    'scripts/test-hr-integration.sh','.github/workflows/ci.yml','tests/fast_track/test_gateway_manual_ci.py',
    'tests/payments/gateway-manual-selection.php','tests/payments/gateway-manual-native.php',
    'tests/payments/gateway-manual-race.php','tests/payments/gateway-manual-races.py',
    'tests/payments/gateway-manual-browser.cjs','tests/payments/integration/gateway-provider.php',
    'tests/payments/integration/verify.php','tests/hr/native-support.php','tests/hr/ci-code-state.php',
    'tests/hr/ci-mis-bootstrap.php','tests/hr/browser.cjs',
}

def bounded_payload(before,after):
    changed={name.removeprefix('sales-network-v4/'):body for name,body in after.items() if before.get(name)!=body}
    return (len(before)==119 and before.keys()==after.keys() and changed.keys()==RUNTIME.keys()
            and all(hashlib.sha256(body).hexdigest()==RUNTIME[name] for name,body in changed.items()))

def payload_matches_revision(revision):
    return bounded_payload(committed_members(Path.cwd(),BASE),committed_members(Path.cwd(),revision))

def scope(base,head,paths):
    if base!=BASE or not set(RUNTIME)<=paths or not paths<=set(RUNTIME)|NON_RUNTIME:return False
    return payload_matches_revision(head)

def completed(root,name,minimum):
    data=json.loads((Path(root)/name).read_text(encoding='utf-8'))
    if (data.get('passed') is not True or data.get('synthetic') is not True
            or data.get('production_touched') is not False
            or type(data.get('assertions')) is not int or data['assertions']<minimum):
        raise ValueError('Missing completed synthetic evidence: '+name)
    return data

def verify_finance_output(output,wordpress):
    root=Path(output)
    native=completed(root,'gateway-manual-native.json',64)
    race=completed(root,'gateway-manual-races.json',18)
    browser=completed(root,'gateway-browser/gateway-manual-browser.json',27)
    if any(row.get('version')!='2.0.240' for row in [native,race,browser]):raise ValueError('Wrong payment candidate version')
    if native.get('wordpress')!=wordpress or not native.get('mysql'):raise ValueError('Wrong payment WordPress/database runtime')
    if (race.get('cases')!=4 or race.get('real_parallel_php_processes') is not True
            or race.get('actual_mysql_advisory_and_row_locks') is not True):raise ValueError('Real concurrent MySQL workers required')
    if (browser.get('transport')!='native WordPress HTTP' or browser.get('actual_multipart_upload_tested') is not True
            or browser.get('real_wordpress_auth_cookie') is not True or not browser.get('chromium')):
        raise ValueError('Real HTTP authentication, multipart upload and browser evidence required')

def verify_hr_output(output,merge):
    root=Path(output)
    for name,minimum in {
        'hr239-native-summary.json':43,'hr239-invoices-summary.json':23,
        'hr239-finance-summary.json':49,'hr239-ledger-summary.json':24,
        'browser/summary.json':32,'code-rollback.json':27,'mis-summary.json':100,
    }.items():
        data=json.loads((root/name).read_text(encoding='utf-8'))
        if (data.get('passed') is not True or data.get('synthetic') is not True
                or type(data.get('assertions')) is not int or data['assertions']<minimum):raise ValueError('Incomplete HR/ledger/schema/MIS regression: '+name)
        if name.startswith('hr239-') and (data.get('core_sha256')!=RUNTIME['includes/class-sn-plugin.php']
                or data.get('policy_sha256')!='1ad645e71cfdaeb63fb9bb81d12646515d2ee626b947099930f88ba7dfb683d8'):
            raise ValueError('HR regression belongs to another runtime')
    browser=json.loads((root/'browser/summary.json').read_text(encoding='utf-8'))
    if browser.get('transport')!='native WordPress HTTP login, shortcode and admin-ajax':raise ValueError('Actual HR HTTP regression required')
    mis=json.loads((root/'mis-summary.json').read_text(encoding='utf-8'))
    if mis.get('scripts')!=10 or mis.get('isolated_database') is not True:raise ValueError('Ten isolated MIS scripts required')
    if git('rev-parse','HEAD').decode().strip()!=merge or not payload_matches_revision(merge):raise ValueError('Verified payment revision changed')
    verify_finance_output(root.parent/'finance','7.1.4')
    (root/'verified-revision.json').write_text(json.dumps({'merge':merge,'runtime':RUNTIME,'synthetic':True,'suites':['ui','access','ledger','schema','mis'],'gateway_manual':True},indent=2)+'\n',encoding='utf-8')
