"""The payment fix adds evidence, and cannot certify changed or unrelated code."""
import copy,json,pathlib,sys,tempfile,unittest
from unittest.mock import patch
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[2]/'scripts'))
import gateway_manual_ci as gw
from package import committed_members
from fast_track_ci import make_plan,outcome
from risk_report import SUITES
ROOT=pathlib.Path(__file__).resolve().parents[2]

class GatewayProofTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.before=committed_members(ROOT,gw.BASE)
        cls.after=committed_members(ROOT,'3e5ea9a950b8ec0c936ba4188ddb6118fe3b1cc6')

    def test_only_eight_reviewed_runtime_changes_are_accepted(self):
        self.assertTrue(gw.bounded_payload(self.before,self.after))
        self.assertEqual(len(self.before),119)
        for name in self.after:
            with self.subTest(path=name):self.assertFalse(gw.bounded_payload(self.before,{**self.after,name:self.after[name]+b'\n'}))
        deleted=dict(self.after);deleted.pop('sales-network-v4/includes/class-sn-sms.php')
        self.assertFalse(gw.bounded_payload(self.before,deleted))
        self.assertFalse(gw.bounded_payload(self.before,{**self.after,'sales-network-v4/includes/extra.php':b'<?php'}))

    def test_scope_rejects_wrong_base_missing_files_and_unknown_changes(self):
        paths=set(gw.RUNTIME)|{'tests/payments/gateway-manual-native.php'}
        with patch('gateway_manual_ci.payload_matches_revision',return_value=True)as check:
            self.assertTrue(gw.scope(gw.BASE,'h'*40,paths));check.assert_called_once()
        with patch('gateway_manual_ci.payload_matches_revision')as check:
            for base,names in [('b'*40,paths),(gw.BASE,paths-{'sales-network.php'}),(gw.BASE,paths|{'scripts/unreviewed.py'}),(gw.BASE,paths|{'includes/class-sn-sms.php'})]:
                self.assertFalse(gw.scope(base,'h'*40,names))
            check.assert_not_called()

    def test_new_mapping_preserves_every_required_suite_and_requires_both_native_jobs(self):
        pr={'pr':20,'base':gw.BASE,'head':'c'*40,'target':'main'};revision={**pr,'merge':'a'*40}
        selection={'risk':'HIGH','base_sha':gw.BASE,'head_sha':pr['head'],'target':'main','affected_domains':['shared','finance','ui','access','ledger','schema','mis','tooling','reporting'],'changed_files':[{'path':p,'risk':'HIGH','domains':['shared'],'reason':'fixture'}for p in set(gw.RUNTIME)|{'scripts/gateway_manual_ci.py'}],'tests':[{'id':p,'applicability':'REQUIRED'}for p in SUITES]}
        with patch('gateway_manual_ci.scope',return_value=True):plan=make_plan(pr,revision['merge'],selection)
        self.assertTrue(plan['gateway_manual'] and plan['hr_native'] and plan['finance'])
        self.assertTrue(all(v=='REQUIRED'for v in plan['suites'].values()))
        results={job:'success'for job in ['syntax','documentation','secrets','stable-pr-boundary','plan','finance','hr-native']}
        def status(**kw):return outcome(plan,revision,revision,results,'https://github.com/example/actions/runs/1',**kw)['status']
        self.assertEqual(status(),'BLOCKED');self.assertEqual(status(matrix_verified=True),'BLOCKED');self.assertEqual(status(hr_verified=True),'BLOCKED')
        self.assertEqual(status(matrix_verified=True,hr_verified=True),'READY FOR MERGE CHECK')
        for job in ['finance','hr-native']:
            for state in ['failure','skipped','cancelled',None]:
                results[job]=state;self.assertNotEqual(status(matrix_verified=True,hr_verified=True),'READY FOR MERGE CHECK')
            results[job]='success'
        selection['risk']='UNKNOWN'
        with patch('gateway_manual_ci.scope')as check:
            self.assertFalse(make_plan(pr,revision['merge'],selection)['hr_native']);check.assert_not_called()

    def test_finance_evidence_requires_real_http_upload_and_parallel_mysql(self):
        common={'passed':True,'synthetic':True,'production_touched':False,'version':'2.0.240','assertions':100}
        records={
            'gateway-manual-native.json':{**common,'wordpress':'7.1.4','mysql':'synthetic-native'},
            'gateway-manual-races.json':{**common,'cases':4,'real_parallel_php_processes':True,'actual_mysql_advisory_and_row_locks':True},
            'gateway-browser/gateway-manual-browser.json':{**common,'transport':'native WordPress HTTP','actual_multipart_upload_tested':True,'real_wordpress_auth_cookie':True,'chromium':'synthetic'},
        }
        with tempfile.TemporaryDirectory()as folder:
            root=pathlib.Path(folder)
            for name,data in records.items():
                p=root/name;p.parent.mkdir(exist_ok=True);p.write_text(json.dumps(data))
            gw.verify_finance_output(root,'7.1.4')
            for name,data in records.items():
                for field,value in [('passed',False),('synthetic',False),('assertions',0),('version','2.0.239'),('production_touched',True)]:
                    (root/name).write_text(json.dumps({**data,field:value}))
                    with self.subTest(name=name,field=field),self.assertRaises(ValueError):gw.verify_finance_output(root,'7.1.4')
                (root/name).write_text(json.dumps(data))
            for name,field,value in [('gateway-manual-native.json','wordpress','wrong'),('gateway-manual-races.json','real_parallel_php_processes',False),('gateway-manual-races.json','actual_mysql_advisory_and_row_locks',False),('gateway-manual-races.json','cases',3),('gateway-browser/gateway-manual-browser.json','actual_multipart_upload_tested',False),('gateway-browser/gateway-manual-browser.json','real_wordpress_auth_cookie',False),('gateway-browser/gateway-manual-browser.json','transport','CLI proxy')]:
                (root/name).write_text(json.dumps({**records[name],field:value}))
                with self.subTest(field=field),self.assertRaises(ValueError):gw.verify_finance_output(root,'7.1.4')
                (root/name).write_text(json.dumps(records[name]))

    def test_native_runner_remains_disposable_and_old_tests_are_retained(self):
        runner=(ROOT/'scripts/test-finance-integration.sh').read_text()
        self.assertIn('test "${RUNNER_ENVIRONMENT:-}" = github-hosted',runner)
        for name in ['tests/payments/gateway-inquiry.php','tests/payments/receipt-last4.php','tests/payments/integration/browser.cjs','tests/payments/integration/verify.php final','tests/payments/gateway-manual-native.php','tests/payments/gateway-manual-races.py','tests/payments/gateway-manual-browser.cjs']:self.assertIn(name,runner)
        self.assertNotIn('performance-volume.php',(ROOT/'scripts/test-hr-integration.sh').read_text())
        worker=(ROOT/'tests/payments/gateway-manual-races.py').read_text();self.assertIn("os.environ.get('RUNNER_ENVIRONMENT')=='github-hosted'",worker)
        self.assertNotIn('password',(ROOT/'scripts/gateway_manual_ci.py').read_text().lower())

if __name__=='__main__':unittest.main()
