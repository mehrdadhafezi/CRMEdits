"""Additive, exact-payload evidence for the reviewed product settings 2.0.241 only."""
import hashlib,json
from pathlib import Path
from package import committed_members
from risk_report import git
from gateway_manual_ci import completed

BASE='3e5ea9a950b8ec0c936ba4188ddb6118fe3b1cc6'
RUNTIME={
    "assets/js/admin.js": "c369eb2580dccc6b996adbbe57882a9bda4f02d6fe1298849e36fee6893e8d8a",
    "assets/js/product-invoice-sms.js": "54dce8a63175ad899c2f287a18dae9fc0ed7a8b59142a57a0e3cb384fb652242",
    "assets/js/public-converter.js": "43ebbef64bc5d1bb5cab89b051da7311215823a4b592e56e5c951a050026c3fb",
    "assets/js/sales-catalog-admin.js": "930335604b0d1caa05be815b01bf34f2ef9262738ee73d2d65d814647ac43dbc",
    "assets/js/sales-catalog-staff.js": "3c4136b820f0e9fcdf2aac2032a788e17e3187bb919c21273b03e39537f7f0d5",
    "includes/class-sn-dot-flow.php": "7eb5ea5bd18f84d960b72aacc20afa77d933bb2fde1891a1b8cc298e7f9dc159",
    "includes/class-sn-invoice-sms-policy.php": "0e552470d00307bf26aca99152d5a782cab54928d7f62b72a6b791d85c83ac23",
    "includes/class-sn-operations-flow.php": "2869765a303ab50221691a47e50cdf80290b0ea7729641bf603714897203fed7",
    "includes/class-sn-plugin.php": "be0acf25509b07ada55fbbfd2f7fab9fe31aab75eca7f94225008ac8da61e015",
    "includes/class-sn-sales-catalog.php": "43aae149521f9ed7b776dfbce05aa6925e9d00024108303fec605906a5ceeb0b",
    "includes/class-sn-sms.php": "352ed558860492e4002fcb1297723997de92d7e9e2e406456b4eede7d9474923",
    "sales-network.php": "321c1100fda24237b9537266ffff249f0fe2421ba8d6ae4c70089a0a9b217e05"
}
NON_RUNTIME={
 'docs/API_CONTRACTS.md','docs/modules/PRODUCTS_CATALOG.md','docs/modules/SMS.md',
 'docs/RELEASE_2.0.241_PRODUCT_SETTINGS.md','docs/tasks/PRODUCT_INVOICE_SETTINGS_241.md',
 'scripts/product_invoice_ci.py','scripts/fast_track_ci.py','scripts/test-hr-integration.sh',
 '.github/workflows/ci.yml','tests/fast_track/test_product_invoice_ci.py','tests/fast_track/test_gateway_manual_ci.py',
 'tests/products/invoice-product-policy.php','tests/products/native-product-settings.php',
 'tests/products/product-provider.php','tests/products/product-settings-browser.cjs',
 'tests/products/product-sms-race.php','tests/products/product-sms-races.py','tests/products/ci-browser.cjs',
 'tests/hr/native-support.php','tests/hr/browser.cjs','tests/hr/ci-code-state.php','tests/hr/ci-mis-bootstrap.php',
 'tests/payments/gateway-manual-native.php','tests/payments/gateway-manual-race.php',
 'tests/payments/gateway-manual-races.py','tests/payments/gateway-manual-browser.cjs','tests/payments/integration/verify.php',
}


def bounded_payload(before,after):
    changed={name.removeprefix('sales-network-v4/'):body for name,body in after.items() if before.get(name)!=body}
    return (len(before)==119 and len(after)==121 and before.keys()<=after.keys() and changed.keys()==RUNTIME.keys()
            and all(hashlib.sha256(body).hexdigest()==RUNTIME[name] for name,body in changed.items()))


def payload_matches_revision(revision):
    return bounded_payload(committed_members(Path.cwd(),BASE),committed_members(Path.cwd(),revision))


def scope(base,head,paths):
    return base==BASE and set(RUNTIME)<=paths<=set(RUNTIME)|NON_RUNTIME and payload_matches_revision(head)


