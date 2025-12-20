<?php
/**
 * Plugin Name: windhard-safe
 * Description: Enforce a private login entrance at /windlogin.php with request-level guards.
 * Version: 4.0.0
 * Author: windhard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Windhard_Safe {

    const LOGIN_SLUG  = 'windlogin';
    const GLOBAL_FLAG = '__windhard_safe_login_whitelist';

    private $is_whitelisted     = false;
    private $is_private_request = false;
    private $request_path       = '/';

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'detect_private_entry' ], 0 );
        add_action( 'init', [ $this, 'guard_login_and_admin' ], 0 );
        add_action( 'template_redirect', [ $this, 'handle_private_login' ], 0 );

        add_filter( 'login_url', [ $this, 'filter_login_url' ], 10, 3 );
        add_filter( 'lostpassword_url', [ $this, 'filter_login_related_url' ], 10, 2 );
        add_filter( 'register_url', [ $this, 'filter_login_related_url' ], 10, 1 );

        add_filter( 'site_url', [ $this, 'rewrite_core_login_urls' ], 10, 4 );
        add_filter( 'network_site_url', [ $this, 'rewrite_core_login_urls' ], 10, 4 );
        add_filter( 'wp_redirect', [ $this, 'rewrite_login_redirect' ], 10, 2 );
    }

    /* ---------- detection ---------- */

    public function detect_private_entry() {
        $this->request_path = $this->current_path();

        if ( $this->is_private_path( $this->request_path ) ) {
            $this->is_whitelisted     = true;
            $this->is_private_request = true;
            $GLOBALS[ self::GLOBAL_FLAG ] = true;
        } elseif ( $this->looks_like_private_script() ) {
            $this->is_private_request = true;
        }
    }

    /* ---------- guards ---------- */

    public function guard_login_and_admin() {
        if ( $this->should_bypass() ) {
            return;
        }

        if ( $this->is_core_login_request() && ! $this->is_whitelisted ) {
            $this->deny_request();
        }

        if ( is_admin() && ! is_user_logged_in() && ! $this->is_whitelisted ) {
            $this->deny_request();
        }
    }

    /* ---------- main dispatcher ---------- */

    public function handle_private_login() {
        if ( $this->is_private_request && ! $this->is_whitelisted ) {
            $this->deny_request();
        }

        if ( ! $this->is_whitelisted || $this->should_bypass() ) {
            return;
        }

        $this->delegate_to_core_login();
    }

    /* ---------- core delegation ---------- */

    private function delegate_to_core_login() {
        nocache_headers();
        status_header( 200 );

        if ( is_user_logged_in() ) {
            wp_safe_redirect( admin_url() );
            exit;
        }

        global $user_login, $error, $errors, $action;

        $user_login = $user_login ?? '';
        $error      = $error ?? '';
        $errors     = ( isset( $errors ) && $errors instanceof WP_Error ) ? $errors : new WP_Error();
        $action     = $action ?? 'login';

        $GLOBALS['pagenow']    = 'wp-login.php';
        $_SERVER['SCRIPT_NAME'] = '/wp-login.php';
        $_SERVER['PHP_SELF']    = '/wp-login.php';

        require ABSPATH . 'wp-login.php';
        exit;
    }

    /* ---------- URL rewriting ---------- */

    public function filter_login_url( $login_url, $redirect, $force_reauth ) {
        $url = $this->private_login_url();

        if ( $redirect ) {
            $url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
        }
        if ( $force_reauth ) {
            $url = add_query_arg( 'reauth', '1', $url );
        }
        return $url;
    }

    public function filter_login_related_url( $url, $redirect = '' ) {
        $target = $this->private_login_url();
        if ( $redirect ) {
            $target = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $target );
        }
        return $target;
    }

    public function rewrite_core_login_urls( $url ) {
        return $this->is_private_context()
            ? $this->rewrite_login_target( $url )
            : $url;
    }

    public function rewrite_login_redirect( $location ) {
        return $this->is_private_context()
            ? $this->rewrite_login_target( $location )
            : $location;
    }

    /* ---------- helpers ---------- */

    private function rewrite_login_target( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['path'] ) || ! str_contains( $parts['path'], 'wp-login.php' ) ) {
            return $url;
        }

        $target = $this->private_login_url();
        if ( ! empty( $parts['query'] ) ) {
            $target .= '?' . $parts['query'];
        }
        return $target;
    }

    private function is_private_context() {
        return $this->is_whitelisted || $this->is_private_request;
    }

    private function private_login_url() {
        return home_url( '/' . self::LOGIN_SLUG . '.php' );
    }

    private function is_private_path( $path ) {
        $normalized = rtrim( $path, '/' );
        $slug = '/' . self::LOGIN_SLUG;

        return $normalized === $slug
            || $normalized === $slug . '.php'
            || str_ends_with( $normalized, $slug )
            || str_ends_with( $normalized, $slug . '.php' )
            || $this->looks_like_private_script();
    }

    private function looks_like_private_script() {
        return basename( $_SERVER['SCRIPT_NAME'] ?? '' ) === 'windlogin.php'
            || basename( $_SERVER['PHP_SELF'] ?? '' ) === 'windlogin.php';
    }

    private function is_core_login_request() {
        return str_contains( $this->request_path, 'wp-login.php' )
            || ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-login.php' );
    }

    private function current_path() {
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url( $uri, PHP_URL_PATH );
        return $path ? '/' . ltrim( $path, '/' ) : '/';
    }

    private function should_bypass() {
        return ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
            || ( defined( 'DOING_CRON' ) && DOING_CRON );
    }

    private function deny_request() {
        nocache_headers();
        status_header( 404 );
        exit;
    }
}

new Windhard_Safe();
