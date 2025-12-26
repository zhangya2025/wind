<?php
/**
 * Minimal maintenance template.
 */

if (!defined('ABSPATH')) {
    exit;
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <?php if (!empty($noindex)) : ?>
        <meta name="robots" content="noindex,nofollow" />
    <?php endif; ?>
    <title><?php echo esc_html__('Site Maintenance', 'windhard-maintenance'); ?></title>
</head>
<body>
    <h1><?php echo esc_html($mode === 'disabled' ? __('暂时停用', 'windhard-maintenance') : __('维护中', 'windhard-maintenance')); ?></h1>
    <p><?php echo esc_html__('当前状态：', 'windhard-maintenance'); ?><?php echo esc_html($mode); ?></p>
    <p><?php echo esc_html__('原因：', 'windhard-maintenance'); ?><?php echo esc_html($reason); ?></p>
    <p><?php echo esc_html__('请稍后再试。', 'windhard-maintenance'); ?></p>
</body>
</html>
