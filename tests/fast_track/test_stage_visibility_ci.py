"""The additive stage gate must bind runtime, tests, identity and retained proof."""
import json,pathlib,sys,tempfile,unittest
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts'))
import stage_visibility_ci as gate
from package import committed_members
class StageVisibilityProofTests(unittest.TestCase):
 def test_every_runtime_member_is_exact(self):
  before=committed_members(ROOT,gate.BASE);after=dict(before)
  for n in gate.CHANGES:after['sales-network-v4/'+n]=(ROOT/n).read_bytes()
  self.assertTrue(gate.bounded_payload(before,after))
  for n in after:
   self.assertFalse(gate.bounded_payload(before,{**after,n:after[n]+b'x'}));missing=dict(after);missing.pop(n);self.assertFalse(gate.bounded_payload(before,missing))
  self.assertFalse(gate.bounded_payload(before,{**after,'sales-network-v4/unknown.php':b'<?php'}))
 def test_scope_binds_base_paths_and_validation_files(self):
  paths=set(gate.PROFILE['changed_paths']);reader=lambda _,spec:(ROOT/spec.split(':',1)[1]).read_bytes()
  with patch.object(gate,'payload_matches_revision',return_value=True),patch.object(gate,'git',side_effect=reader):
   self.assertTrue(gate.scope(gate.BASE,'a'*40,paths));self.assertFalse(gate.scope('b'*40,'a'*40,paths));self.assertFalse(gate.scope(gate.BASE,'a'*40,paths|{'unrelated.php'}))
  with patch.object(gate,'payload_matches_revision',return_value=True),patch.object(gate,'git',return_value=b'changed'):self.assertFalse(gate.scope(gate.BASE,'a'*40,paths))
 def fixtures(self,p):
  common={'passed':True,'synthetic':True,'production_touched':False,'version':gate.VERSION}
  rows={'finance244-stage-visibility':{**common,'assertions':34,'real_wordpress_mysql':True,'actual_seller_controller':True,'owner_scope':True,'read_only':True,'batch_query_verified':True,'core_sha256':gate.RUNTIME['includes/class-sn-plugin.php'],'stage_model_sha256':gate.RUNTIME['includes/class-sn-payment-transactions.php'],'tracking_sha256':gate.RUNTIME['includes/class-sn-payment-tracking.php']},
   'stage246-browser':{**common,'assertions':28,'desktop_mobile':True,'external_network_blocked':True,'actual_runtime_js':True,'stage_js_sha256':gate.RUNTIME['assets/js/payment-transactions.js'],'seller_js_sha256':gate.RUNTIME['assets/js/public-seller.js'],'manager_js_sha256':gate.RUNTIME['assets/js/public-manager.js']}}
  for n,r in rows.items():(p/(n+'.json')).write_text(json.dumps(r))
 def verify(self,p):
  with patch.object(gate.gateway,'verify_hr_output')as retained,patch.object(gate,'payload_matches_revision',return_value=True),patch.object(gate,'git',return_value=('a'*40).encode()):
   gate.verify_hr_output(p,'a'*40);retained.assert_called_once();self.assertEqual(retained.call_args.kwargs['runtime'],gate.RUNTIME);self.assertEqual(retained.call_args.kwargs['version'],gate.VERSION)
 def test_retains_all_gateway_and_finance_proof(self):
  with tempfile.TemporaryDirectory()as d:
   p=pathlib.Path(d);self.fixtures(p);self.verify(p);self.assertTrue(json.loads((p/'stage246-verified.json').read_text())['all_retained_gates'])
 def test_missing_failed_stale_or_unisolated_proof_is_rejected(self):
  for name,key,value in [('finance244-stage-visibility','assertions',33),('finance244-stage-visibility','owner_scope',False),('finance244-stage-visibility','tracking_sha256','wrong'),('finance244-stage-visibility','read_only',False),('stage246-browser','version','2.0.245'),('stage246-browser','actual_runtime_js',False),('stage246-browser','production_touched',None),('stage246-browser','manager_js_sha256','wrong'),('stage246-browser','assertions',27)]:
   with self.subTest(case=(name,key)),tempfile.TemporaryDirectory()as d:
    p=pathlib.Path(d);self.fixtures(p);f=p/(name+'.json');r=json.loads(f.read_text());r[key]=value;f.write_text(json.dumps(r))
    with self.assertRaises(ValueError):self.verify(p)
  with tempfile.TemporaryDirectory()as d:
   p=pathlib.Path(d);self.fixtures(p);(p/'stage246-browser.json').unlink()
   with self.assertRaises(FileNotFoundError):self.verify(p)
if __name__=='__main__':unittest.main()
