"""Rehearse both verified tracks and full backup restore in fresh synthetic namespaces only."""
import hashlib,json,os,pathlib,re,secrets,shutil,stat,subprocess,sys,tempfile,zipfile

BASE='dac9617874b5d0a53e7f7b5c1fde3e8066402761';EXPERIMENTAL='3f544028bf83b641caade9e5859aa4efd4b3769a'
def need(ok,label):
 if not ok:raise RuntimeError(label)
def sha(body):return hashlib.sha256(body).hexdigest()
ci=os.environ.get('SN_APPROVAL_ALLOW_SYNTHETIC')=='YES'
source=pathlib.Path(os.environ.get('SN_APPROVAL_ROOT') if ci else os.environ.get('SN_HR239_ROOT',''))
need(source.is_absolute() and source.resolve()==source and not source.is_symlink(),'Explicit source sandbox')
if ci:need(os.environ.get('GITHUB_ACTIONS')=='true' and os.environ.get('RUNNER_ENVIRONMENT')=='github-hosted' and re.fullmatch('/tmp/sn-approval-[A-Za-z0-9]{8}',str(source)),'Hosted sandbox only')
else:need(os.geteuid()==1073 and re.fullmatch('/home/crmstg1/private/hr239-[a-z0-9_]{8}',str(source)) and not pathlib.Path('/home/payasetareh').exists(),'Private synthetic account only')
need(len(sys.argv)==2,'Pinned lifecycle input required');input_path=pathlib.Path(sys.argv[1]).resolve();profiles=json.loads(input_path.read_text())['profiles']
need(set(profiles)=={'main','experimental','candidate'} and profiles['main']['commit']==BASE and profiles['experimental']['commit']==EXPERIMENTAL and profiles['candidate']['version']=='2.0.242','Verified tracks only')
if ci:need(profiles['candidate']['commit']==os.environ.get('GITHUB_SHA') and profiles['candidate'].get('uncommitted') is not True,'Exact CI merge candidate only')
cfg=(source/'wordpress/wp-config.php').read_text()
need('CUSTOM_USER_TABLE' not in cfg and 'CUSTOM_USER_META_TABLE' not in cfg,'No shared WordPress identity tables')
def literal(name):
 values=re.findall(r"define\(\s*'"+name+r"'\s*,\s*'([^']*)'\s*\)",cfg);need(len(values)==1,'Literal synthetic DB config');return values[0]
dbname,user,password,host=[literal(k) for k in ['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST']]
need(re.fullmatch('[a-f0-9]{48,64}',password),'Generated synthetic credential only')
if ci:need(re.fullmatch('sn_approval_[a-z0-9_]+_test',dbname) and user=='sn_approval' and host=='localhost','Disposable CI database only')
else:need(dbname=='crmstg1_restore237' and user=='crmstg1_rst237' and host=='localhost:/var/lib/mysql/mysql.sock','Private restore database only')
php='/usr/bin/php8.3' if ci else '/opt/cpanel/ea-php83/root/usr/bin/php';need(pathlib.Path(php).is_file(),'Approved PHP CLI required')
tests=pathlib.Path(__file__).resolve().parent;output=pathlib.Path(os.environ.get('SN_APPROVAL_OUTPUT',str(source/'evidence')));need(output.is_dir(),'Prepared evidence directory')
os.umask(0o077);fd,cnf_name=tempfile.mkstemp(prefix='trace242-client-',dir=str(source));os.close(fd);cnf=pathlib.Path(cnf_name)
cnf.write_text('[client]\nuser='+user+'\npassword='+password+'\nprotocol=socket\n'+('socket=/var/lib/mysql/mysql.sock\n' if not ci else ''));os.chmod(str(cnf),0o600)
counter=0;current=source;checks=0
def run(argv,label,payload=None,env=None,timeout=120):
 global counter
 counter+=1;p=subprocess.run(argv,input=payload,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout)
 (current/('life-%03d-%s.stderr.private'%(counter,label))).write_bytes(p.stderr)
 need(p.returncode==0,'Lifecycle command failed: '+label);return p.stdout
