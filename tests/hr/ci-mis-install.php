<?php
require dirname(__DIR__).'/payments/integration/support.php';
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('SN_APPROVAL_ALLOW_SYNTHETIC')!=='YES'){throw new RuntimeException('Hosted disposable install required');}
$root=realpath((string)getenv('SN_APPROVAL_ROOT'));
if(!$root||!preg_match('#^/tmp/sn-approval-[A-Za-z0-9]+$#D',$root)||is_link($root)){throw new RuntimeException('Generated MIS root required');}
define('WP_INSTALLING',true);require $root.'/mis/wordpress/wp-load.php';global $wpdb;
if(!defined('SN_APPROVAL_TEST_SANDBOX')||DB_HOST!=='localhost'||!preg_match('/^sn_approval_[0-9]+_hrmis_test$/D',DB_NAME)
    ||$wpdb->get_var('SELECT DATABASE()')!==DB_NAME||$wpdb->prefix!=='wp_'||realpath(ABSPATH)!==$root.'/mis/wordpress'){throw new RuntimeException('Separate MIS install guard failed');}
require_once ABSPATH.'wp-admin/includes/upgrade.php';
$result=wp_install('Disposable MIS regression','mis_synthetic_admin','mis-admin@example.invalid',false,'',bin2hex(random_bytes(24)));
sn_test_ok(!is_wp_error($result),'separate disposable MIS WordPress installed without CRM activation');
