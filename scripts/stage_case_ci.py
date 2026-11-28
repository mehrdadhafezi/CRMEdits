"""Exact 247 invoice case presentation; retain every prior native gate."""
import argparse,hashlib,json
from pathlib import Path
from package import committed_members
from risk_report import git
import stage_visibility_ci as prior
from gateway_manual_ci import completed

PROFILE=json.loads(Path(__file__).with_name('stage_case_profile.json').read_text())
BASE=PROFILE['base'];VERSION='2.0.247';CHANGES=PROFILE['runtime_changes'];RUNTIME={r['path']:r['sha256']for r in PROFILE['runtime']}

def bounded_payload(before,after):
 changed={n.removeprefix('sales-network-v4/'):b for n,b in after.items()if before.get(n)!=b}
 return(len(before)==len(after)==125 and before.keys()==after.keys()and changed.keys()==CHANGES.keys()
        and all(hashlib.sha256(b).hexdigest()==CHANGES[n]for n,b in changed.items()))

def payload_matches_revision(revision):return bounded_payload(committed_members(Path.cwd(),BASE),committed_members(Path.cwd(),revision))

def scope(base,head,paths):
 return(base==BASE and set(paths)==set(PROFILE['changed_paths'])and payload_matches_revision(head)
        and all(hashlib.sha256(git('show',head+':'+n)).hexdigest()==h for n,h in PROFILE['validation_files'].items()))

def verify_hr_output(output,merge):
 prior.verify_hr_output(output,merge,runtime=RUNTIME,payload_verifier=payload_matches_revision,version=VERSION)
 root=Path(output);native=completed(root,'finance244-stage-case.json',30);memory=completed(root,'stage247-memory.json',32);browser=completed(root,'stage246-browser.json',102)
 for row in[native,memory,browser]:
  if row.get('version')!=VERSION or row.get('production_touched')is not False:raise ValueError('Case evidence revision/isolation mismatch')
 for flag in['actual_admin_renderer','owner_scope','read_only']:
  if native.get(flag)is not True:raise ValueError('Missing actual admin case proof: '+flag)
 for key,value in[('canonical_rows',2),('unsettled_stage_rows',3),('settled_details',1)]:
  if native.get(key)!=value:raise ValueError('Incomplete admin presentation proof: '+key)
 if native.get('stage_model_sha256')!=RUNTIME['includes/class-sn-payment-transactions.php']or native.get('core_sha256')!=RUNTIME['includes/class-sn-plugin.php']:raise ValueError('Wrong admin renderer tested')
 for flag in['unsettled_rows','settled_details','canonical_counts','finance_renderer']:
  if browser.get(flag)is not True:raise ValueError('Missing actual case browser proof: '+flag)
 for name in['stage-case-desktop.png','stage-case-mobile.png']:
  if not(root/'browser'/name).is_file()or(root/'browser'/name).stat().st_size<1000:raise ValueError('Case viewport screenshot missing')
 if git('rev-parse','HEAD').decode().strip()!=merge or not payload_matches_revision(merge):raise ValueError('Case candidate changed after testing')
 (root/'stage247-verified.json').write_text(json.dumps({'version':VERSION,'merge':merge,'all_retained_gates':True,'synthetic':True},indent=2)+'\n')

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('mode',choices=['authorize','lifecycle-inputs']);p.add_argument('--revision');p.add_argument('--output');a=p.parse_args()
 if a.mode=='authorize':raise SystemExit(0 if a.revision and payload_matches_revision(a.revision)else 1)
 if not a.output:raise ValueError('Output required')
 prior.gateway.finance.trace.lifecycle_inputs(a.output,version=VERSION)
