<?php
/**
 * Plugin Name: Wind Warehouse System
 * Description: Provides warehouse and anti-counterfeit data structures.
 * Version: 0.1.0
 * Author: Wind
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/db/class-wind-warehouse-schema.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wind-warehouse-plugin.php';

Wind_Warehouse_Plugin::init();

register_activation_hook(__FILE__, array('Wind_Warehouse_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('Wind_Warehouse_Plugin', 'deactivate'));
