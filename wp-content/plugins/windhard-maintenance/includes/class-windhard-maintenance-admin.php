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
     * Option key.
     *
     * @var string
     */
    private $option_name = 'whm_settings';

    /**
     * Settings API group key.
     *
     * @var string
     */
    private $settings_group = 'whm_settings_group';

    /**
     * Settings page slug.
     *
     * @var string
     */
    private $page_slug = 'whm-settings';

    /**
     * Menu slug.
     *
     * @var string
     */
    private $menu_slug = 'whm-settings';

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
    private $allowed_headline_sizes = array('m', 'l', 'xl', 'xxl');

    /**
     * Allowed subhead sizes.
     *
     * @var array
     */
    private $allowed_subhead_sizes = array('s', 'm', 'l', 'xl');

    /**
     * Get localized mode label.
     *
     * @param string $mode Mode key.
     * @return string
     */
    private function get_mode_label($mode) {
        $labels = array(
            'maintenance' => __('维护中', 'windhard-maintenance'),
            'disabled' => __('已禁用', 'windhard-maintenance'),
        );

        return isset($labels[$mode]) ? $labels[$mode] : $mode;
    }

    /**
     * Get localized reason label.
     *
     * @param string $reason Reason key.
     * @return string
     */
    private function get_reason_label($reason) {
        $labels = array(
            'routine' => __('例行维护', 'windhard-maintenance'),
            'upgrade' => __('系统升级', 'windhard-maintenance'),
            'emergency_fix' => __('紧急修复', 'windhard-maintenance'),
            'migration' => __('数据迁移', 'windhard-maintenance'),
            'third_party' => __('第三方服务故障', 'windhard-maintenance'),
            'security' => __('安全加固', 'windhard-maintenance'),
            'other' => __('其他', 'windhard-maintenance'),
        );

        return isset($labels[$reason]) ? $labels[$reason] : $reason;
    }

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
            __('维护设置', 'windhard-maintenance'),
            __('维护模式', 'windhard-maintenance'),
            'manage_options',
            $this->menu_slug,
            array($this, 'render_page')
        );
    }

    /**
     * Register settings and fields.
     */
    public function register_settings() {
        register_setting($this->settings_group, $this->option_name, array($this, 'sanitize_options'));

        add_settings_section(
            'windhard_maintenance_section',
            __('维护设置', 'windhard-maintenance'),
            '__return_false',
            $this->page_slug
        );

        $fields = array(
            'enabled' => array('label' => __('启用维护模式', 'windhard-maintenance'), 'callback' => 'field_enabled'),
            'mode' => array('label' => __('模式', 'windhard-maintenance'), 'callback' => 'field_mode'),
            'reason_preset' => array('label' => __('原因预设', 'windhard-maintenance'), 'callback' => 'field_reason_preset'),
            'reason_custom' => array('label' => __('自定义原因', 'windhard-maintenance'), 'callback' => 'field_reason_custom'),
            'headline_text' => array('label' => __('标题', 'windhard-maintenance'), 'callback' => 'field_headline_text'),
            'headline_color' => array('label' => __('标题颜色', 'windhard-maintenance'), 'callback' => 'field_headline_color'),
            'headline_size' => array('label' => __('标题字号', 'windhard-maintenance'), 'callback' => 'field_headline_size'),
            'subhead_text' => array('label' => __('副标题', 'windhard-maintenance'), 'callback' => 'field_subhead_text'),
            'subhead_color' => array('label' => __('副标题颜色', 'windhard-maintenance'), 'callback' => 'field_subhead_color'),
            'subhead_size' => array('label' => __('副标题字号', 'windhard-maintenance'), 'callback' => 'field_subhead_size'),
            'allow_roles' => array('label' => __('允许角色', 'windhard-maintenance'), 'callback' => 'field_allow_roles'),
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
                $this->page_slug,
                'windhard_maintenance_section'
            );
        }
    }

    /**
     * Render page.
     */
    public function render_page() {
        $this->options = Windhard_Maintenance::get_options();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('维护设置', 'windhard-maintenance')); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->settings_group);
                do_settings_sections($this->page_slug);
                submit_button(__('保存设置', 'windhard-maintenance'));
                ?>
            </form>
            <?php
            /*
             * Testing note: 保存后刷新后台页面确认值已持久化，再在未登录窗口访问前台查看标题、颜色、角色豁免是否生效。
             */
            ?>
            <p><strong>WHM_ADMIN_SETTINGS_BUILD=01</strong></p>
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
        $raw_headline_color = isset($input['headline_color']) ? $input['headline_color'] : $defaults['headline_color'];
        $output['headline_color'] = sanitize_hex_color($raw_headline_color) ?: $defaults['headline_color'];
        $headline_size = isset($input['headline_size']) ? sanitize_text_field($input['headline_size']) : $defaults['headline_size'];
        if (!in_array($headline_size, $this->allowed_headline_sizes, true)) {
            $headline_size = $defaults['headline_size'];
        }
        $output['headline_size'] = $headline_size;

        $output['subhead_text'] = isset($input['subhead_text']) ? sanitize_text_field($input['subhead_text']) : '';
        $raw_subhead_color = isset($input['subhead_color']) ? $input['subhead_color'] : $defaults['subhead_color'];
        $output['subhead_color'] = sanitize_hex_color($raw_subhead_color) ?: $defaults['subhead_color'];
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
        $roles = array_intersect($valid_roles, array_map('sanitize_key', $roles));
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
            <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[enabled]" value="1" <?php checked(!empty($options['enabled'])); ?> />
            <?php esc_html_e('启用维护模式', 'windhard-maintenance'); ?>
        </label>
        <?php
    }

    public function field_mode() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="<?php echo esc_attr($this->option_name); ?>[mode]">
            <?php foreach ($this->allowed_modes as $mode) : ?>
                <option value="<?php echo esc_attr($mode); ?>" <?php selected($options['mode'], $mode); ?>><?php echo esc_html($this->get_mode_label($mode)); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function field_reason_preset() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="<?php echo esc_attr($this->option_name); ?>[reason_preset]">
            <?php foreach ($this->allowed_reasons as $reason) : ?>
                <option value="<?php echo esc_attr($reason); ?>" <?php selected($options['reason_preset'], $reason); ?>><?php echo esc_html($this->get_reason_label($reason)); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function field_reason_custom() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr($this->option_name); ?>[reason_custom]" value="<?php echo esc_attr($options['reason_custom']); ?>" />
        <?php
    }

    public function field_headline_text() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr($this->option_name); ?>[headline_text]" value="<?php echo esc_attr($options['headline_text']); ?>" />
        <p class="description"><?php esc_html_e('留空将显示原因文案', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_headline_color() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="color" name="<?php echo esc_attr($this->option_name); ?>[headline_color]" value="<?php echo esc_attr($options['headline_color']); ?>" />
        <p class="description"><?php esc_html_e('自定义标题颜色（16 进制）', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_headline_size() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="<?php echo esc_attr($this->option_name); ?>[headline_size]">
            <?php foreach ($this->allowed_headline_sizes as $size) : ?>
                <option value="<?php echo esc_attr($size); ?>" <?php selected($options['headline_size'], $size); ?>><?php echo esc_html(strtoupper($size)); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function field_subhead_text() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr($this->option_name); ?>[subhead_text]" value="<?php echo esc_attr($options['subhead_text']); ?>" />
        <p class="description"><?php esc_html_e('留空将不显示副标题', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_subhead_color() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="color" name="<?php echo esc_attr($this->option_name); ?>[subhead_color]" value="<?php echo esc_attr($options['subhead_color']); ?>" />
        <p class="description"><?php esc_html_e('自定义副标题颜色（16 进制）', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_subhead_size() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="<?php echo esc_attr($this->option_name); ?>[subhead_size]">
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
                <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[allow_roles][]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, (array) $options['allow_roles'], true)); ?> />
                <?php echo esc_html($role['name']); ?>
            </label><br />
            <?php
        }
    }

    public function field_ip_whitelist() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <textarea name="<?php echo esc_attr($this->option_name); ?>[ip_whitelist]" rows="5" cols="50"><?php echo esc_textarea($options['ip_whitelist']); ?></textarea>
        <p class="description"><?php esc_html_e('One IP or CIDR per line.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_login_exempt_paths() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <textarea name="<?php echo esc_attr($this->option_name); ?>[login_exempt_paths]" rows="3" cols="50"><?php echo esc_textarea($options['login_exempt_paths']); ?></textarea>
        <p class="description"><?php esc_html_e('Paths always exempt from maintenance. Must include /windlogin.php.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_scope_mode() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <select name="<?php echo esc_attr($this->option_name); ?>[scope_mode]">
            <?php foreach ($this->allowed_scope_modes as $mode) : ?>
                <option value="<?php echo esc_attr($mode); ?>" <?php selected($options['scope_mode'], $mode); ?>><?php echo esc_html($mode); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function field_intercept_paths() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <textarea name="<?php echo esc_attr($this->option_name); ?>[intercept_paths]" rows="5" cols="50"><?php echo esc_textarea($options['intercept_paths']); ?></textarea>
        <p class="description"><?php esc_html_e('Patterns to intercept (one per line, leading slash required).', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_allow_paths() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <textarea name="<?php echo esc_attr($this->option_name); ?>[allow_paths]" rows="5" cols="50"><?php echo esc_textarea($options['allow_paths']); ?></textarea>
        <p class="description"><?php esc_html_e('Patterns to allow (one per line, leading slash required).', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_send_503() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[send_503]" value="1" <?php checked(!empty($options['send_503'])); ?> />
            <?php esc_html_e('Send 503 status', 'windhard-maintenance'); ?>
        </label>
        <?php
    }

    public function field_retry_after() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <input type="number" name="<?php echo esc_attr($this->option_name); ?>[retry_after_minutes]" value="<?php echo esc_attr($options['retry_after_minutes']); ?>" min="0" />
        <p class="description"><?php esc_html_e('Optional. Leave empty to omit Retry-After.', 'windhard-maintenance'); ?></p>
        <?php
    }

    public function field_noindex() {
        $options = Windhard_Maintenance::get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_name); ?>[noindex]" value="1" <?php checked(!empty($options['noindex'])); ?> />
            <?php esc_html_e('Add noindex,nofollow meta', 'windhard-maintenance'); ?>
        </label>
        <?php
    }
}
