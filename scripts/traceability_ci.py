"""Exact-payload 242 evidence; retain every Finance/HR/product/MIS gate and add traceability proof."""
import argparse,hashlib,json,os,zipfile
from io import BytesIO
from pathlib import Path
from package import committed_members
from risk_report import git
import product_invoice_ci as product
from gateway_manual_ci import completed

BASE='dac9617874b5d0a53e7f7b5c1fde3e8066402761'
EXPERIMENTAL='3f544028bf83b641caade9e5859aa4efd4b3769a'
VERSION='2.0.242'
RUNTIME={'assets/js/payment-transactions.js': 'c8492c1c2ddf41faf7b22161094817ea038cacd23334b1c4fe3928a55f3fabe2', 'assets/js/receipt-review.js': 'a34747d856590071f2247fa51a076219f19a665971c9937840edfacdcf56a4d8', 'includes/class-sn-dot-flow.php': '13b7562a05a8d9e991d0b2f54f1746cf5c1d4c8948717d7420760060c5359eea', 'includes/class-sn-payment-transactions.php': '79af8e2e1e94bf5993acd8db484a85e3ac3eefe61b4d9a9d6b66cd3f9de8c51b', 'includes/class-sn-plugin.php': '672afa5caa909b8b120dfb3d80189cdfdd5787ef3eb641e04a5bf6e4e57169ae', 'includes/class-sn-purpose-commission.php': 'b01f64d9b63b54de9e3e934e347fef5f24b81ed402a2bb752655541c6d6e807e', 'includes/class-sn-receipt-review.php': '3b6561f2715dba587ae3e76e5c2e9a2953d579498f79b2fbcb6bcab85045940d', 'includes/trait-sn-dot-invoice-ledger.php': 'd333b7162e444730f58a10372c09e5d35d224cf1792d0438e1cc38d39c745c3e', 'sales-network.php': 'd5a2b222cf5560934a499da2ba50eb471e421c2eceafb5daa2de5e48ea4ccd37'}
NON_RUNTIME={'tests/products/product-sms-race.php', 'tests/products/product-sms-races.py', 'tests/products/ci-browser.cjs', 'tests/payments/traceability-ci-browser.cjs', 'docs/modules/DOT_CONVERSION.md', 'docs/modules/INVOICES.md', 'tests/fast_track/test_traceability_ci.py', 'tests/hr/native-support.php', 'scripts/product_invoice_ci.py', 'tests/fast_track/test_product_invoice_ci.py', 'tests/payments/gateway-manual-browser.cjs', 'scripts/traceability_ci.py', 'docs/tasks/INVOICE_PAYMENT_TRACEABILITY.md', 'tests/payments/gateway-manual-race.php', 'tests/payments/integration/verify.php', 'tests/payments/traceability-security.php', 'tests/hr/browser.cjs', 'tests/hr/ci-code-state.php', 'docs/API_CONTRACTS.md', 'tests/payments/traceability.php', 'tests/payments/traceability-lifecycle.php', 'tests/hr/ci-mis-bootstrap.php', 'docs/RELEASE_2.0.242_TRACEABILITY.md', 'tests/payments/traceability-native.php', 'tests/payments/traceability-ui.cjs', 'docs/modules/REPORTING.md', 'scripts/fast_track_ci.py', 'tests/payments/gateway-manual-races.py', 'tests/payments/traceability-lifecycle.py', 'tests/products/native-product-settings.php', 'tests/payments/traceability-public-security.php', 'scripts/test-hr-integration.sh', 'tests/products/product-settings-browser.cjs', 'tests/payments/traceability-race.php', 'tests/payments/gateway-manual-native.php', 'tests/payments/traceability-processes.py', 'docs/modules/PAYMENTS_FINANCE.md', 'docs/DB_SCHEMA.md', 'docs/modules/COMMISSIONS_WALLETS.md', 'tests/fast_track/test_traceability_schema.py', 'tests/payments/traceability_schema.py'}

def bounded_payload(before,after):
 changed={n.removeprefix('sales-network-v4/'):b for n,b in after.items() if before.get(n)!=b}
 return (len(before)==121 and len(after)==122 and before.keys()<=after.keys() and changed.keys()==RUNTIME.keys()
         and all(hashlib.sha256(b).hexdigest()==RUNTIME[n] for n,b in changed.items()))

def payload_matches_revision(revision):
 return bounded_payload(committed_members(Path.cwd(),BASE),committed_members(Path.cwd(),revision))

def scope(base,head,paths):
 return base==BASE and set(RUNTIME)<=paths<=set(RUNTIME)|NON_RUNTIME and payload_matches_revision(head)

def verify_finance_output(output,wordpress):
 product.verify_finance_output(output,wordpress,version=VERSION)

