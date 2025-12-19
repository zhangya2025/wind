<?php
/**
 * Plugin Name: windhard-safe
 * Description: Secure virtual login entry via /windlogin (no .php, no query args)
 * Version: 3.0.0
 * Author: windhard
 */

if ( ! defined('ABSPATH') ) exit;

class Windhard_Safe {

    const LOGIN_SLUG = 'windlogin';
    const QUERY_VAR = 'windhard_login';

    public function __construct() {
        add_action('init', [$this, 'add_rewrite']);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'handle_virtual_login']);

        add_action('login_init', [$this, 'block_direct_login']);
        add_action('init', [$this, 'protect_admin']);
    }

    /** 注册 rewrite 规则 */
    public function add_rewrite() {
        add_rewrite_rule(
            '^' . self::LOGIN_SLUG . '/?$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    /** 注册 query var（仅内部使用） */
    public function register_query_var($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /** 处理虚拟登录入口 */
    public function handle_virtual_login() {
        if ( get_query_var(self::QUERY_VAR) ) {
            define('WINDHARD_VIRTUAL_LOGIN', true);
            global $pagenow;
            $pagenow = 'wp-login.php';
            require ABSPATH . 'wp-login.php';
            exit;
        }
    }

    /** 禁止直接访问 wp-login.php */
    public function block_direct_login() {
        if ( defined('WINDHARD_VIRTUAL_LOGIN') ) return;

        wp_safe_redirect( site_url('/' . self::LOGIN_SLUG) );
        exit;
    }

    /** 保护 wp-admin */
    public function protect_admin() {
        if ( is_user_logged_in() || ! is_admin() ) return;
        if ( defined('DOING_AJAX') && DOING_AJAX ) return;

        wp_safe_redirect( site_url('/' . self::LOGIN_SLUG) );
        exit;
    }
}

new Windhard_Safe();
