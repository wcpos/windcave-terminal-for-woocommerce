<?php
/**
 * Tests for the gateway shell.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\Gateway;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;

/**
 * @covers \WCPOS\WooCommercePOS\WindcaveTerminal\Gateway
 */
class GatewayTest extends TestCase {
	/**
	 * Set up WordPress function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );
	}

	/**
	 * Clear the function stubs.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The settings keys and order are stable.
	 */
	public function test_form_field_keys_in_order(): void {
		$gateway = new Gateway();
		$this->assertSame(
			array(
				'enabled',
				'title',
				'description',
				'environment',
				'hit_user',
				'hit_key',
				'stations',
				'default_station',
				'lock_station',
				'vendor_id',
				'pos_name',
				'fprn_enabled',
				'show_logs',
			),
			array_keys( $gateway->form_fields )
		);
	}

	/**
	 * The HIT key uses a password input.
	 */
	public function test_hit_key_is_password_field(): void {
		$gateway = new Gateway();
		$this->assertSame( 'password', $gateway->form_fields['hit_key']['type'] );
	}

	/**
	 * Environment choices default to UAT.
	 */
	public function test_environment_options_and_default(): void {
		$gateway = new Gateway();
		$this->assertSame( 'uat', $gateway->form_fields['environment']['default'] );
		$this->assertSame(
			array( 'uat' => 'UAT (testing)', 'production' => 'Production' ),
			$gateway->form_fields['environment']['options']
		);
	}

	/**
	 * Registration preserves existing gateways.
	 */
	public function test_register_gateway_appends_class(): void {
		$this->assertSame( array( 'ExistingGateway', Gateway::class ), Gateway::register_gateway( array( 'ExistingGateway' ) ) );
	}

	/**
	 * The gateway exposes the specified identity and defaults.
	 */
	public function test_gateway_id_and_supports(): void {
		$gateway = new Gateway();
		$this->assertSame( 'windcave_terminal_for_woocommerce', $gateway->id );
		$this->assertSame( Settings::GATEWAY_ID, $gateway->id );
		$this->assertSame( array( 'products' ), $gateway->supports );
		$this->assertTrue( $gateway->has_fields );
		$this->assertSame( 'Windcave Terminal', $gateway->title );
		$this->assertSame( 'Pay in person on the Windcave terminal.', $gateway->description );
	}
}