def verify_hr_output(output,merge):
 product.verify_hr_output(output,merge,version=VERSION,runtime=RUNTIME,payload_verifier=payload_matches_revision)
 root=Path(output)
 for name,minimum in {'trace242-unit.json':21,'trace242-native.json':51,'trace242-security.json':30,
                      'trace242-public-security.json':24,'trace242-races.json':10,'trace242-browser.json':16}.items():
  row=completed(root,name,minimum)
  if row.get('version')!=VERSION:raise ValueError('Wrong traceability candidate version: '+name)
  if name=='trace242-unit.json' and row.get('payment_model_sha256')!=RUNTIME['includes/class-sn-payment-transactions.php']:raise ValueError('Wrong payment model unit evidence')
  if name!='trace242-unit.json' and (row.get('core_sha256')!=RUNTIME['includes/class-sn-plugin.php'] or row.get('commission_sha256')!=RUNTIME['includes/class-sn-purpose-commission.php']):raise ValueError('Trace evidence belongs to another runtime: '+name)
 native=json.loads((root/'trace242-native.json').read_text())
 if native.get('wordpress')!='7.1.4' or not native.get('mysql'):raise ValueError('Real retained native runtime required')
 security=json.loads((root/'trace242-security.json').read_text())
 if security.get('actual_wordpress_controllers') is not True or security.get('nonce_scope_capability_negative_cases') is not True:raise ValueError('Real controller/security proof required')
 public=json.loads((root/'trace242-public-security.json').read_text())
 if public.get('cases')!=6 or public.get('real_php_controller_requests') is not True:raise ValueError('Unmocked public PHP request proof required')
 race=json.loads((root/'trace242-races.json').read_text())
 if race.get('cases')!=2 or race.get('real_parallel_php_processes') is not True or race.get('actual_mysql_advisory_and_row_locks') is not True:raise ValueError('Real receipt/stage races required')
 browser=json.loads((root/'trace242-browser.json').read_text())
 if browser.get('desktop_mobile') is not True or browser.get('external_network_blocked') is not True:raise ValueError('Affected UI/escaping and retry browser proof required')
 lifecycle=completed(root,'trace242-lifecycle.json',20)
 if lifecycle.get('version')!=VERSION or lifecycle.get('baseline_shas')!={'main':BASE,'experimental':EXPERIMENTAL}:raise ValueError('Both verified tracks must be rehearsed')
 if lifecycle.get('candidate_commit')!=merge or lifecycle.get('core_sha256')!=RUNTIME['includes/class-sn-plugin.php'] or lifecycle.get('commission_sha256')!=RUNTIME['includes/class-sn-purpose-commission.php']:raise ValueError('Lifecycle evidence belongs to another candidate')
 expected=[{'track':track,'baseline':sha,'passed':True,'fresh_namespace':True,'full_restore':True}for track,sha in [('main',BASE),('experimental',EXPERIMENTAL)]]
 if lifecycle.get('profiles')!=expected:raise ValueError('Incomplete lifecycle track results')
 for flag in ['fresh_namespaces','legacy_identities_preserved','new_financial_state_preserved_on_code_swap','full_backup_restore_verified','outside_tables_preserved']:
  if lifecycle.get(flag) is not True:raise ValueError('Incomplete lifecycle proof: '+flag)
 if lifecycle.get('code_only_business_rollback_supported') is not False:raise ValueError('Old code cannot safely continue marked cases')
 if git('rev-parse','HEAD').decode().strip()!=merge or not payload_matches_revision(merge):raise ValueError('Final traceability revision changed')

def archive(members):
 out=BytesIO()
 with zipfile.ZipFile(out,'w',compression=zipfile.ZIP_STORED) as z:
  for name,body in sorted(members.items()):
   info=zipfile.ZipInfo(name,(1980,1,1,0,0,0));info.create_system=3;info.external_attr=0o100644<<16;z.writestr(info,body)
 return out.getvalue()

def lifecycle_inputs(output):
 output=Path(output);output.mkdir(parents=True,exist_ok=True);head=git('rev-parse','HEAD').decode().strip()
 profiles={}
 for name,sha,version in [('main',BASE,'2.0.241'),('experimental',EXPERIMENTAL,'2.0.235'),('candidate',head,VERSION)]:
  members=committed_members(Path.cwd(),sha);body=archive(members);filename='trace242-'+name+'.zip';(output/filename).write_bytes(body)
  profiles[name]={'commit':sha,'version':version,'file':filename,'sha256':hashlib.sha256(body).hexdigest(),'members':{n.split('/',1)[1]:hashlib.sha256(b).hexdigest() for n,b in members.items()}}
 (output/'inputs.json').write_text(json.dumps({'profiles':profiles},indent=2)+'\n');print('LIFECYCLE_INPUTS_VERIFIED')

if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('mode',choices=['lifecycle-inputs']);p.add_argument('--output',required=True);a=p.parse_args();lifecycle_inputs(a.output)