def mysql(sql,label='read',payload=None):
 args=['/usr/bin/mysql','--defaults-file='+str(cnf),'--batch','--raw','--skip-column-names',dbname]
 return run(args+(['-e',sql] if payload is None else []),label,payload).decode().strip()
def names(prefix=None):
 rows=mysql('SHOW TABLES').splitlines();need(all(re.fullmatch('[A-Za-z0-9_]+',x) for x in rows),'Safe generated table identifiers');return sorted(x for x in rows if prefix is None or x.startswith(prefix))
def dump(tables,data=True):
 need(tables and all(re.fullmatch('[A-Za-z0-9_]+',n) for n in tables),'Explicit table set')
 args=['/usr/bin/mysqldump','--defaults-file='+str(cnf),'--single-transaction','--skip-lock-tables','--skip-comments','--skip-add-locks','--skip-add-drop-table','--hex-blob','--order-by-primary','--no-tablespaces']
 if not data:args+=['--no-data']
 return run(args+[dbname]+tables,'dump',timeout=120)
def fingerprint(tables):
 raw=mysql('CHECKSUM TABLE '+','.join('`'+x+'`' for x in tables)+' EXTENDED','checksums');lines=raw.splitlines();need(len(lines)==len(tables) and all(re.fullmatch(r'[^\t]+\t[0-9]+',line) for line in lines),'Actual table checksums required')
 return {'schema':sha(dump(tables,False)),'rows':sha(raw.encode())}
def ok(value,label):
 global checks
 need(value,label);checks+=1;print('PASS '+label,flush=True)
def extract(profile,directory):
 archive=input_path.parent/profile['file'];need(archive.parent.resolve()==input_path.parent and not archive.is_symlink() and sha(archive.read_bytes())==profile['sha256'],'Verified archive')
 directory.mkdir(mode=0o700,parents=True);seen={}
 with zipfile.ZipFile(archive)as z:
  for item in z.infolist():
   p=pathlib.PurePosixPath(item.filename);need(not p.is_absolute() and len(p.parts)>1 and p.parts[0]=='sales-network-v4' and '..' not in p.parts and not item.is_dir() and stat.S_IFMT(item.external_attr>>16)!=stat.S_IFLNK and item.file_size<16*1024*1024,'Runtime member')
   name='/'.join(p.parts[1:]);body=z.read(item);need(name not in seen and profile['members'].get(name)==sha(body),'Exact member identity');seen[name]=sha(body);dest=directory/name;dest.parent.mkdir(mode=0o700,parents=True,exist_ok=True);dest.write_bytes(body);os.chmod(str(dest),0o600)
 need(seen==profile['members'],'Exact archive member set')
def swap(site,profile):
 target=site/'wordpress/wp-content/plugins/sales-network-v4';need(target.resolve()==target and target.is_dir(),'Owned runtime swap')
 target.rename(site/('runtime-preserved-'+secrets.token_hex(4)));extract(profile,target)
def php_run(site,file,label,phase=None):
 env=dict(os.environ,SN_HR239_ROOT=str(site),SN_HR239_SYNTHETIC='YES')
 if ci:env.update(SN_APPROVAL_ROOT=str(site),SN_PAYMENT_TEST_BOOTSTRAP=str(site/'wordpress/wp-load.php'),SN_APPROVAL_OUTPUT=str(site/'evidence'))
 if phase:env['SN_TRACE_LIFECYCLE_PHASE']=phase
 raw=run(['/usr/bin/setpriv','--no-new-privs',php,'-d','memory_limit=256M','-d','max_execution_time=90','-d','display_errors=0','-d','log_errors=1','-d','error_log='+str(site/'php-error.private.log'),'-d','sendmail_path=/bin/false','-d','disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,mail,dl',str(file)],label,env=env,timeout=120)
 (site/(label+'.stdout.private')).write_bytes(raw)
 return raw.decode(errors='replace')
def phase(site,value):
 global checks
 text=php_run(site,tests/'traceability-lifecycle.php',value,value);summary=[json.loads(x[8:]) for x in text.splitlines() if x.startswith('SUMMARY ')][-1]
 need(summary['passed'] and summary['phase']==value and type(summary['assertions']) is int and summary['assertions']>0,'Actual lifecycle phase');checks+=summary['assertions'];return summary
