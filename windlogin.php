<?php
/**
 * Private login bootstrap for windhard-safe plugin.
 * Real entry point without rewrite rules.
 */

define( 'WP_USE_THEMES', false );

/**
 * 1. Load WordPress core environment
 *    等价于 index.php 的前半段
 */
require __DIR__ . '/wp-load.php';

/**
 * 2. Fire init (插件依赖的关键阶段)
 */
do_action( 'init' );

/**
 * 3. Build main query (前台完整生命周期必需)
 */
if ( function_exists( 'wp' ) ) {
    wp();
}

/**
 * 4. wp_loaded（插件/核心状态已稳定）
 */
do_action( 'wp_loaded' );

/**
 * 5. template_redirect
 *    私有登录逻辑在此阶段接管
 */
do_action( 'template_redirect' );
