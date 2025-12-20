<?php
/**
 * Private login bootstrap for windhard-safe plugin.
 * Provides a real file entry point without custom rewrite rules.
 */

define( 'WP_USE_THEMES', false );

require __DIR__ . '/wp-load.php';

do_action( 'init' );

if ( function_exists( 'wp' ) ) {
    wp();
}

do_action( 'wp_loaded' );

do_action( 'template_redirect' );

if ( ! headers_sent() ) {
    header( 'X-Windhard-Safe: template_redirect' );
}

exit;