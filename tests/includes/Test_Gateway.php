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
	/** @dataProvider availability_contexts */
	public function test_availability_respects_pos_and_web_enablement( string $web, bool $pos, string $context, string $missing, bool $expected ): void {
		global $wp;
		$old_query = $wp->query_vars;
		$wp->query_vars = 'pos' === $context ? array( 'wcpos' => 1 ) : ( 'order-pay' === $context ? array( 'order-pay' => 1234 ) : array() );
		$settings = static function ( $settings ) use ( $pos ) {
			$settings['gateways'][ Settings::GATEWAY_ID ]['enabled'] = $pos;
			return $settings;
		};
		add_filter( 'woocommerce_pos_payment_gateways_settings', $settings, 99 );
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$options = array( 'enabled' => $web, 'hit_user' => 'user', 'hit_key' => 'secret', 'stations' => 'station-1' );
		if ( $missing ) { $options[ $missing ] = ''; }
		update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', $options );
		try {
			$this->assertSame( $pos, ( new Settings() )->enabled_for_pos() );
			$this->assertSame( 'pos' === $context, woocommerce_pos_request() );
			$this->assertSame( 'order-pay' === $context, is_checkout_pay_page() );
			$this->assertSame( $expected, ( new Gateway() )->is_available() );
		} finally {
			$wp->query_vars = $old_query;
			remove_filter( 'woocommerce_pos_payment_gateways_settings', $settings, 99 );
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
		}
	}
	public function availability_contexts(): array {
		$cases = array();
		foreach ( array( 'order-pay', 'pos', 'storefront' ) as $context ) {
			$cases[ $context . ' POS only' ] = array( 'no', true, $context, '', 'storefront' !== $context );
			$cases[ $context . ' web only' ] = array( 'yes', false, $context, '', true );
			$cases[ $context . ' neither' ] = array( 'no', false, $context, '', false );
			foreach ( array( 'hit_user', 'hit_key' ) as $missing ) {
				$cases[ $context . ' missing ' . $missing ] = array( 'yes', true, $context, $missing, false );
			}
		}
		return $cases;
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
