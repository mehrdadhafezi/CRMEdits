"""Exact bank tracking payload; additive to all Finance/HR/reporting/ledger/MIS proof."""
import hashlib
import json
from pathlib import Path

from package import committed_members
from risk_report import git
import invoice_reporting_ci as reports
import traceability_ci as trace
import prepayment_edit_ci as editor

BASE = 'd0ca8e8bc9c12fb79187dea0c1a0f0ad0079685d'
CHANGES = {'assets/js/payment-batch.js': '88671cca108d0d987050778edf6148f7b83c33fe0fb0cd8811850802a0804227', 'assets/js/payment-transactions.js': '921c92b6cccfbc449aca58fcbfc547b26bf498b37940f3caa3eb289ebafb48c2', 'assets/js/receipt-review.js': '228fcf4440adb76c49e087ae4c80a1f6ddd5072dd0135508ea7832b40d14143b', 'includes/class-sn-payment-batch.php': 'b34029ada69c0940c15eeebf2bfd4b5a3f20e8159f186b428090ea28176c131a', 'includes/class-sn-payment-tracking.php': 'a61e68f1141297a01a01d2eaba4fe1ed26ecbaad5428a96d40c9b9f668b72908', 'includes/class-sn-payment-transactions.php': '6a793e92e96f570d646868f7ad8c3124b3c5539ae4b8a40813ce2ba1f7fa7e8b', 'includes/class-sn-plugin.php': '3152e27e72021e61a0ff92d40ec7116db6b413462560f971fe13784f9126aaae', 'includes/class-sn-receipt-review.php': '94dd87d916a2b5d0631177eb46835607a73ad20f5eab8eaf534fb24245d86433', 'assets/js/public-shell.js': 'acebd84e630e5a9cf16b9fd8814977c2f0a230a38cd556edb362c9ce410d1cdb', 'includes/class-sn-invoice-edit-quote.php': 'da0cedf04103d3b42360c8d2f8eef5b07ac873c277021e43f9ca55bc525459ec'}
FIXTURES = {'tests/payments/tracking-number.php': 'd05925a78fcf8f5be1e654affb32fffa609ecf20279e2f721b3810883746249c', 'tests/payments/tracking-number-native.php': '163bb040042a7a63e43b7f1094483b844b0d72bf70d1bc594810361dfc5d0b50', 'tests/payments/tracking-number-browser.cjs': 'd7c90575254f4c039b38ff3384c4bc404b46b06c94e10721bb6592131f080edb', 'tests/payments/tracking-sandbox.php': '002ff553373f02a55c389e4b28c9c7a95aa8c05eacf3647feb17e66f87de802f'}
TRACKING_RUNTIME = {name:digest for name,digest in CHANGES.items() if name not in {'assets/js/public-shell.js','includes/class-sn-invoice-edit-quote.php'}}
EDITOR_RUNTIME = {name:CHANGES[name] for name in editor.CHANGES}
RUNTIME = {**reports.RUNTIME, **CHANGES}
REPORT_RUNTIME = {**reports.CHANGES, 'includes/class-sn-plugin.php': CHANGES['includes/class-sn-plugin.php']}
NON_RUNTIME = set(FIXTURES) | editor.NON_RUNTIME | {
    'docs/API_CONTRACTS.md', 'docs/modules/INVOICES.md', 'docs/modules/PAYMENTS_FINANCE.md',
    'docs/tasks/PAYMENT_TRACKING_NUMBER.md', 'tests/payments/TRACKING_NUMBER.md',
    '.github/workflows/ci.yml', 'scripts/fast_track_ci.py', 'scripts/payment_tracking_ci.py',
    'scripts/invoice_reporting_ci.py', 'scripts/test-invoice-reporting.sh',
    'scripts/test-hr-integration.sh', 'scripts/test-payment-tracking.sh',
    'tests/fast_track/test_payment_tracking_ci.py',
}


def bounded_payload(before, after):
    changed = {name.removeprefix('sales-network-v4/'): body for name, body in after.items()
               if before.get(name) != body}
    addition = {'sales-network-v4/includes/class-sn-payment-tracking.php','sales-network-v4/includes/class-sn-invoice-edit-quote.php'}
    return (len(before) == 123 and len(after) == 125 and after.keys() == before.keys() | addition
            and changed.keys() == CHANGES.keys()
            and all(hashlib.sha256(body).hexdigest() == CHANGES[name] for name, body in changed.items()))


