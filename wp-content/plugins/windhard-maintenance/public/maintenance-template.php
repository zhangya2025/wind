<?php
/**
 * Minimal maintenance template.
 */

if (!defined('ABSPATH')) {
    exit;
}

$headline_text = isset($headline) ? $headline : __('网站维护中', 'windhard-maintenance');
$subtitle = isset($subhead_text) ? $subhead_text : __('请稍后再试', 'windhard-maintenance');
$reason_line = isset($reason_text) ? $reason_text : '';
$headline_color = isset($headline_color) ? $headline_color : '#FFFFFF';
$subhead_color = isset($subhead_color) ? $subhead_color : '#FFFFFF';
$headline_font_size = isset($headline_font_size) ? $headline_font_size : '40px';
$subhead_font_size = isset($subhead_font_size) ? $subhead_font_size : '16px';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <?php if (!empty($noindex)) : ?>
        <meta name="robots" content="noindex,nofollow" />
    <?php endif; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html($headline_text); ?></title>
    <style>
        :root {
            --whm-headline-color: <?php echo esc_html($headline_color); ?>;
            --whm-subhead-color: <?php echo esc_html($subhead_color); ?>;
            --whm-headline-size: <?php echo esc_html($headline_font_size); ?>;
            --whm-subhead-size: <?php echo esc_html($subhead_font_size); ?>;
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            color: #111111;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        }
        .whm-wrapper {
            text-align: center;
            padding: 24px;
        }
        .whm-wrapper h1 {
            margin: 0 0 12px;
            font-size: var(--whm-headline-size);
            font-weight: 700;
            color: var(--whm-headline-color);
        }
        .whm-wrapper p {
            margin: 6px 0;
            font-size: var(--whm-subhead-size);
            line-height: 1.6;
            color: var(--whm-subhead-color);
        }
        .whm-wrapper .reason { color: var(--whm-subhead-color); font-size: var(--whm-subhead-size); }
    </style>
</head>
<body>
    <div class="whm-wrapper">
        <h1><?php echo esc_html($headline_text); ?></h1>
        <?php if (!empty($subtitle)) : ?>
            <p><?php echo esc_html($subtitle); ?></p>
        <?php endif; ?>
        <?php if (!empty($reason_line)) : ?>
            <p class="reason"><?php echo esc_html($reason_line); ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
