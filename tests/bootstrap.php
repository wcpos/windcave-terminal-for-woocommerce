<?php
/**
 * PHPUnit bootstrap.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WCTWC_VERSION', '0.0.0-test' );
define( 'WCTWC_PLUGIN_FILE', dirname( __DIR__ ) . '/windcave-terminal-for-woocommerce.php' );
define( 'WCTWC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WCTWC_PLUGIN_URL', 'http://localhost/wp-content/plugins/windcave-terminal-for-woocommerce/' );
define( 'WCTWC_MINIMUM_PHP_VERSION', '7.4' );
define( 'WCTWC_MINIMUM_PHP_VERSION_ID', 70400 );

require_once __DIR__ . '/stubs/woocommerce.php';
