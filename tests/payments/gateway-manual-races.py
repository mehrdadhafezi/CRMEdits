"""Real concurrent PHP workers, only on the disposable hosted integration VM."""
import json,os,pathlib,subprocess,time

def main():
    assert os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('RUNNER_ENVIRONMENT')=='github-hosted'
    assert os.environ.get('SN_APPROVAL_ALLOW_SYNTHETIC')=='YES' and os.geteuid()!=0
    root=pathlib.Path(os.environ['SN_APPROVAL_ROOT']).resolve()
    assert root.parent==pathlib.Path('/tmp') and root.name.startswith('sn-approval-') and not root.is_symlink()
    worker=pathlib.Path(__file__).with_name('gateway-manual-race.php').resolve()
    args=['php8.3','-d','memory_limit=256M','-d','sendmail_path=/bin/false','-d','zend.exception_ignore_args=1',str(worker)]
    def start(mode,key=''):return subprocess.Popen(args+[mode,key],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    def finish(process,label):
        out,err=process.communicate(timeout=35)
        (root/(label+'.gateway.private.stdout')).write_bytes(out);(root/(label+'.gateway.private.stderr')).write_bytes(err)
        if process.returncode:raise RuntimeError('Synthetic gateway worker failed: '+label)
        return json.loads(out)
    def run(mode,key=''):return finish(start(mode,key),mode+'-'+key)
    def reset(names):
        for name in names:
            for suffix in ['ready','go']:
                path=root/('gw240-'+name+'-'+suffix);assert not path.is_symlink()
                if path.exists():path.unlink()
    def wait(name,process):
        path=root/('gw240-'+name+'-ready');end=time.monotonic()+10
        while not path.exists():
            assert process.poll() is None and time.monotonic()<end,'Synthetic race barrier: '+name
            time.sleep(.03)
    def release(name):(root/('gw240-'+name+'-go')).write_text('go')
    assert run('fixtures')['success']
    reset(['switch','callback']);a=start('switch-pause','r1');wait('switch',a);b=start('callback-signal','r1');wait('callback',b);release('switch')
    assert finish(a,'r1-switch')['success'] and finish(b,'r1-callback')['pay_result']=='pending_finance'
    one=run('verify','r1');assert one['passed'];print('PASS native method selection / callback race')
    assert run('switch','r2')['success'];reset(['save','callback']);a=start('save-pause','r2');wait('save',a);b=start('callback-signal','r2');wait('callback',b);release('save')
    assert finish(a,'r2-save')['success'] and finish(b,'r2-callback')['pay_result']=='pending_finance'
    two=run('verify','r2');assert two['passed'];print('PASS native receipt commit / callback / Finance race')
    assert run('callback','r3')['pay_result']=='success' and not run('switch','r3')['success']
    three=run('verify','r3');assert three['passed'];print('PASS completed bank stage rejects stale manual choice')
    assert not run('switch-fail','f0')['success'];four=run('verify','f0');assert four['passed'];print('PASS native SQL failure rolls back all selected requests and audit')
    summary={'passed':True,'synthetic':True,'version':'2.0.240','assertions':sum(v['assertions'] for v in [one,two,three,four]),'real_parallel_php_processes':True,'cases':4,'actual_mysql_advisory_and_row_locks':True,'production_touched':False}
    output=pathlib.Path(os.environ['SN_APPROVAL_OUTPUT']).resolve();assert output.is_dir()
    (output/'gateway-manual-races.json').write_text(json.dumps(summary,indent=2)+'\n')
    print('TOTAL '+str(summary['assertions']))
if __name__=='__main__':main()
