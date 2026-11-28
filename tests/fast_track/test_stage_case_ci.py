"""Fail closed for the 247 payload, scope, retained and new native proof."""
import json,pathlib,sys,tempfile,unittest
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts'))
import stage_case_ci as gate
from package import committed_members
class StageCaseProofTests(unittest.TestCase):
 def test_every_runtime_member_is_exact(self):
  before=committed_members(ROOT,gate.BASE);after=dict(before)
  for n in gate.CHANGES:after['sales-network-v4/'+n]=(ROOT/n).read_bytes()
  self.assertTrue(gate.bounded_payload(before,after))
  for n in after:
   self.assertFalse(gate.bounded_payload(before,{**after,n:after[n]+b'x'}));missing=dict(after);missing.pop(n);self.assertFalse(gate.bounded_payload(before,missing))
  self.assertFalse(gate.bounded_payload(before,{**after,'sales-network-v4/unknown.php':b'<?php'}))
 def test_scope_binds_base_paths_and_validation(self):
  paths=set(gate.PROFILE['changed_paths']);reader=lambda _,spec:(ROOT/spec.split(':',1)[1]).read_bytes()
  with patch.object(gate,'payload_matches_revision',return_value=True),patch.object(gate,'git',side_effect=reader):
   self.assertTrue(gate.scope(gate.BASE,'a'*40,paths));self.assertFalse(gate.scope('b'*40,'a'*40,paths));self.assertFalse(gate.scope(gate.BASE,'a'*40,paths|{'unknown.php'}))
  with patch.object(gate,'payload_matches_revision',return_value=True),patch.object(gate,'git',return_value=b'changed'):self.assertFalse(gate.scope(gate.BASE,'a'*40,paths))
 def fixtures(self,p):
  common={'passed':True,'synthetic':True,'production_touched':False,'version':gate.VERSION}
  rows={'finance244-stage-case':{**common,'assertions':30,'actual_admin_renderer':True,'owner_scope':True,'read_only':True,'canonical_rows':2,'unsettled_stage_rows':3,'settled_details':1,'core_sha256':gate.RUNTIME['includes/class-sn-plugin.php'],'stage_model_sha256':gate.RUNTIME['includes/class-sn-payment-transactions.php']},'stage247-memory':{**common,'assertions':32},'stage246-browser':{**common,'assertions':102,'unsettled_rows':True,'settled_details':True,'canonical_counts':True,'finance_renderer':True}}
  for n,r in rows.items():(p/(n+'.json')).write_text(json.dumps(r))
  (p/'browser').mkdir(exist_ok=True)
  for n in['stage-case-desktop.png','stage-case-mobile.png']:(p/'browser'/n).write_bytes(b'\x89PNG'+b'x'*1200)
 def verify(self,p):
  with patch.object(gate.prior,'verify_hr_output')as retained,patch.object(gate,'payload_matches_revision',return_value=True),patch.object(gate,'git',return_value=b'a'*40):
   gate.verify_hr_output(p,'a'*40);retained.assert_called_once();self.assertEqual(retained.call_args.kwargs['runtime'],gate.RUNTIME);self.assertEqual(retained.call_args.kwargs['version'],gate.VERSION)
 def test_retains_all_previous_gates(self):
  with tempfile.TemporaryDirectory()as d:self.fixtures(pathlib.Path(d));self.verify(pathlib.Path(d))
 def test_prior_wrapper_forwards_current_payload_identity(self):
  verify=lambda revision:True
  with patch.object(gate.prior.gateway,'verify_hr_output',side_effect=RuntimeError('captured'))as retained:
   with self.assertRaisesRegex(RuntimeError,'captured'):gate.prior.verify_hr_output('unused','a'*40,runtime=gate.RUNTIME,payload_verifier=verify,version=gate.VERSION)
   self.assertIs(retained.call_args.kwargs['payload_verifier'],verify)
 def test_missing_failed_stale_or_partial_proof_rejected(self):
  for name,key,value in[('finance244-stage-case','owner_scope',False),('finance244-stage-case','canonical_rows',3),('finance244-stage-case','core_sha256','wrong'),('finance244-stage-case','read_only',False),('stage247-memory','version','2.0.246'),('stage247-memory','assertions',31),('stage246-browser','assertions',101),('stage246-browser','canonical_counts',False),('stage246-browser','finance_renderer',False)]:
   with self.subTest(case=(name,key)),tempfile.TemporaryDirectory()as d:
    p=pathlib.Path(d);self.fixtures(p);f=p/(name+'.json');r=json.loads(f.read_text());r[key]=value;f.write_text(json.dumps(r))
    with self.assertRaises(ValueError):self.verify(p)
  with tempfile.TemporaryDirectory()as d:
   p=pathlib.Path(d);self.fixtures(p);(p/'browser/stage-case-mobile.png').unlink()
   with self.assertRaises(ValueError):self.verify(p)
 def test_native_and_browser_remain_mandatory(self):
  runner=(ROOT/'scripts/test-hr-integration.sh').read_text()
  for text in['stage-case-native.php','stage-case.php','stage-visibility-native.php','stage-visibility-ci-browser.cjs','traceability-upgrade-restore','finance-query-equivalence-volume']:self.assertIn(text,runner)
  self.assertIn('browser/stage-case-*.png',(ROOT/'.github/workflows/ci.yml').read_text())
if __name__=='__main__':unittest.main()