def fresh(profile):
 site=pathlib.Path(tempfile.mkdtemp(prefix='sn-approval-',dir='/tmp')) if ci else pathlib.Path('/home/crmstg1/private')/('hr239-'+secrets.token_hex(4))
 if not ci:site.mkdir(mode=0o700)
 need(site.resolve()==site and site.stat().st_uid==os.geteuid(),'Fresh owned namespace')
 doc=site/'wordpress';doc.mkdir(mode=0o700);origin=source/'wordpress'
 for p in origin.iterdir():
  if p.name in ['wp-content','wp-config.php'] or p.is_symlink():continue
  if p.is_dir():shutil.copytree(p,doc/p.name)
  elif p.is_file():shutil.copy2(p,doc/p.name)
 prefix='h239_'+secrets.token_hex(4)+'_';need(not names(prefix),'Unused prefix')
 plugins=doc/'wp-content/plugins';plugins.mkdir(mode=0o700,parents=True)
 woo=origin/'wp-content/plugins/woocommerce';need(woo.is_dir() and not any(p.is_symlink() for p in woo.rglob('*')),'Approved Woo code');shutil.copytree(woo,plugins/'woocommerce')
 themes=origin/'wp-content/themes'
 if themes.is_dir():shutil.copytree(themes,doc/'wp-content/themes')
 mu=doc/'wp-content/mu-plugins';mu.mkdir(mode=0o700)
 (mu/'trace242-safety.php').write_text("<?php if(PHP_SAPI!=='cli'||posix_geteuid()!=="+str(os.geteuid())+")exit(71);add_filter('pre_http_request',static fn()=>new WP_Error('synthetic_http','Synthetic HTTP disabled'),1);add_filter('pre_wp_mail',static fn()=>false);\n")
 config="<?php\n"
 for key,value in [('DB_NAME',dbname),('DB_USER',user),('DB_PASSWORD',password),('DB_HOST',host),('DB_CHARSET','utf8mb4'),('DB_COLLATE','')]:config+="define('"+key+"','"+value+"');\n"
 for key in ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']:config+="define('"+key+"','"+secrets.token_hex(32)+"');\n"
 config+="$table_prefix='"+prefix+"';define('SN_HR239_PRIVATE_TEST',true);define('SN_APPROVAL_TEST_SANDBOX',true);define('SN_GATEWAY240_PRIVATE_TEST',true);define('SN_PRODUCT241_PRIVATE_TEST',true);define('WP_ENVIRONMENT_TYPE','local');define('WP_HOME','https://trace242.example.invalid');define('WP_SITEURL','https://trace242.example.invalid');define('DISABLE_WP_CRON',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('AUTOMATIC_UPDATER_DISABLED',true);define('WP_AUTO_UPDATE_CORE',false);define('DISALLOW_FILE_MODS',true);define('WP_DEBUG',false);define('WP_FATAL_ERROR_HANDLER_ENABLED',false);\n"
 config+="if(PHP_SAPI!=='cli'||posix_geteuid()!=="+str(os.geteuid())+"||realpath(__DIR__)!=="+repr(str(doc))+"||file_exists('/home/payasetareh'))exit(73);if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';\n"
 (doc/'wp-config.php').write_text(config);os.chmod(str(doc/'wp-config.php'),0o600);(site/'evidence').mkdir(mode=0o700)
 (site/'guard.json').write_text(json.dumps({'database':dbname,'prefix':prefix,'config_sha256':sha((doc/'wp-config.php').read_bytes())}))
 extract(profile,plugins/'sales-network-v4')
 install=site/'install.php';install.write_text("<?php define('WP_INSTALLING',true);require __DIR__.'/wordpress/wp-load.php';require_once ABSPATH.'wp-admin/includes/upgrade.php';require_once ABSPATH.'wp-admin/includes/plugin.php';$r=wp_install('Synthetic lifecycle','trace_admin','admin@example.invalid',false,'',bin2hex(random_bytes(24)));if(is_wp_error($r))exit(2);$a=activate_plugin('sales-network-v4/sales-network.php');if(is_wp_error($a))exit(3);$a=activate_plugin('woocommerce/woocommerce.php');if(is_wp_error($a))exit(4);update_option('timezone_string','Asia/Tehran');echo 'SYNTHETIC_INSTALLED';")
 php_run(site,install,'install');return site,prefix

