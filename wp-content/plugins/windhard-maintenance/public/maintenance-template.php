<?php
/**
 * Maintenance template with external effect loader.
 */

if (!defined('ABSPATH')) {
    exit;
}

$headline_size_map = array(
    'l' => 'clamp(32px, 5vw, 56px)',
    'xl' => 'clamp(40px, 6vw, 72px)',
    'xxl' => 'clamp(48px, 7vw, 88px)',
);

$subhead_size_map = array(
    's' => 'clamp(14px, 2vw, 18px)',
    'm' => 'clamp(16px, 2.3vw, 22px)',
    'l' => 'clamp(18px, 2.6vw, 26px)',
);

$headline_text = !empty($options['headline_text']) ? $options['headline_text'] : $reason;
$subhead_text = !empty($options['subhead_text']) ? $options['subhead_text'] : '';

$headline_size = isset($headline_size_map[$options['headline_size']]) ? $headline_size_map[$options['headline_size']] : $headline_size_map['xl'];
$subhead_size = isset($subhead_size_map[$options['subhead_size']]) ? $subhead_size_map[$options['subhead_size']] : $subhead_size_map['m'];

$headline_color = !empty($options['headline_color']) ? $options['headline_color'] : '#FFFFFF';
$subhead_color = !empty($options['subhead_color']) ? $options['subhead_color'] : '#FFFFFF';

$plugin_root_file = dirname(__DIR__) . '/windhard-maintenance.php';
$effect_file = dirname(__DIR__) . '/assets/whm-effect.js';
$effect_src = plugins_url('assets/whm-effect.js', $plugin_root_file);
$effect_version = file_exists($effect_file) ? filemtime($effect_file) : time();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <?php if (!empty($noindex)) : ?>
        <meta name="robots" content="noindex,nofollow" />
    <?php endif; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html__('Site Maintenance', 'windhard-maintenance'); ?></title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 20% 20%, #0a1a2d 0%, #081422 40%, #060f1b 80%);
            color: #fff;
            font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
            overflow: hidden;
        }
        #whm-effect {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            display: block;
            background: linear-gradient(180deg, #0d1c2f 0%, #050c17 100%);
        }
        .whm-layer {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .whm-loading {
            background: rgba(0, 0, 0, 0.45);
            color: #e5e7eb;
            font-size: 18px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .whm-loading.hidden {
            opacity: 0;
            visibility: hidden;
        }
        .whm-foreground {
            pointer-events: none;
            padding: 24px;
        }
        .whm-text {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: center;
            justify-content: center;
            padding: 24px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
        }
        .whm-headline {
            font-weight: 700;
            line-height: 1.2;
        }
        .whm-subhead {
            font-weight: 500;
            line-height: 1.4;
        }
        .whm-reduced #whm-effect {
            display: none;
        }
        .whm-error {
            pointer-events: none;
            color: #ff4d6d;
            font-size: 20px;
            font-weight: 700;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.6);
        }
        .hidden {
            opacity: 0;
            visibility: hidden;
        }
        .whm-watermark {
            position: fixed;
            right: 12px;
            bottom: 10px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
            pointer-events: none;
        }
    </style>
</head>
<body>
    <canvas id="whm-effect" aria-hidden="true"></canvas>
    <div id="whm-loading" class="whm-layer whm-loading">Loading…</div>
    <div id="whm-effect-error" class="whm-layer whm-error hidden">EFFECT NOT LOADED</div>
    <div class="whm-layer whm-foreground">
        <div class="whm-text">
            <div class="whm-headline" style="color: <?php echo esc_attr($headline_color); ?>; font-size: <?php echo esc_attr($headline_size); ?>;">
                <?php echo esc_html($headline_text); ?>
            </div>
            <?php if ($subhead_text !== '') : ?>
                <div class="whm-subhead" style="color: <?php echo esc_attr($subhead_color); ?>; font-size: <?php echo esc_attr($subhead_size); ?>;">
                    <?php echo esc_html($subhead_text); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div id="whm-effect-watermark" class="whm-watermark">EFFECT_EXPECTED: (pending)</div>
    <script src="<?php echo esc_url($effect_src); ?>?v=<?php echo esc_attr($effect_version); ?>" defer></script>
    <script>
        setTimeout(function() {
            if (!window.__WHM_EFFECT_VERSION) {
                var err = document.getElementById('whm-effect-error');
                if (err) {
                    err.textContent = 'EFFECT NOT LOADED';
                    err.classList.remove('hidden');
                }
                var loading = document.getElementById('whm-loading');
                if (loading) {
                    loading.textContent = 'EFFECT NOT LOADED';
                    loading.classList.remove('hidden');
                }
            }
        }, 1000);
    </script>
</body>
</html>
