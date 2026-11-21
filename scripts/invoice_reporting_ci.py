"""Exact reporting payload + additive native report and retained HR/MIS evidence."""
import hashlib
import json
from pathlib import Path

from package import committed_members
from risk_report import git
import traceability_ci as trace

BASE = 'f509bfacc79782f843500c06db7554d5d27d23c2'
CHANGES = {
    'includes/class-sn-plugin.php': '263451076994db2376b35df67ba45d2c1b39e8ec6b4e8f2ab204f3dd290a8922',
    'includes/class-sn-bulk-order-export.php': '96543a47367106543c8a5516b4206878df5117522a15f7ef2535c0a0a94af1d8',
    'includes/reports/class-sn-invoice-financial-report.php': 'bb5c188a5541a277e185ee590da6235c60eca23ac941c73c651731bc43ff2085',
}
RUNTIME = {**trace.RUNTIME, **CHANGES}
NON_RUNTIME = {
    'docs/API_CONTRACTS.md', 'docs/modules/INVOICES.md', 'docs/modules/REPORTING.md',
    'docs/tasks/INVOICE_FINANCIAL_REPORTING.md', 'docs/tasks/INVOICE_FINANCIAL_REPORTING_VALIDATION.md',
    'tests/reporting/README.md', 'tests/reporting/invoice-financial.php',
    'tests/reporting/invoice-financial-native.php', 'tests/reporting/invoice-financial-browser.cjs',
    'tests/fast_track/test_traceability_ci.py', 'tests/payments/integration/browser.cjs',
    '.github/workflows/ci.yml', 'scripts/fast_track_ci.py', 'scripts/traceability_ci.py',
    'scripts/invoice_reporting_ci.py', 'scripts/test-invoice-reporting.sh',
    'scripts/test-hr-integration.sh', 'tests/fast_track/test_invoice_reporting_ci.py',
}


def bounded_payload(before, after):
    changed = {name.removeprefix('sales-network-v4/'): body for name, body in after.items()
               if before.get(name) != body}
    addition = {'sales-network-v4/includes/reports/class-sn-invoice-financial-report.php'}
    return (len(before) == 122 and len(after) == 123 and after.keys() == before.keys() | addition
            and changed.keys() == CHANGES.keys()
            and all(hashlib.sha256(body).hexdigest() == CHANGES[name] for name, body in changed.items()))


def payload_matches_revision(revision):
    return bounded_payload(committed_members(Path.cwd(), BASE), committed_members(Path.cwd(), revision))


def scope(base, head, paths):
    return (base == BASE and set(CHANGES) <= paths <= set(CHANGES) | NON_RUNTIME
            and payload_matches_revision(head))


def verify_report_output(output, merge):
    root = Path(output)
    identity = json.loads((root/'identity.json').read_text())
    native = json.loads((root/'native-summary.json').read_text())
    browser = json.loads((root/'browser-summary.json').read_text())
    memory = json.loads((root/'memory-summary.json').read_text())
    if (identity.get('merge') != merge or identity.get('runtime') != CHANGES
            or identity.get('synthetic') is not True or identity.get('network_internal') is not True
            or identity.get('published_ports') is not False or identity.get('readonly_source') is not True
            or type(identity.get('native_assertions')) is not int or identity['native_assertions'] < 15):
        raise ValueError('Report evidence has wrong runtime, revision or isolation')
    if (native.get('wordpress') != '7.1.4' or native.get('mysql') != '8.0.46'
            or not isinstance(native.get('php'),str) or not native['php'].startswith('8.3.')
            or native.get('fixture_count') != 6 or native.get('unchanged') is not True
            or not isinstance(native.get('summary'),dict) or native['summary'].get('confirmed_amount') != '8000.00'):
        raise ValueError('Actual report WordPress/MySQL and unchanged ledger evidence required')
    if (browser.get('syntheticOnly') is not True or browser.get('errors') != []
            or type(browser.get('checks')) is not int or browser['checks'] < 85
            or browser.get('viewportEvidence') != ['1440x1000','390x844']):
        raise ValueError('Complete report HTTP, CSV/XLS, authorization and viewport proof required')
    if (memory.get('synthetic') is not True or memory.get('passed') is not True
            or type(memory.get('assertions')) is not int or memory['assertions'] < 63):
        raise ValueError('Report memory regressions are incomplete')
    for name in ['invoice-report-desktop.png','invoice-report-mobile.png','invoice-report-financial-columns.png']:
        if not (root/name).is_file() or (root/name).stat().st_size < 1000:
            raise ValueError('Report screenshot evidence missing')
    if git('rev-parse','HEAD').decode().strip() != merge or not payload_matches_revision(merge):
        raise ValueError('Report proof does not belong to the current tested merge')


def verify_hr_output(output, merge):
    # Keep every older Finance/product/HR/ledger/schema/MIS/traceability gate.
    trace.verify_hr_output(output, merge, runtime=RUNTIME, payload_verifier=payload_matches_revision)
    verify_report_output(Path(output)/'invoice-report', merge)
    (Path(output)/'invoice-report-verified.json').write_text(json.dumps({
        'merge':merge, 'runtime':CHANGES, 'synthetic':True,
        'retained_hr_mis_traceability':True, 'invoice_reports':True,
    },indent=2)+'\n')


if __name__ == '__main__':
    import sys
    raise SystemExit(0 if len(sys.argv)==2 and payload_matches_revision(sys.argv[1]) else 1)
