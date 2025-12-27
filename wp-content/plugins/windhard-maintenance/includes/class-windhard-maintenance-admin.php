<?php
/**
 * Admin settings for Windhard Maintenance.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Windhard_Maintenance_Admin {
    /**
     * Cached options.
     *
     * @var array
     */
    private $options = array();

    /**
     * Allowed values for presets.
     *
     * @var array
     */
    private $allowed_modes = array('maintenance', 'disabled');

    /**
     * Allowed scope modes.
     *
     * @var array
     */
    private $allowed_scope_modes = array('all', 'include_only', 'exclude');

    /**
     * Allowed reasons.
     *
     * @var array
     */
    private $allowed_reasons = array(
        'routine',
        'upgrade',
        'emergency_fix',
        'migration',
        'third_party',
        'security',
        'other',
    );

    /**
     * Allowed headline sizes.
     *
     * @var array
     */
    private $allowed_headline_sizes = array('l', 'xl', 'xxl');

    /**
     * Allowed subhead sizes.
     *
     * @var array
     */
    private $allowed_subhead_sizes = array('s', 'm', 'l');

    /**
     * Constructor.
     *
     * @param array $options Options array.
     */
    public function __construct($options) {
        $this->options = $options;
    }

    /**
     * Initialize hooks.
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Add options page.
     */
    public function add_settings_page() {
        add_options_page(
            __('Wind Maintenance', 'windhard-maintenance'),
            __('Wind Maintenance', 'windhard-maintenance'),
            'manage_options',
            'windhard-maintenance',
            array($this, 'render_page')
        );
    }

    /**
     * Register settings and fields.
     */
    public function register_settings() {
        register_setting('windhard_maintenance', 'windhard_maintenance_options', array($this, 'sanitize_options'));

        add_settings_section(
            'windhard_maintenance_section',
            __('Maintenance Settings', 'windhard-maintenance'),
            '__return_false',
            'windhard-maintenance'
        );

        $fields = array(
            'enabled' => array('label' => __('Enable maintenance', 'windhard-maintenance'), 'callback' => 'field_enabled'),
            'mode' => array('label' => __('Mode', 'windhard-maintenance'), 'callback' => 'field_mode'),
            'reason_preset' => array('label' => __('Reason preset', 'windhard-maintenance'), 'callback' => 'field_reason_preset'),
            'reason_custom' => array('label' => __('Custom reason', 'windhard-maintenance'), 'callback' => 'field_reason_custom'),
            'headline_text' => array('label' => __('Headline text', 'windhard-maintenance'), 'callback' => 'field_headline_text'),
            'headline_color' => array('label' => __('Headline color', 'windhard-maintenance'), 'callback' => 'field_headline_color'),
            'headline_size' => array('label' => __('Headline size', 'windhard-maintenance'), 'callback' => 'field_headline_size'),
            'subhead_text' => array('label' => __('Subhead text', 'windhard-maintenance'), 'callback' => 'field_subhead_text'),
            'subhead_color' => array('label' => __('Subhead color', 'windhard-maintenance'), 'callback' => 'field_subhead_color'),
            'subhead_size' => array('label' => __('Subhead size', 'windhard-maintenance'), 'callback' => 'field_subhead_size'),
            'allow_roles' => array('label' => __('Allowed roles', 'windhard-maintenance'), 'callback' => 'field_allow_roles'),
            'ip_whitelist' => array('label' => __('IP whitelist', 'windhard-maintenance'), 'callback' => 'field_ip_whitelist'),
            'login_exempt_paths' => array('label' => __('Login exempt paths', 'windhard-maintenance'), 'callback' => 'field_login_exempt_paths'),
            'scope_mode' => array('label' => __('Scope mode', 'windhard-maintenance'), 'callback' => 'field_scope_mode'),
            'intercept_paths' => array('label' => __('Intercept paths', 'windhard-maintenance'), 'callback' => 'field_intercept_paths'),
            'allow_paths' => array('label' => __('Allow paths', 'windhard-maintenance'), 'callback' => 'field_allow_paths'),
            'send_503' => array('label' => __('Send 503', 'windhard-maintenance'), 'callback' => 'field_send_503'),
            'retry_after_minutes' => array('label' => __('Retry-After (minutes)', 'windhard-maintenance'), 'callback' => 'field_retry_after'),
            'noindex' => array('label' => __('Noindex', 'windhard-maintenance'), 'callback' => 'field_noindex'),
        );

        foreach ($fields as $id => $field) {
            add_settings_field(
                $id,
                $field['label'],
                array($this, $field['callback']),
                'windhard-maintenance',
                'windhard_maintenance_section'
            );
        }
    }

    /**
     * Render page.
     */
    public function render_page() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Wind Maintenance', 'windhard-maintenance')); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('windhard_maintenance');
                do_settings_sections('windhard-maintenance');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Sanitize options.
     *
     * @param array $input Raw input.
     * @return array
     */
    public function sanitize_options($input) {
        $defaults = Windhard_Maintenance::get_default_options();
        $output = $defaults;

        $enabled = isset($input['enabled']) && (bool) $input['enabled'];
        $output['enabled'] = $enabled;

        $mode = isset($input['mode']) ? sanitize_text_field($input['mode']) : $defaults['mode'];
        if (!in_array($mode, $this->allowed_modes, true)) {
            $mode = $defaults['mode'];
        }
        $output['mode'] = $mode;

        $reason_preset = isset($input['reason_preset']) ? sanitize_text_field($input['reason_preset']) : $defaults['reason_preset'];
        if (!in_array($reason_preset, $this->allowed_reasons, true)) {
            $reason_preset = $defaults['reason_preset'];
        }
        $output['reason_preset'] = $reason_preset;

        $output['reason_custom'] = isset($input['reason_custom']) ? sanitize_text_field($input['reason_custom']) : '';

        $output['headline_text'] = isset($input['headline_text']) ? sanitize_text_field($input['headline_text']) : '';
        $raw_headline_color = isset($input['headline_color_text']) ? $input['headline_color_text'] : (isset($input['headline_color']) ? $input['headline_color'] : $defaults['headline_color']);
        $output['headline_color'] = $this->sanitize_color($raw_headline_color, $defaults['headline_color']);
        $headline_size = isset($input['headline_size']) ? sanitize_text_field($input['headline_size']) : $defaults['headline_size'];
        if (!in_array($headline_size, $this->allowed_headline_sizes, true)) {
            $headline_size = $defaults['headline_size'];
        }
        $output['headline_size'] = $headline_size;

        $output['subhead_text'] = isset($input['subhead_text']) ? sanitize_text_field($input['subhead_text']) : '';
        $raw_subhead_color = isset($input['subhead_color_text']) ? $input['subhead_color_text'] : (isset($input['subhead_color']) ? $input['subhead_color'] : $defaults['subhead_color']);
        $output['subhead_color'] = $this->sanitize_color($raw_subhead_color, $defaults['subhead_color']);
        $subhead_size = isset($input['subhead_size']) ? sanitize_text_field($input['subhead_size']) : $defaults['subhead_size'];
        if (!in_array($subhead_size, $this->allowed_subhead_sizes, true)) {
            $subhead_size = $defaults['subhead_size'];
        }
        $output['subhead_size'] = $subhead_size;

        $output['allow_roles'] = $this->sanitize_roles(isset($input['allow_roles']) ? (array) $input['allow_roles'] : array());

        $output['ip_whitelist'] = $this->sanitize_textarea_lines(isset($input['ip_whitelist']) ? $input['ip_whitelist'] : '', false);

        $output['login_exempt_paths'] = $this->sanitize_path_lines(isset($input['login_exempt_paths']) ? $input['login_exempt_paths'] : '');
        if (strpos($output['login_exempt_paths'], '/windlogin.php') === false) {
            $lines = $this->explode_lines($output['login_exempt_paths']);
            $lines[] = '/windlogin.php';
            $output['login_exempt_paths'] = implode("\n", array_unique($lines));
        }

        $scope_mode = isset($input['scope_mode']) ? sanitize_text_field($input['scope_mode']) : $defaults['scope_mode'];
        if (!in_array($scope_mode, $this->allowed_scope_modes, true)) {
            $scope_mode = $defaults['scope_mode'];
        }
        $output['scope_mode'] = $scope_mode;

        $output['intercept_paths'] = $this->sanitize_path_lines(isset($input['intercept_paths']) ? $input['intercept_paths'] : '');
        $output['allow_paths'] = $this->sanitize_path_lines(isset($input['allow_paths']) ? $input['allow_paths'] : '');

        $output['send_503'] = isset($input['send_503']) && (bool) $input['send_503'];

        if (isset($input['retry_after_minutes']) && $input['retry_after_minutes'] !== '') {
            $retry = intval($input['retry_after_minutes']);
            $output['retry_after_minutes'] = max(0, $retry);
        } else {
            $output['retry_after_minutes'] = null;
        }

        $output['noindex'] = isset($input['noindex']) && (bool) $input['noindex'];

        return $output;
    }

    /**
     * Sanitize roles to only existing roles.
     *
     * @param array $roles Input roles.
     * @return array
     */
    private function sanitize_roles($roles) {
        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = wp_roles();
        }
        $valid_roles = array_keys($wp_roles->roles);
        $roles = array_intersect($valid_roles, array_map('sanitize_text_field', $roles));
        if (empty($roles)) {
            $roles = array('administrator');
        }

        return array_values($roles);
    }

    /**
     * Sanitize textarea lines.
     *
     * @param string $value Raw value.
     * @param bool   $force_slash Ensure leading slash.
     * @return string
     */
    private function sanitize_textarea_lines($value, $force_slash = true) {
        $lines = $this->explode_lines($value);
        $clean = array();
        foreach ($lines as $line) {
            $line = sanitize_text_field($line);
            if ($line === '') {
                continue;
            }
            if ($force_slash && strpos($line, '/') !== 0) {
                $line = '/' . ltrim($line, '/');
            }
            $clean[] = $line;
        }

        return implode("\n", array_unique($clean));
    }

    /**
     * Sanitize paths ensuring leading slash.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function sanitize_path_lines($value) {
        return $this->sanitize_textarea_lines($value, true);
    }

    /**
     * Sanitize a hex color value.
     *
     * @param string $value        Raw value.
     * @param string $default_hex  Default hex color.
     * @return string
     */
    private function sanitize_color($value, $default_hex) {
        $value = trim((string) $value);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            return strtoupper($value);
        }

        return $default_hex;
    }

    /**
     * Explode lines from textarea value.
     *
     * @param string $value Input value.
     * @return array
     */
    private function explode_lines($value) {
        $lines = preg_split('/\r?\n/', (string) $value);
        if (!is_array($lines)) {
            $lines = array();
        }

        return array_filter(array_map('trim', $lines), 'strlen');
    }

    public function field_enabled() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <label>
            <input type="checkbox" name="windhard_maintenance_options[enabled]" value="1" <?php checked(!empty($options['enabled'])); ?> />
            <?php esc_html_e('Enable maintenance mode', 'windhard-maintenance'); ?>
        </label>
        <?php
    }

    public function field_mode() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="windhard_maintenance_options[mode]">
            <?php foreach ($this->allowed_modes as $mode) : ?>
                <option value="<?php echo esc_attr($mode); ?>" <?php selected($options['mode'], $mode); ?>><?php echo esc_html($mode); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function field_reason_preset() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="windhard_maintenance_options[reason_preset]">
            <?php foreach ($this->allowed_reasons as $reason) : ?>
                <option value="<?php echo esc_attr($reason); ?>" <?php selected($options['reason_preset'], $reason); ?>><?php echo esc_html($reason); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function field_reason_custom() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="text" class="regular-text" name="windhard_maintenance_options[reason_custom]" value="<?php echo esc_attr($options['reason_custom']); ?>" />
        <?php
    }

    public function field_headline_text() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="text" class="regular-text" name="windhard_maintenance_options[headline_text]" value="<?php echo esc_attr($options['headline_text']); ?>" />
        <p class="description"><?php esc_html_e('Leave blank to display the reason text.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_headline_color() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="text" name="windhard_maintenance_options[headline_color_text]" value="<?php echo esc_attr($options['headline_color']); ?>" class="regular-text" />
        <input type="color" name="windhard_maintenance_options[headline_color]" value="<?php echo esc_attr($options['headline_color']); ?>" />
        <p class="description"><?php esc_html_e('Hex color, e.g., #FFFFFF.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_headline_size() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="windhard_maintenance_options[headline_size]">
            <?php foreach ($this->allowed_headline_sizes as $size) : ?>
                <option value="<?php echo esc_attr($size); ?>" <?php selected($options['headline_size'], $size); ?>><?php echo esc_html(strtoupper($size)); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function field_subhead_text() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="text" class="regular-text" name="windhard_maintenance_options[subhead_text]" value="<?php echo esc_attr($options['subhead_text']); ?>" />
        <p class="description"><?php esc_html_e('Leave blank to hide the subhead.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_subhead_color() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="text" name="windhard_maintenance_options[subhead_color_text]" value="<?php echo esc_attr($options['subhead_color']); ?>" class="regular-text" />
        <input type="color" name="windhard_maintenance_options[subhead_color]" value="<?php echo esc_attr($options['subhead_color']); ?>" />
        <p class="description"><?php esc_html_e('Hex color, e.g., #FFFFFF.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_subhead_size() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="windhard_maintenance_options[subhead_size]">
            <?php foreach ($this->allowed_subhead_sizes as $size) : ?>
                <option value="<?php echo esc_attr($size); ?>" <?php selected($options['subhead_size'], $size); ?>><?php echo esc_html(strtoupper($size)); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function field_allow_roles() {
        $options = Windhard_Maintenance::get_options();
        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = wp_roles();
        }
        foreach ($wp_roles->roles as $role_key => $role) {
            ?>
            <label>
                <input type="checkbox" name="windhard_maintenance_options[allow_roles][]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, (array) $options['allow_roles'], true)); ?> />
                <?php echo esc_html($role['name']); ?>
            </label><br />
            <?php
        }
    }

    public function field_ip_whitelist() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <textarea name="windhard_maintenance_options[ip_whitelist]" rows="5" cols="50"><?php echo esc_textarea($options['ip_whitelist']); ?></textarea>
        <p class="description"><?php esc_html_e('One IP or CIDR per line.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_login_exempt_paths() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <textarea name="windhard_maintenance_options[login_exempt_paths]" rows="3" cols="50"><?php echo esc_textarea($options['login_exempt_paths']); ?></textarea>
        <p class="description"><?php esc_html_e('Paths always exempt from maintenance. Must include /windlogin.php.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_scope_mode() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="windhard_maintenance_options[scope_mode]">
            <?php foreach ($this->allowed_scope_modes as $mode) : ?>
                <option value="<?php echo esc_attr($mode); ?>" <?php selected($options['scope_mode'], $mode); ?>><?php echo esc_html($mode); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function field_intercept_paths() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <textarea name="windhard_maintenance_options[intercept_paths]" rows="5" cols="50"><?php echo esc_textarea($options['intercept_paths']); ?></textarea>
        <p class="description"><?php esc_html_e('Patterns to intercept (one per line, leading slash required).', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_allow_paths() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <textarea name="windhard_maintenance_options[allow_paths]" rows="5" cols="50"><?php echo esc_textarea($options['allow_paths']); ?></textarea>
        <p class="description"><?php esc_html_e('Patterns to allow (one per line, leading slash required).', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_send_503() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <label>
            <input type="checkbox" name="windhard_maintenance_options[send_503]" value="1" <?php checked(!empty($options['send_503'])); ?> />
            <?php esc_html_e('Send 503 status', 'windhard-maintenance'); ?>
        </label>
        <?php
    }

    public function field_retry_after() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="number" name="windhard_maintenance_options[retry_after_minutes]" value="<?php echo esc_attr($options['retry_after_minutes']); ?>" min="0" />
        <p class="description"><?php esc_html_e('Optional. Leave empty to omit Retry-After.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_noindex() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <label>
            <input type="checkbox" name="windhard_maintenance_options[noindex]" value="1" <?php checked(!empty($options['noindex'])); ?> />
            <?php esc_html_e('Add noindex,nofollow meta', 'windhard-maintenance'); ?>
        </label>
        <?php
    }
}
