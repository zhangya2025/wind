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
            if ($permalink) {
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
        }

        $content = self::render_content($view_key, $nav_items[$view_key]);
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

    private static function render_content(string $view_key, string $label): string {
        if ($view_key === 'dashboard') {
            return '<p>' . esc_html__('Welcome to the warehouse portal. Select a module to continue.', 'wind-warehouse') . '</p>';
        }

        return '<p>' . sprintf(
            /* translators: %s: module name */
            esc_html__('Coming soon: %s', 'wind-warehouse'),
            esc_html($label)
        ) . '</p>';
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