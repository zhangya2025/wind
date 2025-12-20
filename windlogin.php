<?php
/**
 * Private login bootstrap for windhard-safe plugin.
 * Provides a real file entry point without custom rewrite rules.
 */

// Load WordPress to trigger plugins and handle the private login flow.
require __DIR__ . '/wp-blog-header.php';
