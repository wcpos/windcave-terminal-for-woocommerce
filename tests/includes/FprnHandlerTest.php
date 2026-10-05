<?php
/**
 * Tests for the public result notification receiver.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\FprnHandler;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitPaymentService;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeOrder;

class FprnTerminated extends \RuntimeException {}

class TestFprnHandler extends FprnHandler {
	protected function terminate(): void {
		throw new FprnTerminated( 'FPRN terminated' );
	}
}

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\FprnHandler */
class FprnHandlerTest extends TestCase {
	private $previous_get;
	private $previous_post;
	private $order;
	private $service;
	private $handler;
	private $factory_calls;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->previous_get  = $_GET;
		$this->previous_post = $_POST;
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		Functions\when( 'wp_salt' )->justReturn( 'test-auth-salt' );
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'sanitize_text_field' )->alias( function ( $value ) { return trim( strip_tags( $value ) ); } );
		Functions\when( 'absint' )->alias( function ( $value ) { return abs( (int) $value ); } );
		Filters\expectApplied( 'wctwc_logging' )->andReturn( false );
		$this->order = new FakeOrder( 42 );
		PaymentAttempt::record_new( $this->order, 'ref1', 'S1', '10.00', 'NZD', 'uat' );
		Functions\when( 'wc_get_order' )->alias( function ( $id ) { return 42 === $id ? $this->order : false; } );
		$this->service = Mockery::mock( HitPaymentService::class );
		$this->factory_calls = 0;
		$factory = function ( Settings $settings ) {
			++$this->factory_calls;
			return $this->service;
		};
		$this->handler = new TestFprnHandler( $factory );
		$_GET = array(
			'order_id' => '42',
			'sig'      => substr( hash_hmac( 'sha256', 'wctwc_fprn_42', 'test-auth-salt' ), 0, 32 ),
			'txnRef'   => 'ref1',
		);
		$_POST = array();
	}

	protected function tearDown(): void {
		$_GET  = $this->previous_get;
		$_POST = $this->previous_post;
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_valid_signature_and_known_txn_ref_resolves_and_returns_200(): void {
		$_GET['status']  = 'paid';
		$_GET['station'] = 'forged-station';
		$this->service->shouldReceive( 'resolve_txn' )->once()->with( $this->order, 'ref1', 'fprn' )
			->andReturn( array( 'status' => 'pending' ) );
		$this->assert_response( 200 );
		$this->assertSame( 1, $this->factory_calls );
		$this->assertFalse( $this->order->is_paid() );
		$this->assertSame( 'pending', PaymentAttempt::current( $this->order )['status'] );
	}

	public function test_bad_signature_returns_404_without_service_call(): void {
		$_GET['sig'] = 'forged';
		$this->service->shouldNotReceive( 'resolve_txn' );
		$this->assert_response( 404 );
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_unknown_txn_ref_returns_404_without_service_call(): void {
		$_GET['txnRef'] = 'unknown';
		$this->service->shouldNotReceive( 'resolve_txn' );
		$this->assert_response( 404 );
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_missing_order_returns_404(): void {
		$_GET['order_id'] = '999';
		$_GET['sig'] = FprnHandler::signature( 999 );
		$this->service->shouldNotReceive( 'resolve_txn' );
		$this->assert_response( 404 );
		$this->assertSame( 0, $this->factory_calls );
	}

	public function test_accepts_TxnRef_capitalised_param(): void {
		unset( $_GET['txnRef'] );
		$_GET['TxnRef'] = 'ref1';
		$this->service->shouldReceive( 'resolve_txn' )->once()->with( $this->order, 'ref1', 'fprn' )
			->andReturn( array( 'status' => 'paid' ) );
		$this->assert_response( 200 );
	}

	public function test_service_exception_still_returns_200(): void {
		$this->service->shouldReceive( 'resolve_txn' )->once()->with( $this->order, 'ref1', 'fprn' )
			->andThrow( new \Exception( 'Status unavailable' ) );
		$this->assert_response( 200 );
	}

	public function test_missing_query_values_are_not_taken_from_post(): void {
		$this->service->shouldNotReceive( 'resolve_txn' );
		$_POST = $_GET;
		foreach ( array( 'order_id', 'sig', 'txnRef' ) as $key ) {
			$_GET = $_POST;
			unset( $_GET[ $key ] );
			$this->assert_response( 404 );
		}
		$this->assertSame( 0, $this->factory_calls );
	}

	private function assert_response( int $code ): void {
		Functions\expect( 'status_header' )->once()->with( $code );
		ob_start();
		try {
			$this->handler->handle();
			$this->fail( 'The handler must terminate its response.' );
		} catch ( FprnTerminated $e ) {
			$this->assertSame( 'FPRN terminated', $e->getMessage() );
		} finally {
			$body = ob_get_clean();
		}
		$this->assertSame( 200 === $code ? 'OK' : 'Not found', $body );
	}
}
