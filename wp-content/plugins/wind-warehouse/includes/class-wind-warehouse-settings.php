<?php
if (!defined('ABSPATH')) {
    exit;
}

final class Wind_Warehouse_Settings {
    private const OPTION_GROUP = 'wind_warehouse_typography_settings';
    private const OPTION_NAME  = 'wind_warehouse_typography';

    private const DEFAULTS = [
        'l1'          => 26,
        'l2'          => 20,
        'l3'          => 14,
        'l4'          => 13,
        'l5'          => 13,
        'l6'          => 12,
        'line_height' => 1.4,
    ];

    private const FONT_MIN = 10;
    private const FONT_MAX = 40;
    private const LINE_HEIGHT_MIN = 1.1;
    private const LINE_HEIGHT_MAX = 2.0;

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

        add_submenu_page(
            'wind-warehouse',
            __('字体', 'wind-warehouse'),
            __('字体', 'wind-warehouse'),
            'manage_options',
            'wind-warehouse-typography',
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
                'default'           => self::DEFAULTS,
            ]
        );
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wind-warehouse'));
        }

        $settings = self::get_typography_settings();
        ?>
        <div class="wrap ww-app ww-app--settings">
            <h1 class="ww-page-title ww-section-title"><?php echo esc_html(__('Wind Warehouse 字体等级设置', 'wind-warehouse')); ?></h1>
            <?php settings_errors(self::OPTION_NAME); ?>
            <form method="post" action="options.php" class="ww-settings-grid">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <div class="ww-card ww-card--form">
                    <h2 class="ww-card__title ww-section-title"><?php echo esc_html(__('等级配置', 'wind-warehouse')); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php foreach (self::level_labels() as $key => $label) : ?>
                                <tr>
                                    <th scope="row"><label for="ww-font-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                                    <td>
                                        <input
                                            id="ww-font-<?php echo esc_attr($key); ?>"
                                            name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($key); ?>]"
                                            type="number"
                                            min="<?php echo esc_attr(self::FONT_MIN); ?>"
                                            max="<?php echo esc_attr(self::FONT_MAX); ?>"
                                            step="1"
                                            value="<?php echo esc_attr($settings[$key]); ?>"
                                            class="regular-text"
                                        />
                                        <p class="description ww-help"><?php echo esc_html(sprintf(__('像素值，范围 %d-%d px', 'wind-warehouse'), self::FONT_MIN, self::FONT_MAX)); ?></p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <th scope="row"><label for="ww-font-line-height"><?php echo esc_html(__('行高 (可选)', 'wind-warehouse')); ?></label></th>
                                <td>
                                    <input
                                        id="ww-font-line-height"
                                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[line_height]"
                                        type="number"
                                        min="<?php echo esc_attr(self::LINE_HEIGHT_MIN); ?>"
                                        max="<?php echo esc_attr(self::LINE_HEIGHT_MAX); ?>"
                                        step="0.1"
                                        value="<?php echo esc_attr($settings['line_height']); ?>"
                                        class="regular-text"
                                    />
                                    <p class="description ww-help"><?php echo esc_html(sprintf(__('可选，范围 %.1f-%.1f', 'wind-warehouse'), self::LINE_HEIGHT_MIN, self::LINE_HEIGHT_MAX)); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('保存字体设置', 'wind-warehouse')); ?>
                </div>

                <div class="ww-card ww-card--preview">
                    <h2 class="ww-card__title ww-section-title"><?php echo esc_html(__('预览', 'wind-warehouse')); ?></h2>
                    <div class="ww-typography-preview">
                        <div class="ww-typography-preview__block">
                            <h1><?php echo esc_html(__('L1 页面主标题', 'wind-warehouse')); ?></h1>
                            <h2><?php echo esc_html(__('L2 区块标题', 'wind-warehouse')); ?></h2>
                            <p><?php echo esc_html(__('L3 正文/表单文字展示，确保行距舒适易读。', 'wind-warehouse')); ?></p>
                            <p class="ww-help ww-muted"><?php echo esc_html(__('L4 辅助说明文本示例。', 'wind-warehouse')); ?></p>
                            <p class="ww-meta ww-small"><?php echo esc_html(__('L6 极小元信息示例。', 'wind-warehouse')); ?></p>
                        </div>
                        <div class="ww-typography-preview__form">
                            <label for="ww-preview-input"><?php echo esc_html(__('表单标签 (L3)', 'wind-warehouse')); ?></label>
                            <input id="ww-preview-input" type="text" placeholder="<?php echo esc_attr(__('输入示例', 'wind-warehouse')); ?>" />
                            <button class="ww-btn" type="button"><?php echo esc_html(__('按钮 (L3)', 'wind-warehouse')); ?></button>
                        </div>
                        <div class="ww-typography-preview__table">
                            <table>
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html(__('列 A (L5)', 'wind-warehouse')); ?></th>
                                        <th><?php echo esc_html(__('列 B (L5)', 'wind-warehouse')); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?php echo esc_html(__('行数据示例', 'wind-warehouse')); ?></td>
                                        <td><?php echo esc_html(__('另一条数据', 'wind-warehouse')); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    public static function get_typography_settings(): array {
        $saved = get_option(self::OPTION_NAME, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $sanitized = self::sanitize_settings($saved, false);

        return $sanitized;
    }

    public static function build_css_vars(): string {
        $settings = self::get_typography_settings();

        $vars = [
            '--ww-font-l1'      => $settings['l1'] . 'px',
            '--ww-font-l2'      => $settings['l2'] . 'px',
            '--ww-font-l3'      => $settings['l3'] . 'px',
            '--ww-font-l4'      => $settings['l4'] . 'px',
            '--ww-font-l5'      => $settings['l5'] . 'px',
            '--ww-font-l6'      => $settings['l6'] . 'px',
            '--ww-line-height'  => $settings['line_height'],
        ];

        $pairs = [];
        foreach ($vars as $key => $value) {
            $pairs[] = sprintf('%s:%s', $key, esc_attr($value));
        }

        return implode(';', $pairs);
    }

    public static function sanitize_settings($input, bool $add_errors = true): array {
        if (!is_array($input)) {
            $input = [];
        }

        $output = [];

        foreach (self::level_labels() as $key => $label) {
            $raw = $input[$key] ?? self::DEFAULTS[$key];
            $font_size = is_numeric($raw) ? (int) $raw : self::DEFAULTS[$key];

            if ($font_size < self::FONT_MIN || $font_size > self::FONT_MAX) {
                if ($add_errors) {
                    add_settings_error(
                        self::OPTION_NAME,
                        'ww_font_' . $key,
                        sprintf(__('等级 %s 的字号需在 %d-%d 之间，已自动调整。', 'wind-warehouse'), $label, self::FONT_MIN, self::FONT_MAX),
                        'error'
                    );
                }

                $font_size = max(self::FONT_MIN, min(self::FONT_MAX, $font_size));
            }

            $output[$key] = $font_size;
        }

        $raw_line_height = $input['line_height'] ?? self::DEFAULTS['line_height'];
        $line_height = is_numeric($raw_line_height) ? (float) $raw_line_height : self::DEFAULTS['line_height'];

        if ($line_height < self::LINE_HEIGHT_MIN || $line_height > self::LINE_HEIGHT_MAX) {
            if ($add_errors) {
                add_settings_error(
                    self::OPTION_NAME,
                    'ww_line_height',
                    sprintf(__('行高需在 %.1f-%.1f 之间，已自动调整。', 'wind-warehouse'), self::LINE_HEIGHT_MIN, self::LINE_HEIGHT_MAX),
                    'error'
                );
            }

            $line_height = max(self::LINE_HEIGHT_MIN, min(self::LINE_HEIGHT_MAX, $line_height));
        }

        $output['line_height'] = $line_height;

        return $output;
    }

    private static function level_labels(): array {
        return [
            'l1' => __('L1 页面主标题', 'wind-warehouse'),
            'l2' => __('L2 区块标题', 'wind-warehouse'),
            'l3' => __('L3 正文/表单', 'wind-warehouse'),
            'l4' => __('L4 辅助说明', 'wind-warehouse'),
            'l5' => __('L5 表格文字', 'wind-warehouse'),
            'l6' => __('L6 极小信息', 'wind-warehouse'),
        ];
    }
}
