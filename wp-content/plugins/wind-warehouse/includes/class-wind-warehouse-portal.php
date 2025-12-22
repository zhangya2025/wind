<?php
if (!defined('ABSPATH')) {
    exit;
}

final class Wind_Warehouse_Portal {
    private const SHORTCODE = 'wind_warehouse_portal';
    private const PAGE_SLUG = 'warehouse';
    private const TITLE = 'Wind Warehouse Portal';

    public static function register_shortcode(): void {
        add_shortcode(self::SHORTCODE, [self::class, 'render_portal']);
    }

    public static function portal_url(): string {
        $page = get_page_by_path(self::PAGE_SLUG);

        if ($page instanceof WP_Post) {
            $permalink = get_permalink($page->ID);

            if (!empty($permalink)) {
                return $permalink;
            }
        }

        return home_url('/' . self::PAGE_SLUG . '/');
    }

    public static function ensure_portal_page(bool $force = false): void {
        if (!$force) {
            if (!is_admin()) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron()) {
                return;
            }
        }

        $page = get_page_by_path(self::PAGE_SLUG);

        if ($page instanceof WP_Post) {
            return;
        }

        $page_data = [
            'post_title'   => 'Warehouse',
            'post_name'    => self::PAGE_SLUG,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[' . self::SHORTCODE . ']',
        ];

