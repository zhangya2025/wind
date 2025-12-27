<?php
if (!defined('ABSPATH')) {
    exit;
}

final class Wind_Warehouse_Settings {
    private const OPTION_GROUP = 'wind_warehouse_settings';
    private const OPTION_NAME  = 'wind_warehouse_ui';

    private const DEFAULT_PRESET = 'normal';
    private const PRESETS        = ['small', 'normal', 'large'];

    public static function register_admin_menu(): void {
        add_menu_page(
            __('Wind Warehouse', 'wind-warehouse'),
            __('Wind Warehouse', 'wind-warehouse'),
            'manage_options',
            'wind-warehouse',
            [self::class, 'render_settings_page']
        );

        add_submenu_page(
            'wind-warehouse',
            __('设置', 'wind-warehouse'),
            __('设置', 'wind-warehouse'),
            'manage_options',
            'wind-warehouse-settings',
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [self::class, 'sanitize_settings'],
                'default'           => ['preset' => self::DEFAULT_PRESET],
            ]
        );
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wind-warehouse'));
        }

        $settings = self::get_ui_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Wind Warehouse 设置', 'wind-warehouse')); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="ww-ui-preset"><?php echo esc_html(__('字体等级', 'wind-warehouse')); ?></label>
                            </th>
                            <td>
                                <select id="ww-ui-preset" name="<?php echo esc_attr(self::OPTION_NAME); ?>[preset]">
                                    <?php foreach (self::PRESETS as $preset) : ?>
                                        <option value="<?php echo esc_attr($preset); ?>" <?php selected($settings['preset'], $preset); ?>>
                                            <?php echo esc_html(self::label_for_preset($preset)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function get_ui_settings(): array {
        $saved = get_option(self::OPTION_NAME, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $preset = self::sanitize_preset($saved['preset'] ?? self::DEFAULT_PRESET);

        return [
            'preset' => $preset,
        ];
    }

    public static function build_css_vars(): string {
        $settings = self::get_ui_settings();
        $preset   = $settings['preset'];

        switch ($preset) {
            case 'small':
                $base  = '13px';
                $table = '12px';
                $h1    = '24px';
                break;
            case 'large':
                $base  = '15px';
                $table = '14px';
                $h1    = '28px';
                break;
            case 'normal':
            default:
                $base  = '14px';
                $table = '13px';
                $h1    = '26px';
                break;
        }

        return sprintf('--ww-font-base:%s;--ww-font-table:%s;--ww-font-h1:%s;', $base, $table, $h1);
    }

    private static function sanitize_settings($input): array {
        if (!is_array($input)) {
            $input = [];
        }

        $preset = self::sanitize_preset($input['preset'] ?? self::DEFAULT_PRESET);

        return [
            'preset' => $preset,
        ];
    }

    private static function sanitize_preset($preset): string {
        if (!is_string($preset)) {
            return self::DEFAULT_PRESET;
        }

        $preset = sanitize_text_field($preset);

        if (!in_array($preset, self::PRESETS, true)) {
            return self::DEFAULT_PRESET;
        }

        return $preset;
    }

    private static function label_for_preset(string $preset): string {
        switch ($preset) {
            case 'small':
                return __('小', 'wind-warehouse');
            case 'large':
                return __('大', 'wind-warehouse');
            case 'normal':
            default:
                return __('标准', 'wind-warehouse');
        }
    }
}
