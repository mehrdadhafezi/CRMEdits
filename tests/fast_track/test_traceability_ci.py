"""Reject partial, stale or unbounded 242 proof while retaining the older native gates."""
import json,pathlib,sys,tempfile,unittest
from unittest.mock import patch
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[2]/'scripts'))
import traceability_ci as trace
import test_product_invoice_ci as historical
from package import committed_members
from fast_track_ci import make_plan,outcome
from risk_report import SUITES
ROOT=pathlib.Path(__file__).resolve().parents[2]

class TraceProofTests(unittest.TestCase):
 def test_payload_accepts_only_reviewed_bytes_and_member_set(self):
  before=committed_members(ROOT,trace.BASE);after=dict(before)
  for name in trace.RUNTIME:after['sales-network-v4/'+name]=(ROOT/name).read_bytes()
  self.assertTrue(trace.bounded_payload(before,after))
  for name in after:
   with self.subTest(path=name):self.assertFalse(trace.bounded_payload(before,{**after,name:after[name]+b'\n'}))
  for name in after:
   missing=dict(after);missing.pop(name)
   with self.subTest(deleted=name):self.assertFalse(trace.bounded_payload(before,missing))
  self.assertFalse(trace.bounded_payload(before,{**after,'sales-network-v4/unreviewed.php':b'<?php'}))

 def test_scope_rejects_unknown_paths_wrong_base_and_unverified_payload(self):
  names=set(trace.RUNTIME)|{'scripts/traceability_ci.py','tests/payments/traceability-security.php'}
  with patch('traceability_ci.payload_matches_revision',return_value=True):self.assertTrue(trace.scope(trace.BASE,'h'*40,names))
  with patch('traceability_ci.payload_matches_revision')as verify:
   for base,paths in [('b'*40,names),(trace.BASE,names-{'sales-network.php'}),(trace.BASE,names|{'includes/unknown.php'}),(trace.BASE,names|{'.github/workflows/unreviewed.yml'})]:
    self.assertFalse(trace.scope(base,'h'*40,paths))
   verify.assert_not_called()
  with patch('traceability_ci.payload_matches_revision',return_value=False):self.assertFalse(trace.scope(trace.BASE,'h'*40,names))

 def test_plan_retains_all_suites_and_both_native_gates(self):
  pr={'pr':22,'base':trace.BASE,'head':'c'*40,'target':'main'};revision={**pr,'merge':'a'*40}
  selection={'risk':'HIGH','base_sha':trace.BASE,'head_sha':pr['head'],'target':'main','affected_domains':['shared','finance','ui','access','ledger','schema','mis','tooling','reporting'],
    'changed_files':[{'path':p,'risk':'HIGH','domains':['shared'],'reason':'fixture'}for p in set(trace.RUNTIME)|{'scripts/traceability_ci.py'}],
    'tests':[{'id':p,'applicability':'REQUIRED'}for p in SUITES]}
  with patch('traceability_ci.scope',return_value=True):plan=make_plan(pr,revision['merge'],selection)
  self.assertTrue(plan['traceability'] and plan['hr_native'] and plan['finance'])
  self.assertTrue(all(v=='REQUIRED' for v in plan['suites'].values()))
  jobs={j:'success'for j in ['syntax','documentation','secrets','stable-pr-boundary','plan','finance','hr-native']}
  self.assertEqual(outcome(plan,revision,revision,jobs,'https://github.com/example/actions/runs/1',matrix_verified=True,hr_verified=True)['status'],'READY FOR MERGE CHECK')
  for matrix,hr in [(False,True),(True,False),(False,False)]:self.assertEqual(outcome(plan,revision,revision,jobs,'x',matrix_verified=matrix,hr_verified=hr)['status'],'BLOCKED')
  for job in ['finance','hr-native']:
   for value in ['failure','cancelled','skipped',None]:
    jobs[job]=value;self.assertEqual(outcome(plan,revision,revision,jobs,'x',matrix_verified=True,hr_verified=True)['status'],'BLOCKED')
   jobs[job]='success'

 @staticmethod
 def records():
  hr,finance=historical.ProductProofTests.records()
  for records in [hr,finance]:
   for data in records.values():
    if 'version'in data:data['version']='2.0.242'
    if 'core_sha256'in data:data['core_sha256']=trace.RUNTIME['includes/class-sn-plugin.php']
  common={'passed':True,'synthetic':True,'production_touched':False,'version':'2.0.242','assertions':100,'core_sha256':trace.RUNTIME['includes/class-sn-plugin.php'],'commission_sha256':trace.RUNTIME['includes/class-sn-purpose-commission.php']}
  hr.update({
   'trace242-unit.json':{**common,'payment_model_sha256':trace.RUNTIME['includes/class-sn-payment-transactions.php']},
   'trace242-native.json':{**common,'wordpress':'7.1.4','mysql':'synthetic-native'},
   'trace242-security.json':{**common,'actual_wordpress_controllers':True,'nonce_scope_capability_negative_cases':True},
   'trace242-public-security.json':{**common,'cases':6,'real_php_controller_requests':True},
   'trace242-races.json':{**common,'cases':2,'real_parallel_php_processes':True,'actual_mysql_advisory_and_row_locks':True},
   'trace242-browser.json':{**common,'desktop_mobile':True,'external_network_blocked':True},
   'trace242-lifecycle.json':{**common,'candidate_commit':'a'*40,'baseline_shas':{'main':trace.BASE,'experimental':trace.EXPERIMENTAL},
    'profiles':[{'track':t,'baseline':s,'passed':True,'fresh_namespace':True,'full_restore':True}for t,s in [('main',trace.BASE),('experimental',trace.EXPERIMENTAL)]],
    **{k:True for k in ['fresh_namespaces','legacy_identities_preserved','new_financial_state_preserved_on_code_swap','full_backup_restore_verified','post_restore_bootstrap_schema_verified','outside_tables_preserved']},'code_only_business_rollback_supported':False}
  })
  for field in ['core_sha256','commission_sha256']:hr['trace242-unit.json'].pop(field)
  return hr,finance

 def test_full_evidence_rejects_missing_failed_stale_and_mocked_proof(self):
  hr,finance=self.records()
  with tempfile.TemporaryDirectory()as folder:
   root=pathlib.Path(folder)
   for category,records in [('hr',hr),('finance',finance)]:
    for name,data in records.items():p=root/category/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(data))
   with patch('traceability_ci.git',return_value=b'a'*40),patch('product_invoice_ci.git',return_value=b'a'*40),patch('traceability_ci.payload_matches_revision',return_value=True):
    trace.verify_hr_output(root/'hr','a'*40)
    for category,records in [('hr',hr),('finance',finance)]:
     for name,data in records.items():
      p=root/category/name;p.unlink()
      with self.subTest(missing=name),self.assertRaises(FileNotFoundError):trace.verify_hr_output(root/'hr','a'*40)
      p.write_text(json.dumps(data))
      if not name.startswith('trace242-'):continue
      for field in data:
       bad={**data,field:None};p.write_text(json.dumps(bad))
       with self.subTest(name=name,field=field),self.assertRaises(ValueError):trace.verify_hr_output(root/'hr','a'*40)
      p.write_text(json.dumps(data))
   for head,payload in [(b'b'*40,True),(b'a'*40,False)]:
    with patch('traceability_ci.git',return_value=head),patch('product_invoice_ci.git',return_value=head),patch('traceability_ci.payload_matches_revision',return_value=payload),self.assertRaises(ValueError):trace.verify_hr_output(root/'hr','a'*40)

 def test_runner_keeps_old_suites_and_adds_all_new_real_scenarios(self):
  runner=(ROOT/'scripts/test-hr-integration.sh').read_text()
  for path in ['native.php','native-invoices.php','native-ledger.php','native-finance.php','tests/mis/run-all.php','tests/products/native-product-settings.php','tests/products/product-sms-races.py','tests/products/ci-browser.cjs','traceability.php','traceability-native.php','traceability-processes.py race','traceability-processes.py public-security','traceability-security.php','traceability-ci-browser.cjs','traceability-lifecycle.py']:
   self.assertIn(path,runner)
  for path in ['traceability-processes.py','traceability-lifecycle.py']:
   body=(ROOT/'tests/payments'/path).read_text();self.assertIn("RUNNER_ENVIRONMENT')=='github-hosted'",body);self.assertIn("os.geteuid()==1073",body)
  workflow=(ROOT/'.github/workflows/ci.yml').read_text();self.assertNotIn('baseline-backup.private.sql',workflow);self.assertNotIn('trace242-public-',workflow)

if __name__=='__main__':unittest.main()
