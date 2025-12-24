<?php
if (!defined('ABSPATH')) {
    exit;
}

final class Wind_Warehouse_Portal {
    private const MAX_GENERATE_QTY = 200;
    private const MAX_CODE_GENERATION_ATTEMPTS = 10;
    private const SHORTCODE = 'wind_warehouse_portal';
    private const PAGE_SLUG = 'warehouse';
    private const TITLE = 'Wind Warehouse Portal';

    public static function register_shortcode(): void {
        add_shortcode(self::SHORTCODE, [self::class, 'render_portal']);
        add_action('wp_ajax_ww_add_sku', [self::class, 'ajax_add_sku']);
        add_action('wp_ajax_ww_add_dealer', [self::class, 'ajax_add_dealer']);
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

    private static function portal_query_base_url(): string {
        return add_query_arg('pagename', self::PAGE_SLUG, home_url('/index.php'));
    }

    private static function portal_post_url(string $view_key): string {
        return add_query_arg('wh', $view_key, self::portal_query_base_url());
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

        $error_message = null;

        if ($view_key === 'skus') {
            $error_message = self::handle_skus_submission($user);
        } elseif ($view_key === 'dealers') {
            $error_message = self::handle_dealers_submission($user);
        } elseif ($view_key === 'generate') {
            $error_message = self::handle_generate_submission($user);
        }
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

        if ($view_key === 'dealers') {
            return self::render_dealers_view($error_message);
        }

        if ($view_key === 'generate') {
            return self::render_generate_view($error_message);
        }

        return '<p>' . sprintf(
            /* translators: %s: module name */
            esc_html__('Coming soon: %s', 'wind-warehouse'),
            esc_html($label)
        ) . '</p>';
    }

    private static function handle_skus_submission(WP_User $user): ?string {
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

        if ($action === 'toggle_status') {
            if (!self::user_can_access_view('skus', $user)) {
                return __('Forbidden', 'wind-warehouse');
            }

            $sku_id = isset($_POST['sku_id']) ? absint($_POST['sku_id']) : 0;

            if ($sku_id < 1) {
                return __('Invalid request. Please try again.', 'wind-warehouse');
            }

            if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_skus_toggle_' . $sku_id)) {
                return __('Invalid request. Please try again.', 'wind-warehouse');
            }

            global $wpdb;
            $table = $wpdb->prefix . 'wh_skus';

            $current_status = $wpdb->get_var(
                $wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $sku_id)
            );

            if ($current_status === null) {
                return __('Invalid request. Please try again.', 'wind-warehouse');
            }

            $target_status = ($current_status === 'active') ? 'disabled' : 'active';

            $updated = $wpdb->update(
                $table,
                [
                    'status'     => $target_status,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $sku_id],
                ['%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                return __('Could not update SKU. Please try again.', 'wind-warehouse');
            }

            $redirect_url = add_query_arg(
                [
                    'wh'  => 'skus',
                    'msg' => $target_status === 'active' ? 'enabled' : 'disabled',
                ],
                self::portal_url()
            );

            wp_safe_redirect($redirect_url);
            exit;
        }

        return __('Invalid request. Please try again.', 'wind-warehouse');
    }

    private static function handle_generate_submission(WP_User $user): ?string {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return null;
        }

        if (!self::user_can_access_view('generate', $user)) {
            return __('Forbidden', 'wind-warehouse');
        }

        $action = isset($_POST['ww_action']) ? sanitize_text_field(wp_unslash($_POST['ww_action'])) : '';

