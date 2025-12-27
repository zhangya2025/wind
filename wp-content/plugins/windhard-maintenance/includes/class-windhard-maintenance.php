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
            'headline_text' => '',
            'headline_color' => '#FFFFFF',
            'headline_size' => 'xl',
            'subhead_text' => '',
            'subhead_color' => '#FFFFFF',
            'subhead_size' => 'm',
        );
    }

    /**
     * Get merged options with defaults.
     *
     * @return array
     */
    public static function get_options() {
        $defaults = self::get_default_options();
        $saved = get_option('whm_settings', array());
        if (!is_array($saved)) {
            $saved = array();
        }

        // Legacy option compatibility to avoid losing previously stored settings.
        if (empty($saved)) {
            $legacy = get_option('windhard_maintenance_options', array());
            if (is_array($legacy) && !empty($legacy)) {
                $saved = $legacy;
            }
        }

        return wp_parse_args($saved, $defaults);
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
