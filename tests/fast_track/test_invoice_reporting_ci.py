"""Reporting evidence is additive to every existing native gate and exact payload."""
import json
import pathlib
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts'))
import invoice_reporting_ci as reports
import traceability_ci as trace
import test_traceability_ci as historical
from fast_track_ci import make_plan,outcome
from package import committed_members
from risk_report import SUITES,git


class InvoiceReportingProofTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.before=committed_members(ROOT,reports.BASE)
        cls.after=dict(cls.before)
        # These immutable blobs survive a squash merge; no mutable checkout bytes.
        blobs={
            'includes/class-sn-plugin.php':'005cb3ba2065090280472122e8a9d5a7aa2a82ca',
            'includes/class-sn-bulk-order-export.php':'4e45bb078db74acf7d03e489c28efee791168596',
            'includes/reports/class-sn-invoice-financial-report.php':'1b05233cfa55869317366545b75c04943499359f',
        }
        for name,oid in blobs.items():cls.after['sales-network-v4/'+name]=git('cat-file','blob',oid)

    def test_only_three_reviewed_runtime_changes_and_one_addition_are_accepted(self):
        self.assertTrue(reports.bounded_payload(self.before,self.after))
        for name in self.after:
            with self.subTest(path=name):
                self.assertFalse(reports.bounded_payload(self.before,{**self.after,name:self.after[name]+b'\n'}))
        for name in self.after:
            missing=dict(self.after);missing.pop(name)
            with self.subTest(deleted=name):self.assertFalse(reports.bounded_payload(self.before,missing))
        self.assertFalse(reports.bounded_payload(self.before,{**self.after,'sales-network-v4/extra.php':b'<?php'}))
        self.assertFalse(trace.bounded_payload(committed_members(ROOT,trace.BASE),self.after))

    def test_wrong_base_unknown_paths_and_unverified_payload_fail_closed(self):
        paths=set(reports.CHANGES)|{'scripts/invoice_reporting_ci.py'}
        with patch('invoice_reporting_ci.payload_matches_revision',return_value=True):
            self.assertTrue(reports.scope(reports.BASE,'a'*40,paths))
        with patch('invoice_reporting_ci.payload_matches_revision') as verify:
            for base,names in [('b'*40,paths),(reports.BASE,paths-{'includes/class-sn-plugin.php'}),(reports.BASE,paths|{'includes/unreviewed.php'}),(reports.BASE,paths|{'scripts/unknown.py'})]:
                self.assertFalse(reports.scope(base,'a'*40,names))
            verify.assert_not_called()
        with patch('invoice_reporting_ci.payload_matches_revision',return_value=False):
            self.assertFalse(reports.scope(reports.BASE,'a'*40,paths))

    def test_plan_keeps_all_suites_and_requires_finance_plus_comprehensive_native_job(self):
        pr={'pr':25,'base':reports.BASE,'head':'b'*40,'target':'main'};expected={**pr,'merge':'a'*40}
        selection={'risk':'HIGH','base_sha':pr['base'],'head_sha':pr['head'],'target':'main','affected_domains':['shared','finance','reporting','ui','access','ledger','schema','mis','tooling'],
            'changed_files':[{'path':p,'risk':'HIGH','domains':['shared'],'reason':'fixture'} for p in set(reports.CHANGES)|{'scripts/invoice_reporting_ci.py'}],
            'tests':[{'id':name,'applicability':'REQUIRED'} for name in SUITES]}
        with patch('invoice_reporting_ci.scope',return_value=True):plan=make_plan(pr,expected['merge'],selection)
        self.assertTrue(plan['invoice_reporting'] and plan['hr_native'] and plan['finance'])
        self.assertTrue(all(value=='REQUIRED' for value in plan['suites'].values()))
        results={name:'success' for name in ['syntax','documentation','secrets','stable-pr-boundary','plan','finance','hr-native']}
        self.assertEqual(outcome(plan,expected,expected,results,'run',matrix_verified=True,hr_verified=True)['status'],'READY FOR MERGE CHECK')
        for matrix,native in [(False,True),(True,False),(False,False)]:
            self.assertEqual(outcome(plan,expected,expected,results,'run',matrix_verified=matrix,hr_verified=native)['status'],'BLOCKED')
        for job in ['finance','hr-native']:
            for state in ['failure','skipped','cancelled',None]:
                self.assertEqual(outcome(plan,expected,expected,{**results,job:state},'run',matrix_verified=True,hr_verified=True)['status'],'BLOCKED')
        for flag in ['finance','hr_native']:
            self.assertEqual(outcome({**plan,flag:False},expected,expected,results,'run',matrix_verified=True,hr_verified=True)['status'],'BLOCKED')

    @staticmethod
    def report_records():
        return {
            'identity.json':{'merge':'a'*40,'runtime':reports.CHANGES,'synthetic':True,'network_internal':True,'published_ports':False,'readonly_source':True,'native_assertions':15},
            'native-summary.json':{'wordpress':'7.1.4','php':'8.3.36','mysql':'8.0.46','fixture_count':6,'unchanged':True,'summary':{'confirmed_amount':'8000.00'}},
            'browser-summary.json':{'syntheticOnly':True,'errors':[],'checks':85,'viewportEvidence':['1440x1000','390x844']},
            'memory-summary.json':{'passed':True,'synthetic':True,'assertions':63},
        }

    def write_report(self,root):
        root.mkdir(parents=True,exist_ok=True)
        for name,data in self.report_records().items():(root/name).write_text(json.dumps(data))
        for name in ['invoice-report-desktop.png','invoice-report-mobile.png','invoice-report-financial-columns.png']:(root/name).write_bytes(b'\x89PNG'+b'x'*1200)

    def test_report_evidence_rejects_missing_forged_stale_or_partial_proof(self):
        with tempfile.TemporaryDirectory() as folder:
            root=pathlib.Path(folder);self.write_report(root)
            with patch('invoice_reporting_ci.git',return_value=b'a'*40),patch('invoice_reporting_ci.payload_matches_revision',return_value=True):
                reports.verify_report_output(root,'a'*40)
                for name,data in self.report_records().items():
                    path=root/name;path.unlink()
                    with self.subTest(missing=name),self.assertRaises(FileNotFoundError):reports.verify_report_output(root,'a'*40)
                    for field in data:
                        path.write_text(json.dumps({**data,field:None}))
                        with self.subTest(name=name,field=field),self.assertRaises(ValueError):reports.verify_report_output(root,'a'*40)
                    path.write_text(json.dumps(data))
                (root/'invoice-report-mobile.png').unlink()
                with self.assertRaises(ValueError):reports.verify_report_output(root,'a'*40)
            self.write_report(root)
            for head,payload in [(b'b'*40,True),(b'a'*40,False)]:
                with patch('invoice_reporting_ci.git',return_value=head),patch('invoice_reporting_ci.payload_matches_revision',return_value=payload),self.assertRaises(ValueError):reports.verify_report_output(root,'a'*40)

    def test_combined_verifier_retains_every_old_native_proof_and_requires_new_reports(self):
        hr,finance=historical.TraceProofTests.records()
        for records in [hr,finance]:
            for data in records.values():
                if 'core_sha256' in data:data['core_sha256']=reports.CHANGES['includes/class-sn-plugin.php']
        with tempfile.TemporaryDirectory() as folder:
            root=pathlib.Path(folder)
            for category,records in [('hr',hr),('finance',finance)]:
                for name,data in records.items():
                    path=root/category/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text(json.dumps(data))
            self.write_report(root/'hr/invoice-report')
            with patch('invoice_reporting_ci.git',return_value=b'a'*40),patch('traceability_ci.git',return_value=b'a'*40),patch('product_invoice_ci.git',return_value=b'a'*40),patch('invoice_reporting_ci.payload_matches_revision',return_value=True):
                reports.verify_hr_output(root/'hr','a'*40)
                for name in ['hr239-ledger-summary.json','mis-summary.json','trace242-lifecycle.json','staged243-races.json','invoice-report/browser-summary.json']:
                    path=root/'hr'/name;data=path.read_bytes();path.unlink()
                    with self.subTest(missing=name),self.assertRaises(FileNotFoundError):reports.verify_hr_output(root/'hr','a'*40)
                    path.write_bytes(data)

    def test_runner_is_separate_internal_and_additive_to_all_old_hr_mis_phases(self):
        runner=(ROOT/'scripts/test-invoice-reporting.sh').read_text()
        for text in ['RUNNER_ENVIRONMENT','github-hosted','--internal','readonly','SN_REPORTING_TEST_SANDBOX','WP_HTTP_BLOCK_EXTERNAL','pre_wp_mail','invoice-financial-native.php setup','invoice-financial-browser.cjs','invoice-financial-native.php verify','com.crmedits.invoice-report']:
            self.assertIn(text,runner)
        self.assertNotIn('--network host',runner);self.assertNotIn('--publish',runner)
        parent=(ROOT/'scripts/test-hr-integration.sh').read_text()
        for text in ['native-ledger.php','mis-ten-script-regression','traceability-upgrade-restore','staged-gateway-races','scripts/test-invoice-reporting.sh']:
            self.assertIn(text,parent)
        workflow=(ROOT/'.github/workflows/ci.yml').read_text()
        self.assertIn('test-results/hr/invoice-report/*.json',workflow)
        self.assertNotIn('private-state.json',workflow)


if __name__=='__main__':unittest.main()
