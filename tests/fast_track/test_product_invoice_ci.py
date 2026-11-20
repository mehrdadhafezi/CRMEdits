"""Product CI proof must retain every existing gate and reject stale/partial evidence."""
import json,pathlib,sys,tempfile,unittest
from unittest.mock import patch
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[2]/'scripts'))
import product_invoice_ci as product
from package import committed_members
from fast_track_ci import make_plan,outcome
from risk_report import SUITES,assess,report
ROOT=pathlib.Path(__file__).resolve().parents[2]


class ProductProofTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.before=committed_members(ROOT,product.BASE)
        cls.after={**cls.before,**{'sales-network-v4/'+name:(ROOT/name).read_bytes().replace(b'\r\n',b'\n') for name in product.RUNTIME}}

    def test_only_twelve_exact_runtime_changes_and_two_additions_are_accepted(self):
        self.assertTrue(product.bounded_payload(self.before,self.after))
        self.assertEqual((len(self.before),len(self.after)),(119,121))
        for name in self.after:
            with self.subTest(path=name):self.assertFalse(product.bounded_payload(self.before,{**self.after,name:self.after[name]+b'\n'}))
        deleted=dict(self.after);deleted.pop('sales-network-v4/includes/class-sn-invoice.php')
        self.assertFalse(product.bounded_payload(self.before,deleted))
        self.assertFalse(product.bounded_payload(self.before,{**self.after,'sales-network-v4/includes/unreviewed.php':b'<?php'}))

    def test_scope_rejects_wrong_base_missing_runtime_and_unknown_files(self):
        paths=set(product.RUNTIME)|{'tests/products/native-product-settings.php'}
        with patch('product_invoice_ci.payload_matches_revision',return_value=True)as check:
            self.assertTrue(product.scope(product.BASE,'h'*40,paths));check.assert_called_once()
        with patch('product_invoice_ci.payload_matches_revision')as check:
            for base,names in [('b'*40,paths),(product.BASE,paths-{'sales-network.php'}),(product.BASE,paths|{'scripts/unreviewed.py'}),(product.BASE,paths|{'includes/class-sn-invoice.php'})]:
                self.assertFalse(product.scope(base,'h'*40,names))
            check.assert_not_called()

    def test_actual_reviewed_commit_selects_the_complete_native_plan(self):
        head='e38d04264894a51bb1d02a2d63f1b4574ed7d205'
        selected=report(product.BASE,head,'main')
        pr={'pr':21,'base':product.BASE,'head':head,'target':'main'}
        plan=make_plan(pr,'a'*40,selected)
        self.assertEqual(selected['risk'],'HIGH');self.assertEqual(selected['blockers'],[])
        self.assertTrue(plan['product_settings'] and plan['finance'] and plan['hr_native'])
        self.assertTrue(all(value=='REQUIRED'for value in plan['suites'].values()))
        with patch('product_invoice_ci.payload_matches_revision',return_value=False):
            unreviewed=make_plan(pr,'a'*40,selected)
        self.assertFalse(unreviewed['hr_native'] or unreviewed['product_settings'])
        self.assertTrue(all(value=='REQUIRED'for value in unreviewed['suites'].values()))

    def test_shared_sms_flow_mapping_is_conservative_and_keeps_object_guards(self):
        for name in ['includes/class-sn-sms.php','includes/class-sn-dot-flow.php','includes/class-sn-operations-flow.php']:
            risk,domains,_=assess(name,'M',('100644','100644'),('a'*40,'b'*40),'c'*40,'d'*40)
            self.assertEqual((risk,domains),('HIGH',{'shared'}))
            risk,_,_=assess(name,'M',('100644','120000'),('a'*40,'b'*40),'c'*40,'d'*40)
            self.assertEqual(risk,'UNKNOWN')

    def test_product_plan_requires_all_finance_and_native_hr_gates(self):
        pr={'pr':21,'base':product.BASE,'head':'c'*40,'target':'main'};revision={**pr,'merge':'a'*40}
        selection={'risk':'HIGH','base_sha':product.BASE,'head_sha':pr['head'],'target':'main',
            'affected_domains':['shared','finance','ui','access','ledger','schema','mis','tooling','reporting'],
            'changed_files':[{'path':p,'risk':'HIGH','domains':['shared'],'reason':'fixture'}for p in set(product.RUNTIME)|{'scripts/product_invoice_ci.py'}],
            'tests':[{'id':p,'applicability':'REQUIRED'}for p in SUITES]}
        with patch('product_invoice_ci.scope',return_value=True):plan=make_plan(pr,revision['merge'],selection)
        self.assertTrue(plan['product_settings'] and plan['hr_native'] and plan['finance'])
        self.assertTrue(all(v=='REQUIRED' for v in plan['suites'].values()))
        results={job:'success'for job in ['syntax','documentation','secrets','stable-pr-boundary','plan','finance','hr-native']}
        def status(**kw):return outcome(plan,revision,revision,results,'https://github.com/example/actions/runs/1',**kw)['status']
        self.assertEqual(status(),'BLOCKED');self.assertEqual(status(matrix_verified=True),'BLOCKED');self.assertEqual(status(hr_verified=True),'BLOCKED')
        self.assertEqual(status(matrix_verified=True,hr_verified=True),'READY FOR MERGE CHECK')
        for job in ['finance','hr-native']:
            for value in ['failure','skipped','cancelled',None]:
                results[job]=value;self.assertEqual(status(matrix_verified=True,hr_verified=True),'BLOCKED')
            results[job]='success'
        selection['risk']='UNKNOWN'
        with patch('product_invoice_ci.scope')as check:self.assertFalse(make_plan(pr,revision['merge'],selection)['hr_native']);check.assert_not_called()

    @staticmethod
    def records():
        common={'passed':True,'synthetic':True,'production_touched':False,'version':'2.0.241','assertions':100,'real_sms_sent':False}
        fingerprints={'core_sha256':product.RUNTIME['includes/class-sn-plugin.php'],'policy_sha256':'1ad645e71cfdaeb63fb9bb81d12646515d2ee626b947099930f88ba7dfb683d8'}
        hr={name:{**common,**fingerprints}for name in ['hr239-native-summary.json','hr239-invoices-summary.json','hr239-finance-summary.json','hr239-ledger-summary.json']}
        hr.update({'browser/summary.json':{**common,'transport':'native WordPress HTTP login, shortcode and admin-ajax'},'code-rollback.json':common,
            'mis-summary.json':{**common,'scripts':10,'isolated_database':True},'product241-unit.json':common,
            'product241-native.json':{**common,'wordpress':'7.1.4','woocommerce':'10.4.3'},
            'product241-races.json':{**common,'cases':2,'real_parallel_php_processes':True,'actual_mysql_advisory_locks':True,'stale_cache_control':True},
            'product-browser/product241-browser.json':{**common,**{key:True for key in ['actual_wordpress_cookie','actual_admin_http','actual_woocommerce_product_save','legacy_400_reproduced','json_large_form_passed','desktop_mobile']}},
            'product-browser/product241-browser-errors.json':{'errors':[]}})
        finance={'gateway-manual-native.json':{**common,'wordpress':'7.1.4','mysql':'synthetic-native'},
            'gateway-manual-races.json':{**common,'cases':4,'real_parallel_php_processes':True,'actual_mysql_advisory_and_row_locks':True},
            'gateway-browser/gateway-manual-browser.json':{**common,'transport':'native WordPress HTTP','actual_multipart_upload_tested':True,'real_wordpress_auth_cookie':True,'chromium':'synthetic'}}
        return hr,finance

    def test_complete_retained_and_product_evidence_is_exact_revision_bound(self):
        hr,finance=self.records()
        with tempfile.TemporaryDirectory()as folder:
            root=pathlib.Path(folder);out=root/'hr'
            for category,records in [('hr',hr),('finance',finance)]:
                for name,data in records.items():
                    p=root/category/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(data))
            with patch('product_invoice_ci.git',return_value=b'a'*40),patch('product_invoice_ci.payload_matches_revision',return_value=True):
                product.verify_hr_output(out,'a'*40)
                for category,records in [('hr',hr),('finance',finance)]:
                    for name,data in records.items():
                        p=root/category/name
                        for field,value in [('passed',False),('synthetic',False),('assertions',0)]:
                            if name.endswith('errors.json'):continue
                            p.write_text(json.dumps({**data,field:value}))
                            with self.subTest(name=name,field=field),self.assertRaises(ValueError):product.verify_hr_output(out,'a'*40)
                        p.write_text(json.dumps(data));p.unlink()
                        with self.subTest(missing=name),self.assertRaises(FileNotFoundError):product.verify_hr_output(out,'a'*40)
                        p.write_text(json.dumps(data))
                for name,fields in {
                    'product241-native.json':['wordpress','woocommerce','version','real_sms_sent'],
                    'product241-races.json':['version','cases','real_parallel_php_processes','actual_mysql_advisory_locks','stale_cache_control','real_sms_sent'],
                    'product-browser/product241-browser.json':['version','actual_wordpress_cookie','actual_admin_http','actual_woocommerce_product_save','legacy_400_reproduced','json_large_form_passed','desktop_mobile','real_sms_sent'],
                    'hr239-native-summary.json':['core_sha256','policy_sha256']}.items():
                    for field in fields:
                        (out/name).write_text(json.dumps({**hr[name],field:None}))
                        with self.subTest(name=name,field=field),self.assertRaises(ValueError):product.verify_hr_output(out,'a'*40)
                    (out/name).write_text(json.dumps(hr[name]))
                (out/'product-browser/product241-browser-errors.json').write_text(json.dumps({'errors':[{'message':'synthetic'}]}))
                with self.assertRaises(ValueError):product.verify_hr_output(out,'a'*40)
                (out/'product-browser/product241-browser-errors.json').write_text(json.dumps({'errors':[]}))
            for head,payload in [(b'b'*40,True),(b'a'*40,False)]:
                with patch('product_invoice_ci.git',return_value=head),patch('product_invoice_ci.payload_matches_revision',return_value=payload),self.assertRaises(ValueError):product.verify_hr_output(out,'a'*40)

    def test_retained_gateway_evidence_rejects_previous_release(self):
        _,finance=self.records()
        with tempfile.TemporaryDirectory()as folder:
            root=pathlib.Path(folder)
            for name,data in finance.items():p=root/name;p.parent.mkdir(exist_ok=True);p.write_text(json.dumps(data))
            product.verify_finance_output(root,'7.1.4')
            for name,data in finance.items():
                (root/name).write_text(json.dumps({**data,'version':'2.0.240'}))
                with self.assertRaises(ValueError):product.verify_finance_output(root,'7.1.4')
                (root/name).write_text(json.dumps(data))

    def test_runner_keeps_prior_regressions_and_publishes_only_sanitized_product_outputs(self):
        runner=(ROOT/'scripts/test-hr-integration.sh').read_text()
        for name in ['test-finance-integration.sh','native.php','native-invoices.php','native-ledger.php','native-finance.php','ci-browser.cjs','ci-code-state.php','tests/mis/run-all.php','tests/products/native-product-settings.php','tests/products/product-sms-races.py','tests/products/ci-browser.cjs']:self.assertIn(name,runner)
        self.assertIn('test "${RUNNER_ENVIRONMENT:-}" = github-hosted',runner)
        self.assertIn("os.environ.get('RUNNER_ENVIRONMENT')=='github-hosted'",(ROOT/'tests/products/product-sms-races.py').read_text())
        workflow=(ROOT/'.github/workflows/ci.yml').read_text();self.assertNotIn('product241-fixture.json',workflow)
        self.assertIn('test-results/hr/product-browser/product241-browser-errors.json',workflow)
        self.assertIn('production_touched:false',(ROOT/'tests/products/product-settings-browser.cjs').read_text())


if __name__=='__main__':unittest.main()
