<?php
/**
 * Tests for authorised payment AJAX routing.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\AjaxHandler;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentRequestToken;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitPaymentService;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeOrder;

/** Error sentinel so the controller's Exception handlers do not swallow JSON exits. */
class AjaxJsonResponse extends \Error {
	public $success;
	public $data;
	public $status;

	public function __construct( bool $success, $data, int $status ) {
		parent::__construct( 'JSON response' );
		$this->success = $success;
		$this->data = $data;
		$this->status = $status;
	}
}

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\AjaxHandler */
class AjaxHandlerTest extends TestCase {
	private $service;
	private $handler;
	private $order;
	private $factory_calls;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		$_POST = array( 'order_id' => '42' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'absint' )->alias( function ( $value ) { return abs( (int) $value ); } );
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'sanitize_text_field' )->alias( function ( $value ) { return trim( strip_tags( $value ) ); } );
		Functions\when( 'wp_salt' )->justReturn( 'test-auth-salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => 'yes' ) );
		Filters\expectApplied( 'wctwc_logging' )->andReturn( false );
		Functions\when( 'wp_send_json_success' )->alias( function ( $data, $status = 200 ) {
			throw new AjaxJsonResponse( true, $data, $status );
		} );
		Functions\when( 'wp_send_json_error' )->alias( function ( $data, $status = 200 ) {
			throw new AjaxJsonResponse( false, $data, $status );
		} );
		$this->order = new FakeOrder( 42 );
		$this->service = Mockery::mock( HitPaymentService::class );
		$this->factory_calls = 0;
		$this->handler = new AjaxHandler( function ( Settings $settings ) {
			++$this->factory_calls;
			return $this->service;
		} );
	}

	protected function tearDown(): void {
		$_POST = array();
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_missing_order_id_is_400(): void {
		$_POST = array();
		$response = $this->request( 'start_payment' );
		$this->assertFalse( $response->success );
		$this->assertSame( 400, $response->status );
		$this->assertSame( 'Order ID is required.', $response->data );
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_unauthorised_without_capability_or_token_is_403_and_service_not_called(): void {
		$this->service->shouldNotReceive( 'start' );
		Functions\expect( 'wc_get_order' )->never();
		$response = $this->request( 'start_payment' );
		$this->assertFalse( $response->success );
		$this->assertSame( 403, $response->status );
		$this->assertSame( 'Unauthorized request.', $response->data );
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_valid_token_allows_poll(): void {
		Functions\expect( 'wc_get_order' )->once()->with( 42 )->andReturn( $this->order );
		$_POST['order_token'] = PaymentRequestToken::for_order( 42 );
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => 'no' ) );
		$this->service->shouldReceive( 'poll' )->once()->with( $this->order )->andReturn( array( 'status' => 'pending' ) );
		$response = $this->request( 'poll_payment' );
		$this->assertTrue( $response->success );
		$this->assertSame( 200, $response->status );
		$this->assertSame( array( 'status' => 'pending' ), $response->data );
		$this->assertSame( 1, $this->factory_calls );
	}

	public function test_expired_token_is_403(): void {
		$_POST['order_token'] = PaymentRequestToken::create( 42, time() - 1 );
		$this->service->shouldNotReceive( 'start' );
		$response = $this->request( 'start_payment' );
		$this->assertFalse( $response->success );
		$this->assertSame( 403, $response->status );
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_start_refused_when_gateway_inactive(): void {
		Functions\expect( 'wc_get_order' )->once()->with( 42 )->andReturn( $this->order );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => 'no' ) );
		$this->assertFalse( function_exists( 'wcpos_get_settings' ) );
		$this->service->shouldNotReceive( 'start' );
		$response = $this->request( 'start_payment' );
		$this->assertFalse( $response->success );
		$this->assertSame( 403, $response->status );
		$this->assertSame( 'Windcave Terminal is disabled.', $response->data );
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_start_passes_station(): void {
		Functions\expect( 'wc_get_order' )->once()->with( 42 )->andReturn( $this->order );
		$_POST['order_token'] = PaymentRequestToken::for_order( 42 );
		$_POST['station'] = ' <b>S1</b> ';
		$this->service->shouldReceive( 'start' )->once()->with( $this->order, 'S1' )->andReturn( array( 'status' => 'pending' ) );
		$response = $this->request( 'start_payment' );
		$this->assertTrue( $response->success );
		$this->assertSame( array( 'status' => 'pending' ), $response->data );
	}

	public function test_answer_passes_button_and_value(): void {
		Functions\expect( 'wc_get_order' )->once()->with( 42 )->andReturn( $this->order );
		$_POST['order_token'] = PaymentRequestToken::for_order( 42 );
		$_POST['button'] = ' <b>B1</b> ';
		$_POST['value'] = ' <b>YES</b> ';
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => 'no' ) );
		$this->service->shouldReceive( 'answer' )->once()->with( $this->order, 'B1', 'YES' )->andReturn( array( 'status' => 'pending' ) );
		$this->assertTrue( $this->request( 'answer_prompt' )->success );
	}

	public function test_lock_runtime_exception_is_409(): void {
		Functions\expect( 'wc_get_order' )->once()->with( 42 )->andReturn( $this->order );
		$_POST['order_token'] = PaymentRequestToken::for_order( 42 );
		$this->service->shouldReceive( 'start' )->once()->with( $this->order, '' )->andThrow( new \RuntimeException( 'Payment lock is held.' ) );
		$response = $this->request( 'start_payment' );
		$this->assertFalse( $response->success );
		$this->assertSame( 409, $response->status );
		$this->assertSame( 'Payment lock is held.', $response->data );
	}

	public function test_paid_result_gets_pos_redirect_url(): void {
		$_POST['order_token'] = PaymentRequestToken::for_order( 42 );
		$order = Mockery::mock( FakeOrder::class, array( 42 ) )->makePartial();
		$order->shouldReceive( 'get_order_key' )->once()->andReturn( 'wc_order_42' );
		Functions\expect( 'wc_get_order' )->once()->andReturn( $order );
		Functions\when( 'woocommerce_pos_request' )->justReturn( true );
		Functions\expect( 'get_home_url' )->once()->with( null, '/wcpos-checkout/order-received/42' )->andReturn( 'https://shop.test/wcpos-checkout/order-received/42' );
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			return $url . '?' . http_build_query( $args );
		} );
		$this->service->shouldReceive( 'poll' )->once()->with( $order )->andReturn( array( 'status' => 'paid' ) );
		$response = $this->request( 'poll_payment' );
		$this->assertTrue( $response->success );
		$this->assertSame( 'https://shop.test/wcpos-checkout/order-received/42?key=wc_order_42', $response->data['redirect_url'] );
		$this->assertSame( array(), FakeOrder::$completion_calls );
	}

	private function request( string $method ): AjaxJsonResponse {
		try {
			$this->handler->$method();
		} catch ( AjaxJsonResponse $response ) {
			return $response;
		}
		$this->fail( 'Expected a JSON response to terminate the request.' );
	}
}
