"""Real concurrent public callbacks; disposable synthetic WordPress only."""
import os,pathlib,re,subprocess,json,sys
ci=os.environ.get('SN_APPROVAL_ALLOW_SYNTHETIC')=='YES'
root=pathlib.Path(os.environ.get('SN_APPROVAL_ROOT')if ci else os.environ.get('SN_HR239_ROOT',''))
assert root.is_absolute()and root.resolve()==root and not root.is_symlink()
if ci:assert os.environ.get('GITHUB_ACTIONS')=='true'and os.environ.get('RUNNER_ENVIRONMENT')=='github-hosted'and re.fullmatch('/tmp/sn-approval-[A-Za-z0-9]{8}',str(root))
else:assert os.geteuid()==1073 and re.fullmatch('/home/crmstg1/private/hr239-[a-z0-9_]{8}',str(root))and not pathlib.Path('/home/payasetareh').exists()
php='/usr/bin/php8.3'if ci else'/opt/cpanel/ea-php83/root/usr/bin/php'
worker=pathlib.Path(__file__).with_name('staged-gateway-race.php');assert worker.is_file()and not worker.is_symlink()
def start(op):
 return subprocess.Popen(['/usr/bin/setpriv','--no-new-privs',php,'-d','memory_limit=256M','-d','display_errors=0','-d','log_errors=1','-d','error_log='+str(root/'staged243-race.private.log'),'-d','sendmail_path=/bin/false','-d','disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,mail,dl',str(worker)],env=dict(os.environ,SN_STAGED243_RACE_OP=op),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
def finish(p,label):
 try:out,err=p.communicate(timeout=60)
 except subprocess.TimeoutExpired:p.kill();p.communicate();raise RuntimeError('Synthetic callback worker timed out')
 (root/('staged243-'+label+'.stdout.private')).write_bytes(out);(root/('staged243-'+label+'.stderr.private')).write_bytes(err)
 assert p.returncode==0,'Synthetic callback worker failed';return out.decode()
one=start('callback');two=start('callback');results=[json.loads(finish(one,'one')),json.loads(finish(two,'two'))]
assert all(x['result']=='partial_success'and x['version']=='2.0.244'for x in results)
print('PASS both concurrent verified public callbacks completed',flush=True)
lines=finish(start('verify'),'verify').splitlines();labels=[x for x in lines if x.startswith('PASS ')];assert len(labels)==5 and'TOTAL 5'in lines
for line in labels:print(line,flush=True)
out=pathlib.Path(os.environ.get('SN_APPROVAL_OUTPUT',str(root/'evidence')))
summary={'passed':True,'synthetic':True,'version':'2.0.244','assertions':6,'real_parallel_php_processes':True,'actual_public_callbacks':True,'real_provider_calls':0,'production_touched':False}
(out/'staged243-races.json').write_text(json.dumps(summary,indent=2)+'\n');print('SUMMARY '+json.dumps(summary));print('TOTAL 6')
