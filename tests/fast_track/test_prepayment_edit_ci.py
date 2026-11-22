"""Fail-closed editor CI proof, preserving every older native gate."""
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts'))
import prepayment_edit_ci as editor
import test_invoice_reporting_ci as historical_reports
import test_traceability_ci as historical_trace
from fast_track_ci import make_plan,outcome
from package import committed_members
from risk_report import SUITES


class PrepaymentProofTests(unittest.TestCase):
    def test_exact_payload_member_set_and_hashes_fail_closed(self):
        before=committed_members(ROOT,editor.BASE);after=dict(before)
        for name in editor.CHANGES:after['sales-network-v4/'+name]=(ROOT/name).read_bytes()
        self.assertTrue(editor.bounded_payload(before,after))
        for name in after:
            with self.subTest(changed=name):self.assertFalse(editor.bounded_payload(before,{**after,name:after[name]+b'\n'}))
            missing=dict(after);missing.pop(name)
            with self.subTest(deleted=name):self.assertFalse(editor.bounded_payload(before,missing))
        self.assertFalse(editor.bounded_payload(before,{**after,'sales-network-v4/unknown.php':b'<?php'}))

    def test_scope_rejects_wrong_base_unknown_path_and_changed_fixture(self):
        paths=set(editor.CHANGES)|{'scripts/prepayment_edit_ci.py'}
        with patch('prepayment_edit_ci.payload_matches_revision',return_value=True),patch('prepayment_edit_ci.git',side_effect=lambda _,spec:(ROOT/spec.split(':',1)[1]).read_bytes()):
            self.assertTrue(editor.scope(editor.BASE,'a'*40,paths))
            for base,names in [('b'*40,paths),(editor.BASE,paths|{'unreviewed.py'}),(editor.BASE,paths-{'assets/js/public-shell.js'})]:
                self.assertFalse(editor.scope(base,'a'*40,names))
        with patch('prepayment_edit_ci.payload_matches_revision',return_value=True),patch('prepayment_edit_ci.git',return_value=b'altered fixture'):
            self.assertFalse(editor.scope(editor.BASE,'a'*40,paths))
        with patch('prepayment_edit_ci.payload_matches_revision',return_value=False):self.assertFalse(editor.scope(editor.BASE,'a'*40,paths))

    def test_plan_requires_all_suites_four_finance_entries_and_native_proof(self):
        pr={'pr':27,'base':editor.BASE,'head':'b'*40,'target':'main'};expected={**pr,'merge':'a'*40}
        selection={'risk':'HIGH','base_sha':pr['base'],'head_sha':pr['head'],'target':'main','affected_domains':['shared','finance','reporting','ui','access','ledger','schema','mis','tooling'],
            'changed_files':[{'path':p,'risk':'HIGH','domains':['shared'],'reason':'fixture'} for p in set(editor.CHANGES)|{'scripts/prepayment_edit_ci.py'}],
            'tests':[{'id':name,'applicability':'REQUIRED'} for name in SUITES]}
        with patch('prepayment_edit_ci.scope',return_value=True):plan=make_plan(pr,expected['merge'],selection)
        self.assertTrue(plan['prepayment_edit'] and plan['hr_native'] and plan['finance'])
        self.assertTrue(all(v=='REQUIRED' for v in plan['suites'].values()))
        results={name:'success' for name in ['syntax','documentation','secrets','stable-pr-boundary','plan','finance','hr-native']}
        self.assertEqual(outcome(plan,expected,expected,results,'run',matrix_verified=True,hr_verified=True)['status'],'READY FOR MERGE CHECK')
        for matrix,native in [(False,True),(True,False),(False,False)]:self.assertEqual(outcome(plan,expected,expected,results,'run',matrix_verified=matrix,hr_verified=native)['status'],'BLOCKED')
        for flag in ['finance','hr_native']:
            self.assertEqual(outcome({**plan,flag:False},expected,expected,results,'run',matrix_verified=True,hr_verified=True)['status'],'BLOCKED')
        for job in ['finance','hr-native']:
            for state in ['failure','skipped','cancelled',None]:self.assertEqual(outcome(plan,expected,expected,{**results,job:state},'run',matrix_verified=True,hr_verified=True)['status'],'BLOCKED')

    @staticmethod
    def records():
        return {
            'identity.json':{'merge':'a'*40,'runtime':editor.CHANGES,'fixtures':editor.FIXTURES,'synthetic':True,'network_internal':True,'published_ports':False,'readonly_source':True},
            'native-summary.json':{'passed':True,'synthetic':True,'checks':102,'wordpress':'7.1.4','woocommerce':'10.4.3','php':'8.3.36','mysql':'8.0.46'},
            'browser-summary.json':{'passed':True,'synthetic':True,'checks':31,'errors':[],'viewports':['1440x1000','390x844']},
            'memory-summary.json':{'passed':True,'synthetic':True,'guards':34,'pricing':30,'catalog':40},
            'post-browser-summary.json':{'passed':True,'assertions':7,'synthetic':True},
        }

    def write_editor(self,root):
        root.mkdir(parents=True,exist_ok=True)
        for name,data in self.records().items():(root/name).write_text(json.dumps(data))
        for name in ['prepayment-edit-desktop.png','prepayment-edit-mobile.png']:(root/name).write_bytes(b'\x89PNG'+b'x'*1200)

    def test_partial_stale_forged_or_missing_editor_evidence_is_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);self.write_editor(root)
            with patch('prepayment_edit_ci.git',return_value=b'a'*40),patch('prepayment_edit_ci.payload_matches_revision',return_value=True):
                editor.verify_editor_output(root,'a'*40)
                for name,data in self.records().items():
                    p=root/name;p.unlink()
                    with self.subTest(missing=name),self.assertRaises(FileNotFoundError):editor.verify_editor_output(root,'a'*40)
                    for field in data:
                        p.write_text(json.dumps({**data,field:None}))
                        with self.subTest(name=name,field=field),self.assertRaises(ValueError):editor.verify_editor_output(root,'a'*40)
                    p.write_text(json.dumps(data))
                (root/'prepayment-edit-mobile.png').unlink()
                with self.assertRaises(ValueError):editor.verify_editor_output(root,'a'*40)
            self.write_editor(root)
            for head,payload in [(b'b'*40,True),(b'a'*40,False)]:
                with patch('prepayment_edit_ci.git',return_value=head),patch('prepayment_edit_ci.payload_matches_revision',return_value=payload),self.assertRaises(ValueError):editor.verify_editor_output(root,'a'*40)

    def test_retains_all_prior_native_reporting_ledger_and_mis_proof(self):
        hr,finance=historical_trace.TraceProofTests.records()
        for records in [hr,finance]:
            for data in records.values():
                if 'core_sha256' in data:data['core_sha256']=editor.CHANGES['includes/class-sn-plugin.php']
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder)
            for category,records in [('hr',hr),('finance',finance)]:
                for name,data in records.items():
                    p=root/category/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(data))
            historical_reports.InvoiceReportingProofTests().write_report(root/'hr/invoice-report')
            p=root/'hr/invoice-report/identity.json';data=json.loads(p.read_text());data['runtime']=editor.REPORT_RUNTIME;p.write_text(json.dumps(data))
            self.write_editor(root/'hr/prepayment-edit')
            with patch('prepayment_edit_ci.git',return_value=b'a'*40),patch('invoice_reporting_ci.git',return_value=b'a'*40),patch('traceability_ci.git',return_value=b'a'*40),patch('product_invoice_ci.git',return_value=b'a'*40),patch('prepayment_edit_ci.payload_matches_revision',return_value=True):
                editor.verify_hr_output(root/'hr','a'*40)
                for name in ['hr239-ledger-summary.json','mis-summary.json','trace242-lifecycle.json','staged243-races.json','invoice-report/browser-summary.json','prepayment-edit/post-browser-summary.json']:
                    p=root/'hr'/name;data=p.read_bytes();p.unlink()
                    with self.subTest(missing=name),self.assertRaises(FileNotFoundError):editor.verify_hr_output(root/'hr','a'*40)
                    p.write_bytes(data)

    def test_runner_is_guarded_isolated_and_uploads_only_sanitized_evidence(self):
        runner=(ROOT/'scripts/test-prepayment-edit.sh').read_text()
        for text in ['RUNNER_ENVIRONMENT','github-hosted','scripts/prepayment_edit_ci.py','--internal','readonly','SN_PREPAYMENT_TEST_SANDBOX','WP_HTTP_BLOCK_EXTERNAL','tests/invoices/sandbox.php','woocommerce.10.4.3.zip','sha256sum --check','pre-payment-edit-native.php setup','pre-payment-edit-native.php verify','pre-payment-edit-browser.cjs','com.crmedits.prepayment-edit']:
            self.assertIn(text,runner)
        self.assertNotIn('--network host',runner);self.assertNotIn('--publish',runner)
        parent=(ROOT/'scripts/test-hr-integration.sh').read_text()
        for text in ['native-ledger.php','mis-ten-script-regression','traceability-upgrade-restore','staged-gateway-races','scripts/test-invoice-reporting.sh','scripts/test-prepayment-edit.sh']:self.assertIn(text,parent)
        workflow=(ROOT/'.github/workflows/ci.yml').read_text();self.assertIn('test-results/hr/prepayment-edit/*.json',workflow);self.assertNotIn('private-state.json',workflow)


if __name__=='__main__':unittest.main()
