"""Exact 244 Finance hotfix proof; retain all earlier native and browser gates."""
import argparse,hashlib,json
from pathlib import Path
from package import committed_members
from risk_report import git
import traceability_ci as trace
import invoice_reporting_ci as reports
import prepayment_edit_ci as editor
import payment_tracking_ci as tracking
from gateway_manual_ci import completed

PROFILE=json.loads(Path(__file__).with_name('finance_review_profile.json').read_text())
BASE=PROFILE['base'];VERSION='2.0.244';CHANGES=PROFILE['runtime_changes'];RUNTIME={row['path']:row['sha256']for row in PROFILE['runtime']}

def bounded_payload(before,after):
    changed={n.removeprefix('sales-network-v4/'):b for n,b in after.items()if before.get(n)!=b}
    return (len(before)==len(after)==125 and before.keys()==after.keys() and changed.keys()==CHANGES.keys()
            and all(hashlib.sha256(b).hexdigest()==CHANGES[n]for n,b in changed.items()))

def payload_matches_revision(revision):
    return bounded_payload(committed_members(Path.cwd(),BASE),committed_members(Path.cwd(),revision))

def scope(base,head,paths):
    return (base==BASE and set(paths)==set(PROFILE['changed_paths']) and payload_matches_revision(head)
            and all(hashlib.sha256(git('show',head+':'+n)).hexdigest()==h for n,h in PROFILE['validation_files'].items()))

def verify_hr_output(output,merge):
    # Parameterize only exact revision/runtime identity; no retained gate is skipped.
    trace.verify_hr_output(output,merge,runtime=RUNTIME,payload_verifier=payload_matches_revision,version=VERSION)
    reports.verify_report_output(Path(output)/'invoice-report',merge,runtime={n:RUNTIME[n]for n in reports.CHANGES},payload_verifier=payload_matches_revision)
    editor.verify_editor_output(Path(output)/'prepayment-edit',merge,runtime={n:RUNTIME[n]for n in editor.CHANGES},payload_verifier=payload_matches_revision)
    tracking.verify_tracking_output(Path(output)/'tracking-number',merge,runtime={n:RUNTIME[n]for n in tracking.TRACKING_RUNTIME},payload_verifier=payload_matches_revision)
    root=Path(output)
    for name,minimum in [('capacity',44),('catalog',20),('query',54)]:
        row=completed(root,'finance244-'+name+'.json',minimum)
        if row.get('version')!=VERSION or row.get('production_touched')is not False or row.get('wordpress')!='7.1.4' or not row.get('mysql'):
            raise ValueError('Incomplete native Finance hotfix evidence: '+name)
        for field,path in [('core_sha256','includes/class-sn-plugin.php'),('review_sha256','includes/class-sn-receipt-review.php'),('catalog_sha256','includes/class-sn-sales-catalog.php')]:
            if row.get(field)!=RUNTIME[path]:raise ValueError('Finance evidence has wrong source: '+name)
    capacity=json.loads((root/'finance244-capacity.json').read_text())
    if any(capacity.get(k)is not True for k in ['audit_rollback','approval_rejection','overpayment_blocked']):raise ValueError('Missing receipt safety proof')
    catalog=json.loads((root/'finance244-catalog.json').read_text())
    if catalog.get('receipt_history_preserved')is not True:raise ValueError('Missing catalog preservation proof')
    query=json.loads((root/'finance244-query.json').read_text())
    if (query.get('identical_outputs')is not True or query.get('read_only')is not True or query.get('synthetic_invoices',0)<30000
            or not 0<query.get('after_queries',0)<query.get('before_queries',0)):raise ValueError('Incomplete query equivalence/volume proof')
    browser=completed(root,'finance244-browser.json',12)
    if (browser.get('version')!=VERSION or browser.get('desktop_mobile')is not True or browser.get('external_network_blocked')is not True
            or browser.get('receipt_js_sha256')!=RUNTIME['assets/js/receipt-review.js']):raise ValueError('Incomplete Finance browser proof')
    if git('rev-parse','HEAD').decode().strip()!=merge or not payload_matches_revision(merge):raise ValueError('Finance candidate changed after testing')
    (root/'finance244-verified.json').write_text(json.dumps({'merge':merge,'runtime':RUNTIME,'version':VERSION,'synthetic':True,'all_retained_gates':True},indent=2)+'\n')

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('mode',choices=['authorize','lifecycle-inputs']);p.add_argument('--revision');p.add_argument('--output');a=p.parse_args()
    if a.mode=='authorize':raise SystemExit(0 if a.revision and payload_matches_revision(a.revision)else 1)
    if not a.output:raise ValueError('Output required')
    trace.lifecycle_inputs(a.output,version=VERSION)