profiles_done=[]
try:
 before_names=names();before=fingerprint(before_names)
 for track in ['main','experimental']:
  baseline=profiles[track];need(baseline['file']=='trace242-'+track+'.zip','Fixed baseline archive name')
  site,prefix=fresh(baseline);current=site;baseline_phase='baseline241' if track=='main' else 'baseline235'
  phase(site,baseline_phase);base_names=names(prefix);base_fingerprint=fingerprint(base_names);backup=dump(base_names);backup_path=site/'baseline-backup.private.sql';backup_path.write_bytes(backup);os.chmod(str(backup_path),0o600)
  ok(bool(backup) and sha(backup_path.read_bytes())==sha(backup),track+' private consistent baseline backup verified')
  if track=='experimental':swap(site,profiles['main']);phase(site,'bridge')
  swap(site,profiles['candidate']);phase(site,'upgrade');ok(True,track+' legacy identities schema and roles survive candidate upgrade')
  php_run(site,tests.parent/'hr/native-fixtures.php','new-fixtures');text=php_run(site,tests/'traceability-native.php','new-financial-flow');need('TOTAL 50' in text.splitlines(),'New receipt financial invariants');checks+=50
  phase(site,'snapshot');swap(site,profiles['main']);phase(site,'downgrade');ok(True,track+' code swap preserves new financial data without using old code to continue purchases')
  swap(site,profiles['candidate']);phase(site,'resume');ok(True,track+' candidate resume preserves receipts commissions and roles')
  targets=names(prefix);need(targets and all(x.startswith(prefix) and re.fullmatch('h239_[a-f0-9]{8}_[A-Za-z0-9_]+',x) for x in targets),'Generated private namespace reset only')
  # These are freshly generated synthetic tables only, never an existing site/clone.
  mysql('SET FOREIGN_KEY_CHECKS=0; DROP TABLE '+','.join('`'+x+'`' for x in targets)+'; SET FOREIGN_KEY_CHECKS=1','synthetic-restore-reset')
  mysql('',label='synthetic-restore-import',payload=backup)
  ok(names(prefix)==base_names and fingerprint(base_names)==base_fingerprint,track+' full backup restores every generated baseline table exactly')
  swap(site,baseline);phase(site,'restore241' if track=='main' else 'restore235');ok(True,track+' old plugin and restored database agree')
  if track=='experimental':swap(site,profiles['main']);phase(site,'bridge')
  swap(site,profiles['candidate']);phase(site,'reupgrade');ok(True,track+' repeated upgrade after full restore is idempotent')
  profiles_done.append({'track':track,'baseline':baseline['commit'],'passed':True,'fresh_namespace':True,'full_restore':True})
 current=source;ok(fingerprint(before_names)==before,'every preexisting table outside both generated namespaces is preserved')
 summary={'passed':True,'synthetic':True,'assertions':checks,'version':'2.0.242','baseline_shas':{'main':BASE,'experimental':EXPERIMENTAL},'profiles':profiles_done,'fresh_namespaces':True,'legacy_identities_preserved':True,'new_financial_state_preserved_on_code_swap':True,'full_backup_restore_verified':True,'outside_tables_preserved':True,'code_only_business_rollback_supported':False,'production_touched':False}
 summary.update(candidate_commit=profiles['candidate']['commit'],core_sha256=profiles['candidate']['members']['includes/class-sn-plugin.php'],commission_sha256=profiles['candidate']['members']['includes/class-sn-purpose-commission.php'])
 (output/'trace242-lifecycle.json').write_text(json.dumps(summary,indent=2)+'\n');print('SUMMARY '+json.dumps(summary));print('TOTAL '+str(checks))
finally:
 if cnf.is_file() and not cnf.is_symlink():cnf.unlink()
