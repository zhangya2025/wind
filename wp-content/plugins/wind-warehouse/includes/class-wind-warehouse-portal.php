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
    private const HQ_DEALER_CODE = 'HQ';

    private static function portal_base_url(): string {
        return trailingslashit(home_url('index.php/' . self::PAGE_SLUG));
    }

    public static function register_shortcode(): void {
        add_shortcode(self::SHORTCODE, [self::class, 'render_portal']);
        add_action('wp_ajax_ww_add_sku', [self::class, 'ajax_add_sku']);
        add_action('wp_ajax_ww_add_dealer', [self::class, 'ajax_add_dealer']);
        add_action('admin_post_ww_dealers_add', [self::class, 'admin_post_dealers_add']);
        add_action('admin_post_ww_dealers_toggle', [self::class, 'admin_post_dealers_toggle']);
        add_action('admin_post_ww_ship_create', [self::class, 'admin_post_ship_create']);
        add_action('wp_ajax_ww_ship_validate_code', [self::class, 'ajax_ship_validate_code']);
        add_action('admin_post_ww_ship_confirm', [self::class, 'admin_post_ship_confirm']);
        add_action('admin_post_ww_ship_export', [self::class, 'admin_post_ship_export']);
    }

    public static function portal_url(): string {
        return self::portal_base_url();
    }

    private static function portal_query_base_url(): string {
        return self::portal_base_url();
    }

    private static function portal_post_url(string $view_key): string {
        return add_query_arg('wh', $view_key, self::portal_base_url());
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

        if ($view_key === 'ship') {
            return self::render_ship_view($error_message);
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
        return null;
    }

    public static function admin_post_dealers_add(): void {
        if (!is_user_logged_in()) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        if (!current_user_can('wh_manage_dealers')) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        $redirect = add_query_arg('wh', 'dealers', self::portal_url());

        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_dealers_add')) {
            wp_safe_redirect(add_query_arg(['err' => 'bad_nonce'], $redirect));
            exit;
        }

        $input_data = self::collect_dealer_input();
        $validation_error = self::validate_dealer_input($input_data);

        if ($validation_error !== null) {
            $redirect_url = add_query_arg(
                [
                    'err'     => 'validation',
                    'err_msg' => rawurlencode($validation_error),
                ],
                $redirect
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wh_dealers';

        $existing_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE dealer_code = %s LIMIT 1", $input_data['dealer_code'])
        );

        if ($existing_id !== null) {
            $redirect_url = add_query_arg(
                [
                    'err'     => 'validation',
                    'err_msg' => rawurlencode(__('Dealer code already exists.', 'wind-warehouse')),
                ],
                $redirect
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        $now = current_time('mysql');
        $data = [
            'dealer_code'                        => $input_data['dealer_code'],
            'name'                               => $input_data['name'],
            'phone'                              => $input_data['phone'],
            'address'                            => $input_data['address'],
            'contact_name'                       => $input_data['contact_name'],
            'intro'                              => $input_data['intro'],
            'authorized_from'                    => $input_data['authorized_from'],
            'authorized_to'                      => $input_data['authorized_to'],
            'business_license_attachment_id'     => $input_data['business_license_attachment_id'],
            'authorization_letter_attachment_id' => $input_data['authorization_letter_attachment_id'],
            'status'                             => 'active',
            'created_at'                         => $now,
            'updated_at'                         => $now,
        ];

        $inserted = $wpdb->insert(
            $table,
            $data,
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

        if ($inserted === false) {
            wp_safe_redirect(add_query_arg(['err' => 'insert_failed'], $redirect));
            exit;
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

    public static function admin_post_dealers_toggle(): void {
        if (!is_user_logged_in()) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        if (!current_user_can('wh_manage_dealers')) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        $redirect = add_query_arg('wh', 'dealers', self::portal_url());

        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_dealers_toggle')) {
            wp_safe_redirect(add_query_arg(['err' => 'bad_nonce'], $redirect));
            exit;
        }

        $dealer_id     = isset($_POST['dealer_id']) ? absint($_POST['dealer_id']) : 0;
        $target_status = isset($_POST['target_status']) ? sanitize_text_field(wp_unslash($_POST['target_status'])) : '';

        if ($dealer_id < 1 || !in_array($target_status, ['active', 'disabled'], true)) {
            wp_safe_redirect(add_query_arg(['err' => 'bad_request'], $redirect));
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wh_dealers';

        $dealer_code = $wpdb->get_var(
            $wpdb->prepare("SELECT dealer_code FROM {$table} WHERE id = %d", $dealer_id)
        );

        if ($dealer_code === null) {
            wp_safe_redirect(add_query_arg(['err' => 'bad_request'], $redirect));
            exit;
        }

        if ($dealer_code === self::HQ_DEALER_CODE && $target_status === 'disabled') {
            wp_safe_redirect(add_query_arg(['err' => 'hq_cannot_disable'], $redirect));
            exit;
        }

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
            wp_safe_redirect(add_query_arg(['err' => 'update_failed'], $redirect));
            exit;
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

        $input_data = self::collect_dealer_input();
        $validation_error = self::validate_dealer_input($input_data);

        if ($validation_error !== null) {
            return $validation_error;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wh_dealers';

        $existing_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE dealer_code = %s LIMIT 1", $input_data['dealer_code'])
        );

        if ($existing_id !== null) {
            return __('Dealer code already exists.', 'wind-warehouse');
        }

        $now = current_time('mysql');
        $data = [
            'dealer_code' => $input_data['dealer_code'],
            'name'        => $input_data['name'],
            'phone'       => $input_data['phone'],
            'address'     => $input_data['address'],
            'contact_name'=> $input_data['contact_name'],
            'intro'       => $input_data['intro'],
            'authorized_from' => $input_data['authorized_from'],
            'authorized_to'   => $input_data['authorized_to'],
            'business_license_attachment_id'     => $input_data['business_license_attachment_id'],
            'authorization_letter_attachment_id' => $input_data['authorization_letter_attachment_id'],
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $inserted = $wpdb->insert(
            $table,
            $data,
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

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

        $input_data = self::collect_dealer_input();
        $validation_error = self::validate_dealer_input($input_data);

        if ($validation_error !== null) {
            wp_die($validation_error, '', ['response' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wh_dealers';

        $existing_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE dealer_code = %s LIMIT 1", $input_data['dealer_code'])
        );

        if ($existing_id !== null) {
            wp_die(__('Dealer code already exists.', 'wind-warehouse'), '', ['response' => 400]);
        }

        $now = current_time('mysql');
        $data = [
            'dealer_code' => $input_data['dealer_code'],
            'name'        => $input_data['name'],
            'phone'       => $input_data['phone'],
            'address'     => $input_data['address'],
            'contact_name'=> $input_data['contact_name'],
            'intro'       => $input_data['intro'],
            'authorized_from' => $input_data['authorized_from'],
            'authorized_to'   => $input_data['authorized_to'],
            'business_license_attachment_id'     => $input_data['business_license_attachment_id'],
            'authorization_letter_attachment_id' => $input_data['authorization_letter_attachment_id'],
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $inserted = $wpdb->insert(
            $table,
            $data,
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

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

    private static function collect_dealer_input(): array {
        return [
            'dealer_code'                        => isset($_POST['dealer_code']) ? sanitize_text_field(wp_unslash($_POST['dealer_code'])) : '',
            'name'                               => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
            'phone'                              => isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '',
            'address'                            => isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '',
            'contact_name'                       => isset($_POST['contact_name']) ? sanitize_text_field(wp_unslash($_POST['contact_name'])) : '',
            'intro'                              => isset($_POST['intro']) ? sanitize_text_field(wp_unslash($_POST['intro'])) : '',
            'authorized_from'                    => isset($_POST['authorized_from']) ? sanitize_text_field(wp_unslash($_POST['authorized_from'])) : '',
            'authorized_to'                      => isset($_POST['authorized_to']) ? sanitize_text_field(wp_unslash($_POST['authorized_to'])) : '',
            'business_license_attachment_id'     => isset($_POST['business_license_attachment_id']) ? sanitize_text_field(wp_unslash($_POST['business_license_attachment_id'])) : '',
            'authorization_letter_attachment_id' => isset($_POST['authorization_letter_attachment_id']) ? sanitize_text_field(wp_unslash($_POST['authorization_letter_attachment_id'])) : '',
        ];
    }

    private static function validate_dealer_input(array &$data): ?string {
        if ($data['dealer_code'] === '' || $data['name'] === '') {
            return __('Dealer code and name are required.', 'wind-warehouse');
        }

        if (strlen($data['dealer_code']) > 191) {
            return __('Dealer code must be 191 characters or fewer.', 'wind-warehouse');
        }

        if (strlen($data['name']) > 255) {
            return __('Name must be 255 characters or fewer.', 'wind-warehouse');
        }

        if ($data['phone'] !== '' && strlen($data['phone']) > 50) {
            return __('Phone must be 50 characters or fewer.', 'wind-warehouse');
        }

        if ($data['contact_name'] !== '' && strlen($data['contact_name']) > 100) {
            return __('Contact name must be 100 characters or fewer.', 'wind-warehouse');
        }

        if ($data['address'] !== '' && strlen($data['address']) > 255) {
            return __('Address must be 255 characters or fewer.', 'wind-warehouse');
        }

        if ($data['intro'] !== '' && strlen($data['intro']) > 255) {
            return __('Intro must be 255 characters or fewer.', 'wind-warehouse');
        }

        $authorized_from = self::normalize_date($data['authorized_from']);
        $authorized_to   = self::normalize_date($data['authorized_to']);

        if ($data['authorized_from'] !== '' && $authorized_from === null) {
            return __('Authorized from date is invalid. Use YYYY-MM-DD.', 'wind-warehouse');
        }

        if ($data['authorized_to'] !== '' && $authorized_to === null) {
            return __('Authorized to date is invalid. Use YYYY-MM-DD.', 'wind-warehouse');
        }

        if ($authorized_from !== null && $authorized_to !== null) {
            if (strtotime($authorized_from) > strtotime($authorized_to)) {
                return __('Authorized from date must be earlier than or equal to authorized to date.', 'wind-warehouse');
            }
        }

        $data['authorized_from'] = $authorized_from;
        $data['authorized_to']   = $authorized_to;

        $business_attachment = $data['business_license_attachment_id'];
        $authorization_attachment = $data['authorization_letter_attachment_id'];

        $data['business_license_attachment_id'] = ($business_attachment === '' || $business_attachment === '0') ? null : absint($business_attachment);
        if ($business_attachment !== '' && $business_attachment !== '0' && $data['business_license_attachment_id'] < 1) {
            return __('Business license attachment ID must be a positive integer.', 'wind-warehouse');
        }

        $data['authorization_letter_attachment_id'] = ($authorization_attachment === '' || $authorization_attachment === '0') ? null : absint($authorization_attachment);
        if ($authorization_attachment !== '' && $authorization_attachment !== '0' && $data['authorization_letter_attachment_id'] < 1) {
            return __('Authorization letter attachment ID must be a positive integer.', 'wind-warehouse');
        }

        $data['phone']        = $data['phone'] !== '' ? $data['phone'] : null;
        $data['address']      = $data['address'] !== '' ? $data['address'] : null;
        $data['contact_name'] = $data['contact_name'] !== '' ? $data['contact_name'] : null;
        $data['intro']        = $data['intro'] !== '' ? $data['intro'] : null;

        return null;
    }

    private static function normalize_date(string $date): ?string {
        $trimmed = trim($date);

        if ($trimmed === '') {
            return null;
        }

        $date_object = date_create_from_format('Y-m-d', $trimmed);

        if (!$date_object || $date_object->format('Y-m-d') !== $trimmed) {
            return null;
        }

        return $trimmed;
    }

    private static function dealer_is_available(array $dealer): bool {
        if (!isset($dealer['status']) || $dealer['status'] !== 'active') {
            return false;
        }

        if (empty($dealer['authorized_from']) || empty($dealer['authorized_to'])) {
            return false;
        }

        $from_ts    = strtotime($dealer['authorized_from']);
        $to_ts      = strtotime($dealer['authorized_to']);
        $current_ts = strtotime(date('Y-m-d', current_time('timestamp')));

        if ($from_ts === false || $to_ts === false || $current_ts === false) {
            return false;
        }

        return $current_ts >= $from_ts && $current_ts <= $to_ts;
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

        $success_message = '';
        $query_error_message = null;
        if (isset($_GET['msg'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['msg']));
            if ($msg === 'enabled') {
                $success_message = __('Dealer enabled.', 'wind-warehouse');
            } elseif ($msg === 'disabled') {
                $success_message = __('Dealer disabled.', 'wind-warehouse');
            } elseif ($msg === 'created') {
                $success_message = __('Dealer created.', 'wind-warehouse');
            }
        }

        if (isset($_GET['err'])) {
            $err = sanitize_text_field(wp_unslash($_GET['err']));
            if ($err === 'hq_cannot_disable') {
                $query_error_message = __('HQ dealer cannot be disabled.', 'wind-warehouse');
            } elseif ($err === 'invalid_request' || $err === 'bad_nonce' || $err === 'bad_request') {
                $query_error_message = __('Invalid request. Please try again.', 'wind-warehouse');
            } elseif ($err === 'db_error' || $err === 'insert_failed') {
                $query_error_message = __('Could not create dealer. Please try again.', 'wind-warehouse');
            } elseif ($err === 'update_failed') {
                $query_error_message = __('Could not update dealer. Please try again.', 'wind-warehouse');
            } elseif ($err === 'validation') {
                $raw_err_msg = isset($_GET['err_msg']) ? sanitize_text_field(wp_unslash($_GET['err_msg'])) : '';
                $query_error_message = $raw_err_msg !== '' ? rawurldecode($raw_err_msg) : __('Invalid request. Please try again.', 'wind-warehouse');
            }
        }

        $dealers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, dealer_code, name, phone, address, contact_name, intro, authorized_from, authorized_to, business_license_attachment_id, authorization_letter_attachment_id, status, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT %d",
                50
            ),
            ARRAY_A
        );

        $form_action   = admin_url('admin-post.php');
        $toggle_action = $form_action;

        $html  = '<div class="ww-dealers">';
        if ($success_message !== '') {
            $html .= '<div class="notice notice-success"><p>' . esc_html($success_message) . '</p></div>';
        }
        if ($query_error_message !== null) {
            $html .= '<div class="notice notice-error"><p>' . esc_html($query_error_message) . '</p></div>';
        } elseif ($error_message !== null) {
            $html .= '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }
        $html .= '<form method="post" action="' . esc_url($form_action) . '">';
        $html .= '<input type="hidden" name="action" value="ww_dealers_add" />';
        $html .= '<h2>' . esc_html__('Add Dealer', 'wind-warehouse') . '</h2>';
        $html .= '<p><label>' . esc_html__('Dealer Code', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="dealer_code" required /></label></p>';
        $html .= '<p><label>' . esc_html__('Name', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="name" required /></label></p>';
        $html .= '<p><label>' . esc_html__('Phone (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="phone" maxlength="50" /></label></p>';
        $html .= '<p><label>' . esc_html__('Contact Name (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="contact_name" maxlength="100" /></label></p>';
        $html .= '<p><label>' . esc_html__('Address (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="address" maxlength="255" /></label></p>';
        $html .= '<p><label>' . esc_html__('Intro (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="intro" maxlength="255" /></label></p>';
        $html .= '<p><label>' . esc_html__('Authorized From (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="date" name="authorized_from" /></label></p>';
        $html .= '<p><label>' . esc_html__('Authorized To (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="date" name="authorized_to" /></label></p>';
        $html .= '<p><label>' . esc_html__('Business License (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="hidden" name="business_license_attachment_id" id="ww_business_license_attachment_id" />';
        $html .= '<button type="button" class="button" id="ww_business_license_button">' . esc_html__('Select / Upload', 'wind-warehouse') . '</button> ';
        $html .= '<button type="button" class="button" id="ww_business_license_remove">' . esc_html__('Remove', 'wind-warehouse') . '</button>';
        $html .= '<div id="ww_business_license_preview" class="ww-media-preview"></div></label></p>';
        $html .= '<p><label>' . esc_html__('Authorization Letter (optional)', 'wind-warehouse') . '<br />';
        $html .= '<input type="hidden" name="authorization_letter_attachment_id" id="ww_authorization_letter_attachment_id" />';
        $html .= '<button type="button" class="button" id="ww_authorization_letter_button">' . esc_html__('Select / Upload', 'wind-warehouse') . '</button> ';
        $html .= '<button type="button" class="button" id="ww_authorization_letter_remove">' . esc_html__('Remove', 'wind-warehouse') . '</button>';
        $html .= '<div id="ww_authorization_letter_preview" class="ww-media-preview"></div></label></p>';
        $html .= wp_nonce_field('ww_dealers_add', 'ww_nonce', true, false);
        $html .= '<p><button type="submit">' . esc_html__('Add', 'wind-warehouse') . '</button></p>';
        $html .= '</form>';

        $html .= '<h2>' . esc_html__('Latest Dealers', 'wind-warehouse') . '</h2>';
        $html .= '<table class="ww-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('ID', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Dealer Code', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Name', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Phone', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Authorized From', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Authorized To', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Available', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Status', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Created At', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Updated At', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Actions', 'wind-warehouse') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        if (!empty($dealers)) {
            foreach ($dealers as $dealer) {
                $available = self::dealer_is_available($dealer);
                $html .= '<tr>';
                $html .= '<td>' . esc_html($dealer['id']) . '</td>';
                $html .= '<td>' . esc_html($dealer['dealer_code']) . '</td>';
                $html .= '<td>' . esc_html($dealer['name']) . '</td>';
                $html .= '<td>' . esc_html($dealer['phone'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($dealer['authorized_from'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($dealer['authorized_to'] ?? '') . '</td>';
                $html .= '<td>' . ($available ? esc_html__('Yes', 'wind-warehouse') : esc_html__('No', 'wind-warehouse')) . '</td>';
                $html .= '<td>' . esc_html($dealer['status']) . '</td>';
                $html .= '<td>' . esc_html($dealer['created_at']) . '</td>';
                $html .= '<td>' . esc_html($dealer['updated_at']) . '</td>';

                $target_status = ($dealer['status'] === 'active') ? 'disabled' : 'active';
                $button_label  = ($dealer['status'] === 'active') ? esc_html__('Disable', 'wind-warehouse') : esc_html__('Enable', 'wind-warehouse');

                $html .= '<td>';
                $html .= '<form method="post" action="' . esc_url($toggle_action) . '" style="display:inline">';
                $html .= '<input type="hidden" name="action" value="ww_dealers_toggle" />';
                $html .= '<input type="hidden" name="dealer_id" value="' . esc_attr($dealer['id']) . '" />';
                $html .= '<input type="hidden" name="target_status" value="' . esc_attr($target_status) . '" />';
                $html .= wp_nonce_field('ww_dealers_toggle', 'ww_nonce', true, false);
                $html .= '<button type="submit">' . $button_label . '</button>';
                $html .= '</form>';
                $html .= '</td>';

                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="11">' . esc_html__('No dealers found.', 'wind-warehouse') . '</td></tr>';
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

        $form_action = add_query_arg('wh', 'generate', self::portal_url());

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

        $html .= '<h2>' . esc_html__('Codes', 'wind-warehouse') . '</h2>';

        $summary = self::get_codes_summary();
        $filters = self::get_codes_filters_from_request();
        $total_codes = self::query_codes_count($filters);
        $codes = [];

        if ($total_codes > 0) {
            $codes = self::query_codes_page($filters, $filters['per_page'], $filters['page']);
        }

        $summary_html  = '<div class="notice notice-info"><p>';
        $summary_html .= esc_html__('Total codes', 'wind-warehouse') . ': ' . esc_html((string) $summary['total']) . ' | ';
        $summary_html .= esc_html__('In stock', 'wind-warehouse') . ': ' . esc_html((string) $summary['in_stock']) . ' | ';
        $summary_html .= esc_html__('Shipped', 'wind-warehouse') . ': ' . esc_html((string) $summary['shipped']) . ' | ';
        $summary_html .= esc_html__('Latest generated at', 'wind-warehouse') . ': ' . esc_html($summary['latest_generated_at'] ?? '-');
        $summary_html .= '</p></div>';

        $filter_action = add_query_arg('wh', 'generate', self::portal_url());

        $html .= $summary_html;
        $html .= '<form method="get" action="' . esc_url($filter_action) . '">';
        $html .= '<input type="hidden" name="wh" value="generate" />';
        $html .= '<div class="ww-filters">';
        $html .= '<p><label>' . esc_html__('SKU', 'wind-warehouse') . '<br />';
        $html .= '<select name="sku_id">';
        $html .= '<option value="">' . esc_html__('All', 'wind-warehouse') . '</option>';
        foreach ($skus as $sku) {
            $label = $sku['sku_code'] . ' - ' . $sku['name'];
            $selected = $filters['sku_id'] === (int) $sku['id'] ? ' selected' : '';
            $html .= '<option value="' . esc_attr($sku['id']) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select></label></p>';

        $html .= '<p><label>' . esc_html__('Batch No', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="batch_no" value="' . esc_attr($filters['batch_no']) . '" /></label></p>';

        $html .= '<p><label>' . esc_html__('Code', 'wind-warehouse') . '<br />';
        $html .= '<input type="text" name="code" value="' . esc_attr($filters['code']) . '" /></label></p>';

        $html .= '<p><label>' . esc_html__('Status', 'wind-warehouse') . '<br />';
        $html .= '<select name="status">';
        $statuses = [
            'all'      => esc_html__('All', 'wind-warehouse'),
            'in_stock' => esc_html__('In stock', 'wind-warehouse'),
            'shipped'  => esc_html__('Shipped', 'wind-warehouse'),
        ];
        foreach ($statuses as $value => $label) {
            $selected = $filters['status'] === $value ? ' selected' : '';
            $html .= '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select></label></p>';

        $html .= '<p><label>' . esc_html__('Per page', 'wind-warehouse') . '<br />';
        $per_page_options = [20, 50, 100];
        $html .= '<select name="per_page">';
        foreach ($per_page_options as $option) {
            $selected = $filters['per_page'] === $option ? ' selected' : '';
            $html .= '<option value="' . esc_attr($option) . '"' . $selected . '>' . esc_html($option) . '</option>';
        }
        $html .= '</select></label></p>';

        $html .= '<p><button type="submit" class="button">' . esc_html__('Filter', 'wind-warehouse') . '</button></p>';
        $html .= '</div>';
        $html .= '</form>';

        $html .= '<table class="ww-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('Code', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('SKU', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Batch No', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Status', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Generated At', 'wind-warehouse') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        if (!empty($codes)) {
            foreach ($codes as $code_row) {
                $sku_label = $code_row['sku_code'] !== null ? $code_row['sku_code'] . ' - ' . $code_row['sku_name'] : '';
                $html .= '<tr>';
                $html .= '<td>' . esc_html($code_row['code']) . '</td>';
                $html .= '<td>' . esc_html($sku_label) . '</td>';
                $html .= '<td>' . esc_html($code_row['batch_no'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($code_row['status']) . '</td>';
                $html .= '<td>' . esc_html($code_row['generated_at']) . '</td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="5">' . esc_html__('No codes found.', 'wind-warehouse') . '</td></tr>';
        }

        $html .= '</tbody></table>';

        $base_args = [
            'wh'       => 'generate',
            'sku_id'   => $filters['sku_id'] ?: '',
            'batch_no' => $filters['batch_no'],
            'code'     => $filters['code'],
            'status'   => $filters['status'],
            'per_page' => $filters['per_page'],
        ];

        $html .= self::render_codes_pagination($total_codes, $filters['page'], $filters['per_page'], $base_args);
        $html .= '</div>';

        return $html;
    }

    private static function get_codes_summary(): array {
        global $wpdb;

        $codes_table = $wpdb->prefix . 'wh_codes';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total, "
                . "SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS in_stock, "
                . "SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS shipped, "
                . "MAX(generated_at) AS latest_generated_at FROM {$codes_table}",
                'in_stock',
                'shipped'
            ),
            ARRAY_A
        );

        return [
            'total'               => isset($row['total']) ? (int) $row['total'] : 0,
            'in_stock'            => isset($row['in_stock']) ? (int) $row['in_stock'] : 0,
            'shipped'             => isset($row['shipped']) ? (int) $row['shipped'] : 0,
            'latest_generated_at' => $row['latest_generated_at'] ?? null,
        ];
    }

    private static function get_codes_filters_from_request(): array {
        $sku_id   = isset($_GET['sku_id']) ? absint($_GET['sku_id']) : 0;
        $batch_no = isset($_GET['batch_no']) ? sanitize_text_field(wp_unslash($_GET['batch_no'])) : '';
        $code     = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $status   = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'all';
        $per_page = isset($_GET['per_page']) ? absint($_GET['per_page']) : 50;
        $page     = isset($_GET['codes_page']) ? absint($_GET['codes_page']) : 1;

        if (!in_array($per_page, [20, 50, 100], true)) {
            $per_page = 50;
        }

        $page = $page >= 1 ? $page : 1;

        if (!in_array($status, ['in_stock', 'shipped'], true)) {
            $status = 'all';
        }

        return [
            'sku_id'  => $sku_id,
            'batch_no'=> $batch_no,
            'code'    => $code,
            'status'  => $status,
            'per_page'=> $per_page,
            'page'    => $page,
        ];
    }

    private static function query_codes_count(array $filters): int {
        global $wpdb;

        $codes   = $wpdb->prefix . 'wh_codes';
        $skus    = $wpdb->prefix . 'wh_skus';
        $batches = $wpdb->prefix . 'wh_code_batches';

        $where_sql = '';
        $params = [];
        $conditions = [];

        if (!empty($filters['sku_id'])) {
            $conditions[] = 'c.sku_id = %d';
            $params[] = (int) $filters['sku_id'];
        }

        if ($filters['batch_no'] !== '') {
            $conditions[] = 'b.batch_no LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['batch_no']) . '%';
        }

        if ($filters['code'] !== '') {
            $conditions[] = 'c.code = %s';
            $params[] = $filters['code'];
        }

        if ($filters['status'] !== 'all') {
            $conditions[] = 'c.status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($conditions)) {
            $where_sql = ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql = "SELECT COUNT(*) FROM {$codes} c "
            . "LEFT JOIN {$skus} s ON c.sku_id = s.id "
            . "LEFT JOIN {$batches} b ON c.batch_id = b.id"
            . $where_sql;

        if (!empty($params)) {
            $prepared = $wpdb->prepare($sql, $params);
            return (int) $wpdb->get_var($prepared);
        }

        return (int) $wpdb->get_var($sql);
    }

    private static function query_codes_page(array $filters, int $per_page, int $page): array {
        global $wpdb;

        $codes   = $wpdb->prefix . 'wh_codes';
        $skus    = $wpdb->prefix . 'wh_skus';
        $batches = $wpdb->prefix . 'wh_code_batches';

        $where_sql = '';
        $params = [];
        $conditions = [];

        if (!empty($filters['sku_id'])) {
            $conditions[] = 'c.sku_id = %d';
            $params[] = (int) $filters['sku_id'];
        }

        if ($filters['batch_no'] !== '') {
            $conditions[] = 'b.batch_no LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['batch_no']) . '%';
        }

        if ($filters['code'] !== '') {
            $conditions[] = 'c.code = %s';
            $params[] = $filters['code'];
        }

        if ($filters['status'] !== 'all') {
            $conditions[] = 'c.status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($conditions)) {
            $where_sql = ' WHERE ' . implode(' AND ', $conditions);
        }

        $offset = ($page - 1) * $per_page;

        $sql = "SELECT c.code, c.status, c.generated_at, s.sku_code, s.name AS sku_name, b.batch_no FROM {$codes} c "
            . "LEFT JOIN {$skus} s ON c.sku_id = s.id "
            . "LEFT JOIN {$batches} b ON c.batch_id = b.id"
            . $where_sql
            . " ORDER BY c.id DESC LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $prepared = $wpdb->prepare($sql, $params);

        return (array) $wpdb->get_results($prepared, ARRAY_A);
    }

    private static function render_codes_pagination(int $total, int $page, int $per_page, array $base_args): string {
        if ($per_page <= 0) {
            return '';
        }

        $total_pages = (int) ceil($total / $per_page);

        if ($total_pages <= 1) {
            return '';
        }

        $page = max(1, $page);
        $page = min($page, $total_pages);

        $html = '<div class="tablenav"><div class="tablenav-pages">';
        $base_url = add_query_arg($base_args, self::portal_url());

        $html .= '<span class="displaying-num">' . sprintf(esc_html__('Total %d items', 'wind-warehouse'), $total) . '</span>';

        $html .= '<span class="pagination-links">';

        if ($page > 1) {
            $prev_url = add_query_arg('codes_page', $page - 1, $base_url);
            $html    .= '<a class="prev-page" href="' . esc_url($prev_url) . '">&laquo;</a>';
        } else {
            $html .= '<span class="tablenav-pages-navspan">&laquo;</span>';
        }

        for ($i = 1; $i <= $total_pages; $i++) {
            $page_url = add_query_arg('codes_page', $i, $base_url);
            $class = $i === $page ? ' class="page-numbers current"' : ' class="page-numbers"';
            $html .= '<a' . $class . ' href="' . esc_url($page_url) . '">' . esc_html((string) $i) . '</a>';
        }

        if ($page < $total_pages) {
            $next_url = add_query_arg('codes_page', $page + 1, $base_url);
            $html    .= '<a class="next-page" href="' . esc_url($next_url) . '">&raquo;</a>';
        } else {
            $html .= '<span class="tablenav-pages-navspan">&raquo;</span>';
        }

        $html .= '</span>';
        $html .= '</div></div>';

        return $html;
    }

    private static function render_ship_view(?string $error_message): string {
        global $wpdb;

        $success_message      = '';
        $query_error_message  = null;
        $shipment_id          = isset($_GET['shipment_id']) ? absint($_GET['shipment_id']) : 0;
        $shipment_detail      = $shipment_id > 0 ? self::get_shipment_detail($shipment_id) : null;

        if (isset($_GET['msg'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['msg']));
            if ($msg === 'shipped') {
                $success_message = __('Shipment created.', 'wind-warehouse');
            }
        }

        if (isset($_GET['err'])) {
            $err = sanitize_text_field(wp_unslash($_GET['err']));
            if ($err === 'bad_nonce') {
                $query_error_message = __('Invalid request. Please try again.', 'wind-warehouse');
            } elseif ($err === 'bad_request' || $err === 'invalid_dealer' || $err === 'invalid_codes') {
                $query_error_message = __('Invalid request parameters.', 'wind-warehouse');
            } elseif ($err === 'duplicate_code') {
                $query_error_message = __('Duplicate code detected. Please rescan.', 'wind-warehouse');
            } elseif ($err === 'db_error') {
                $query_error_message = __('Could not create shipment. Please try again.', 'wind-warehouse');
            }
        }

        $dealers = self::get_active_dealers_for_ship();
        $form_action = admin_url('admin-post.php');
        $ajax_url    = admin_url('admin-ajax.php');
        $validate_nonce = wp_create_nonce('ww_ship_validate');
        $confirm_nonce  = wp_create_nonce('ww_ship_confirm');
        $export_nonce   = wp_create_nonce('ww_ship_export');

        $html  = '<div class="ww-ship">';
        if ($success_message !== '') {
            $html .= '<div class="notice notice-success"><p>' . esc_html($success_message) . '</p></div>';
        }

        if ($query_error_message !== null) {
            $html .= '<div class="notice notice-error"><p>' . esc_html($query_error_message) . '</p></div>';
        } elseif ($error_message !== null) {
            $html .= '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }

        $html .= '<h2>' . esc_html__('Ship Codes', 'wind-warehouse') . '</h2>';
        $html .= '<form id="ww-ship-form" method="post" action="' . esc_url($form_action) . '">';
        $html .= '<input type="hidden" name="action" value="ww_ship_confirm" />';
        $html .= '<input type="hidden" name="ww_nonce" value="' . esc_attr($confirm_nonce) . '" />';
        $html .= '<input type="hidden" id="ww-ship-dealer-id" name="dealer_id" value="" />';

        $html .= '<div class="ww-ship-dealer">';
        $html .= '<label>' . esc_html__('Dealer (type code/name to search)', 'wind-warehouse') . '</label>';
        $html .= '<input type="text" id="ww-ship-dealer-search" placeholder="' . esc_attr__('Search dealer', 'wind-warehouse') . '" autocomplete="off" />';
        $html .= '<div id="ww-ship-dealer-suggestions" class="ww-ship-suggestions"></div>';
        $html .= '</div>';

        $html .= '<div class="ww-ship-scanner">';
        $html .= '<label for="ww-ship-scan-input">' . esc_html__('Scan codes (press Enter after each)', 'wind-warehouse') . '</label>';
        $html .= '<input type="text" id="ww-ship-scan-input" autocomplete="off" />';
        $html .= '<p class="description">' . esc_html__('Scan continuously; valid codes will appear below.', 'wind-warehouse') . '</p>';
        $html .= '<div id="ww-ship-scan-message" class="ww-ship-message"></div>';
        $html .= '</div>';

        $html .= '<div class="ww-ship-table-wrapper">';
        $html .= '<table class="ww-ship-table"><thead><tr>';
        $html .= '<th>' . esc_html__('Code', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('SKU', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Color', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Size', 'wind-warehouse') . '</th>';
        $html .= '<th>' . esc_html__('Action', 'wind-warehouse') . '</th>';
        $html .= '</tr></thead><tbody id="ww-ship-rows"></tbody></table>';
        $html .= '</div>';

        $html .= '<div class="ww-ship-submit">';
        $html .= '<button type="submit" id="ww-ship-confirm" class="button button-primary">' . esc_html__('Confirm Shipment', 'wind-warehouse') . '</button>';
        $html .= '</div>';

        $html .= '</form>';

        if ($shipment_detail !== null) {
            $shipment = $shipment_detail['shipment'];
            $summary  = $shipment_detail['summary'];
            $items    = $shipment_detail['items'];
            $export_url = add_query_arg(
                [
                    'action'       => 'ww_ship_export',
                    'shipment_id'  => $shipment_id,
                    'ww_nonce'     => $export_nonce,
                ],
                $form_action
            );

            $html .= '<div class="ww-ship-summary">';
            $html .= '<h3>' . esc_html__('Shipment Summary', 'wind-warehouse') . '</h3>';
            $html .= '<p>' . esc_html__('Dealer:', 'wind-warehouse') . ' ' . esc_html($shipment['dealer_name_snapshot'] ?? ($shipment['dealer_name'] ?? '')) . '</p>';
            $html .= '<p>' . esc_html__('Address:', 'wind-warehouse') . ' ' . esc_html($shipment['dealer_address_snapshot'] ?? ($shipment['dealer_address'] ?? '')) . '</p>';
            $html .= '<p>' . esc_html__('Shipped at:', 'wind-warehouse') . ' ' . esc_html($shipment['shipped_at']);
            $html .= ' <button type="button" class="button" onclick="window.print();">' . esc_html__('Print', 'wind-warehouse') . '</button>';
            $html .= ' <a class="button" href="' . esc_url($export_url) . '">' . esc_html__('Export CSV', 'wind-warehouse') . '</a>';
            $html .= '</p>';

            $html .= '<table class="ww-ship-summary-table"><thead><tr>';
            $html .= '<th>' . esc_html__('SKU Code', 'wind-warehouse') . '</th>';
            $html .= '<th>' . esc_html__('Name', 'wind-warehouse') . '</th>';
            $html .= '<th>' . esc_html__('Color', 'wind-warehouse') . '</th>';
            $html .= '<th>' . esc_html__('Size', 'wind-warehouse') . '</th>';
            $html .= '<th>' . esc_html__('Quantity', 'wind-warehouse') . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($summary as $row) {
                $html .= '<tr>';
                $html .= '<td>' . esc_html($row['sku_code'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($row['name'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($row['color'] ?? '') . '</td>';
                $html .= '<td>' . esc_html($row['size'] ?? '') . '</td>';
                $html .= '<td>' . esc_html((string) $row['qty']) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';

            if (!empty($items)) {
                $html .= '<h4>' . esc_html__('Shipment Items', 'wind-warehouse') . '</h4>';
                $html .= '<table class="ww-ship-items-table"><thead><tr>';
                $html .= '<th>' . esc_html__('Code', 'wind-warehouse') . '</th>';
                $html .= '<th>' . esc_html__('SKU Code', 'wind-warehouse') . '</th>';
                $html .= '<th>' . esc_html__('Name', 'wind-warehouse') . '</th>';
                $html .= '<th>' . esc_html__('Color', 'wind-warehouse') . '</th>';
                $html .= '<th>' . esc_html__('Size', 'wind-warehouse') . '</th>';
                $html .= '</tr></thead><tbody>';
                foreach ($items as $item) {
                    $html .= '<tr>';
                    $html .= '<td>' . esc_html($item['code']) . '</td>';
                    $html .= '<td>' . esc_html($item['sku_code'] ?? '') . '</td>';
                    $html .= '<td>' . esc_html($item['name'] ?? '') . '</td>';
                    $html .= '<td>' . esc_html($item['color'] ?? '') . '</td>';
                    $html .= '<td>' . esc_html($item['size'] ?? '') . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        $dealers_json = wp_json_encode($dealers);
        $script  = '<script>document.addEventListener("DOMContentLoaded",function(){';
        $script .= 'const dealers=' . $dealers_json . ';';
        $script .= 'const dealerSearch=document.getElementById("ww-ship-dealer-search");';
        $script .= 'const dealerIdInput=document.getElementById("ww-ship-dealer-id");';
        $script .= 'const suggestionBox=document.getElementById("ww-ship-dealer-suggestions");';
        $script .= 'const scanInput=document.getElementById("ww-ship-scan-input");';
        $script .= 'const tableBody=document.getElementById("ww-ship-rows");';
        $script .= 'const messageBox=document.getElementById("ww-ship-scan-message");';
        $script .= 'const form=document.getElementById("ww-ship-form");';
        $script .= 'const scannedMap=new Map();';

        $script .= 'function focusScan(){ if(scanInput){ scanInput.focus(); }};';
        $script .= 'function setMessage(msg,isError=false){ if(!messageBox)return; messageBox.textContent=msg; messageBox.className="ww-ship-message"+(isError?" ww-ship-error":" ww-ship-success"); };';
        $script .= 'function renderDealerSuggestions(keyword){ suggestionBox.innerHTML=""; if(!keyword){ return; } const lower=keyword.toLowerCase(); const matched=dealers.filter(function(d){ return d.dealer_code.toLowerCase().includes(lower)||d.name.toLowerCase().includes(lower); }).slice(0,8); matched.forEach(function(d){ const btn=document.createElement("button"); btn.type="button"; btn.textContent=d.dealer_code+" - "+d.name; btn.addEventListener("click",function(){ dealerIdInput.value=d.id; dealerSearch.value=d.dealer_code+" - "+d.name; suggestionBox.innerHTML=""; focusScan(); }); suggestionBox.appendChild(btn);}); };';
        $script .= 'dealerSearch.addEventListener("input",function(e){ dealerIdInput.value=""; renderDealerSuggestions(e.target.value);});';
        $script .= 'dealerSearch.addEventListener("focus",function(){ renderDealerSuggestions(dealerSearch.value);});';

        $script .= 'function addRow(data){ if(scannedMap.has(data.code_id)){ setMessage("' . esc_js(__('Code already scanned.', 'wind-warehouse')) . '",true); return; } scannedMap.set(data.code_id,true); const tr=document.createElement("tr"); tr.dataset.codeId=data.code_id; tr.innerHTML="<td>"+data.code+"</td><td>"+(data.sku_code||"")+"</td><td>"+(data.color||"")+"</td><td>"+(data.size||"")+"</td><td><button type=\"button\" class=\"button ww-ship-remove\">' . esc_js(__('Remove', 'wind-warehouse')) . '</button></td>"; const hidden=document.createElement("input"); hidden.type="hidden"; hidden.name="code_ids[]"; hidden.value=data.code_id; tr.appendChild(hidden); const removeBtn=tr.querySelector(".ww-ship-remove"); removeBtn.addEventListener("click",function(){ scannedMap.delete(data.code_id); tr.remove(); setMessage("' . esc_js(__('Removed code.', 'wind-warehouse')) . '"); focusScan();}); tableBody.appendChild(tr); setMessage("' . esc_js(__('Code added.', 'wind-warehouse')) . '"); scanInput.value=""; focusScan(); }';

        $script .= 'async function validateCode(code){ const formData=new FormData(); formData.append("action","ww_ship_validate_code"); formData.append("code",code); formData.append("ww_nonce","' . esc_js($validate_nonce) . '"); try { const res=await fetch("' . esc_url_raw($ajax_url) . '",{method:"POST",credentials:"same-origin",body:formData}); const json=await res.json(); if(!json.success){ setMessage(json.data && json.data.message ? json.data.message : "' . esc_js(__('Invalid code.', 'wind-warehouse')) . '",true); return; } addRow(json.data); } catch(e){ setMessage("' . esc_js(__('Network error. Please try again.', 'wind-warehouse')) . '",true);} }';

        $script .= 'scanInput.addEventListener("keydown",function(e){ if(e.key==="Enter"){ e.preventDefault(); const code=scanInput.value.trim(); if(!code){ setMessage("' . esc_js(__('Please scan a code.', 'wind-warehouse')) . '",true); return; } if(dealerIdInput.value===""){ setMessage("' . esc_js(__('Please select a dealer first.', 'wind-warehouse')) . '",true); focusScan(); return; } validateCode(code); }});';

        $script .= 'form.addEventListener("submit",function(e){ if(dealerIdInput.value===""){ e.preventDefault(); setMessage("' . esc_js(__('Please select a dealer.', 'wind-warehouse')) . '",true); dealerSearch.focus(); return; } if(tableBody.children.length===0){ e.preventDefault(); setMessage("' . esc_js(__('Please scan at least one code.', 'wind-warehouse')) . '",true); focusScan(); return; } });';

        $script .= 'focusScan();';
        $script .= '});</script>';

        return $html . $script;
    }

    private static function get_active_dealers_for_ship(): array {
        global $wpdb;

        $dealer_table = $wpdb->prefix . 'wh_dealers';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, dealer_code, name, address FROM {$dealer_table} WHERE status = %s OR dealer_code = %s ORDER BY dealer_code ASC LIMIT %d",
                'active',
                self::HQ_DEALER_CODE,
                500
            ),
            ARRAY_A
        );
    }

    public static function ajax_ship_validate_code(): void {
        if (!is_user_logged_in() || !current_user_can('wh_ship_codes')) {
            wp_send_json_error(['message' => __('Forbidden', 'wind-warehouse')], 403);
        }

        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_ship_validate')) {
            wp_send_json_error(['message' => __('Invalid request. Please refresh and try again.', 'wind-warehouse')]);
        }

        $code = isset($_POST['code']) ? trim(sanitize_text_field(wp_unslash($_POST['code']))) : '';

        if ($code === '') {
            wp_send_json_error(['message' => __('Please scan a code.', 'wind-warehouse')]);
        }

        global $wpdb;
        $code_table = $wpdb->prefix . 'wh_codes';
        $sku_table  = $wpdb->prefix . 'wh_skus';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.shipped_at, c.sku_id, s.sku_code, s.name, s.color, s.size FROM {$code_table} c LEFT JOIN {$sku_table} s ON c.sku_id = s.id WHERE c.code = %s LIMIT 1",
                $code
            ),
            ARRAY_A
        );

        if ($row === null) {
            wp_send_json_error(['message' => __('Code not found.', 'wind-warehouse')]);
        }

        if ($row['status'] !== 'in_stock' || !empty($row['shipped_at'])) {
            wp_send_json_error(['message' => __('Code already shipped or unavailable.', 'wind-warehouse')]);
        }

        wp_send_json_success(
            [
                'code_id'  => (int) $row['id'],
                'code'     => $row['code'],
                'sku_id'   => (int) $row['sku_id'],
                'sku_code' => $row['sku_code'],
                'name'     => $row['name'],
                'color'    => $row['color'],
                'size'     => $row['size'],
            ]
        );
    }

    public static function admin_post_ship_confirm(): void {
        if (!is_user_logged_in()) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        if (!current_user_can('wh_ship_codes')) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        $redirect = add_query_arg('wh', 'ship', self::portal_url());

        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_ship_confirm')) {
            wp_safe_redirect(add_query_arg(['err' => 'bad_nonce'], $redirect));
            exit;
        }

        $dealer_id = isset($_POST['dealer_id']) ? absint($_POST['dealer_id']) : 0;
        $code_ids  = isset($_POST['code_ids']) && is_array($_POST['code_ids']) ? array_map('absint', $_POST['code_ids']) : [];
        $unique_code_ids = array_values(array_unique(array_filter($code_ids)));

        if ($dealer_id < 1 || empty($unique_code_ids)) {
            wp_safe_redirect(add_query_arg(['err' => 'bad_request'], $redirect));
            exit;
        }

        global $wpdb;
        $dealer_table   = $wpdb->prefix . 'wh_dealers';
        $code_table     = $wpdb->prefix . 'wh_codes';
        $shipment_table = $wpdb->prefix . 'wh_shipments';
        $items_table    = $wpdb->prefix . 'wh_shipment_items';
        $events_table   = $wpdb->prefix . 'wh_events';

        $dealer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name, address FROM {$dealer_table} WHERE id = %d AND (status = %s OR dealer_code = %s) LIMIT 1",
                $dealer_id,
                'active',
                self::HQ_DEALER_CODE
            ),
            ARRAY_A
        );

        if ($dealer === null) {
            wp_safe_redirect(add_query_arg(['err' => 'invalid_dealer'], $redirect));
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($unique_code_ids), '%d'));

        $wpdb->query('START TRANSACTION');

        $codes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, code, sku_id, status, shipped_at FROM {$code_table} WHERE id IN ({$placeholders}) FOR UPDATE",
                $unique_code_ids
            ),
            ARRAY_A
        );

        if (count($codes) !== count($unique_code_ids)) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg(['err' => 'invalid_codes'], $redirect));
            exit;
        }

        foreach ($codes as $code_row) {
            if ($code_row['status'] !== 'in_stock' || !empty($code_row['shipped_at'])) {
                $wpdb->query('ROLLBACK');
                wp_safe_redirect(add_query_arg(['err' => 'invalid_codes'], $redirect));
                exit;
            }
        }

        $now = current_time('mysql');

        $shipment_inserted = $wpdb->insert(
            $shipment_table,
            [
                'dealer_id'               => $dealer_id,
                'created_by'              => get_current_user_id(),
                'shipped_at'              => $now,
                'status'                  => 'shipped',
                'dealer_name_snapshot'    => $dealer['name'] ?? null,
                'dealer_address_snapshot' => $dealer['address'] ?? null,
                'created_at'              => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($shipment_inserted === false) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg(['err' => 'db_error'], $redirect));
            exit;
        }

        $shipment_id = (int) $wpdb->insert_id;

        foreach ($codes as $code_row) {
            $inserted_item = $wpdb->insert(
                $items_table,
                [
                    'shipment_id' => $shipment_id,
                    'code_id'     => $code_row['id'],
                    'sku_id'      => $code_row['sku_id'],
                    'code'        => $code_row['code'],
                    'created_at'  => $now,
                ],
                ['%d', '%d', '%d', '%s', '%s']
            );

            if ($inserted_item === false) {
                $wpdb->query('ROLLBACK');
                $error_param = stripos((string) $wpdb->last_error, 'duplicate') !== false ? 'duplicate_code' : 'db_error';
                wp_safe_redirect(add_query_arg(['err' => $error_param], $redirect));
                exit;
            }
        }

        $update_sql = $wpdb->prepare(
            "UPDATE {$code_table} SET status = %s, dealer_id = %d, shipment_id = %d, shipped_at = %s WHERE id IN ({$placeholders})",
            array_merge(['shipped', $dealer_id, $shipment_id, $now], $unique_code_ids)
        );

        $updated = $wpdb->query($update_sql);

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg(['err' => 'db_error'], $redirect));
            exit;
        }

        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events_table)) === $events_table) {
            $wpdb->insert(
                $events_table,
                [
                    'event_type' => 'ship',
                    'code'       => $codes[0]['code'],
                    'code_id'    => $codes[0]['id'],
                    'ip'         => $ip_address,
                    'meta_json'  => wp_json_encode([
                        'shipment_id' => $shipment_id,
                        'dealer_id'   => $dealer_id,
                        'qty'         => count($unique_code_ids),
                        'actor'       => get_current_user_id(),
                        'code_ids'    => $unique_code_ids,
                    ]),
                    'created_at' => $now,
                ],
                ['%s', '%s', '%d', '%s', '%s', '%s']
            );
        }

        $wpdb->query('COMMIT');

        $redirect_url = add_query_arg(
            [
                'wh'          => 'ship',
                'msg'         => 'shipped',
                'shipment_id' => $shipment_id,
            ],
            self::portal_url()
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    public static function admin_post_ship_export(): void {
        if (!is_user_logged_in()) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        if (!current_user_can('wh_ship_codes')) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        $shipment_id = isset($_GET['shipment_id']) ? absint($_GET['shipment_id']) : 0;
        if ($shipment_id < 1) {
            wp_die(__('Invalid request.', 'wind-warehouse'), '', ['response' => 400]);
        }

        if (!isset($_GET['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['ww_nonce'])), 'ww_ship_export')) {
            wp_die(__('Invalid request.', 'wind-warehouse'), '', ['response' => 400]);
        }

        $detail = self::get_shipment_detail($shipment_id);

        if ($detail === null) {
            wp_die(__('Shipment not found.', 'wind-warehouse'), '', ['response' => 404]);
        }

        $shipment = $detail['shipment'];
        $summary  = $detail['summary'];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="shipment-' . $shipment_id . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['dealer_code', 'dealer_name', 'address', 'shipped_at', 'sku_code', 'name', 'color', 'size', 'qty']);

        foreach ($summary as $row) {
            fputcsv(
                $output,
                [
                    $shipment['dealer_code'] ?? '',
                    $shipment['dealer_name_snapshot'] ?? ($shipment['dealer_name'] ?? ''),
                    $shipment['dealer_address_snapshot'] ?? ($shipment['dealer_address'] ?? ''),
                    $shipment['shipped_at'],
                    $row['sku_code'] ?? '',
                    $row['name'] ?? '',
                    $row['color'] ?? '',
                    $row['size'] ?? '',
                    $row['qty'],
                ]
            );
        }

        fclose($output);
        exit;
    }

    private static function get_shipment_detail(int $shipment_id): ?array {
        global $wpdb;

        $shipment_table = $wpdb->prefix . 'wh_shipments';
        $items_table    = $wpdb->prefix . 'wh_shipment_items';
        $dealer_table   = $wpdb->prefix . 'wh_dealers';
        $sku_table      = $wpdb->prefix . 'wh_skus';

        $shipment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.*, d.dealer_code, d.name as dealer_name, d.address as dealer_address FROM {$shipment_table} s LEFT JOIN {$dealer_table} d ON s.dealer_id = d.id WHERE s.id = %d",
                $shipment_id
            ),
            ARRAY_A
        );

        if ($shipment === null) {
            return null;
        }

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*, sk.sku_code, sk.name, sk.color, sk.size FROM {$items_table} i LEFT JOIN {$sku_table} sk ON i.sku_id = sk.id WHERE i.shipment_id = %d",
                $shipment_id
            ),
            ARRAY_A
        );

        $summary = [];
        foreach ($items as $item) {
            $key = (string) ($item['sku_id'] ?? '');
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'sku_code' => $item['sku_code'] ?? '',
                    'name'     => $item['name'] ?? '',
                    'color'    => $item['color'] ?? '',
                    'size'     => $item['size'] ?? '',
                    'qty'      => 0,
                ];
            }

            $summary[$key]['qty']++;
        }

        return [
            'shipment' => $shipment,
            'items'    => $items,
            'summary'  => array_values($summary),
        ];
    }

    public static function admin_post_ship_create(): void {
        if (!is_user_logged_in()) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        if (!current_user_can('wh_ship_codes')) {
            wp_die(__('Forbidden', 'wind-warehouse'), '', ['response' => 403]);
        }

        $redirect = add_query_arg('wh', 'ship', self::portal_url());

        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_ship_create')) {
            wp_safe_redirect(add_query_arg(['err' => 'bad_nonce'], $redirect));
            exit;
        }

        $dealer_id = isset($_POST['dealer_id']) ? absint($_POST['dealer_id']) : 0;
        $sku_id    = isset($_POST['sku_id']) ? absint($_POST['sku_id']) : 0;
        $quantity  = isset($_POST['quantity']) ? absint($_POST['quantity']) : 0;
        $notes     = isset($_POST['notes']) ? sanitize_text_field(wp_unslash($_POST['notes'])) : '';

        if ($dealer_id < 1 || $sku_id < 1 || $quantity < 1 || $quantity > 200) {
            wp_safe_redirect(add_query_arg(['err' => 'bad_request'], $redirect));
            exit;
        }

        global $wpdb;
        $dealer_table   = $wpdb->prefix . 'wh_dealers';
        $sku_table      = $wpdb->prefix . 'wh_skus';
        $code_table     = $wpdb->prefix . 'wh_codes';
        $shipment_table = $wpdb->prefix . 'wh_shipments';
        $items_table    = $wpdb->prefix . 'wh_shipment_items';
        $events_table   = $wpdb->prefix . 'wh_events';

        $dealer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, dealer_code, name, address FROM {$dealer_table} WHERE id = %d AND (status = %s OR dealer_code = %s) LIMIT 1",
                $dealer_id,
                'active',
                self::HQ_DEALER_CODE
            ),
            ARRAY_A
        );

        if ($dealer === null) {
            wp_safe_redirect(add_query_arg(['err' => 'invalid_dealer'], $redirect));
            exit;
        }

        $sku_exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$sku_table} WHERE id = %d AND status = %s", $sku_id, 'active')
        );

        if ($sku_exists === null) {
            wp_safe_redirect(add_query_arg(['err' => 'invalid_sku'], $redirect));
            exit;
        }

        $wpdb->query('START TRANSACTION');

        $codes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, code FROM {$code_table} WHERE sku_id = %d AND status = %s ORDER BY id ASC LIMIT %d FOR UPDATE",
                $sku_id,
                'in_stock',
                $quantity
            ),
            ARRAY_A
        );

        if (count($codes) < $quantity) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg(['err' => 'insufficient_stock'], $redirect));
            exit;
        }

        $now = current_time('mysql');

        $shipment_inserted = $wpdb->insert(
            $shipment_table,
            [
                'dealer_id'               => $dealer_id,
                'created_by'              => get_current_user_id(),
                'shipped_at'              => $now,
                'status'                  => 'shipped',
                'notes'                   => $notes !== '' ? $notes : null,
                'dealer_name_snapshot'    => $dealer['name'] ?? null,
                'dealer_address_snapshot' => $dealer['address'] ?? null,
                'created_at'              => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($shipment_inserted === false) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg(['err' => 'db_error'], $redirect));
            exit;
        }

        $shipment_id = (int) $wpdb->insert_id;

        foreach ($codes as $code_row) {
            $inserted_item = $wpdb->insert(
                $items_table,
                [
                    'shipment_id' => $shipment_id,
                    'code_id'     => $code_row['id'],
                    'sku_id'      => $sku_id,
                    'code'        => $code_row['code'],
                    'created_at'  => $now,
                ],
                ['%d', '%d', '%d', '%s', '%s']
            );

            if ($inserted_item === false) {
                $wpdb->query('ROLLBACK');
                wp_safe_redirect(add_query_arg(['err' => 'db_error'], $redirect));
                exit;
            }
        }

        $code_ids = wp_list_pluck($codes, 'id');
        $placeholders = implode(',', array_fill(0, count($code_ids), '%d'));

        $update_sql = $wpdb->prepare(
            "UPDATE {$code_table} SET status = %s, dealer_id = %d, shipment_id = %d, shipped_at = %s WHERE id IN ({$placeholders})",
            array_merge(['shipped', $dealer_id, $shipment_id, $now], $code_ids)
        );

        $updated = $wpdb->query($update_sql);

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg(['err' => 'db_error'], $redirect));
            exit;
        }

        $first_code = $codes[0];
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        $wpdb->insert(
            $events_table,
            [
                'event_type' => 'ship',
                'code'       => $first_code['code'],
                'code_id'    => $first_code['id'],
                'ip'         => $ip_address !== '' ? $ip_address : '0.0.0.0',
                'meta_json'  => wp_json_encode([
                    'dealer_id'   => $dealer_id,
                    'sku_id'      => $sku_id,
                    'quantity'    => $quantity,
                    'shipment_id' => $shipment_id,
                    'operator'    => get_current_user_id(),
                    'notes'       => $notes,
                ]),
                'created_at' => $now,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s']
        );

        $wpdb->query('COMMIT');

        $redirect_url = add_query_arg(
            [
                'wh'  => 'ship',
                'msg' => 'shipped',
            ],
            self::portal_url()
        );

        wp_safe_redirect($redirect_url);
        exit;
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
