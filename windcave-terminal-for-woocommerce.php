<?php
/**
 * Plugin Name: Windcave Terminal for WooCommerce
 * Description: Take in-person card payments on a Windcave terminal (HIT) from WooCommerce POS.
 * Version:     1.0.0
 * Author:      kilbot
 * Author URI:  https://kilbot.com/
 * Update URI:  https://github.com/wcpos/windcave-terminal-for-woocommerce
 * License:     GPL v3 or later
 * Text Domain: windcave-terminal-for-woocommerce
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCTWC_VERSION', '1.0.0' );
define( 'WCTWC_PLUGIN_FILE', __FILE__ );
define( 'WCTWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCTWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCTWC_MINIMUM_PHP_VERSION', '7.4' );
define( 'WCTWC_MINIMUM_PHP_VERSION_ID', 70400 );

if ( file_exists( WCTWC_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once WCTWC_PLUGIN_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	function ( $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		$len    = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}
		$file = WCTWC_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Check the minimum PHP version on activation.
 */
function wctwc_activate(): void {
	if ( PHP_VERSION_ID >= WCTWC_MINIMUM_PHP_VERSION_ID ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Windcave Terminal for WooCommerce requires the PHP DOM extension (php-xml). Ask your host to enable it.', 'windcave-terminal-for-woocommerce' ) );
		}
		return;
	}
	deactivate_plugins( plugin_basename( __FILE__ ) );
	wp_die(
		esc_html(
			sprintf(
				/* translators: 1: Minimum PHP version, 2: Current PHP version. */
				__( 'Windcave Terminal for WooCommerce requires PHP %1$s or newer. Your server is running PHP %2$s.', 'windcave-terminal-for-woocommerce' ),
				WCTWC_MINIMUM_PHP_VERSION,
				PHP_VERSION
			)
		)
	);
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\wctwc_activate' );

/**
 * Remove the payment sweep on deactivation.
 */
function wctwc_deactivate(): void {
	wp_clear_scheduled_hook( 'wctwc_sweep_stale_payments' );
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\wctwc_deactivate' );

/**
 * Load plugin translations.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'windcave-terminal-for-woocommerce', false, dirname( plugin_basename( WCTWC_PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

/**
 * Register the WooCommerce gateway.
 */
function init(): void {
	if ( ! function_exists( 'wcpos_pro_requires' ) || ! wcpos_pro_requires( '2.0.0', __FILE__ ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Windcave Terminal requires WooCommerce POS Pro 2.0 or newer.', 'windcave-terminal-for-woocommerce' ) . '</p></div>';
			}
		);
		return;
	}
	add_filter( 'woocommerce_payment_gateways', array( Gateway::class, 'register_gateway' ) );
	wcpos_pro_register_server_provider( Settings::GATEWAY_ID, Provider_Adapter::class );
	add_action( 'init', array( Legacy_Adoption::class, 'upgrade' ), 20 );
}
// Pro defines wcpos_pro_requires() and the provider registration API from its own
// plugins_loaded hook at priority 20; the gate must run after that.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init', 30 );

/**
 * Declare compatibility with WooCommerce custom order tables.
 */
function declare_hpos_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\declare_hpos_compatibility' );
