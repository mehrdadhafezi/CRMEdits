"""Run real isolated PHP processes for the traceability races/public security suite."""
import hashlib,json,os,pathlib,re,shutil,subprocess,sys

def require(ok,label):
 if not ok:raise RuntimeError(label)

ci=os.environ.get('SN_APPROVAL_ALLOW_SYNTHETIC')=='YES'
root=pathlib.Path(os.environ.get('SN_APPROVAL_ROOT') if ci else os.environ.get('SN_HR239_ROOT',''))
require(root.is_absolute() and root.resolve()==root and not root.is_symlink(),'Explicit owned test root')
if ci:
 require(os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('RUNNER_ENVIRONMENT')=='github-hosted' and re.fullmatch(r'/tmp/sn-approval-[A-Za-z0-9]{8}',str(root)),'Hosted disposable runtime required')
else:
 require(os.geteuid()==1073 and re.fullmatch(r'/home/crmstg1/private/hr239-[a-z0-9_]{8}',str(root)) and not pathlib.Path('/home/payasetareh').exists(),'Private synthetic runtime required')
 guard=json.loads((root/'guard.json').read_text());require(hashlib.sha256((root/'wordpress/wp-config.php').read_bytes()).hexdigest()==guard['config_sha256'],'Guarded config')
php=os.environ.get('SN_TRACE_PHP_BIN','/usr/bin/php8.3' if ci else '/opt/cpanel/ea-php83/root/usr/bin/php')
require(php in ['/usr/bin/php8.3','/usr/bin/php8.1','/opt/cpanel/ea-php83/root/usr/bin/php','/opt/cpanel/ea-php81/root/usr/bin/php'] and pathlib.Path(php).is_file(),'Approved PHP CLI only')
output=pathlib.Path(os.environ.get('SN_APPROVAL_OUTPUT',str(root/'evidence')));require(output.is_dir() and not output.is_symlink(),'Prepared output directory')
tests=pathlib.Path(__file__).resolve().parent

def start(file,extra):
 p=tests/file;require(p.is_file() and not p.is_symlink(),'Reviewed worker file')
 return subprocess.Popen(['/usr/bin/setpriv','--no-new-privs',php,'-d','memory_limit=256M','-d','max_execution_time=45','-d','display_errors=0','-d','log_errors=1','-d','error_log='+str(root/'trace242-process-error.private.log'),'-d','sendmail_path=/bin/false','-d','disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,mail,dl',str(p)],env=dict(os.environ,**extra),stdout=subprocess.PIPE,stderr=subprocess.PIPE)

def finish(process,label):
 try:out,err=process.communicate(timeout=60)
 except subprocess.TimeoutExpired:process.kill();process.communicate();raise RuntimeError('Synthetic worker timeout')
 (root/('trace242-process-'+label+'.stdout.private')).write_bytes(out);(root/('trace242-process-'+label+'.stderr.private')).write_bytes(err)
 require(process.returncode==0,'Synthetic worker failed: '+label);return out.decode()

checks=0
def ok(value,label):
 global checks
 require(value,label);checks+=1;print('PASS '+label,flush=True)

mode=sys.argv[1] if len(sys.argv)==2 else ''
if mode=='race':
 file='traceability-race.php'
 ok(json.loads(finish(start(file,{'SN_TRACE_RACE_OP':'prepare'}),'prepare'))['prepared'],'fresh race fixture prepared')
 c1=start(file,{'SN_TRACE_RACE_OP':'commission'});c2=start(file,{'SN_TRACE_RACE_OP':'commission'})
 credits=[json.loads(finish(c1,'commission-one')),json.loads(finish(c2,'commission-two'))]
 ok(all(not r['blocked'] for r in credits) and sorted(r['created'] for r in credits)==[0,1],'simultaneous commission retries credit once')
 p1=start(file,{'SN_TRACE_RACE_OP':'issue'});p2=start(file,{'SN_TRACE_RACE_OP':'issue'})
 results=[json.loads(finish(p1,'issue-one'))['success'],json.loads(finish(p2,'issue-two'))['success']]
 ok(sorted(results)==[False,True],'simultaneous next-stage requests advance once')
 lines=finish(start(file,{'SN_TRACE_RACE_OP':'verify'}),'race-verify').splitlines();labels=[x for x in lines if x.startswith('PASS ')]
 ok(len(labels)==6 and 'TOTAL 6' in lines,'all financial and identity race invariants verified')
 for line in labels:checks+=1;print(line)
 summary={'passed':True,'synthetic':True,'assertions':checks,'cases':2,'real_parallel_php_processes':True,'actual_mysql_advisory_and_row_locks':True,'production_touched':False}
 name='trace242-races.json'
elif mode=='public-security':
 for case in ['guest_missing','guest_forged','guest_valid','guest_nonce','foreign_forged','hr_forged']:
  raw=finish(start('traceability-public-security.php',{'SN_TRACE_SECURITY_CASE':case}),'public-'+case);reply=json.loads(raw)
  meta=json.loads((root/('trace242-public-'+case+'.json')).read_text())
  ok(bool(reply.get('success'))==(case=='guest_valid'),'public token/access decision '+case)
  ok(meta['unchanged'] is True,'public controller is read-only '+case)
  ok('"access_token"' not in raw and '"authority"' not in raw,'public trace omits credential fields '+case)
  def strings(value):
   if isinstance(value,str):yield value
   elif isinstance(value,dict):
    for v in value.values():yield from strings(v)
   elif isinstance(value,list):
    for v in value:yield from strings(v)
  ok(all(hashlib.sha256(x.encode()).hexdigest()!=meta['token_hash'] for x in strings(reply)),'public response never echoes its invoice token '+case)
 summary={'passed':True,'synthetic':True,'assertions':checks,'cases':6,'real_php_controller_requests':True,'production_touched':False};name='trace242-public-security.json'
else:raise RuntimeError('Explicit synthetic process mode required')
native=json.loads((output/'trace242-native.json').read_text());require(native['version']=='2.0.245' and native['passed'] is True,'Matching native candidate evidence required')
summary.update(version=native['version'],core_sha256=native['core_sha256'],commission_sha256=native['commission_sha256'])
(output/name).write_text(json.dumps(summary,indent=2)+'\n');print('SUMMARY '+json.dumps(summary));print('TOTAL '+str(checks))