def payload_matches_revision(revision):
    return bounded_payload(committed_members(Path.cwd(), BASE), committed_members(Path.cwd(), revision))


def scope(base, head, paths):
    return (base == BASE and set(CHANGES) <= paths <= set(CHANGES) | NON_RUNTIME
            and payload_matches_revision(head)
            and all(hashlib.sha256(git('show', f'{head}:{name}')).hexdigest() == digest
                    for name, digest in {**FIXTURES, **editor.FIXTURES}.items()))


def verify_tracking_output(output, merge, *, runtime=None, payload_verifier=None):
    runtime = TRACKING_RUNTIME if runtime is None else runtime
    payload_verifier = payload_verifier or payload_matches_revision
    root = Path(output)
    identity = json.loads((root/'identity.json').read_text())
    native = json.loads((root/'tracking-native-summary.json').read_text())
    browser = json.loads((root/'tracking-browser-summary.json').read_text())
    memory = json.loads((root/'memory-summary.json').read_text())
    integrity = json.loads((root/'tracking-integrity-summary.json').read_text())
    if (identity.get('merge') != merge or identity.get('runtime') != runtime
            or identity.get('fixtures') != FIXTURES
            or identity.get('synthetic') is not True or identity.get('network_internal') is not True
            or identity.get('published_ports') is not False or identity.get('readonly_source') is not True):
        raise ValueError('Tracking evidence has wrong runtime, fixture, revision or isolation')
    if (native.get('passed') is not True or native.get('synthetic') is not True
            or type(native.get('checks')) is not int or native['checks'] != 29
            or native.get('wordpress') != '7.1.4' or native.get('woocommerce') != '10.4.3'
            or native.get('mysql') != '8.0.46' or not str(native.get('php','')).startswith('8.3.')):
        raise ValueError('Native tracking HTTP/security/rollback proof is incomplete')
    if (browser.get('passed') is not True or browser.get('synthetic') is not True
            or browser.get('checks') != 14 or browser.get('errors') != []
            or browser.get('viewports') != ['1440x1000', '390x844']):
        raise ValueError('Actual tracking browser and viewport proof is incomplete')
    if memory != {'passed': True, 'synthetic': True, 'assertions': 39}:
        raise ValueError('Tracking memory regressions are incomplete')
    if integrity != {'passed': True, 'checks': 7, 'synthetic': True}:
        raise ValueError('Invoice identity/payment/wallet integrity proof is incomplete')
    if json.loads((root/'tracking-compat-summary.json').read_text()) != {'passed': True, 'checks': 18, 'synthetic': True}:
        raise ValueError('Classic receipt/info and legacy reference proof is incomplete')
    for name in ['tracking-desktop.png','tracking-mobile.png']:
        if not (root/name).is_file() or (root/name).stat().st_size < 1000:
            raise ValueError('Tracking screenshot evidence missing')
    if git('rev-parse','HEAD').decode().strip() != merge or not payload_verifier(merge):
        raise ValueError('Tracking proof does not belong to the tested merge')


def verify_hr_output(output, merge):
    trace.verify_hr_output(output, merge, runtime=RUNTIME, payload_verifier=payload_matches_revision)
    reports.verify_report_output(Path(output)/'invoice-report', merge,
        runtime=REPORT_RUNTIME, payload_verifier=payload_matches_revision)
    verify_tracking_output(Path(output)/'tracking-number', merge)
    editor.verify_editor_output(Path(output)/'prepayment-edit', merge,
        runtime=EDITOR_RUNTIME, payload_verifier=payload_matches_revision)
    (Path(output)/'tracking-number-verified.json').write_text(json.dumps({
        'merge':merge,'runtime':CHANGES,'synthetic':True,
        'retained_finance_hr_mis_traceability_reporting':True,'payment_tracking':True,
    },indent=2)+'\n')


if __name__ == '__main__':
    import sys
    raise SystemExit(0 if len(sys.argv)==2 and payload_matches_revision(sys.argv[1]) else 1)
