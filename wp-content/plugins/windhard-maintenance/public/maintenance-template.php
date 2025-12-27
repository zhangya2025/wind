<?php
/**
 * Minimal maintenance template.
 */

if (!defined('ABSPATH')) {
    exit;
}

$headline = 'JUST FOR FANS';
$subtitle = '网站调试中';
$reason_text = isset($reason) ? $reason : '';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <?php if (!empty($noindex)) : ?>
        <meta name="robots" content="noindex,nofollow" />
    <?php endif; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html($headline); ?></title>
    <style>
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
            font-size: 28px;
            font-weight: 700;
        }
        .whm-wrapper p {
            margin: 6px 0;
            font-size: 16px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="whm-wrapper">
        <h1><?php echo esc_html($headline); ?></h1>
        <p><?php echo esc_html($subtitle); ?></p>
        <?php if (!empty($reason_text)) : ?>
            <p><?php echo esc_html($reason_text); ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
