<?php
/** Separate fresh MIS database; load source after WP init, without plugin activation. */
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('SN_MIS_TEST_ALLOW_RESET')!=='YES'){throw new RuntimeException('Hosted disposable MIS sandbox required');}
$root=realpath((string)getenv('SN_APPROVAL_ROOT'));
if(!$root||!preg_match('#^/tmp/sn-approval-[A-Za-z0-9]+$#D',$root)||is_link($root)
    ||getenv('SN_MIS_TEST_BOOTSTRAP')!==__FILE__){throw new RuntimeException('Generated MIS bootstrap required');}
require_once $root.'/mis/wordpress/wp-load.php';global $wpdb;
if(!defined('SN_APPROVAL_TEST_SANDBOX')||SN_APPROVAL_TEST_SANDBOX!==true||DB_HOST!=='localhost'
    ||!preg_match('/^sn_approval_[0-9]+_hrmis_test$/D',DB_NAME)||$wpdb->prefix!=='wp_'
    ||$wpdb->get_var('SELECT DATABASE()')!==DB_NAME||realpath(ABSPATH)!==$root.'/mis/wordpress'
    ||get_option('active_plugins',[])!==[]){throw new RuntimeException('Separate minimal MIS database guard failed');}
require_once dirname(__DIR__,2).'/sales-network.php';sn_bootstrap_load_core();
if(SN_VERSION!=='2.0.247'){throw new RuntimeException('Reviewed MIS candidate required');}
// MIS fixtures exercise their legacy minimum schema. Activation/full init would
// silently replace it with invoice tables that have a different fixture contract.
$method=new ReflectionMethod(SN_Activator::class,'register_roles');$method->setAccessible(true);$method->invoke(null);
$wpdb->hide_errors();
