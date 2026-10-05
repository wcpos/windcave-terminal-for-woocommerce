<?php
/**
 * Tests for the gateway shell.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\Gateway;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentRequestToken;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeOrder;

/**
 * @covers \WCPOS\WooCommercePOS\WindcaveTerminal\Gateway
 */
class GatewayTest extends TestCase {
	private $previous_wp;
	private $order;

	/**
	 * Set up WordPress function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );
		$this->previous_wp = $GLOBALS['wp'] ?? null;
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 42 ) );
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		$this->order = new FakeOrder( 42 );
		Functions\when( 'is_checkout_pay_page' )->justReturn( true );
		Functions\when( 'woocommerce_pos_request' )->justReturn( false );
		Functions\when( 'wc_get_order' )->justReturn( $this->order );
		Functions\when( 'absint' )->alias( function ( $value ) { return abs( (int) $value ); } );
		Functions\when( 'wp_salt' )->justReturn( 'test-auth-salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_attr' )->alias( function ( $value ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		} );
		Functions\when( 'esc_html' )->alias( function ( $value ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		} );
		Functions\when( 'esc_html__' )->alias( function ( $value ) { return esc_html( $value ); } );
		Functions\when( 'esc_attr__' )->alias( function ( $value ) { return esc_attr( $value ); } );
	}

	/**
	 * Clear the function stubs.
	 */
	protected function tearDown(): void {
		$GLOBALS['wp'] = $this->previous_wp;
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
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
				'log_level',
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

	public function test_payment_fields_renders_station_options_and_data_attributes(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'stations' => "S1\n<b>S2</b>",
			'default_station' => '<b>S2</b>',
			'show_logs' => 'yes',
		) );
		Functions\when( 'woocommerce_pos_request' )->justReturn( true );
		$html = $this->render_payment_fields();
		$this->assertStringContainsString( '<option value="S1">S1</option>', $html );
		$this->assertStringContainsString( '<option value="&lt;b&gt;S2&lt;/b&gt;" selected>&lt;b&gt;S2&lt;/b&gt;</option>', $html );
		$this->assertStringNotContainsString( '<b>S2</b>', $html );
		$document = new \DOMDocument();
		$document->loadHTML( $html );
		$root = $document->getElementById( 'wctwc-payment-interface' );
		$this->assertSame( 'wctwc-payment-interface', $root->getAttribute( 'class' ) );
		$this->assertSame( '42', $root->getAttribute( 'data-order-id' ) );
		$this->assertTrue( PaymentRequestToken::verify( $root->getAttribute( 'data-order-token' ), 42 ) );
		$this->assertSame( '<b>S2</b>', $root->getAttribute( 'data-default-station' ) );
		$this->assertSame( '0', $root->getAttribute( 'data-lock-station' ) );
		$this->assertSame( '0', $root->getAttribute( 'data-resume' ) );
		$this->assertSame( '1', $root->getAttribute( 'data-pos' ) );
		$this->assertSame( Settings::GATEWAY_ID, $root->getAttribute( 'data-gateway-id' ) );
		$this->assertStringContainsString( 'data-wctwc-mode="start">Start Terminal Payment</button>', $html );
		$this->assertStringContainsString( 'class="button wctwc-abandon" hidden>Set payment aside</button>', $html );
		$this->assertStringContainsString( '<div class="wctwc-prompt" hidden aria-live="assertive">', $html );
		$this->assertStringContainsString( '<p class="wctwc-prompt-line1"></p><p class="wctwc-prompt-line2"></p><div class="wctwc-prompt-buttons"></div>', $html );
		$this->assertStringContainsString( '<div class="wctwc-payment-status" role="status" aria-live="polite"></div>', $html );
		$this->assertStringContainsString( 'wctwc-toggle-log', $html );
	}

	public function test_payment_fields_locked_station_select_is_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'stations' => "S1\nS2",
			'default_station' => 'S1',
			'lock_station' => 'yes',
		) );
		$html = $this->render_payment_fields();
		$this->assertStringContainsString( 'data-lock-station="1"', $html );
		$this->assertStringContainsString( '<select id="wctwc-station-select" class="wctwc-station-select" disabled>', $html );
		$this->assertStringContainsString( '<option value="S1" selected>S1</option>', $html );
		$this->assertStringContainsString( '<option value="S2">S2</option>', $html );
		$this->assertStringNotContainsString( 'wctwc-toggle-log', $html );
		$this->assertStringContainsString( 'wctwc-payment-log-textarea', $html );
	}

	public function test_payment_fields_resume_flag_when_pending_attempt(): void {
		PaymentAttempt::record_new( $this->order, 'ref', 'S1', '10.00', 'NZD', 'uat' );
		$html = $this->render_payment_fields();
		$this->assertStringContainsString( 'data-resume="1"', $html );
		$this->assertStringContainsString( 'data-wctwc-mode="cancel">Cancel Terminal Payment</button>', $html );
		$this->order->payment_complete( 'txn' );
		$this->assertStringContainsString( 'data-resume="0"', $this->render_payment_fields() );
	}

	public function test_process_payment_success_redirect_when_paid(): void {
		$order = Mockery::mock( FakeOrder::class, array( 42 ) )->makePartial();
		$order->payment_complete( 'txn' );
		$order->shouldReceive( 'get_checkout_order_received_url' )->once()->andReturn( 'https://shop.test/checkout/order-received/42?key=wc_order_42' );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wc_add_notice' )->never();
		$this->assertSame(
			array( 'result' => 'success', 'redirect' => 'https://shop.test/checkout/order-received/42?key=wc_order_42' ),
			( new Gateway() )->process_payment( 42 )
		);
		$this->assertSame( 1, FakeOrder::$completion_calls[42] );
	}

	public function test_process_payment_failure_notice_when_unpaid(): void {
		Functions\expect( 'wc_add_notice' )->once()->with(
			'This order has not been paid yet. Start the payment above and wait for the terminal to approve it; the order finishes on its own.',
			'notice'
		);
		$this->assertSame( array( 'result' => 'failure' ), ( new Gateway() )->process_payment( 42 ) );
		$this->assertFalse( $this->order->is_paid() );
		$this->assertSame( array(), FakeOrder::$completion_calls );
	}

	private function render_payment_fields(): string {
		ob_start();
		try {
			( new Gateway() )->payment_fields();
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}
}
