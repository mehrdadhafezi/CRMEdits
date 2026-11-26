"""Exact stage visibility release; every previous gateway/Finance gate remains."""
import argparse,hashlib,json
from pathlib import Path
from package import committed_members
from risk_report import git
import gateway_catalog_ci as gateway
from gateway_manual_ci import completed

PROFILE=json.loads(Path(__file__).with_name('stage_visibility_profile.json').read_text())
BASE=PROFILE['base'];VERSION='2.0.246';CHANGES=PROFILE['runtime_changes'];RUNTIME={r['path']:r['sha256']for r in PROFILE['runtime']}

def bounded_payload(before,after):
 changed={n.removeprefix('sales-network-v4/'):b for n,b in after.items()if before.get(n)!=b}
 return(len(before)==len(after)==125 and before.keys()==after.keys()and changed.keys()==CHANGES.keys()
        and all(hashlib.sha256(b).hexdigest()==CHANGES[n]for n,b in changed.items()))

def payload_matches_revision(revision):return bounded_payload(committed_members(Path.cwd(),BASE),committed_members(Path.cwd(),revision))

def scope(base,head,paths):
 return(base==BASE and set(paths)==set(PROFILE['changed_paths'])and payload_matches_revision(head)
        and all(hashlib.sha256(git('show',head+':'+n)).hexdigest()==h for n,h in PROFILE['validation_files'].items()))

def verify_hr_output(output,merge):
 gateway.verify_hr_output(output,merge,runtime=RUNTIME,payload_verifier=payload_matches_revision,version=VERSION)
 root=Path(output);native=completed(root,'finance244-stage-visibility.json',34);browser=completed(root,'stage246-browser.json',28)
 for row in[native,browser]:
  if row.get('version')!=VERSION or row.get('production_touched')is not False:raise ValueError('Stage visibility evidence version/isolation mismatch')
 for flag in['real_wordpress_mysql','actual_seller_controller','owner_scope','read_only','batch_query_verified']:
  if native.get(flag)is not True:raise ValueError('Missing native stage proof: '+flag)
 for field,name in[('core_sha256','includes/class-sn-plugin.php'),('stage_model_sha256','includes/class-sn-payment-transactions.php'),('tracking_sha256','includes/class-sn-payment-tracking.php')]:
  if native.get(field)!=RUNTIME[name]:raise ValueError('Wrong native stage source tested')
 for flag in['desktop_mobile','external_network_blocked','actual_runtime_js']:
  if browser.get(flag)is not True:raise ValueError('Missing stage browser proof: '+flag)
 for field,name in[('stage_js_sha256','assets/js/payment-transactions.js'),('seller_js_sha256','assets/js/public-seller.js'),('manager_js_sha256','assets/js/public-manager.js')]:
  if browser.get(field)!=RUNTIME[name]:raise ValueError('Wrong stage browser source tested')
 if git('rev-parse','HEAD').decode().strip()!=merge or not payload_matches_revision(merge):raise ValueError('Stage candidate changed after testing')
 (root/'stage246-verified.json').write_text(json.dumps({'version':VERSION,'merge':merge,'all_retained_gates':True,'synthetic':True},indent=2)+'\n')

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('mode',choices=['authorize','lifecycle-inputs']);p.add_argument('--revision');p.add_argument('--output');a=p.parse_args()
 if a.mode=='authorize':raise SystemExit(0 if a.revision and payload_matches_revision(a.revision)else 1)
 if not a.output:raise ValueError('Output required')
 gateway.finance.trace.lifecycle_inputs(a.output,version=VERSION)
