<?php
namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Includes;
use WCPOS\WooCommercePOS\WindcaveTerminal\Gateway;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
class Test_Gateway extends \WP_UnitTestCase {
	public function test_availability_requires_credentials_and_station(): void {
		$key = 'woocommerce_' . Settings::GATEWAY_ID . '_settings';
		$options = array( 'enabled' => 'yes', 'hit_user' => 'user', 'hit_key' => 'secret', 'stations' => 'station-1' );
		update_option( $key, $options );
		$this->assertTrue( ( new Gateway() )->is_available() );
		foreach ( array( 'hit_user', 'hit_key', 'stations' ) as $missing ) {
			update_option( $key, array_replace( $options, array( $missing => '' ) ) );
			$this->assertFalse( ( new Gateway() )->is_available(), $missing );
		}
	}
	public function test_secret_is_never_rendered_and_blank_preserves_it(): void {
		update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array( 'hit_key' => 'fixture-private-value' ) );
		$gateway = new Gateway();
		$this->assertTrue( method_exists( $gateway, 'generate_wctwc_secret_html' ) );
		$html = $gateway->generate_wctwc_secret_html( 'hit_key', $gateway->form_fields['hit_key'] );
		$this->assertStringNotContainsString( 'fixture-private-value', $html );
		$this->assertSame( 'fixture-private-value', $gateway->validate_wctwc_secret_field( 'hit_key', '' ) );
	}
}
