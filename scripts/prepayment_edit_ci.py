"""Exact unpaid-editor payload; additive to all Finance/HR/reporting/ledger/MIS proof."""
import hashlib
import json
from pathlib import Path

from package import committed_members
from risk_report import git
import invoice_reporting_ci as reports
import traceability_ci as trace

BASE = 'd0ca8e8bc9c12fb79187dea0c1a0f0ad0079685d'
CHANGES = {'includes/class-sn-plugin.php': '3dbc14a8bd2d557780081a5370739284d7b74a4694ca140a1700f8b425913240', 'assets/js/public-shell.js': 'acebd84e630e5a9cf16b9fd8814977c2f0a230a38cd556edb362c9ce410d1cdb', 'includes/class-sn-invoice-edit-quote.php': 'da0cedf04103d3b42360c8d2f8eef5b07ac873c277021e43f9ca55bc525459ec'}
FIXTURES = {'tests/invoices/pre-payment-edit-guards.php': 'bd2bfbe3beff4997ca45a491520d6ffe40cd6d97af815247f54fed5170d74df3', 'tests/invoices/pre-payment-edit-pricing.php': '8ff492e9ff6c33309327cc0d3e1d2982d642946ca4b46fefec8fb0eeaa069451', 'tests/invoices/pre-payment-edit-native.php': '355b8ef3d277425e662d47d6b4179bf7b099648c9d404571692dba3436e0e42b', 'tests/invoices/pre-payment-edit-browser.cjs': '6fb8890664a8d35bb44aa82af9d399f9aa9c3f5c4d2cc42f047c390cc7cf8c81', 'tests/invoices/sandbox.php': '000c6b9a34ff98390fd6112f4ad975d9aac0db4a9eba1adc035953e6ba717371'}
RUNTIME = {**reports.RUNTIME, **CHANGES}
REPORT_RUNTIME = {**reports.CHANGES, 'includes/class-sn-plugin.php': CHANGES['includes/class-sn-plugin.php']}
NON_RUNTIME = set(FIXTURES) | {
    'docs/API_CONTRACTS.md', 'docs/modules/INVOICES.md',
    'docs/tasks/PREPAYMENT_INVOICE_EDIT.md', 'docs/tasks/PREPAYMENT_INVOICE_EDIT_VALIDATION.md',
    'tests/invoices/README.md', '.github/workflows/ci.yml',
    'scripts/fast_track_ci.py', 'scripts/prepayment_edit_ci.py',
    'scripts/invoice_reporting_ci.py', 'scripts/test-invoice-reporting.sh',
    'scripts/test-hr-integration.sh', 'scripts/test-prepayment-edit.sh',
    'tests/fast_track/test_prepayment_edit_ci.py',
}


def bounded_payload(before, after):
    changed = {name.removeprefix('sales-network-v4/'): body for name, body in after.items()
               if before.get(name) != body}
    addition = {'sales-network-v4/includes/class-sn-invoice-edit-quote.php'}
    return (len(before) == 123 and len(after) == 124 and after.keys() == before.keys() | addition
            and changed.keys() == CHANGES.keys()
            and all(hashlib.sha256(body).hexdigest() == CHANGES[name] for name, body in changed.items()))


def payload_matches_revision(revision):
    return bounded_payload(committed_members(Path.cwd(), BASE), committed_members(Path.cwd(), revision))


def scope(base, head, paths):
    return (base == BASE and set(CHANGES) <= paths <= set(CHANGES) | NON_RUNTIME
            and payload_matches_revision(head)
            and all(hashlib.sha256(git('show', f'{head}:{name}')).hexdigest() == digest
                    for name, digest in FIXTURES.items()))


def verify_editor_output(output, merge):
    root = Path(output)
    identity = json.loads((root/'identity.json').read_text())
    native = json.loads((root/'native-summary.json').read_text())
    browser = json.loads((root/'browser-summary.json').read_text())
    memory = json.loads((root/'memory-summary.json').read_text())
    integrity = json.loads((root/'post-browser-summary.json').read_text())
    if (identity.get('merge') != merge or identity.get('runtime') != CHANGES
            or identity.get('fixtures') != FIXTURES
            or identity.get('synthetic') is not True or identity.get('network_internal') is not True
            or identity.get('published_ports') is not False or identity.get('readonly_source') is not True):
        raise ValueError('Editor evidence has wrong runtime, fixture, revision or isolation')
    if (native.get('passed') is not True or native.get('synthetic') is not True
            or type(native.get('checks')) is not int or native['checks'] < 102
            or native.get('wordpress') != '7.1.4' or native.get('woocommerce') != '10.4.3'
            or native.get('mysql') != '8.0.46' or not str(native.get('php','')).startswith('8.3.')):
        raise ValueError('Native editor HTTP/security/rollback proof is incomplete')
    if (browser.get('passed') is not True or browser.get('synthetic') is not True
            or browser.get('checks') != 31 or browser.get('errors') != []
            or browser.get('viewports') != ['1440x1000', '390x844']):
        raise ValueError('Actual editor browser and viewport proof is incomplete')
    if memory != {'passed': True, 'synthetic': True, 'guards': 34, 'pricing': 30, 'catalog': 40}:
        raise ValueError('Editor memory regressions are incomplete')
    if integrity != {'passed': True, 'assertions': 7, 'synthetic': True}:
        raise ValueError('Invoice identity/payment/wallet integrity proof is incomplete')
    for name in ['prepayment-edit-desktop.png','prepayment-edit-mobile.png']:
        if not (root/name).is_file() or (root/name).stat().st_size < 1000:
            raise ValueError('Editor screenshot evidence missing')
    if git('rev-parse','HEAD').decode().strip() != merge or not payload_matches_revision(merge):
        raise ValueError('Editor proof does not belong to the tested merge')


def verify_hr_output(output, merge):
    trace.verify_hr_output(output, merge, runtime=RUNTIME, payload_verifier=payload_matches_revision)
    reports.verify_report_output(Path(output)/'invoice-report', merge,
        runtime=REPORT_RUNTIME, payload_verifier=payload_matches_revision)
    verify_editor_output(Path(output)/'prepayment-edit', merge)
    (Path(output)/'prepayment-edit-verified.json').write_text(json.dumps({
        'merge':merge,'runtime':CHANGES,'synthetic':True,
        'retained_finance_hr_mis_traceability_reporting':True,'prepayment_editor':True,
    },indent=2)+'\n')


if __name__ == '__main__':
    import sys
    raise SystemExit(0 if len(sys.argv)==2 and payload_matches_revision(sys.argv[1]) else 1)
