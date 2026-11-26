"""Exact 2.0.245 gateway/catalog follow-up; retain every 244 Finance gate."""
import argparse,hashlib,json
from pathlib import Path
from package import committed_members
from risk_report import git
import finance_review_ci as finance
from gateway_manual_ci import completed

PROFILE=json.loads(Path(__file__).with_name('gateway_catalog_profile.json').read_text())
BASE=PROFILE['base'];VERSION='2.0.245';CHANGES=PROFILE['runtime_changes'];RUNTIME={r['path']:r['sha256']for r in PROFILE['runtime']}
def bounded_payload(before,after):
 changed={n.removeprefix('sales-network-v4/'):b for n,b in after.items()if before.get(n)!=b}
 return (len(before)==len(after)==125 and before.keys()==after.keys()and changed.keys()==CHANGES.keys()
         and all(hashlib.sha256(b).hexdigest()==CHANGES[n]for n,b in changed.items()))
def payload_matches_revision(revision):return bounded_payload(committed_members(Path.cwd(),BASE),committed_members(Path.cwd(),revision))
def scope(base,head,paths):
 return(base==BASE and set(paths)==set(PROFILE['changed_paths'])and payload_matches_revision(head)
        and all(hashlib.sha256(git('show',head+':'+n)).hexdigest()==h for n,h in PROFILE['validation_files'].items()))
def verify_hr_output(output,merge,*,runtime=None,payload_verifier=None,version=VERSION):
 RUNTIME=runtime or globals()['RUNTIME'];VERSION=version
 payload_verifier=payload_verifier or payload_matches_revision
 finance.verify_hr_output(output,merge,runtime=RUNTIME,payload_verifier=payload_verifier,version=VERSION)
 root=Path(output)
 native=completed(root,'gateway245-native.json',71);catalog=completed(root,'catalog245-native.json',14);browser=completed(root,'gateway245-browser.json',44)
 for row in[native,catalog,browser]:
  if row.get('version')!=VERSION:raise ValueError('Gateway/catalog evidence version mismatch')
 for flag in['baseline_reproduced','real_wordpress_mysql','scope_nonce_negative','rollback','duplicate_guard']:
  if native.get(flag)is not True:raise ValueError('Missing gateway financial proof: '+flag)
 if native.get('core_sha256')!=RUNTIME['includes/class-sn-plugin.php']:raise ValueError('Wrong gateway controller tested')
 if catalog.get('catalog_sha256')!=RUNTIME['includes/class-sn-sales-catalog.php']or any(catalog.get(k)is not True for k in['real_wordpress_mysql','owner_scope','forged_selections_denied']):raise ValueError('Missing catalog scope proof')
 if any(browser.get(k)is not True for k in['desktop_mobile','external_network_blocked']):raise ValueError('Missing catalog/Finance browser isolation proof')
 for field,name in[('manager_js_sha256','assets/js/public-manager.js'),('catalog_js_sha256','assets/js/sales-catalog-staff.js')]:
  if browser.get(field)!=RUNTIME[name]:raise ValueError('Wrong browser script tested')
 if git('rev-parse','HEAD').decode().strip()!=merge or not payload_verifier(merge):raise ValueError('Gateway/catalog candidate changed after testing')
 (root/'gateway245-verified.json').write_text(json.dumps({'version':VERSION,'merge':merge,'all_retained_gates':True,'synthetic':True},indent=2)+'\n')
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('mode',choices=['authorize','lifecycle-inputs']);p.add_argument('--revision');p.add_argument('--output');a=p.parse_args()
 if a.mode=='authorize':raise SystemExit(0 if a.revision and payload_matches_revision(a.revision)else 1)
 if not a.output:raise ValueError('Output required')
 finance.trace.lifecycle_inputs(a.output,version=VERSION)