def verify_finance_output(output,wordpress):
    root=Path(output)
    native=completed(root,'gateway-manual-native.json',64)
    race=completed(root,'gateway-manual-races.json',18)
    browser=completed(root,'gateway-browser/gateway-manual-browser.json',27)
    if any(row.get('version')!='2.0.241' for row in [native,race,browser]):raise ValueError('Wrong product candidate payment version')
    if native.get('wordpress')!=wordpress or not native.get('mysql'):raise ValueError('Wrong payment WordPress/database runtime')
    if race.get('cases')!=4 or race.get('real_parallel_php_processes') is not True or race.get('actual_mysql_advisory_and_row_locks') is not True:raise ValueError('Real concurrent payment MySQL workers required')
    if (browser.get('transport')!='native WordPress HTTP' or browser.get('actual_multipart_upload_tested') is not True
            or browser.get('real_wordpress_auth_cookie') is not True or not browser.get('chromium')):raise ValueError('Real payment HTTP upload/authentication required')


def verify_hr_output(output,merge):
    root=Path(output)
    for name,minimum in {'hr239-native-summary.json':43,'hr239-invoices-summary.json':23,'hr239-finance-summary.json':49,
        'hr239-ledger-summary.json':24,'browser/summary.json':32,'code-rollback.json':27,'mis-summary.json':100}.items():
        data=json.loads((root/name).read_text(encoding='utf-8'))
        if (data.get('passed') is not True or data.get('synthetic') is not True or type(data.get('assertions')) is not int
                or data['assertions']<minimum):raise ValueError('Incomplete retained HR/ledger/schema/MIS regression: '+name)
        if name.startswith('hr239-') and (data.get('core_sha256')!=RUNTIME['includes/class-sn-plugin.php']
                or data.get('policy_sha256')!='1ad645e71cfdaeb63fb9bb81d12646515d2ee626b947099930f88ba7dfb683d8'):raise ValueError('HR evidence belongs to another runtime')
    browser=json.loads((root/'browser/summary.json').read_text(encoding='utf-8'))
    if browser.get('transport')!='native WordPress HTTP login, shortcode and admin-ajax':raise ValueError('Actual HR HTTP regression required')
    mis=json.loads((root/'mis-summary.json').read_text(encoding='utf-8'))
    if mis.get('scripts')!=10 or mis.get('isolated_database') is not True:raise ValueError('Ten isolated MIS scripts required')
    unit=completed(root,'product241-unit.json',40)
    native=completed(root,'product241-native.json',55)
    race=completed(root,'product241-races.json',16)
    browser=completed(root,'product-browser/product241-browser.json',32)
    if any(row.get('version')!='2.0.241' or row.get('real_sms_sent') is not False for row in [unit,native,race,browser]):raise ValueError('Wrong product version or real SMS status')
    if native.get('wordpress')!='7.1.4' or native.get('woocommerce')!='10.4.3':raise ValueError('Real pinned WordPress/WooCommerce required')
    if (race.get('cases')!=2 or race.get('real_parallel_php_processes') is not True
            or race.get('actual_mysql_advisory_locks') is not True or race.get('stale_cache_control') is not True):raise ValueError('Real SMS races and stale-cache control required')
    for flag in ['actual_wordpress_cookie','actual_admin_http','actual_woocommerce_product_save','legacy_400_reproduced','json_large_form_passed','desktop_mobile']:
        if browser.get(flag) is not True:raise ValueError('Missing product HTTP/browser evidence: '+flag)
    if json.loads((root/'product-browser/product241-browser-errors.json').read_text(encoding='utf-8'))!={'errors':[]}:raise ValueError('Product browser has uncaught errors')
    if git('rev-parse','HEAD').decode().strip()!=merge or not payload_matches_revision(merge):raise ValueError('Verified product revision changed')
    verify_finance_output(root.parent/'finance','7.1.4')
    (root/'verified-revision.json').write_text(json.dumps({'merge':merge,'runtime':RUNTIME,'synthetic':True,
        'suites':['ui','access','ledger','schema','mis'],'gateway_manual':True,'product_invoice_settings':True},indent=2)+'\n',encoding='utf-8')
