<?php
/**
 * Completion and two-stale-copies race tests.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\OrderCompletion;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentLock;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeOrder;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeWpdb;

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\OrderCompletion */
class OrderCompletionTest extends TestCase {
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\WCPOS\WooCommercePOS\WindcaveTerminal\Logger::$threshold = 'off';
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new FakeWpdb();
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array( 'title' => 'Counter terminal' ) );
		Functions\when( 'wp_generate_uuid4' )->alias( function () {
			static $n = 0;
			return 'uuid-' . ++$n;
		} );
		Functions\when( 'wc_get_order' )->alias( function ( $id ) {
			return new FakeOrder( $id );
		} );
	}

	protected function tearDown(): void {
		\WCPOS\WooCommercePOS\WindcaveTerminal\Logger::$threshold = null;
		\WCPOS\WooCommercePOS\WindcaveTerminal\Logger::$logger = null;
		PaymentLock::release( 42, 'complete_payment' );
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		unset( $GLOBALS['wctwc_test_cache'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_completes_unpaid_order_once(): void {
		Functions\when( 'clean_post_cache' )->justReturn( null );
		$order = new FakeOrder( 42 );
		$order->save();
		$this->assertSame( OrderCompletion::COMPLETED, OrderCompletion::complete( $order, 'dps-123' ) );
		$fresh = new FakeOrder( 42 );
		$this->assertTrue( $fresh->is_paid() );
		$this->assertSame( 1, FakeOrder::$completion_calls[42] );
		$this->assertSame( 'dps-123', $fresh->get_transaction_id() );
		$this->assertSame( Settings::GATEWAY_ID, $fresh->get_payment_method() );
		$this->assertSame( 'Counter terminal', $fresh->get_payment_method_title() );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	/**
	 * Keep two requests' HPOS caches stale until the completion reload evicts them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_already_paid_fresh_copy_is_not_completed_again(): void {
		require_once __DIR__ . '/Support/woocommerce-cache-stubs.php';
		$seed = new FakeOrder( 42 );
		$seed->save();
		$request = 'first';
		$order_cache = array();
		$data_cache = array();
		$evictions = array();
		$GLOBALS['wctwc_test_cache'] = array(
			'data_caching' => true,
			'row_delete_result' => false,
			'order_cache_remove' => function ( $id ) use ( &$order_cache, &$request, &$evictions ) {
				unset( $order_cache[ $request ][ $id ] );
				$evictions[] = 'order';
			},
			'data_row_clear' => function ( $id ) use ( &$data_cache, &$request, &$evictions ) {
				unset( $data_cache[ $request ][ $id ] );
				$evictions[] = 'row';
			},
			'data_meta_clear' => function ( $id ) use ( &$evictions ) {
				$this->assertSame( 42, $id );
				$evictions[] = 'meta';
			},
		);
		Functions\when( 'wc_get_order' )->alias( function ( $id ) use ( &$order_cache, &$data_cache, &$request ) {
			if ( ! isset( $order_cache[ $request ][ $id ] ) ) {
				$data_cache[ $request ][ $id ] = $data_cache[ $request ][ $id ] ?? new FakeOrder( $id );
				$order_cache[ $request ][ $id ] = clone $data_cache[ $request ][ $id ];
			}
			return clone $order_cache[ $request ][ $id ];
		} );
		Functions\expect( 'clean_post_cache' )->twice()->with( 42 );
		$first = wc_get_order( 42 );
		$request = 'second';
		$second = wc_get_order( 42 );
		$this->assertFalse( $first->is_paid() );
		$this->assertFalse( $second->is_paid() );
		$request = 'first';
		$this->assertSame( OrderCompletion::COMPLETED, OrderCompletion::complete( $first, 'dps-first' ) );
		$request = 'second';
		$this->assertFalse( $second->is_paid(), 'The second request still holds its unpaid copy.' );
		$second->update_meta_data( 'late_note', 'saved from stale copy' );
		$second->save();
		$this->assertSame( OrderCompletion::ALREADY_PAID, OrderCompletion::complete( $second, 'dps-second' ) );
		$this->assertSame( 1, FakeOrder::$completion_calls[42] );
		$this->assertSame( 'dps-first', ( new FakeOrder( 42 ) )->get_transaction_id() );
		$this->assertSame( array( 'order', 'row', 'meta', 'order', 'row', 'meta' ), $evictions );
	}

	public function test_busy_when_claim_held(): void {
		Filters\expectApplied( 'wctwc_logging' )->once()->andReturn( false );
		$this->assertTrue( PaymentLock::acquire( 42, 'complete_payment' ) );
		$order = new FakeOrder( 42 );
		$this->assertSame( OrderCompletion::BUSY, OrderCompletion::complete( $order, 'dps-busy' ) );
		$this->assertSame( 0, $order->payment_complete_calls );
		$this->assertSame( array(), FakeOrder::$completion_calls );
		$this->assertFalse( $order->is_paid() );
	}
}
