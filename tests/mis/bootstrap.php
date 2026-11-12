<?php
/** CLI only. This suite resets MIS tables in an explicitly approved disposable database. */
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
if (getenv('SN_MIS_TEST_ALLOW_RESET')!=='YES') { throw new RuntimeException('Set SN_MIS_TEST_ALLOW_RESET=YES for a disposable test database only.'); }
$bootstrap=getenv('SN_MIS_TEST_BOOTSTRAP');
if (!$bootstrap || !is_file($bootstrap)) { throw new RuntimeException('SN_MIS_TEST_BOOTSTRAP must identify a test WordPress wp-load.php or test adapter bootstrap.'); }
require_once $bootstrap;
global $wpdb;
$database=(string)$wpdb->get_var('SELECT DATABASE()');
if (!preg_match('/_test$/',$database)) { throw new RuntimeException('The database name must end with _test. Refusing to reset '.$database); }
add_filter('pre_wp_mail',static fn()=>true);
add_filter('pre_http_request',static fn()=>new WP_Error('sn_mis_test_network_disabled','External HTTP disabled during MIS tests'));
wp_installing(false); wp_set_current_user(1);
$plugin_root=dirname(__DIR__,2);
if (!defined('SN_PLUGIN_URL')) { define('SN_PLUGIN_URL',plugins_url('/', $plugin_root.'/sales-network.php')); }
if (!defined('SN_VERSION')) { define('SN_VERSION','2.0.194-mis-ux-rc1'); }
foreach(['class-sn-helpers.php','class-sn-migration-service.php','class-sn-plugin.php','class-sn-hr-service.php','class-sn-hierarchy-service.php','class-sn-scope-service.php'] as $file) { require_once $plugin_root.'/includes/'.$file; }
require_once ABSPATH.'wp-admin/includes/upgrade.php';
$migration=new SN_Migration_Service();
foreach(['create_hr_tables','create_hierarchy_tables','create_mis_tables','create_distribution_engine_tables'] as $name) { $method=new ReflectionMethod($migration,$name); $method->setAccessible(true); $method->invoke($migration); }
// Small synthetic sales tables are created only when missing in the disposable test DB.
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sn_leads (id bigint unsigned NOT NULL AUTO_INCREMENT,phone varchar(50),seller_id bigint unsigned,status varchar(60),lead_status varchar(60),PRIMARY KEY(id),KEY phone(phone)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sn_invoices (id bigint unsigned NOT NULL AUTO_INCREMENT,lead_id bigint unsigned,status varchar(60),PRIMARY KEY(id),KEY lead_id(lead_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$plugin=new SN_Plugin(); $method=new ReflectionMethod($plugin,'sn_distribution_ensure_seller_inline_columns'); $method->setAccessible(true); $method->invoke($plugin);
$mis=new SN_MIS_Service($wpdb,1); $mis->install();
function sn_mis_test_new_connection() { global $wpdb; $class=get_class($wpdb); $db=new $class(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST); $db->set_prefix($wpdb->prefix); return $db; }
function sn_mis_test_write(string $name,$value): void {
    $directory=getenv('SN_MIS_TEST_OUTPUT') ?: sys_get_temp_dir().'/sn-mis-test-results';
    if (!is_dir($directory) && !mkdir($directory,0700,true)) { throw new RuntimeException('Cannot create test result directory.'); }
    file_put_contents($directory.'/'.$name,is_string($value)?$value:json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
