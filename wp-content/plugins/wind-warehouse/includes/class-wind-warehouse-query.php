<?php
if (!defined('ABSPATH')) {
    exit;
}

final class Wind_Warehouse_Query {
    private const SHORTCODE = 'wind_warehouse_query';
    private const PAGE_SLUG = 'query';
    private const LEGACY_SLUG = 'verify';
    private const RATE_LIMIT_MAX = 10;
    private const RATE_LIMIT_WINDOW = MINUTE_IN_SECONDS;

    public static function register_shortcode(): void {
        add_shortcode(self::SHORTCODE, [self::class, 'render_shortcode']);
        add_action('admin_post_ww_reset_b', [self::class, 'admin_post_reset_b']);
        add_action('admin_post_nopriv_ww_reset_b', [self::class, 'admin_post_reset_b']);
    }

    public static function ensure_query_page(bool $force = false): void {
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

        self::ensure_single_page(self::PAGE_SLUG);
        self::ensure_single_page(self::LEGACY_SLUG, true);
    }

    private static function ensure_single_page(string $slug, bool $allow_existing = false): void {
        $page = get_page_by_path($slug);

        if ($page instanceof WP_Post) {
            if (strpos((string) $page->post_content, '[' . self::SHORTCODE . ']') === false) {
                wp_update_post([
                    'ID'           => $page->ID,
                    'post_content' => '[' . self::SHORTCODE . ']',
                ]);
            }
            return;
        }

        if (!$allow_existing || !$page instanceof WP_Post) {
            wp_insert_post([
                'post_title'   => ucfirst($slug),
                'post_name'    => $slug,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '[' . self::SHORTCODE . ']',
            ]);
        }
    }

    public static function render_shortcode($atts = []): string {
        $code_input = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $is_internal = self::is_internal_actor();

        $html  = '<div class="ww-query">';
        $html .= '<form method="get">';
        $html .= '<label for="ww-code-input">' . esc_html__('防伪码', 'wind-warehouse') . '</label> ';
        $html .= '<input type="text" id="ww-code-input" name="code" value="' . esc_attr($code_input) . '" />';
        $html .= '<button type="submit">' . esc_html__('查询', 'wind-warehouse') . '</button>';
        $html .= '</form>';

        if ($code_input !== '') {
            $rate_error = self::check_rate_limit();
            if ($rate_error !== null) {
                $html .= '<p class="ww-error">' . esc_html($rate_error) . '</p>';
                $html .= '</div>';
                return $html;
            }

            if (!preg_match('/^[A-Fa-f0-9]{20}$/', $code_input)) {
                $html .= '<p class="ww-error">' . esc_html__('防伪码格式无效', 'wind-warehouse') . '</p>';
                $html .= '</div>';
                return $html;
            }

            $result = self::handle_query($code_input, $is_internal);
            $html  .= $result;
        }

        $html .= '</div>';
        return $html;
    }

