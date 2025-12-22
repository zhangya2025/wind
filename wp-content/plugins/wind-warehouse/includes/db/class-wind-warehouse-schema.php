<?php
if (!defined('ABSPATH')) {
    exit;
}

class Wind_Warehouse_Schema {
    const SCHEMA_VERSION = '1.0.4';
    const OPTION_NAME = 'wh_schema_version';

    public static function maybe_upgrade_schema(): void {
        $installed_version = get_option(self::OPTION_NAME);
        if ($installed_version === self::SCHEMA_VERSION) {
            return;
        }

        self::create_tables();
        update_option(self::OPTION_NAME, self::SCHEMA_VERSION);
    }

    private static function create_tables(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $tables = [];

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_skus (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sku_code varchar(191) NOT NULL,
            name varchar(255) NOT NULL DEFAULT '',
            status varchar(50) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY sku_code (sku_code)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_dealers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dealer_code varchar(191) NOT NULL,
            name varchar(255) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY dealer_code (dealer_code)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_code_batches (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            batch_no varchar(191) NOT NULL,
            sku_id bigint(20) unsigned NOT NULL,
            quantity int(11) unsigned NOT NULL DEFAULT 0,
            qty int(11) unsigned DEFAULT NULL,
            note varchar(255) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'created',
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_no (batch_no),
            KEY sku_id (sku_id),
            KEY status (status),
            KEY created_by (created_by)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_codes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(191) NOT NULL,
            sku_id bigint(20) unsigned DEFAULT NULL,
            dealer_id bigint(20) unsigned DEFAULT NULL,
            batch_id bigint(20) unsigned DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'in_stock',
            internal_query_count bigint(20) unsigned NOT NULL DEFAULT 0,
            consumer_query_count_lifetime bigint(20) unsigned NOT NULL DEFAULT 0,
            consumer_query_offset bigint(20) unsigned NOT NULL DEFAULT 0,
            last_consumer_query_at datetime DEFAULT NULL,
            generated_at datetime DEFAULT NULL,
            shipped_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status_dealer_shipped (status, dealer_id, shipped_at),
            KEY generated_at (generated_at),
            KEY sku_id (sku_id),
            KEY batch_id (batch_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_shipments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dealer_id bigint(20) unsigned DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            shipped_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_shipment_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shipment_id bigint(20) unsigned DEFAULT NULL,
            code_id bigint(20) unsigned DEFAULT NULL,
            code varchar(191) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL DEFAULT '',
            code varchar(191) NOT NULL,
            code_id bigint(20) unsigned DEFAULT NULL,
            ip varchar(45) NOT NULL,
            counted tinyint(1) NOT NULL DEFAULT 0,
            meta_before longtext,
            meta_after longtext,
            meta_json longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY code_ip_created_at (code, ip, created_at),
            KEY event_type_created_at (event_type, created_at)
        ) $charset_collate;";

        dbDelta($tables);
    }
}