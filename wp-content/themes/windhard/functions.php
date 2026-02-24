<?php
/**
 * Windhard theme bootstrap.
 *
 * Standalone fork scaffold from Twenty Twenty-Five.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'windhard_theme_setup' ) ) {
	/**
	 * Basic theme supports.
	 */
	function windhard_theme_setup() {
		add_editor_style( 'assets/css/editor-style.css' );
		add_theme_support( 'post-formats', array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' ) );
	}
}
add_action( 'after_setup_theme', 'windhard_theme_setup' );

if ( ! function_exists( 'windhard_enqueue_styles' ) ) {
	/**
	 * Enqueue frontend styles.
	 */
	function windhard_enqueue_styles() {
		$theme_version = wp_get_theme()->get( 'Version' );

		wp_enqueue_style(
			'windhard-style',
			get_theme_file_uri( 'style.css' ),
			array(),
			$theme_version
		);

		wp_enqueue_style(
			'windhard-base',
			get_theme_file_uri( 'assets/css/base.css' ),
			array( 'windhard-style' ),
			$theme_version
		);
	}
}
add_action( 'wp_enqueue_scripts', 'windhard_enqueue_styles' );
