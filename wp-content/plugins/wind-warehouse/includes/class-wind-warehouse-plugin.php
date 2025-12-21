<?php
if (!defined('ABSPATH')) {
    exit;
}

final class Wind_Warehouse_Plugin {
    private static $plugin_file;

    private const WAREHOUSE_ROLES = [
        'warehouse_staff' => [
            'label' => 'Warehouse Staff',
            'caps'  => [
                'wh_view_portal',
                'wh_ship_codes',
                'wh_view_reports',
            ],
        ],
        'warehouse_manager' => [
            'label' => 'Warehouse Manager',
            'caps'  => [
                'wh_view_portal',
                'wh_manage_skus',
                'wh_manage_dealers',
                'wh_generate_codes',
                'wh_ship_codes',
                'wh_view_reports',
                'wh_reset_consumer_count_internal',
            ],
        ],
        'dealer_user' => [
            'label' => 'Dealer User',
            'caps'  => [
                'wh_view_portal',
                'wh_reset_consumer_count_dealer',
            ],
        ],
    ];

    public static function init($plugin_file): void {
        self::$plugin_file = $plugin_file;

        register_activation_hook($plugin_file, [self::class, 'on_activation']);
        register_deactivation_hook($plugin_file, [self::class, 'on_deactivation']);

        add_action('plugins_loaded', [self::class, 'maybe_upgrade_schema']);
        add_action('admin_init', [self::class, 'maybe_redirect_from_admin']);
        add_filter('login_redirect', [self::class, 'filter_login_redirect'], 10, 3);
    }

    public static function on_activation(): void {
        Wind_Warehouse_Schema::maybe_upgrade_schema();
        self::ensure_roles();
    }

    public static function on_deactivation(): void {
        // No cleanup required per requirements; schema remains intact.
    }

    public static function maybe_upgrade_schema(): void {
        Wind_Warehouse_Schema::maybe_upgrade_schema();
    }

    private static function ensure_roles(): void {
        foreach (self::WAREHOUSE_ROLES as $role => $data) {
            $role_obj = get_role($role);
            if (!$role_obj) {
                add_role($role, $data['label'], []);
                $role_obj = get_role($role);
            }

            if (!$role_obj) {
                continue;
            }

            foreach ($data['caps'] as $cap) {
                if (!$role_obj->has_cap($cap)) {
                    $role_obj->add_cap($cap);
                }
            }
        }
    }

    public static function maybe_redirect_from_admin(): void {
        if (!is_user_logged_in()) {
            return;
        }

        if (!is_admin()) {
            return;
        }

        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron()) {
            return;
        }

        $user = wp_get_current_user();
        if (!$user instanceof WP_User) {
            return;
        }

        if (user_can($user, 'manage_options')) {
            return;
        }

        if (!self::user_is_target_role($user)) {
            return;
        }

        if (!user_can($user, 'wh_view_portal')) {
            return;
        }

        wp_safe_redirect(home_url('/warehouse/'));
        exit;
    }

    public static function filter_login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (!$user instanceof WP_User) {
            return $redirect_to;
        }

        if (user_can($user, 'manage_options')) {
            return $redirect_to;
        }

        if (!self::user_is_target_role($user)) {
            return $redirect_to;
        }

        if (!user_can($user, 'wh_view_portal')) {
            return $redirect_to;
        }

        return home_url('/warehouse/');
    }

    private static function user_is_target_role(WP_User $user): bool {
        $roles = (array) $user->roles;
        $matched = array_intersect($roles, array_keys(self::WAREHOUSE_ROLES));
        return !empty($matched);
    }
}
