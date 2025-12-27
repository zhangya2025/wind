<?php
/**
 * Plugin Name: Windhard Maintenance
 * Description: Maintenance mode guard with scope control.
 * Version: PR-ROLLBACK-MINIMAL-02
 * Author: Windhard
 * Text Domain: windhard-maintenance
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WINDHARD_MAINTENANCE_VERSION', 'PR-ROLLBACK-MINIMAL-02');
// MINIMAL_MAINTENANCE_BUILD=01
define('WINDHARD_MAINTENANCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WINDHARD_MAINTENANCE_PLUGIN_URL', plugin_dir_url(__FILE__));
require_once WINDHARD_MAINTENANCE_PLUGIN_DIR . 'includes/class-windhard-maintenance.php';
require_once WINDHARD_MAINTENANCE_PLUGIN_DIR . 'includes/class-windhard-maintenance-admin.php';
require_once WINDHARD_MAINTENANCE_PLUGIN_DIR . 'includes/class-windhard-maintenance-guard.php';

function windhard_maintenance_init() {
    $plugin = new Windhard_Maintenance();
    $plugin->run();
}
add_action('plugins_loaded', 'windhard_maintenance_init');
