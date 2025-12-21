<?php

if (!defined('ABSPATH')) {
    exit;
}

class Wind_Warehouse_Schema
{
    const SCHEMA_VERSION = '1.0.1';
    const OPTION_NAME = 'wh_schema_version';

    public static function maybe_upgrade_schema()
    {
        $installed_version = get_option(self::OPTION_NAME);
        if ($installed_version === self::SCHEMA_VERSION) {
            return;
        }

        self::create_tables();
        update_option(self::OPTION_NAME, self::SCHEMA_VERSION);
    }

    protected static function create_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $tables = self::get_table_schemas($wpdb->prefix, $charset_collate);

        foreach ($tables as $table_sql) {
            dbDelta($table_sql);
        }
    }

    protected static function get_table_schemas($prefix, $charset_collate)
    {
        $schemas = array();

        $schemas[] = "CREATE TABLE {$prefix}wh_skus (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sku_code VARCHAR(191) NOT NULL,
            name VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY sku_code (sku_code)
        ) {$charset_collate};";

        $schemas[] = "CREATE TABLE {$prefix}wh_dealers (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            dealer_code VARCHAR(191) NOT NULL,
            name VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY dealer_code (dealer_code)
        ) {$charset_collate};";

        $schemas[] = "CREATE TABLE {$prefix}wh_code_batches (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_no VARCHAR(191) NOT NULL,
            sku_id BIGINT(20) UNSIGNED DEFAULT NULL,
            generated_by BIGINT(20) UNSIGNED DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_no (batch_no),
            KEY sku_id (sku_id)
        ) {$charset_collate};";

        $schemas[] = "CREATE TABLE {$prefix}wh_codes (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(191) NOT NULL,
            sku_id BIGINT(20) UNSIGNED DEFAULT NULL,
            batch_id BIGINT(20) UNSIGNED DEFAULT NULL,
            dealer_id BIGINT(20) UNSIGNED DEFAULT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'in_stock',
            generated_at DATETIME DEFAULT NULL,
            shipped_at DATETIME DEFAULT NULL,
            internal_query_count INT UNSIGNED NOT NULL DEFAULT 0,
            consumer_query_count_lifetime INT UNSIGNED NOT NULL DEFAULT 0,
            consumer_query_offset INT UNSIGNED NOT NULL DEFAULT 0,
            last_consumer_query_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status_dealer_shipped (status, dealer_id, shipped_at),
            KEY generated_at (generated_at),
            KEY sku_id (sku_id),
            KEY batch_id (batch_id)
        ) {$charset_collate};";

        $schemas[] = "CREATE TABLE {$prefix}wh_shipments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            dealer_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_by BIGINT(20) UNSIGNED DEFAULT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'draft',
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY dealer_id (dealer_id)
        ) {$charset_collate};";

        $schemas[] = "CREATE TABLE {$prefix}wh_shipment_items (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_id BIGINT(20) UNSIGNED NOT NULL,
            code VARCHAR(191) NOT NULL,
            code_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY shipment_id (shipment_id),
            KEY code_id (code_id)
        ) {$charset_collate};";

        $schemas[] = "CREATE TABLE {$prefix}wh_events (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(100) NOT NULL,
            code VARCHAR(191) NOT NULL,
            code_id BIGINT(20) UNSIGNED DEFAULT NULL,
            ip VARCHAR(45) NOT NULL,
            counted TINYINT(1) NOT NULL DEFAULT 0,
            meta_before LONGTEXT DEFAULT NULL,
            meta_after LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY code_ip_created_at (code, ip, created_at),
            KEY event_type_created_at (event_type, created_at),
            KEY code_id (code_id)
        ) {$charset_collate};";

        return $schemas;
    }
}
