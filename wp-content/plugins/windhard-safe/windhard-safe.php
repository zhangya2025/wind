<?php
/**
 * Plugin Name: windhard-safe
 * Description: Enforce a private login entrance at /windlogin/ or windlogin.php with request-level guards.
 * Version: 4.0.0
 * Author: windhard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Windhard_Safe {
    const LOGIN_SLUG = 'windlogin';
    const GLOBAL_FLAG = '__windhard_safe_login_whitelist';

    /** @var bool */
    private $is_whitelisted = false;

    /** @var string */
    private $request_path = '/';

    /** @var WP_Error|null */
    private $login_error = null;

    /** @var bool */
    private $authenticated_user = false;

    /** @var bool */
    private $is_private_request = false;

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'detect_private_entry' ], 0 );
        add_action( 'init', [ $this, 'guard_login_and_admin' ], 0 );
        add_action( 'template_redirect', [ $this, 'handle_private_login' ], 0 );

        add_filter( 'login_url', [ $this, 'filter_login_url' ], 10, 3 );
        add_filter( 'lostpassword_url', [ $this, 'filter_login_related_url' ], 10, 2 );
        add_filter( 'register_url', [ $this, 'filter_login_related_url' ], 10, 1 );
    }

    public function detect_private_entry() {
        $this->request_path = $this->current_path();

        if ( $this->is_private_path( $this->request_path ) ) {
            $this->is_whitelisted    = true;
            $this->is_private_request = true;

            if ( ! isset( $GLOBALS[ self::GLOBAL_FLAG ] ) ) {
                $GLOBALS[ self::GLOBAL_FLAG ] = true;
            }

            if ( ! headers_sent() ) {
                header( 'X-Windhard-Safe: whitelisted' );
            }
        } elseif ( $this->looks_like_private_script() ) {
            $this->is_private_request = true;
        }
    }

    public function guard_login_and_admin() {
        if ( $this->should_bypass() ) {
            return;
        }

        $this->authenticated_user = $this->has_authenticated_user();

        $is_login_request = $this->is_core_login_request();
        if ( $is_login_request && ! $this->is_whitelisted ) {
            $this->deny_request();
        }

        if ( is_admin() && ! $this->authenticated_user && ! $this->is_whitelisted ) {
            $this->deny_request();
        }
    }

    public function handle_private_login() {
        if ( $this->is_private_request && ! $this->is_whitelisted ) {
            $this->deny_request();
        }

        if ( ! $this->is_whitelisted || $this->should_bypass() ) {
            return;
        }

        nocache_headers();
        status_header( 200 );

        if ( is_user_logged_in() ) {
            wp_safe_redirect( admin_url() );
            exit;
        }

        $redirect_to = $this->sanitize_redirect( isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '' );

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            $this->process_login_request( $redirect_to );
        }

        $this->render_login_form( $redirect_to );
        exit;
    }

    public function filter_login_url( $login_url, $redirect, $force_reauth ) {
        $private_url = $this->private_login_url();
        if ( $redirect ) {
            $private_url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $private_url );
        }

        if ( $force_reauth ) {
            $private_url = add_query_arg( 'reauth', '1', $private_url );
        }

        return $private_url;
    }

    public function filter_login_related_url( $url, $redirect = '' ) {
        $private_url = $this->private_login_url();
        if ( $redirect ) {
            $private_url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $private_url );
        }
        return $private_url;
    }

    private function current_path() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $path        = parse_url( $request_uri, PHP_URL_PATH );
        return $path ? '/' . ltrim( $path, '/' ) : '/';
    }

    private function is_private_path( $path ) {
        $slug          = '/' . self::LOGIN_SLUG;
        $normalized    = rtrim( $path, '/' );
        $script_name   = isset( $_SERVER['SCRIPT_NAME'] ) ? wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';
        $php_self      = isset( $_SERVER['PHP_SELF'] ) ? wp_unslash( $_SERVER['PHP_SELF'] ) : '';
        $script_base   = basename( $script_name );
        $php_self_base = basename( $php_self );

        if ( $normalized === $slug || $normalized === $slug . '.php' ) {
            return true;
        }

        if ( $this->ends_with( $normalized, $slug . '.php' ) || $this->ends_with( $normalized, $slug ) ) {
            return true;
        }

        if ( 'windlogin.php' === $script_base || 'windlogin.php' === $php_self_base ) {
            return true;
        }

        return false;
    }

    private function looks_like_private_script() {
        $script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';
        $php_self    = isset( $_SERVER['PHP_SELF'] ) ? wp_unslash( $_SERVER['PHP_SELF'] ) : '';

        return 'windlogin.php' === basename( $script_name ) || 'windlogin.php' === basename( $php_self );
    }

    private function ends_with( $haystack, $needle ) {
        if ( '' === $needle ) {
            return true;
        }

        $haystack_length = strlen( $haystack );
        $needle_length   = strlen( $needle );

        if ( $needle_length > $haystack_length ) {
            return false;
        }

        return substr( $haystack, -$needle_length ) === $needle;
    }

    private function private_login_url() {
        return home_url( '/' . self::LOGIN_SLUG . '.php' );
    }

    private function is_core_login_request() {
        if ( false !== stripos( $this->request_path, 'wp-login.php' ) ) {
            return true;
        }

        if ( function_exists( 'wp_validate_redirect' ) && isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
            return true;
        }

        return false;
    }

    private function should_bypass() {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }

        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return true;
        }

        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return true;
        }

        $script = isset( $_SERVER['SCRIPT_NAME'] ) ? wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';
        if ( false !== strpos( $script, 'admin-ajax.php' ) ) {
            return true;
        }

        if ( false !== strpos( $script, 'wp-cron.php' ) ) {
            return true;
        }

        return false;
    }

    private function has_authenticated_user() {
        if ( is_user_logged_in() ) {
            return true;
        }

        if ( function_exists( 'wp_validate_auth_cookie' ) ) {
            $user_id = wp_validate_auth_cookie( '', 'logged_in' );
            if ( $user_id ) {
                wp_set_current_user( $user_id );
                return true;
            }
        }

        return false;
    }

    private function deny_request() {
        nocache_headers();
        status_header( 404 );
        exit;
    }

    private function sanitize_redirect( $redirect_to ) {
        $redirect_to = $redirect_to ? $redirect_to : admin_url();
        return wp_validate_redirect( $redirect_to, admin_url() );
    }

    private function process_login_request( $redirect_to ) {
        $nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'windhard_safe_login' ) ) {
            $this->login_error = new WP_Error( 'invalid_nonce', __( 'Security check failed. Please try again.', 'windhard-safe' ) );
            return;
        }

        $credentials = [
            'user_login'    => isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '',
            'user_password' => isset( $_POST['pwd'] ) ? wp_unslash( $_POST['pwd'] ) : '',
            'remember'      => ! empty( $_POST['rememberme'] ),
        ];

        $user = wp_signon( $credentials, false );
        if ( is_wp_error( $user ) ) {
            $this->login_error = $user;
            return;
        }

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, $credentials['remember'] );
        wp_safe_redirect( $redirect_to );
        exit;
    }

    private function render_login_form( $redirect_to ) {
        $this->output_page_head();

        if ( $this->login_error instanceof WP_Error ) {
            echo '<div class="notice notice-error" role="alert">';
            foreach ( $this->login_error->get_error_messages() as $message ) {
                echo '<p>' . esc_html( $message ) . '</p>';
            }
            echo '</div>';
        }

        add_filter( 'login_form_middle', [ $this, 'render_nonce_field' ] );

        echo wp_login_form( [
            'echo'           => false,
            'form_id'        => 'windhard-safe-login',
            'label_username' => __( 'Username or Email', 'windhard-safe' ),
            'label_password' => __( 'Password', 'windhard-safe' ),
            'label_remember' => __( 'Remember Me', 'windhard-safe' ),
            'label_log_in'   => __( 'Log In', 'windhard-safe' ),
            'redirect'       => $redirect_to,
            'remember'       => true,
            'value_username' => isset( $_POST['log'] ) ? esc_attr( wp_unslash( $_POST['log'] ) ) : '',
            'value_remember' => ! empty( $_POST['rememberme'] ),
        ] );

        remove_filter( 'login_form_middle', [ $this, 'render_nonce_field' ] );
        $this->output_page_footer();
    }

    public function render_nonce_field( $content ) {
        return $content . wp_nonce_field( 'windhard_safe_login', '_wpnonce', true, false );
    }

    private function output_page_head() {
        echo '<!DOCTYPE html><html><head>';
        echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
        echo '<title>' . esc_html( get_bloginfo( 'name' ) ) . ' - ' . esc_html__( 'Login', 'windhard-safe' ) . '</title>';
        wp_enqueue_script( 'user-profile' );
        wp_print_head_scripts();
        wp_print_styles();
        echo '</head><body class="login">';
        echo '<div id="login">';
        echo '<h1><a href="' . esc_url( home_url( '/' ) ) . '" title="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></h1>';
    }

    private function output_page_footer() {
        echo '</div>';
        wp_print_footer_scripts();
        echo '</body></html>';
    }
}

new Windhard_Safe();
