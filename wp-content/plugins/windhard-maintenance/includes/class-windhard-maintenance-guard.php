<?php
/**
 * Front-end guard for Windhard Maintenance.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Windhard_Maintenance_Guard {
    /**
     * Options.
     *
     * @var array
     */
    private $options = array();

    /**
     * Constructor.
     *
     * @param array $options Options array.
     */
    public function __construct($options) {
        $this->options = $options;
    }

    /**
     * Hook into WordPress.
     */
    public function init() {
        add_action('template_redirect', array($this, 'maybe_block'), 0);
    }

    /**
     * Maybe intercept the request.
     */
    public function maybe_block() {
        $options = Windhard_Maintenance::get_options();
        if (empty($options['enabled'])) {
            return;
        }

        if (is_user_logged_in() && current_user_can('manage_options')) {
            return;
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($this->user_has_allowed_role($user, $options)) {
                return;
            }
        }

        if ($this->ip_is_whitelisted($options)) {
            return;
        }

        $path = $this->get_current_path();
        if ($this->is_system_endpoint($path)) {
            return;
        }

        if ($this->is_login_exempt($path, $options)) {
            return;
        }

        if (!$this->should_intercept_path($path, $options)) {
            return;
        }

        $this->send_headers($options);
        $reason = $this->resolve_reason($options);
        $mode = isset($options['mode']) ? $options['mode'] : 'maintenance';
        $noindex = !empty($options['noindex']);

        include WINDHARD_MAINTENANCE_PLUGIN_DIR . 'public/maintenance-template.php';
        exit;
    }

    /**
     * Check if user has allowed role.
     *
     * @param WP_User $user    User object.
     * @param array   $options Options.
     * @return bool
     */
    private function user_has_allowed_role($user, $options) {
        if (!($user instanceof WP_User)) {
            return false;
        }
        $allowed_roles = isset($options['allow_roles']) ? (array) $options['allow_roles'] : array();
        foreach ((array) $user->roles as $role) {
            if (in_array($role, $allowed_roles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check IP whitelist.
     *
     * @param array $options Options.
     * @return bool
     */
    private function ip_is_whitelisted($options) {
        $lines = $this->explode_lines(isset($options['ip_whitelist']) ? $options['ip_whitelist'] : '');
        if (empty($lines)) {
            return false;
        }

        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ($remote_ip === '') {
            return false;
        }

        foreach ($lines as $line) {
            if ($this->ip_in_range($remote_ip, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if IP is in range (single IP or CIDR).
     *
     * @param string $ip   Client IP.
     * @param string $range Allowed IP or CIDR.
     * @return bool
     */
    private function ip_in_range($ip, $range) {
        if ($range === '') {
            return false;
        }

        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        list($subnet, $mask) = explode('/', $range, 2);
        if ($subnet === '' || $mask === '') {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || filter_var($subnet, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $mask = intval($mask);
        if ($mask < 0 || $mask > 32) {
            return false;
        }

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - $mask);

        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }

    /**
     * Get current request path.
     *
     * @return string
     */
    private function get_current_path() {
        $path = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $parsed = wp_parse_url($path, PHP_URL_PATH);
        if (empty($parsed)) {
            $parsed = '/';
        }

        return $parsed;
    }

    /**
     * Determine if path is a system endpoint that should always pass.
     *
     * @param string $path Path.
     * @return bool
     */
    private function is_system_endpoint($path) {
        $path = untrailingslashit($path);
        return $path === '/wp-admin/admin-ajax.php' || $path === '/wp-cron.php';
    }

    /**
     * Check login exempt paths.
     *
     * @param string $path    Current path.
     * @param array  $options Options.
     * @return bool
     */
    private function is_login_exempt($path, $options) {
        if ($path === '/wp-login.php') {
            return true;
        }
        $lines = $this->explode_lines(isset($options['login_exempt_paths']) ? $options['login_exempt_paths'] : '');
        return $this->path_matches_any($path, $lines);
    }

    /**
     * Determine if path should be intercepted based on scope.
     *
     * @param string $path    Current path.
     * @param array  $options Options.
     * @return bool
     */
    private function should_intercept_path($path, $options) {
        $scope_mode = isset($options['scope_mode']) ? $options['scope_mode'] : 'all';
        $intercept_paths = $this->explode_lines(isset($options['intercept_paths']) ? $options['intercept_paths'] : '');
        $allow_paths = $this->explode_lines(isset($options['allow_paths']) ? $options['allow_paths'] : '');

        if ($scope_mode === 'include_only') {
            return $this->path_matches_any($path, $intercept_paths);
        }

        if ($scope_mode === 'exclude') {
            if ($this->path_matches_any($path, $allow_paths)) {
                return false;
            }
            return true;
        }

        return true;
    }

    /**
     * Check if path matches any pattern.
     *
     * @param string $path     Path.
     * @param array  $patterns Patterns.
     * @return bool
     */
    private function path_matches_any($path, $patterns) {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if ($this->match_pattern($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pattern matcher supporting wildcard.
     *
     * @param string $path    Path.
     * @param string $pattern Pattern.
     * @return bool
     */
    private function match_pattern($path, $pattern) {
        if (strpos($pattern, '*') === false) {
            return strpos($path, $pattern) === 0;
        }

        $quoted = str_replace('\*', '[^/]+', preg_quote($pattern, '#'));
        $regex = '#^' . $quoted . '#';

        return (bool) preg_match($regex, $path);
    }

    /**
     * Send headers for maintenance response.
     *
     * @param array $options Options.
     */
    private function send_headers($options) {
        if (!headers_sent()) {
            status_header(503);
            if ($options['retry_after_minutes'] !== null) {
                $seconds = intval($options['retry_after_minutes']) * 60;
                header('Retry-After: ' . $seconds);
            }
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
    }

    /**
     * Resolve maintenance reason text.
     *
     * @param array $options Options.
     * @return string
     */
    private function resolve_reason($options) {
        if (!empty($options['reason_custom'])) {
            return $options['reason_custom'];
        }

        $presets = array(
            'routine' => __('例行维护', 'windhard-maintenance'),
            'upgrade' => __('系统升级', 'windhard-maintenance'),
            'emergency_fix' => __('紧急修复', 'windhard-maintenance'),
            'migration' => __('数据迁移', 'windhard-maintenance'),
            'third_party' => __('第三方服务故障', 'windhard-maintenance'),
            'security' => __('安全加固', 'windhard-maintenance'),
            'other' => __('其他', 'windhard-maintenance'),
        );

        $preset = isset($options['reason_preset']) ? $options['reason_preset'] : 'routine';

        return isset($presets[$preset]) ? $presets[$preset] : $presets['routine'];
    }

    /**
     * Explode stored lines to array.
     *
     * @param string $value Value.
     * @return array
     */
    private function explode_lines($value) {
        $lines = preg_split('/\r?\n/', (string) $value);
        if (!is_array($lines)) {
            return array();
        }

        return array_filter(array_map('trim', $lines), 'strlen');
    }
}