        wp_insert_post($page_data);
    }

    public static function render_portal($atts = []): string {
        if (!is_user_logged_in()) {
            status_header(404);
            return '';
        }

        $user = wp_get_current_user();
        if (!$user instanceof WP_User) {
            status_header(403);
            return '';
        }

        if (!user_can($user, 'wh_view_portal')) {
            return wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        $current_view = isset($_GET['wh']) ? sanitize_text_field(wp_unslash($_GET['wh'])) : '';
        $view_key = $current_view !== '' ? $current_view : 'dashboard';

        $nav_items = self::nav_items();

        if (!array_key_exists($view_key, $nav_items)) {
            $view_key = 'dashboard';
        } elseif (!self::user_can_access_view($view_key, $user)) {
            return wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        $error_message = self::handle_skus_submission($view_key, $user);
        $nav_items = self::filter_nav_items_by_capability($nav_items, $user);
        $content = self::render_content($view_key, $nav_items[$view_key], $error_message);
        $navigation = self::render_navigation($view_key, $nav_items);
        $user_info = self::render_user_info($user);

        $html  = '<div class="wind-warehouse-portal">';
        $html .= '<h1>' . esc_html(self::TITLE) . '</h1>';
        $html .= $user_info;
        $html .= $navigation;
        $html .= $content;
        $html .= '</div>';

        return $html;
    }

    private static function nav_items(): array {
        return [
            'dashboard'       => __('Dashboard', 'wind-warehouse'),
            'skus'            => __('SKUs', 'wind-warehouse'),
            'dealers'         => __('Dealers', 'wind-warehouse'),
            'generate'        => __('Generate Codes', 'wind-warehouse'),
            'ship'            => __('Ship Codes', 'wind-warehouse'),
            'reset-b'         => __('Reset B', 'wind-warehouse'),
            'monitor-hq'      => __('HQ Monitor', 'wind-warehouse'),
            'reports-monthly' => __('Monthly Reports', 'wind-warehouse'),
            'reports-yearly'  => __('Yearly Reports', 'wind-warehouse'),
        ];
    }

    private static function render_navigation(string $current_view, array $nav_items): string {
        $base_url = self::portal_url();
        $html = '<nav><ul>';

        foreach ($nav_items as $key => $label) {
            $url = add_query_arg('wh', $key, $base_url);
            $active_class = $key === $current_view ? ' class="active"' : '';
            $html .= '<li' . $active_class . '><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }

        $html .= '</ul></nav>';
        return $html;
    }

    private static function view_capabilities(): array {
        return [
            'dashboard'       => 'wh_view_portal',
            'skus'            => 'wh_manage_skus',
            'dealers'         => 'wh_manage_dealers',
            'generate'        => 'wh_generate_codes',
            'ship'            => 'wh_ship_codes',
            'reset-b'         => ['wh_reset_consumer_count_internal', 'wh_reset_consumer_count_dealer'],
            'monitor-hq'      => 'wh_view_reports',
            'reports-monthly' => 'wh_view_reports',
            'reports-yearly'  => 'wh_view_reports',
        ];
    }

    private static function user_can_access_view(string $view_key, WP_User $user): bool {
        if ($view_key === 'dashboard') {
            return true;
        }

        $capabilities = self::view_capabilities();

        if (!array_key_exists($view_key, $capabilities)) {
            return false;
        }

        $required_caps = $capabilities[$view_key];

        if (is_array($required_caps)) {
            foreach ($required_caps as $cap) {
                if (user_can($user, $cap)) {
                    return true;
                }
            }

            return false;
        }

        return user_can($user, $required_caps);
    }

    private static function filter_nav_items_by_capability(array $nav_items, WP_User $user): array {
        return array_filter(
            $nav_items,
            static function (string $label, string $key) use ($user): bool {
                return self::user_can_access_view($key, $user);
            },
            ARRAY_FILTER_USE_BOTH
        );
    }

    private static function render_content(string $view_key, string $label, ?string $error_message = null): string {
        if ($view_key === 'dashboard') {
            return '<p>' . esc_html__('Welcome to the warehouse portal. Select a module to continue.', 'wind-warehouse') . '</p>';
        }

        if ($view_key === 'skus') {
            return self::render_skus_view($error_message);
        }

        return '<p>' . sprintf(
            /* translators: %s: module name */
            esc_html__('Coming soon: %s', 'wind-warehouse'),
            esc_html($label)
        ) . '</p>';
    }

    private static function handle_skus_submission(string $view_key, WP_User $user): ?string {
        if ($view_key !== 'skus') {
            return null;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return null;
        }

        if (!self::user_can_access_view('skus', $user)) {
            return __('Forbidden', 'wind-warehouse');
        }

        $action = isset($_POST['ww_action']) ? sanitize_text_field(wp_unslash($_POST['ww_action'])) : '';

        if ($action === 'add_sku') {
            return self::handle_add_sku();
        }

        return __('Invalid request. Please try again.', 'wind-warehouse');
    }

    private static function handle_add_sku(): ?string {
        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_skus_add')) {
            return __('Invalid request. Please try again.', 'wind-warehouse');
        }

        $sku_code = isset($_POST['sku_code']) ? sanitize_text_field(wp_unslash($_POST['sku_code'])) : '';
        $name     = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

        if ($sku_code === '' || $name === '') {
            return __('SKU code and name are required.', 'wind-warehouse');
        }

        if (strlen($sku_code) > 191) {
            return __('SKU code must be 191 characters or fewer.', 'wind-warehouse');
        }

        if (strlen($name) > 255) {
            return __('Name must be 255 characters or fewer.', 'wind-warehouse');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wh_skus';

        $existing_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE sku_code = %s LIMIT 1", $sku_code)
        );

        if ($existing_id !== null) {
            return __('SKU code already exists.', 'wind-warehouse');
        }

        $data = [
            'sku_code'   => $sku_code,
            'name'       => $name,
            'status'     => 'active',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $inserted = $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s']);

        if ($inserted === false) {
            return __('Could not create SKU. Please try again.', 'wind-warehouse');
        }

        $redirect_url = add_query_arg(
            [
                'wh'  => 'skus',
                'msg' => 'created',
            ],
            self::portal_url()
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    private static function render_skus_view(?string $error_message): string {
        global $wpdb;
        $table = $wpdb->prefix . 'wh_skus';

        $skus = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sku_code, name, status, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT %d",
                50
            ),
            ARRAY_A
        );

        $form_action = add_query_arg('wh', 'skus', self::portal_url());

        $html  = '<div class="ww-skus">';
        if ($error_message !== null) {
            $html .= '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }
        $html .= '<form method="post" action="' . esc_url($form_action) . '">';
        $html .= '<h2>' . esc_html__('Add SKU', 'wind-warehouse') . '</h2>';
        $html .= '<p><label>' . esc_html__('SKU Code', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="sku_code" required /></label></p>';
        $html .= '<p><label>' . esc_html__('Name', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="name" required /></label></p>';
        $html .= '<input type="hidden" name="ww_action" value="add_sku" />';
        $html .= wp_nonce_field('ww_skus_add', 'ww_nonce', true, false);
        $html .= '<p><button type="submit">' . esc_html__('Add', 'wind-warehouse') . '</button></p>';
        $html .= '</form>';

        $html .= '<h2>' . esc_html__('Latest SKUs', 'wind-warehouse') . '</h2>';
        $html .= '<table class="ww-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('ID', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('SKU Code', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Name', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Status', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Created At', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Updated At', 'wind-warehouse') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        if (!empty($skus)) {
            foreach ($skus as $sku) {
                $html .= '<tr>';
                $html .= '<td>' . esc_html($sku['id']) . '</td>';
                $html .= '<td>' . esc_html($sku['sku_code']) . '</td>';
                $html .= '<td>' . esc_html($sku['name']) . '</td>';
                $html .= '<td>' . esc_html($sku['status']) . '</td>';
                $html .= '<td>' . esc_html($sku['created_at']) . '</td>';
                $html .= '<td>' . esc_html($sku['updated_at']) . '</td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="6">' . esc_html__('No SKUs found.', 'wind-warehouse') . '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    private static function render_user_info(WP_User $user): string {
        $roles = !empty($user->roles) ? implode(', ', array_map('esc_html', $user->roles)) : __('(none)', 'wind-warehouse');
        $login = esc_html($user->user_login);

        $html  = '<div class="ww-user-info">';
        $html .= '<p>' . esc_html__('Current user:', 'wind-warehouse') . ' ' . $login . '</p>';
        $html .= '<p>' . esc_html__('Roles:', 'wind-warehouse') . ' ' . $roles . '</p>';
        $html .= '</div>';

        return $html;
    }
}
