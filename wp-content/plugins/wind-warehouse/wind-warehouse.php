<?php
/**
 * Plugin Name: Wind Warehouse
 * Description: Warehouse and anti-counterfeit system bootstrap with schema management, roles, wp-admin isolation, and login redirects.
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/db/class-wind-warehouse-schema.php';
require_once __DIR__ . '/includes/class-wind-warehouse-settings.php';
require_once __DIR__ . '/includes/class-wind-warehouse-portal.php';
require_once __DIR__ . '/includes/class-wind-warehouse-query.php';
require_once __DIR__ . '/includes/class-wind-warehouse-plugin.php';

Wind_Warehouse_Plugin::init(__FILE__);