"""Exact Finance hotfix scope and evidence must fail closed."""
import copy,hashlib,json,pathlib,sys,tempfile,unittest
from unittest.mock import patch
ROOT=pathlib.Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts'))
import finance_review_ci as finance
from package import committed_members

class FinanceReviewProofTests(unittest.TestCase):
    def test_exact_payload_rejects_missing_added_and_changed_members(self):
        before=committed_members(ROOT,finance.BASE);after=dict(before)
        for name in finance.CHANGES:after['sales-network-v4/'+name]=(ROOT/name).read_bytes()
        self.assertTrue(finance.bounded_payload(before,after))
        for name in after:
            self.assertFalse(finance.bounded_payload(before,{**after,name:after[name]+b'\n'}))
            missing=dict(after);missing.pop(name);self.assertFalse(finance.bounded_payload(before,missing))
        self.assertFalse(finance.bounded_payload(before,{**after,'sales-network-v4/unknown.php':b'<?php'}))

    def test_scope_binds_every_reviewed_validation_file(self):
        paths=set(finance.PROFILE['changed_paths'])
        reader=lambda _,spec:(ROOT/spec.split(':',1)[1]).read_bytes()
        with patch.object(finance,'payload_matches_revision',return_value=True),patch.object(finance,'git',side_effect=reader):
            self.assertTrue(finance.scope(finance.BASE,'a'*40,paths))
            self.assertFalse(finance.scope('b'*40,'a'*40,paths))
            self.assertFalse(finance.scope(finance.BASE,'a'*40,paths|{'unexpected.php'}))
        with patch.object(finance,'payload_matches_revision',return_value=True),patch.object(finance,'git',return_value=b'changed'):
            self.assertFalse(finance.scope(finance.BASE,'a'*40,paths))

    def fixtures(self,root):
        common={'passed':True,'synthetic':True,'version':'2.0.244','production_touched':False,'wordpress':'7.1.4','mysql':'8.0.46',
            'core_sha256':finance.RUNTIME['includes/class-sn-plugin.php'],'review_sha256':finance.RUNTIME['includes/class-sn-receipt-review.php'],'catalog_sha256':finance.RUNTIME['includes/class-sn-sales-catalog.php']}
        rows={'capacity':{**common,'assertions':44,'audit_rollback':True,'approval_rejection':True,'overpayment_blocked':True},
            'catalog':{**common,'assertions':20,'receipt_history_preserved':True},
            'query':{**common,'assertions':54,'identical_outputs':True,'read_only':True,'synthetic_invoices':30000,'before_queries':41,'after_queries':37},
            'browser':{**common,'assertions':12,'desktop_mobile':True,'external_network_blocked':True,'receipt_js_sha256':finance.RUNTIME['assets/js/receipt-review.js']}}
        for name,row in rows.items():(root/('finance244-'+name+'.json')).write_text(json.dumps(row))

    def verify(self,root):
        with patch.object(finance.trace,'verify_hr_output')as retained,patch.object(finance.reports,'verify_report_output')as reports,patch.object(finance.editor,'verify_editor_output')as editor,patch.object(finance.tracking,'verify_tracking_output')as tracking,patch.object(finance,'payload_matches_revision',return_value=True),patch.object(finance,'git',return_value=('a'*40).encode()):
            finance.verify_hr_output(root,'a'*40)
            for check in [retained,reports,editor,tracking]:check.assert_called_once()
            self.assertEqual(retained.call_args.kwargs['version'],'2.0.244')

    def test_complete_native_browser_and_retained_gates_are_required(self):
        with tempfile.TemporaryDirectory()as t:
            root=pathlib.Path(t);self.fixtures(root);self.verify(root)
            self.assertTrue(json.loads((root/'finance244-verified.json').read_text())['all_retained_gates'])

    def test_missing_failed_stale_or_partial_proof_is_rejected(self):
        cases=[('capacity','audit_rollback',False),('capacity','core_sha256','bad'),('catalog','receipt_history_preserved',False),('query','read_only',False),('query','synthetic_invoices',29999),('query','after_queries',41),('browser','receipt_js_sha256','bad'),('browser','desktop_mobile',False),('browser','production_touched',None),('browser','production_touched',True),('capacity','version','2.0.243'),('catalog','passed',False)]
        for name,key,value in cases:
            with self.subTest(case=(name,key)),tempfile.TemporaryDirectory()as t:
                root=pathlib.Path(t);self.fixtures(root);p=root/('finance244-'+name+'.json');row=json.loads(p.read_text());row[key]=value;p.write_text(json.dumps(row))
                with self.assertRaises(ValueError):self.verify(root)
        with tempfile.TemporaryDirectory()as t:
            root=pathlib.Path(t);self.fixtures(root);(root/'finance244-query.json').unlink()
            with self.assertRaises(FileNotFoundError):self.verify(root)

if __name__=='__main__':unittest.main()
