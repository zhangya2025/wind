<?php

if (!defined('ABSPATH')) {
    exit;
}

class Wind_Warehouse_Plugin
{
    const VERSION = '0.1.0';

    public static function init()
    {
        add_action('plugins_loaded', array(__CLASS__, 'maybe_upgrade_schema'));
    }

    public static function activate()
    {
        self::maybe_upgrade_schema();
    }

    public static function deactivate()
    {
        // No actions needed on deactivation for now.
    }

    public static function maybe_upgrade_schema()
    {
        Wind_Warehouse_Schema::maybe_upgrade_schema();
    }
}
