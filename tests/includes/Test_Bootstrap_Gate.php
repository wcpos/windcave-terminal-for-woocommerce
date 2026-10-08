<?php
namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Includes;
class Test_Bootstrap_Gate extends \WP_UnitTestCase {
	public function test_missing_pro_loads_only_the_notice_in_real_wordpress(): void {
		// Fresh PHP process, real WordPress hooks/functions, no Pro or Woo stubs.
		$root = var_export( ABSPATH, true );
		$plugin = var_export( dirname( __DIR__, 2 ) . '/windcave-terminal-for-woocommerce.php', true );
		$code = <<<PHP_CODE
 define( 'ABSPATH', $root );
 define( 'DISABLE_WP_CRON', true );
 require ABSPATH . 'wp-includes/plugin.php';
 add_filter( 'option_active_plugins', '__return_empty_array' );
 add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
 require ABSPATH . 'wp-load.php';
 require $plugin;
 \\WCPOS\\WooCommercePOS\\WindcaveTerminal\\init();
 ob_start(); do_action( 'admin_notices' ); \$notice = ob_get_clean();
 echo json_encode( array(
 'helper' => function_exists( 'wcpos_pro_requires' ),
 'gateway' => has_filter( 'woocommerce_payment_gateways', array( 'WCPOS\\\\WooCommercePOS\\\\WindcaveTerminal\\\\Gateway', 'register_gateway' ) ),
 'adapter_loaded' => class_exists( 'WCPOS\\\\WooCommercePOS\\\\WindcaveTerminal\\\\Provider_Adapter', false ),
 'upgrade' => has_action( 'init', array( 'WCPOS\\\\WooCommercePOS\\\\WindcaveTerminal\\\\Legacy_Adoption', 'upgrade' ) ),
 'notice' => \$notice ) );
PHP_CODE;
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $exit );
		$this->assertSame( 0, $exit, implode( "\n", $output ) );
		$data = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $data, implode( "\n", $output ) );
		$this->assertFalse( $data['helper'] ); $this->assertFalse( $data['gateway'] );
		$this->assertFalse( $data['adapter_loaded'] ); $this->assertFalse( $data['upgrade'] );
		$this->assertStringContainsString( 'WooCommerce POS Pro 2.0', $data['notice'] );
	}
	public function test_gate_runs_after_pro_defines_its_helpers(): void {
		// Pro's Activator hooks plugins_loaded at 20 and that is what requires wcpos-pro-functions.php.
		$this->assertSame( 30, has_action( 'plugins_loaded', 'WCPOS\\WooCommercePOS\\WindcaveTerminal\\init' ) );
	}
	public function test_successful_gate_registers_provider_and_gateway(): void {
		\WCPOS\WooCommercePOS\WindcaveTerminal\init();
		$this->assertTrue( \WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers::instance()->has( \WCPOS\WooCommercePOS\WindcaveTerminal\Settings::GATEWAY_ID ) );
		$this->assertSame( 10, has_filter( 'woocommerce_payment_gateways', array( \WCPOS\WooCommercePOS\WindcaveTerminal\Gateway::class, 'register_gateway' ) ) );
	}
}
