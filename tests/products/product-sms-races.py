"""Real concurrent SMS claims on a generated synthetic site; never a customer clone."""
import json,os,pathlib,re,subprocess,time


def main():
    assert os.geteuid()!=0
    if os.environ.get('SN_APPROVAL_ALLOW_SYNTHETIC')=='YES':
        assert os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('RUNNER_ENVIRONMENT')=='github-hosted'
        root=pathlib.Path(os.environ['SN_APPROVAL_ROOT']).resolve()
        assert root.parent==pathlib.Path('/tmp') and re.fullmatch('sn-approval-[a-zA-Z0-9]{8}',root.name)
        output=pathlib.Path(os.environ['SN_APPROVAL_OUTPUT']).resolve();php='php8.3'
    else:
        assert os.environ.get('SN_HR239_SYNTHETIC')=='YES' and os.geteuid()==1073
        root=pathlib.Path(os.environ['SN_HR239_ROOT']).resolve()
        assert root.parent==pathlib.Path('/home/crmstg1/private') and re.fullmatch('hr239-[a-z0-9_]{8}',root.name)
        assert not pathlib.Path('/home/payasetareh').exists()
        output=root/'evidence';php='/opt/cpanel/ea-php83/root/usr/bin/php'
    assert root.is_dir() and not root.is_symlink() and output.is_dir()
    worker=pathlib.Path(__file__).with_name('product-sms-race.php').resolve()
    args=[php,'-d','memory_limit=256M','-d','sendmail_path=/bin/false','-d','zend.exception_ignore_args=1',str(worker)]
    children=[]
    def start(mode,key=''):
        p=subprocess.Popen(args+[mode,key],stdout=subprocess.PIPE,stderr=subprocess.PIPE);children.append(p);return p
    def finish(p,label):
        out,err=p.communicate(timeout=25)
        (root/(label+'.sms.private.stdout')).write_bytes(out);(root/(label+'.sms.private.stderr')).write_bytes(err)
        if p.returncode:raise RuntimeError('Synthetic SMS worker failed: '+label)
        return json.loads(out)
    def run(mode,key=''):return finish(start(mode,key),mode+'-'+key)
    def reset(name):
        for suffix in ['ready','go']:
            p=root/('p241-'+name+'-'+suffix);assert not p.is_symlink()
            if p.exists():p.unlink()
    def wait(name,p):
        target=root/('p241-'+name+'-ready');end=time.monotonic()+12
        while not target.exists():
            assert p.poll() is None and time.monotonic()<end,'Synthetic SMS barrier: '+name
            time.sleep(.03)
    def release(name):(root/('p241-'+name+'-go')).write_text('go')
    prepared=False
    try:
        assert run('fixtures')['success'];prepared=True;reset('provider')
        a=start('send-pause','overlap');wait('provider',a);second=run('compete','overlap')
        assert not second['sent'] and second['busy'];release('provider');assert finish(a,'overlap-first')['sent']
        one=run('verify','overlap');assert one['passed'];print('PASS real overlapping dispatch and creation claim send once')
        reset('reader');a=start('stale-reader','stale');wait('reader',a);assert run('send','stale')['sent'];release('reader')
        second=finish(a,'stale-reader');assert not second['sent'] and second['state']=='skipped'
        two=run('verify','stale');assert two['passed'];print('PASS stale WordPress option cache cannot duplicate accepted SMS')
        summary={'passed':True,'synthetic':True,'version':'2.0.247','assertions':one['assertions']+two['assertions']+2,'cases':2,
                 'real_parallel_php_processes':True,'actual_mysql_advisory_locks':True,'stale_cache_control':True,'production_touched':False,'real_sms_sent':False}
        (output/'product241-races.json').write_text(json.dumps(summary,indent=2)+'\n');print('SUMMARY '+json.dumps(summary));print('TOTAL '+str(summary['assertions']))
    finally:
        for p in children:
            if p.poll() is None:p.kill();p.communicate()
        if prepared:assert run('restore')['success']


if __name__=='__main__':main()
