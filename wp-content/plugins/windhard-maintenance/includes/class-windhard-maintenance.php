<?php
/**
 * Core plugin bootstrap for Windhard Maintenance.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Windhard_Maintenance {
    /**
     * Plugin options.
     *
     * @var array
     */
    private $options = array();

    /**
     * Default options.
     *
     * @return array
     */
    public static function get_default_options() {
        return array(
            'enabled' => false,
            'mode' => 'maintenance',
            'reason_preset' => 'routine',
            'reason_custom' => '',
            'allow_roles' => array('administrator'),
            'ip_whitelist' => '',
            'login_exempt_paths' => "/windlogin.php",
            'scope_mode' => 'all',
            'intercept_paths' => '',
            'allow_paths' => '',
            'send_503' => true,
            'retry_after_minutes' => null,
            'noindex' => true,
        );
    }

    /**
     * Get merged options with defaults.
     *
     * @return array
     */
    public static function get_options() {
        $defaults = self::get_default_options();
        $saved = get_option('windhard_maintenance_options', array());
        if (!is_array($saved)) {
            $saved = array();
        }

        return array_merge($defaults, $saved);
    }

    /**
     * Run the plugin hooks.
     */
    public function run() {
        $this->options = self::get_options();

        if (is_admin()) {
            $admin = new Windhard_Maintenance_Admin($this->options);
            $admin->init();
        }

        $guard = new Windhard_Maintenance_Guard($this->options);
        $guard->init();
    }
}
