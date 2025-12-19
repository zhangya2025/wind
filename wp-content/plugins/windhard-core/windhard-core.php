<?php
/*
Plugin Name: Windhard Core
Description: Full-localization framework for WordPress China environment.
Version: 1.0
Author: Aisa Zhang
*/

if (!defined('ABSPATH')) exit;


// -----------------------------
// 本地化 & 禁用外部调用
// -----------------------------
add_action('init', function() {

    // 禁用 Emoji
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');

    // 禁用 Gutenberg 前端 REST API
    add_filter('rest_enabled', '__return_false');
    add_filter('rest_jsonp_enabled', '__return_false');

    // 隐藏 WordPress 版本信息
    remove_action('wp_head', 'wp_generator');

    // 禁用 wp-embed 脚本
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');

    // 禁用默认外部字体（Google Fonts）
    add_filter('style_loader_src', function($src) {
        if(strpos($src, 'fonts.googleapis.com') !== false) return '';
        return $src;
    });

    // 禁用外部头像（Gravatar 等）
    add_filter('get_avatar_url', function($url, $id_or_email, $args) {
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        $avatar_host = parse_url($url, PHP_URL_HOST);

        // 如果头像来源外部域名，则使用本地可控头像
        if(!empty($avatar_host) && !empty($site_host) && strcasecmp($avatar_host, $site_host) !== 0) {
            $local_avatar_file = ABSPATH . WPINC . '/images/w-logo-blue-white-bg.png';
            if(file_exists($local_avatar_file)) {
                $url = includes_url('images/w-logo-blue-white-bg.png');
            } else {
                // 安全退化为 1x1 像素透明图片
                $url = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
            }
        }

        return $url;
    }, 10, 3);
});

// -----------------------------
// 自动加载模块
// -----------------------------
foreach (glob(plugin_dir_path(__FILE__) . 'modules/*.php') as $module_file) {
    require_once $module_file;
}





