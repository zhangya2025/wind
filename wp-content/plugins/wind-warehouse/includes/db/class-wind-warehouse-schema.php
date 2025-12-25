<?php
if (!defined('ABSPATH')) {
    exit;
}

class Wind_Warehouse_Schema {
    const SCHEMA_VERSION = '1.4.0';
    const OPTION_NAME = 'wh_schema_version';

    public static function maybe_upgrade_schema(): void {
        $installed_version = get_option(self::OPTION_NAME);

        if ($installed_version === self::SCHEMA_VERSION) {
            self::ensure_hq_dealer();
            return;
        }

        if (!empty($installed_version) && version_compare($installed_version, '1.1.0', '<')) {
            self::drop_all_tables();
            self::create_tables();
        } else {
            self::create_tables();
        }

        update_option(self::OPTION_NAME, self::SCHEMA_VERSION);
    }

    private static function drop_all_tables(): void {
        global $wpdb;

        $tables = [
            'wh_shipment_items',
            'wh_shipments',
            'wh_codes',
            'wh_code_batches',
            'wh_events',
            'wh_dealers',
            'wh_skus',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }
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
            color varchar(50) DEFAULT NULL,
            size varchar(50) DEFAULT NULL,
            summary varchar(255) DEFAULT NULL,
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
            phone varchar(50) DEFAULT NULL,
            address varchar(255) DEFAULT NULL,
            contact_name varchar(100) DEFAULT NULL,
            intro varchar(255) DEFAULT NULL,
            authorized_from date DEFAULT NULL,
            authorized_to date DEFAULT NULL,
            business_license_attachment_id bigint(20) unsigned DEFAULT NULL,
            authorization_letter_attachment_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY dealer_code (dealer_code),
            KEY status (status)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_code_batches (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            batch_no varchar(191) NOT NULL,
            sku_id bigint(20) unsigned NOT NULL,
            quantity int(10) unsigned NOT NULL DEFAULT 0,
            notes varchar(255) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'created',
            generated_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_no (batch_no),
            KEY sku_id (sku_id),
            KEY status (status),
            KEY generated_by (generated_by)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_codes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(191) NOT NULL,
            sku_id bigint(20) unsigned DEFAULT NULL,
            dealer_id bigint(20) unsigned DEFAULT NULL,
            batch_id bigint(20) unsigned DEFAULT NULL,
            shipment_id bigint(20) unsigned DEFAULT NULL,
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
            KEY batch_id (batch_id),
            KEY shipment_id (shipment_id)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_shipments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shipment_no varchar(191) DEFAULT NULL,
            dealer_id bigint(20) unsigned DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            shipped_at datetime DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            notes varchar(255) DEFAULT NULL,
            dealer_name_snapshot varchar(255) DEFAULT NULL,
            dealer_address_snapshot varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY shipment_no (shipment_no),
            KEY dealer_id (dealer_id),
            KEY created_by (created_by),
            KEY shipped_at (shipped_at)
        ) $charset_collate;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}wh_shipment_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shipment_id bigint(20) unsigned NOT NULL,
            code_id bigint(20) unsigned NOT NULL,
            sku_id bigint(20) unsigned DEFAULT NULL,
            code varchar(191) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code_id (code_id),
            KEY shipment_id (shipment_id),
            KEY sku_id (sku_id)
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

        self::maybe_add_missing_dealer_columns();
        self::maybe_add_dealer_indexes();
        self::ensure_ship_schema();
        self::ensure_hq_dealer();
    }

    private static function ensure_ship_schema(): void {
        global $wpdb;

        $items_table = $wpdb->prefix . 'wh_shipment_items';
        $codes_table = $wpdb->prefix . 'wh_codes';

        $item_columns = $wpdb->get_col("SHOW COLUMNS FROM {$items_table}", 0);
        if (!in_array('code_id', $item_columns, true)) {
            $wpdb->query("ALTER TABLE {$items_table} ADD COLUMN code_id bigint(20) unsigned NOT NULL");
        }

        $item_indexes = $wpdb->get_results("SHOW INDEX FROM {$items_table}", ARRAY_A);
        $item_index_names = array_map(
            static function (array $row): string {
                return (string) $row['Key_name'];
            },
            $item_indexes
        );

        if (!in_array('code_id', $item_index_names, true)) {
            $wpdb->query("ALTER TABLE {$items_table} ADD UNIQUE KEY code_id (code_id)");
        }

        $code_columns = $wpdb->get_col("SHOW COLUMNS FROM {$codes_table}", 0);

        if (!in_array('shipment_id', $code_columns, true)) {
            $wpdb->query("ALTER TABLE {$codes_table} ADD COLUMN shipment_id bigint(20) unsigned DEFAULT NULL AFTER batch_id");
        }
    }

    private static function maybe_add_missing_dealer_columns(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'wh_dealers';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

        $target_columns = [
            'phone'                              => "ALTER TABLE {$table} ADD COLUMN phone varchar(50) DEFAULT NULL",
            'address'                            => "ALTER TABLE {$table} ADD COLUMN address varchar(255) DEFAULT NULL",
            'contact_name'                       => "ALTER TABLE {$table} ADD COLUMN contact_name varchar(100) DEFAULT NULL",
            'intro'                              => "ALTER TABLE {$table} ADD COLUMN intro varchar(255) DEFAULT NULL",
            'authorized_from'                    => "ALTER TABLE {$table} ADD COLUMN authorized_from date DEFAULT NULL",
            'authorized_to'                      => "ALTER TABLE {$table} ADD COLUMN authorized_to date DEFAULT NULL",
            'business_license_attachment_id'     => "ALTER TABLE {$table} ADD COLUMN business_license_attachment_id bigint(20) unsigned DEFAULT NULL",
            'authorization_letter_attachment_id' => "ALTER TABLE {$table} ADD COLUMN authorization_letter_attachment_id bigint(20) unsigned DEFAULT NULL",
        ];

        foreach ($target_columns as $column => $statement) {
            if (!in_array($column, $columns, true)) {
                $wpdb->query($statement);
            }
        }
    }

    private static function maybe_add_dealer_indexes(): void {
        global $wpdb;

        $table  = $wpdb->prefix . 'wh_dealers';
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);

        $index_names = array_map(
            static function (array $index_row): string {
                return (string) $index_row['Key_name'];
            },
            $indexes
        );

        if (!in_array('status', $index_names, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX status (status)");
        }

        if (!in_array('dealer_code', $index_names, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY dealer_code (dealer_code)");
        }
    }

    public static function ensure_hq_dealer(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'wh_dealers';
        $hq_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE dealer_code = %s LIMIT 1", 'HQ')
        );

        if ($hq_id !== null) {
            return;
        }

        $now = current_time('mysql');

        $wpdb->insert(
            $table,
            [
                'dealer_code' => 'HQ',
                'name'        => '总部销售',
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
    }
}