    private static function handle_query(string $code, bool $is_internal): string {
        global $wpdb;

        $code_table   = $wpdb->prefix . 'wh_codes';
        $dealer_table = $wpdb->prefix . 'wh_dealers';
        $sku_table    = $wpdb->prefix . 'wh_skus';
        $events_table = $wpdb->prefix . 'wh_events';

        $wpdb->query('START TRANSACTION');

        $code_row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$code_table} WHERE code = %s FOR UPDATE", $code),
            ARRAY_A
        );

        if ($code_row === null) {
            $wpdb->query('ROLLBACK');
            return '<p class="ww-error">' . esc_html__('未找到防伪码', 'wind-warehouse') . '</p>';
        }

        $update_fields = [];
        $update_formats = [];

        if ($is_internal) {
            $update_fields['internal_query_count'] = (int) ($code_row['internal_query_count'] ?? 0) + 1;
            $update_formats[] = '%d';
        } else {
            $update_fields['consumer_query_count_lifetime'] = (int) ($code_row['consumer_query_count_lifetime'] ?? 0) + 1;
            $update_formats[] = '%d';
            if (array_key_exists('last_consumer_query_at', $code_row)) {
                $update_fields['last_consumer_query_at'] = current_time('mysql');
                $update_formats[] = '%s';
            }
        }

        $updated = true;
        if (!empty($update_fields)) {
            $updated = $wpdb->update(
                $code_table,
                $update_fields,
                ['id' => $code_row['id']],
                $update_formats,
                ['%d']
            );
        }

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            return '<p class="ww-error">' . esc_html__('查询失败，请稍后再试', 'wind-warehouse') . '</p>';
        }

        $wpdb->query('COMMIT');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, d.name AS dealer_name, d.dealer_code, s.name AS sku_name, s.sku_code, s.color, s.size FROM {$code_table} c " .
                "LEFT JOIN {$dealer_table} d ON c.dealer_id = d.id " .
                "LEFT JOIN {$sku_table} s ON c.sku_id = s.id WHERE c.code = %s LIMIT 1",
                $code
            ),
            ARRAY_A
        );

        if ($row === null) {
            return '<p class="ww-error">' . esc_html__('未找到防伪码', 'wind-warehouse') . '</p>';
        }

        $is_unshipped = false;
        $status = (string) ($row['status'] ?? '');
        $shipped_at = $row['shipped_at'] ?? null;
        if ($status === 'in_stock' || empty($shipped_at)) {
            $is_unshipped = true;
        }

        $dealer_display = $row['dealer_name'] ?? '';
        if ($dealer_display === '' || (!$is_internal && $is_unshipped)) {
            $dealer_display = '总部销售';
        }

        if (!$is_internal && $is_unshipped) {
            $meta = [
                'code'      => $code,
                'code_id'   => $row['id'] ?? null,
                'sku_id'    => $row['sku_id'] ?? null,
                'actor_user_id' => get_current_user_id(),
                'external'  => true,
                'actor_ip'  => self::get_request_ip(),
                'timestamp' => current_time('mysql'),
                'unshipped' => true,
            ];

            $wpdb->insert(
                $events_table,
                [
                    'event_type' => 'consumer_query_unshipped',
                    'code'       => $code,
                    'code_id'    => $row['id'] ?? null,
                    'ip'         => self::get_request_ip(),
                    'counted'    => 0,
                    'meta_json'  => wp_json_encode($meta),
                    'created_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%d', '%s', '%d', '%s', '%s']
            );
        }

        $lifetime = (int) ($row['consumer_query_count_lifetime'] ?? 0);
        $offset   = (int) ($row['consumer_query_offset'] ?? 0);
        $b_value  = max($lifetime - $offset, 0);

        if (!$is_internal) {
            $html  = '<div class="ww-result">';
            $html .= '<p>' . esc_html__('产品名', 'wind-warehouse') . '：' . esc_html($row['sku_name'] ?? '') . '</p>';
            $html .= '<p>' . esc_html__('防伪码', 'wind-warehouse') . '：' . esc_html($code) . '</p>';
            $html .= '<p>' . esc_html__('经销商', 'wind-warehouse') . '：' . esc_html($dealer_display) . '</p>';
            $html .= '</div>';
            return $html;
        }

        $html  = '<div class="ww-result">';
        $html .= '<p>' . esc_html__('产品名', 'wind-warehouse') . '：' . esc_html($row['sku_name'] ?? '') . '</p>';
        $html .= '<p>' . esc_html__('SKU', 'wind-warehouse') . '：' . esc_html($row['sku_code'] ?? '') . '</p>';
        $html .= '<p>' . esc_html__('防伪码', 'wind-warehouse') . '：' . esc_html($code) . '</p>';
        $html .= '<p>' . esc_html__('状态', 'wind-warehouse') . '：' . esc_html($status) . '</p>';
        if (!empty($shipped_at)) {
            $html .= '<p>' . esc_html__('出库时间', 'wind-warehouse') . '：' . esc_html($shipped_at) . '</p>';
        }
        $html .= '<p>' . esc_html__('经销商', 'wind-warehouse') . '：' . esc_html($dealer_display) . '</p>';
        $html .= '<p>' . esc_html__('内部查询 A', 'wind-warehouse') . '：' . esc_html((int) ($row['internal_query_count'] ?? 0)) . '</p>';
        $html .= '<p>' . esc_html__('B（可清零）', 'wind-warehouse') . '：' . esc_html($b_value) . '</p>';
        $html .= '</div>';

        return $html;
    }

    public static function render_reset_b_view(): string {
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $message = '';
        if (isset($_GET['msg']) && $_GET['msg'] === 'reset') {
            $message = esc_html__('已清零', 'wind-warehouse');
        } elseif (isset($_GET['err'])) {
            $message = esc_html__('操作失败：', 'wind-warehouse') . ' ' . esc_html(sanitize_text_field(wp_unslash($_GET['err'])));
        }

        $html  = '<div class="ww-reset-b">';
        if ($message !== '') {
            $html .= '<p>' . $message . '</p>';
        }
        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= wp_nonce_field('ww_reset_b', 'ww_nonce', true, false);
        $html .= '<input type="hidden" name="action" value="ww_reset_b" />';
        $html .= '<label for="ww-reset-code">' . esc_html__('防伪码', 'wind-warehouse') . '</label> ';
        $html .= '<input type="text" id="ww-reset-code" name="code" value="' . esc_attr($code) . '" />';
        $html .= '<button type="submit">' . esc_html__('清零 B', 'wind-warehouse') . '</button>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    public static function admin_post_reset_b(): void {
        $redirect = Wind_Warehouse_Portal::portal_url();
        $redirect = add_query_arg('wh', 'reset-b', $redirect);

        if (!is_user_logged_in()) {
            wp_safe_redirect(add_query_arg('err', 'forbidden', $redirect));
            exit;
        }

        $user = wp_get_current_user();
        if (!$user instanceof WP_User) {
            wp_safe_redirect(add_query_arg('err', 'forbidden', $redirect));
            exit;
        }

        $has_internal_cap = user_can($user, 'wh_reset_consumer_count_internal');
        $has_dealer_cap   = user_can($user, 'wh_reset_consumer_count_dealer');

        if (!$has_internal_cap && !$has_dealer_cap) {
            wp_safe_redirect(add_query_arg('err', 'forbidden', $redirect));
            exit;
        }

        if (!isset($_POST['ww_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ww_nonce'])), 'ww_reset_b')) {
            wp_safe_redirect(add_query_arg('err', 'bad_nonce', $redirect));
            exit;
        }

        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
        if (!preg_match('/^[A-Fa-f0-9]{20}$/', $code)) {
            wp_safe_redirect(add_query_arg('err', 'invalid_code', $redirect));
            exit;
        }

        $current_dealer_id = null;
        if ($has_dealer_cap && !$has_internal_cap) {
            $current_dealer_id = apply_filters('wind_warehouse_current_dealer_id', null, $user);
            if ($current_dealer_id === null) {
                wp_safe_redirect(add_query_arg('err', rawurlencode('经销商用户不可清零'), $redirect));
                exit;
            }
        }

        global $wpdb;
        $code_table   = $wpdb->prefix . 'wh_codes';
        $events_table = $wpdb->prefix . 'wh_events';

        $wpdb->query('START TRANSACTION');

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$code_table} WHERE code = %s FOR UPDATE", $code),
            ARRAY_A
        );

        if ($row === null) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg('err', 'not_found', $redirect));
            exit;
        }

        $code_dealer_id = isset($row['dealer_id']) ? (int) $row['dealer_id'] : null;
        if ($current_dealer_id !== null) {
            if ($code_dealer_id === null || $code_dealer_id !== (int) $current_dealer_id) {
                $wpdb->query('ROLLBACK');
                wp_safe_redirect(add_query_arg('err', rawurlencode('无权清零该防伪码'), $redirect));
                exit;
            }
        }

        $lifetime = (int) ($row['consumer_query_count_lifetime'] ?? 0);
        $offset   = (int) ($row['consumer_query_offset'] ?? 0);
        $b_value  = max($lifetime - $offset, 0);

        $meta_before = [
            'lifetime' => $lifetime,
            'offset'   => $offset,
            'B'        => $b_value,
        ];

        $update_result = $wpdb->update(
            $code_table,
            ['consumer_query_offset' => $lifetime],
            ['id' => $row['id']],
            ['%d'],
            ['%d']
        );

        if ($update_result === false) {
            $wpdb->query('ROLLBACK');
            wp_safe_redirect(add_query_arg('err', 'db_error', $redirect));
            exit;
        }

        $meta_after = [
            'lifetime' => $lifetime,
            'offset'   => $lifetime,
            'B'        => 0,
        ];

        $wpdb->insert(
            $events_table,
            [
                'event_type'  => 'dealer_reset_b',
                'code'        => $code,
                'code_id'     => $row['id'],
                'ip'          => self::get_request_ip(),
                'counted'     => 0,
                'meta_before' => wp_json_encode($meta_before),
                'meta_after'  => wp_json_encode($meta_after),
                'meta_json'   => wp_json_encode([
                    'actor_user_id' => get_current_user_id(),
                    'dealer_id'     => $code_dealer_id,
                ]),
                'created_at'  => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        $wpdb->query('COMMIT');

        wp_safe_redirect(add_query_arg(['msg' => 'reset', 'code' => rawurlencode($code)], $redirect));
        exit;
    }

    private static function check_rate_limit(): ?string {
        $ip = self::get_request_ip();
        if ($ip === '') {
            return null;
        }

        $key = 'ww_query_' . md5($ip);
        $data = get_transient($key);
        if (!is_array($data)) {
            $data = ['count' => 0];
        }

        $data['count']++;
        set_transient($key, $data, self::RATE_LIMIT_WINDOW);

        if ($data['count'] > self::RATE_LIMIT_MAX) {
            return __('请求过于频繁，请稍后再试', 'wind-warehouse');
        }

        return null;
    }

    private static function is_internal_actor(): bool {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (current_user_can('wh_view_portal')) {
            return true;
        }

        return false;
    }

    private static function get_request_ip(): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return substr($ip, 0, 45);
    }
}