        if ($action !== 'create_batch') {
            return __('Invalid request. Please try again.', 'wind-warehouse');
        }

        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_generate_add')) {
            return __('Invalid request. Please try again.', 'wind-warehouse');
        }

        $sku_id = isset($_POST['sku_id']) ? absint($_POST['sku_id']) : 0;
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 0;
        $notes    = isset($_POST['notes']) ? sanitize_text_field(wp_unslash($_POST['notes'])) : '';

        if ($sku_id < 1 || $quantity < 1 || $quantity > self::MAX_GENERATE_QTY) {
            return sprintf(
                /* translators: %d: maximum quantity */
                __('Quantity must be between 1 and %d.', 'wind-warehouse'),
                self::MAX_GENERATE_QTY
            );
        }

        if (strlen($notes) > 255) {
            return __('Note must be 255 characters or fewer.', 'wind-warehouse');
        }

        global $wpdb;
        $sku_table  = $wpdb->prefix . 'wh_skus';
        $batch_table = $wpdb->prefix . 'wh_code_batches';

        $sku_exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$sku_table} WHERE id = %d AND status = %s", $sku_id, 'active')
        );

        if ($sku_exists === null) {
            return __('Selected SKU is not available.', 'wind-warehouse');
        }

        $code_table  = $wpdb->prefix . 'wh_codes';
        $batch_no = 'B' . gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);

        $wpdb->query('START TRANSACTION');

        $batch_data = [
            'batch_no'     => $batch_no,
            'sku_id'       => $sku_id,
            'quantity'     => $quantity,
            'notes'        => $notes !== '' ? $notes : null,
            'generated_by' => $user->ID,
            'created_at'   => current_time('mysql'),
        ];

        $batch_formats = ['%s', '%d', '%d', '%s', '%d', '%s'];

        $inserted = $wpdb->insert($batch_table, $batch_data, $batch_formats);

        if ($inserted === false) {
            $wpdb->query('ROLLBACK');
            return __('Could not create code batch. Please try again.', 'wind-warehouse');
        }

        $batch_id = (int) $wpdb->insert_id;

        if ($batch_id < 1) {
            $wpdb->query('ROLLBACK');
            return __('Could not determine batch ID.', 'wind-warehouse');
        }

        $code_generation_failed = false;

        for ($i = 0; $i < $quantity; $i++) {
            $code_inserted = false;

            for ($attempt = 0; $attempt < self::MAX_CODE_GENERATION_ATTEMPTS; $attempt++) {
                try {
                    $code = bin2hex(random_bytes(10));
                } catch (Exception $e) {
                    $code_generation_failed = true;
                    break;
                }

                $code_data = [
                    'code'         => $code,
                    'sku_id'       => $sku_id,
                    'batch_id'     => $batch_id,
                    'status'       => 'in_stock',
                    'generated_at' => current_time('mysql'),
                    'created_at'   => current_time('mysql'),
                ];

                $code_formats = ['%s', '%d', '%d', '%s', '%s', '%s'];
                $code_inserted = $wpdb->insert($code_table, $code_data, $code_formats) !== false;

                if ($code_inserted) {
                    break;
                }

                if (stripos($wpdb->last_error, 'duplicate') === false) {
                    break;
                }
            }

            if (!$code_inserted) {
                $code_generation_failed = true;
            }

            if ($code_generation_failed) {
                $wpdb->query('ROLLBACK');
                return __('Could not generate unique codes. Please try again.', 'wind-warehouse');
            }
        }

        $wpdb->query('COMMIT');

        $redirect_url = add_query_arg(
            [
                'wh'  => 'generate',
                'msg' => 'created',
            ],
            self::portal_url()
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    private static function handle_dealers_submission(WP_User $user): ?string {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return null;
        }

        if (!self::user_can_access_view('dealers', $user) && !user_can($user, 'wh_manage_dealers')) {
            return __('Forbidden', 'wind-warehouse');
        }

        $action = isset($_POST['ww_action']) ? sanitize_text_field(wp_unslash($_POST['ww_action'])) : '';

        if ($action === 'add_dealer') {
            return self::handle_add_dealer();
        }

        if ($action === 'toggle_dealer_status') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                return __('Invalid request. Please try again.', 'wind-warehouse');
            }

            if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_dealers_toggle')) {
                return __('Invalid request. Please try again.', 'wind-warehouse');
            }

            $dealer_id     = isset($_POST['dealer_id']) ? absint($_POST['dealer_id']) : 0;
            $target_status = isset($_POST['target_status']) ? sanitize_text_field(wp_unslash($_POST['target_status'])) : '';

            if ($dealer_id < 1 || !in_array($target_status, ['active', 'disabled'], true)) {
                return __('Invalid request. Please try again.', 'wind-warehouse');
            }

            global $wpdb;
            $table = $wpdb->prefix . 'wh_dealers';

            $updated = $wpdb->update(
                $table,
                [
                    'status'     => $target_status,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $dealer_id],
                ['%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                return __('Could not update dealer. Please try again.', 'wind-warehouse');
            }

            $redirect_url = add_query_arg(
                [
                    'wh'  => 'dealers',
                    'msg' => $target_status === 'active' ? 'enabled' : 'disabled',
                ],
                self::portal_url()
            );

            wp_safe_redirect($redirect_url);
            exit;
        }

        return __('Invalid request. Please try again.', 'wind-warehouse');
    }

    public static function ajax_add_sku(): void {
        if (!is_user_logged_in()) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        if (!current_user_can('wh_manage_skus')) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_skus_add')) {
            wp_die(__('Invalid request. Please try again.', 'wind-warehouse'), '', ['response' => 400]);
        }

        $sku_code = isset($_POST['sku_code']) ? trim(sanitize_text_field(wp_unslash($_POST['sku_code']))) : '';
        $name     = isset($_POST['name']) ? trim(sanitize_text_field(wp_unslash($_POST['name']))) : '';
        $color    = isset($_POST['color']) ? trim(sanitize_text_field(wp_unslash($_POST['color']))) : '';
        $size     = isset($_POST['size']) ? trim(sanitize_text_field(wp_unslash($_POST['size']))) : '';
        $summary  = isset($_POST['summary']) ? trim(sanitize_text_field(wp_unslash($_POST['summary']))) : '';

        if ($sku_code === '' || $name === '') {
            wp_die(__('SKU code and name are required.', 'wind-warehouse'), '', ['response' => 400]);
        }

        if (strlen($sku_code) !== 13 || !ctype_digit($sku_code)) {
            wp_die(__('SKU code must be a 13-digit number.', 'wind-warehouse'), '', ['response' => 400]);
        }

        if (strlen($name) > 255) {
            wp_die(__('Name must be 255 characters or fewer.', 'wind-warehouse'), '', ['response' => 400]);
        }

        if ($color !== '' && strlen($color) > 50) {
            wp_die(__('Color must be 50 characters or fewer.', 'wind-warehouse'), '', ['response' => 400]);
        }

        if ($size !== '' && strlen($size) > 50) {
            wp_die(__('Size must be 50 characters or fewer.', 'wind-warehouse'), '', ['response' => 400]);
        }

        if ($summary !== '' && strlen($summary) > 255) {
            wp_die(__('Summary must be 255 characters or fewer.', 'wind-warehouse'), '', ['response' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wh_skus';

        $existing_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE sku_code = %s LIMIT 1", $sku_code)
        );

        if ($existing_id !== null) {
            wp_die(__('SKU code already exists.', 'wind-warehouse'), '', ['response' => 400]);
        }

        $data = [
            'sku_code'   => $sku_code,
            'name'       => $name,
            'color'      => $color !== '' ? $color : null,
            'size'       => $size !== '' ? $size : null,
            'summary'    => $summary !== '' ? $summary : null,
            'status'     => 'active',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $inserted = $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        if ($inserted === false) {
            wp_die(__('Could not create SKU. Please try again.', 'wind-warehouse'), '', ['response' => 400]);
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

    private static function handle_add_sku(): ?string {
        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_skus_add')) {
            return __('Invalid request. Please try again.', 'wind-warehouse');
        }

        $sku_code = isset($_POST['sku_code']) ? trim(sanitize_text_field(wp_unslash($_POST['sku_code']))) : '';
        $name     = isset($_POST['name']) ? trim(sanitize_text_field(wp_unslash($_POST['name']))) : '';
        $color    = isset($_POST['color']) ? trim(sanitize_text_field(wp_unslash($_POST['color']))) : '';
        $size     = isset($_POST['size']) ? trim(sanitize_text_field(wp_unslash($_POST['size']))) : '';
        $summary  = isset($_POST['summary']) ? trim(sanitize_text_field(wp_unslash($_POST['summary']))) : '';

        if ($sku_code === '' || $name === '') {
            return __('SKU code and name are required.', 'wind-warehouse');
        }

        if (strlen($sku_code) !== 13 || !ctype_digit($sku_code)) {
            return __('SKU code must be a 13-digit number.', 'wind-warehouse');
        }

        if (strlen($name) > 255) {
            return __('Name must be 255 characters or fewer.', 'wind-warehouse');
        }

        if ($color !== '' && strlen($color) > 50) {
            return __('Color must be 50 characters or fewer.', 'wind-warehouse');
        }

        if ($size !== '' && strlen($size) > 50) {
            return __('Size must be 50 characters or fewer.', 'wind-warehouse');
        }

        if ($summary !== '' && strlen($summary) > 255) {
            return __('Summary must be 255 characters or fewer.', 'wind-warehouse');
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
            'color'      => $color !== '' ? $color : null,
            'size'       => $size !== '' ? $size : null,
            'summary'    => $summary !== '' ? $summary : null,
            'status'     => 'active',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $inserted = $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

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

    private static function handle_add_dealer(): ?string {
        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_dealers_add')) {
            return __('Invalid request. Please try again.', 'wind-warehouse');
        }

        $dealer_code = isset($_POST['dealer_code']) ? sanitize_text_field(wp_unslash($_POST['dealer_code'])) : '';
        $name        = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

        if ($dealer_code === '' || $name === '') {
            return __('Dealer code and name are required.', 'wind-warehouse');
        }

        if (strlen($dealer_code) > 191) {
            return __('Dealer code must be 191 characters or fewer.', 'wind-warehouse');
        }

        if (strlen($name) > 255) {
            return __('Name must be 255 characters or fewer.', 'wind-warehouse');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wh_dealers';

        $existing_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE dealer_code = %s LIMIT 1", $dealer_code)
        );

        if ($existing_id !== null) {
            return __('Dealer code already exists.', 'wind-warehouse');
        }

        $now = current_time('mysql');
        $data = [
            'dealer_code' => $dealer_code,
            'name'        => $name,
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $inserted = $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s']);

        if ($inserted === false) {
            return __('Could not create dealer. Please try again.', 'wind-warehouse');
        }

        $redirect_url = add_query_arg(
            [
                'wh'  => 'dealers',
                'msg' => 'created',
            ],
            self::portal_url()
        );
        wp_safe_redirect($redirect_url);
        exit;
    }

    public static function ajax_add_dealer(): void {
        if (!is_user_logged_in()) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        if (!current_user_can('wh_manage_dealers')) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_dealers_add')) {
            wp_die(__('Invalid request. Please try again.', 'wind-warehouse'), '', ['response' => 400]);
        }

        $dealer_code = isset($_POST['dealer_code']) ? sanitize_text_field(wp_unslash($_POST['dealer_code'])) : '';
        $name        = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

        if ($dealer_code === '' || $name === '') {
            wp_die(__('Dealer code and name are required.', 'wind-warehouse'), '', ['response' => 400]);
        }

        if (strlen($dealer_code) > 191) {
            wp_die(__('Dealer code must be 191 characters or fewer.', 'wind-warehouse'), '', ['response' => 400]);
        }

        if (strlen($name) > 255) {
            wp_die(__('Name must be 255 characters or fewer.', 'wind-warehouse'), '', ['response' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wh_dealers';

        $existing_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE dealer_code = %s LIMIT 1", $dealer_code)
        );

        if ($existing_id !== null) {
            wp_die(__('Dealer code already exists.', 'wind-warehouse'), '', ['response' => 400]);
        }

        $now = current_time('mysql');
        $data = [
            'dealer_code' => $dealer_code,
            'name'        => $name,
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $inserted = $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s']);

        if ($inserted === false) {
            wp_die(__('Could not create dealer. Please try again.', 'wind-warehouse'), '', ['response' => 400]);
        }

        $redirect_url = add_query_arg(
            [
                'wh'  => 'dealers',
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

        $success_message = '';
        if (isset($_GET['msg'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['msg']));
            if ($msg === 'enabled') {
                $success_message = __('SKU enabled.', 'wind-warehouse');
            } elseif ($msg === 'disabled') {
                $success_message = __('SKU disabled.', 'wind-warehouse');
            } elseif ($msg === 'created') {
                $success_message = __('SKU created.', 'wind-warehouse');
            }
        }

        $skus = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sku_code, name, color, size, summary, status, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT %d",
                50
            ),
            ARRAY_A
        );

        $form_action   = admin_url('admin-ajax.php');
        $toggle_action = add_query_arg('wh', 'skus', self::portal_url());

        $html  = '<div class="ww-skus">';
        if ($success_message !== '') {
            $html .= '<div class="notice notice-success"><p>' . esc_html($success_message) . '</p></div>';
        }
        if ($error_message !== null) {
            $html .= '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }
        $html .= '<form method="post" action="' . esc_url($form_action) . '">';
        $html .= '<h2>' . esc_html__('Add SKU', 'wind-warehouse') . '</h2>';
        $html .= '<p><label>' . esc_html__('SKU Code', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="sku_code" required maxlength="13" /></label></p>';
        $html .= '<p><label>' . esc_html__('Name', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="name" required maxlength="255" /></label></p>';
        $html .= '<p><label>' . esc_html__('Color (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="color" maxlength="50" /></label></p>';
        $html .= '<p><label>' . esc_html__('Size (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="size" maxlength="50" /></label></p>';
        $html .= '<p><label>' . esc_html__('Summary (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="summary" maxlength="255" /></label></p>';
        $html .= '<input type="hidden" name="action" value="ww_add_sku" />';
        $html .= wp_nonce_field('ww_skus_add', 'ww_nonce', true, false);
        $html .= '<p><button type="submit">' . esc_html__('Add', 'wind-warehouse') . '</button></p>';
        $html .= '</form>';

        $html .= '<h2>' . esc_html__('Latest SKUs', 'wind-warehouse') . '</h2>';
        $html .= '<table class="ww-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('ID', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('SKU Code', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Name', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Color', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Size', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Summary', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Status', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Created At', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Updated At', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Actions', 'wind-warehouse') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        if (!empty($skus)) {
            foreach ($skus as $sku) {
                $html .= '<tr>';
                $html .= '<td>' . esc_html($sku['id']) . '</td>';
                $html .= '<td>' . esc_html($sku['sku_code']) . '</td>';
                $html .= '<td>' . esc_html($sku['name']) . '</td>';
                $html .= '<td>' . esc_html($sku['color'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($sku['size'] ?? '') . '</td>';

                $summary_text = isset($sku['summary']) ? (string) $sku['summary'] : '';
                $display_summary = $summary_text !== '' ? wp_html_excerpt($summary_text, 50, '…') : '';
                $html .= '<td>' . esc_html($display_summary) . '</td>';
                $html .= '<td>' . esc_html($sku['status']) . '</td>';
                $html .= '<td>' . esc_html($sku['created_at']) . '</td>';
                $html .= '<td>' . esc_html($sku['updated_at']) . '</td>';
                $html .= '<td>';
                $html .= '<form method="post" action="' . esc_url($toggle_action) . '" style="display:inline">';
                $html .= '<input type="hidden" name="ww_action" value="toggle_status" />';
                $html .= '<input type="hidden" name="sku_id" value="' . esc_attr($sku['id']) . '" />';
                $html .= wp_nonce_field('ww_skus_toggle_' . $sku['id'], 'ww_nonce', true, false);
                $button_label = $sku['status'] === 'active' ? esc_html__('Disable', 'wind-warehouse') : esc_html__('Enable', 'wind-warehouse');
                $html .= '<button type="submit">' . $button_label . '</button>';
                $html .= '</form>';
                $html .= '</td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="10">' . esc_html__('No SKUs found.', 'wind-warehouse') . '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    private static function render_dealers_view(?string $error_message): string {
        global $wpdb;
        $table = $wpdb->prefix . 'wh_dealers';

        $dealers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, dealer_code, name, status, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT %d",
                50
            ),
            ARRAY_A
        );

        $form_action   = self::portal_post_url('dealers');
        $toggle_action = $form_action;

        $html  = '<div class="ww-dealers">';
        if ($error_message !== null) {
            $html .= '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }
        $html .= '<form method="post" action="' . esc_url($form_action) . '">';
        $html .= '<h2>' . esc_html__('Add Dealer', 'wind-warehouse') . '</h2>';
        $html .= '<p><label>' . esc_html__('Dealer Code', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="dealer_code" required /></label></p>';
        $html .= '<p><label>' . esc_html__('Name', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="name" required /></label></p>';
        $html .= '<input type="hidden" name="ww_action" value="add_dealer" />';
        $html .= wp_nonce_field('ww_dealers_add', 'ww_nonce', true, false);
        $html .= '<p><button type="submit">' . esc_html__('Add', 'wind-warehouse') . '</button></p>';
        $html .= '</form>';

        $html .= '<h2>' . esc_html__('Latest Dealers', 'wind-warehouse') . '</h2>';
        $html .= '<table class="ww-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('ID', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Dealer Code', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Name', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Status', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Created At', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Updated At', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Actions', 'wind-warehouse') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        if (!empty($dealers)) {
            foreach ($dealers as $dealer) {
                $html .= '<tr>';
                $html .= '<td>' . esc_html($dealer['id']) . '</td>';
                $html .= '<td>' . esc_html($dealer['dealer_code']) . '</td>';
                $html .= '<td>' . esc_html($dealer['name']) . '</td>';
                $html .= '<td>' . esc_html($dealer['status']) . '</td>';
                $html .= '<td>' . esc_html($dealer['created_at']) . '</td>';
                $html .= '<td>' . esc_html($dealer['updated_at']) . '</td>';

                $target_status = ($dealer['status'] === 'active') ? 'disabled' : 'active';
                $button_label  = ($dealer['status'] === 'active') ? esc_html__('Disable', 'wind-warehouse') : esc_html__('Enable', 'wind-warehouse');

                $html .= '<td>';
                $html .= '<form method="post" action="' . esc_url($toggle_action) . '" style="display:inline">';
                $html .= '<input type="hidden" name="ww_action" value="toggle_dealer_status" />';
                $html .= '<input type="hidden" name="dealer_id" value="' . esc_attr($dealer['id']) . '" />';
                $html .= '<input type="hidden" name="target_status" value="' . esc_attr($target_status) . '" />';
                $html .= wp_nonce_field('ww_dealers_toggle', 'ww_nonce', true, false);
                $html .= '<button type="submit">' . $button_label . '</button>';
                $html .= '</form>';
                $html .= '</td>';

                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="7">' . esc_html__('No dealers found.', 'wind-warehouse') . '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    private static function render_generate_view(?string $error_message): string {
        global $wpdb;
        $sku_table    = $wpdb->prefix . 'wh_skus';
        $batch_table  = $wpdb->prefix . 'wh_code_batches';
        $code_table   = $wpdb->prefix . 'wh_codes';

        $skus = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sku_code, name, color, size FROM {$sku_table} WHERE status = %s ORDER BY id DESC LIMIT %d",
                'active',
                100
            ),
            ARRAY_A
        );

        $batches = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.id, b.batch_no, b.sku_id, b.quantity, b.notes, b.generated_by, b.created_at, s.sku_code,"
                . " COALESCE(c.code_count, 0) AS code_count FROM {$batch_table} b "
                . "LEFT JOIN {$sku_table} s ON b.sku_id = s.id "
                . "LEFT JOIN (SELECT batch_id, COUNT(*) AS code_count FROM {$code_table} GROUP BY batch_id) c ON b.id = c.batch_id "
                . "ORDER BY b.id DESC LIMIT %d",
                50
            ),
            ARRAY_A
        );

        $form_action = self::portal_post_url('generate');

        $html  = '<div class="ww-generate">';
        if ($error_message !== null) {
            $html .= '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }

        $html .= '<form method="post" action="' . esc_url($form_action) . '">';
        $html .= '<h2>' . esc_html__('Create Code Batch', 'wind-warehouse') . '</h2>';

        if (empty($skus)) {
            $html .= '<p>' . esc_html__('No active SKUs available. Please add SKUs first.', 'wind-warehouse') . '</p>';
        } else {
            $html .= '<p><label>' . esc_html__('SKU', 'wind-warehouse') . '<br />';
            $html .= '<select name="sku_id" required>';
            foreach ($skus as $sku) {
                $label = $sku['sku_code'] . ' - ' . $sku['name'];

                $suffix_parts = [];
                if (!empty($sku['color'])) {
                    $suffix_parts[] = $sku['color'];
                }
                if (!empty($sku['size'])) {
                    $suffix_parts[] = $sku['size'];
                }

                if (!empty($suffix_parts)) {
                    $label .= ' / ' . implode(' ', $suffix_parts);
                }

                $html .= '<option value="' . esc_attr($sku['id']) . '">' . esc_html($label) . '</option>';
            }
            $html .= '</select></label></p>';

            $html .= '<p><label>' . esc_html__('Quantity', 'wind-warehouse') . '<br />';
            $html .= '<input type="number" name="quantity" min="1" max="' . esc_attr(self::MAX_GENERATE_QTY) . '" required /></label></p>';

            $html .= '<p><label>' . esc_html__('Notes (optional)', 'wind-warehouse') . '<br />';
            $html .= '<input type="text" name="notes" maxlength="255" /></label></p>';

            $html .= '<input type="hidden" name="ww_action" value="create_batch" />';
            $html .= wp_nonce_field('ww_generate_add', 'ww_nonce', true, false);
            $html .= '<p><button type="submit">' . esc_html__('Generate', 'wind-warehouse') . '</button></p>';
        }

        $html .= '</form>';

        $html .= '<h2>' . esc_html__('Latest Batches', 'wind-warehouse') . '</h2>';
        $html .= '<table class="ww-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('ID', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Batch No', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('SKU', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Quantity', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Notes', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Generated By', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Created At', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Codes Generated', 'wind-warehouse') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        if (!empty($batches)) {
            foreach ($batches as $batch) {
                $sku_label = $batch['sku_code'] !== null ? $batch['sku_code'] : $batch['sku_id'];
                $html  .= '<tr>';
                $html  .= '<td>' . esc_html($batch['id']) . '</td>';
                $html  .= '<td>' . esc_html($batch['batch_no']) . '</td>';
                $html  .= '<td>' . esc_html($sku_label) . '</td>';
                $html  .= '<td>' . esc_html($batch['quantity']) . '</td>';
                $html  .= '<td>' . esc_html($batch['notes']) . '</td>';
                $html  .= '<td>' . esc_html($batch['generated_by']) . '</td>';
                $html  .= '<td>' . esc_html($batch['created_at']) . '</td>';
                $html  .= '<td>' . esc_html($batch['code_count']) . '</td>';
                $html  .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="7">' . esc_html__('No batches found.', 'wind-warehouse') . '</td></tr>';
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
