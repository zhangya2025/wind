<?php
/**
 * Blizzard maintenance template.
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

?><!DOCTYPE html>
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
            background: #0b1625;
            color: #fff;
            font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
            overflow: hidden;
        }
        #whm-blizzard {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            display: block;
        }
        .whm-overlay {
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.08), transparent 35%),
                        radial-gradient(circle at 80% 10%, rgba(255,255,255,0.06), transparent 30%),
                        linear-gradient(135deg, rgba(0,0,0,0.5), rgba(0,0,0,0.3));
            pointer-events: none;
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
            background: rgba(0, 0, 0, 0.4);
            color: #e5e7eb;
            font-size: 18px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            transition: opacity 0.4s ease;
        }
        .whm-loading.hidden {
            opacity: 0;
            visibility: hidden;
        }
        .whm-foreground {
            pointer-events: none;
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
        .whm-reduced #whm-blizzard {
            display: none;
        }
        .whm-reduced .whm-overlay {
            background: linear-gradient(180deg, rgba(7, 18, 36, 0.95), rgba(10, 25, 48, 0.92));
        }
        @media (max-width: 600px) {
            .whm-text {
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <canvas id="whm-blizzard" aria-hidden="true"></canvas>
    <div class="whm-overlay" aria-hidden="true"></div>
    <div id="whm-loading" class="whm-layer whm-loading">Loading…</div>
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
    <script>
        (function() {
            const body = document.body;
            const canvas = document.getElementById('whm-blizzard');
            const loading = document.getElementById('whm-loading');
            const prefersReduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (!canvas || !canvas.getContext || prefersReduce) {
                body.classList.add('whm-reduced');
                if (loading) {
                    loading.classList.add('hidden');
                }
                return;
            }

            const ctx = canvas.getContext('2d');
            if (!ctx) {
                body.classList.add('whm-reduced');
                if (loading) {
                    loading.classList.add('hidden');
                }
                return;
            }

            let width = 0;
            let height = 0;
            const snowflakes = [];

            const resize = () => {
                const dpr = window.devicePixelRatio || 1;
                width = window.innerWidth;
                height = window.innerHeight;
                canvas.width = Math.floor(width * dpr);
                canvas.height = Math.floor(height * dpr);
                canvas.style.width = width + 'px';
                canvas.style.height = height + 'px';
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

                const desired = Math.min(400, Math.floor((width * height) / 8000));
                snowflakes.length = desired;
                for (let i = 0; i < desired; i++) {
                    if (!snowflakes[i]) {
                        snowflakes[i] = makeFlake();
                    }
                }
            };

            const makeFlake = () => {
                return {
                    x: Math.random() * width,
                    y: Math.random() * height,
                    r: Math.random() * 2.2 + 0.8,
                    vy: Math.random() * 1.2 + 0.4,
                    swing: Math.random() * 0.6 + 0.2,
                    phase: Math.random() * Math.PI * 2,
                };
            };

            let tick = 0;
            const draw = () => {
                ctx.clearRect(0, 0, width, height);
                ctx.fillStyle = '#0b1625';
                ctx.fillRect(0, 0, width, height);

                const wind = Math.sin(tick / 180) * 0.6;

                for (let i = 0; i < snowflakes.length; i++) {
                    const f = snowflakes[i];
                    if (!f) continue;
                    f.y += f.vy + Math.abs(wind) * 0.15;
                    f.x += Math.sin(f.phase + tick / 90) * f.swing + wind;

                    if (f.y > height) {
                        f.y = -5;
                        f.x = Math.random() * width;
                    }
                    if (f.x > width) {
                        f.x = -5;
                    } else if (f.x < -5) {
                        f.x = width + 5;
                    }

                    ctx.beginPath();
                    ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(255,255,255,0.85)';
                    ctx.fill();
                }

                tick++;
                if (loading) {
                    loading.classList.add('hidden');
                }
                window.requestAnimationFrame(draw);
            };

            resize();
            draw();
            window.addEventListener('resize', resize);
        })();
    </script>
</body>
</html>
