"""Follow-up Finance/catalog gates must retain all earlier proof and fail closed."""
import copy,hashlib,json,pathlib,re,subprocess,sys,tempfile,unittest
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts'))
import gateway_catalog_ci as gate
from package import committed_members
from risk_report import git as historical_git
from functools import lru_cache
@lru_cache(maxsize=None)
def historical(path):return historical_git("show","6787ebee21511e4f6fc02cd55bbf1c3693866b7d:"+path)
class GatewayCatalogProofTests(unittest.TestCase):
 def test_real_memory_evidence_writers_match_candidate_version(self):
  shell=(ROOT/'scripts/test-hr-integration.sh').read_text();version=re.search(r'Version:\s*([0-9.]+)',(ROOT/'sales-network.php').read_text()).group(1)
  for name,count in [('product241-unit.json',40),('trace242-unit.json',21)]:
   start=shell.index('"$output/'+name+'"');code=shell[start:].split("<<'PY'\n",1)[1].split('\nPY',1)[0]
   with self.subTest(writer=name),tempfile.TemporaryDirectory()as d:
    p=pathlib.Path(d);log=p/'memory.txt';out=p/'evidence.json';log.write_text('PASS synthetic\n'*count+'TOTAL '+str(count)+'\n')
    subprocess.run([sys.executable,'-B','-c',code,str(log),str(out)],cwd=ROOT,check=True,capture_output=True)
    row=json.loads(out.read_text());self.assertEqual(row['version'],version);self.assertEqual(row['assertions'],count);self.assertIs(row['production_touched'],False)
 def test_payload_requires_every_exact_runtime_member(self):
  before=committed_members(ROOT,gate.BASE);after=dict(before)
  for n in gate.CHANGES:after['sales-network-v4/'+n]=historical(n)
  self.assertTrue(gate.bounded_payload(before,after))
  for n in after:
   self.assertFalse(gate.bounded_payload(before,{**after,n:after[n]+b'\n'}));missing=dict(after);missing.pop(n);self.assertFalse(gate.bounded_payload(before,missing))
  self.assertFalse(gate.bounded_payload(before,{**after,'sales-network-v4/extra.php':b'<?php'}))
 def test_scope_binds_base_paths_and_all_validation_sources(self):
  paths=set(gate.PROFILE['changed_paths']);reader=lambda _,spec:historical(spec.split(':',1)[1])
  with patch.object(gate,'payload_matches_revision',return_value=True),patch.object(gate,'git',side_effect=reader):
   self.assertTrue(gate.scope(gate.BASE,'a'*40,paths));self.assertFalse(gate.scope('b'*40,'a'*40,paths));self.assertFalse(gate.scope(gate.BASE,'a'*40,paths|{'unrelated.php'}))
  with patch.object(gate,'payload_matches_revision',return_value=True),patch.object(gate,'git',return_value=b'wrong'):
   self.assertFalse(gate.scope(gate.BASE,'a'*40,paths))
 def fixtures(self,p):
  common={'passed':True,'synthetic':True,'production_touched':False,'version':'2.0.245'}
  rows={'gateway245-native':{**common,'assertions':71,'baseline_reproduced':True,'real_wordpress_mysql':True,'scope_nonce_negative':True,'rollback':True,'duplicate_guard':True,'core_sha256':gate.RUNTIME['includes/class-sn-plugin.php']},
   'catalog245-native':{**common,'assertions':14,'real_wordpress_mysql':True,'owner_scope':True,'forged_selections_denied':True,'catalog_sha256':gate.RUNTIME['includes/class-sn-sales-catalog.php']},
   'gateway245-browser':{**common,'assertions':44,'desktop_mobile':True,'external_network_blocked':True,'manager_js_sha256':gate.RUNTIME['assets/js/public-manager.js'],'catalog_js_sha256':gate.RUNTIME['assets/js/sales-catalog-staff.js']}}
  for n,r in rows.items():(p/(n+'.json')).write_text(json.dumps(r))
 def verify(self,p):
  with patch.object(gate.finance,'verify_hr_output')as retained,patch.object(gate,'payload_matches_revision',return_value=True),patch.object(gate,'git',return_value=('a'*40).encode()):
   gate.verify_hr_output(p,'a'*40);retained.assert_called_once();self.assertEqual(retained.call_args.kwargs['version'],'2.0.245');self.assertEqual(retained.call_args.kwargs['runtime'],gate.RUNTIME)
 def test_complete_proof_retains_every_244_gate(self):
  with tempfile.TemporaryDirectory()as d:p=pathlib.Path(d);self.fixtures(p);self.verify(p);self.assertTrue(json.loads((p/'gateway245-verified.json').read_text())['all_retained_gates'])
 def test_missing_failed_stale_or_partial_evidence_is_rejected(self):
  for name,key,value in [('gateway245-native','rollback',False),('gateway245-native','scope_nonce_negative',False),('gateway245-native','core_sha256','bad'),('catalog245-native','owner_scope',False),('catalog245-native','assertions',13),('gateway245-browser','production_touched',None),('gateway245-browser','catalog_js_sha256','bad'),('gateway245-browser','assertions',43),('gateway245-browser','version','2.0.244')]:
   with self.subTest(case=(name,key)),tempfile.TemporaryDirectory()as d:
    p=pathlib.Path(d);self.fixtures(p);f=p/(name+'.json');r=json.loads(f.read_text());r[key]=value;f.write_text(json.dumps(r))
    with self.assertRaises(ValueError):self.verify(p)
  with tempfile.TemporaryDirectory()as d:
   p=pathlib.Path(d);self.fixtures(p);(p/'catalog245-native.json').unlink()
   with self.assertRaises(FileNotFoundError):self.verify(p)
if __name__=='__main__':unittest.main()
