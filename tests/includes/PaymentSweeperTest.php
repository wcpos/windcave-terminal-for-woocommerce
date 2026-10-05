<?php
/**
 * Tests for automatic follow-up of stale and set-aside attempts.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentSweeper;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitPaymentService;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeOrder;

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\PaymentSweeper */
class PaymentSweeperTest extends TestCase {
	private $order;
	private $service;
	private $sweeper;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'wc_get_order' )->alias( function ( $id ) { return new FakeOrder( $id ); } );
		Filters\expectApplied( 'wctwc_logging' )->andReturn( false );
		$this->order = new FakeOrder( 42 );
		$this->service = Mockery::mock( HitPaymentService::class );
		$this->sweeper = new PaymentSweeper( $this->service );
	}

	protected function tearDown(): void {
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_resolves_abandoned_txn_refs_and_notes_final_results(): void {
		foreach ( array( 'old-paid', 'old-pending', 'old-declined' ) as $ref ) {
			PaymentAttempt::record_new( $this->order, $ref, 'S1', '10.00', 'NZD', 'uat' );
			PaymentAttempt::abandon_current( $this->order );
		}
		foreach ( array( 'old-paid' => 'paid', 'old-pending' => 'pending', 'old-declined' => 'declined' ) as $ref => $status ) {
			$this->service->shouldReceive( 'resolve_txn' )->once()->with( $this->order, $ref, 'abandoned_sweep' )
				->andReturn( array( 'status' => $status ) );
		}

		$this->assertTrue( $this->sweeper->sweep_order( $this->order ) );
		$this->assertSame(
			array(
				array( 'note' => 'Windcave Terminal: set-aside TxnRef old-paid resolved by automatic follow-up (paid).', 'is_customer_note' => 0 ),
				array( 'note' => 'Windcave Terminal: set-aside TxnRef old-declined resolved by automatic follow-up (declined).', 'is_customer_note' => 0 ),
			),
			$this->order->notes
		);
	}

	public function test_skips_paid_order_after_abandoned_resolution(): void {
		PaymentAttempt::record_new( $this->order, 'old-ref', 'S1', '10.00', 'NZD', 'uat' );
		PaymentAttempt::abandon_current( $this->order );
		$this->record_current( 1200 );
		$this->service->shouldReceive( 'resolve_txn' )->once()->with( $this->order, 'old-ref', 'abandoned_sweep' )
			->andReturnUsing( function () {
				$fresh = new FakeOrder( 42 );
				$fresh->payment_complete( 'dps-old' );
				return array( 'status' => 'paid' );
			} );

		$this->assertTrue( $this->sweeper->sweep_order( $this->order ) );
		$this->assertFalse( $this->order->is_paid() );
		$this->assertTrue( ( new FakeOrder( 42 ) )->is_paid() );
	}

	public function test_resolves_stale_pending_current_attempt(): void {
		$this->record_current( 1200 );
		$this->service->shouldReceive( 'resolve_txn' )->once()->with( $this->order, 'current-ref', 'stale_sweep' )
			->andReturn( array( 'status' => 'pending' ) );

		$this->assertTrue( $this->sweeper->sweep_order( $this->order ) );
		$this->assertSame( 'pending', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( array(), PaymentAttempt::abandoned( $this->order ) );
	}

	public function test_leaves_fresh_pending_attempt_alone(): void {
		$this->record_current( 60 );
		$this->service->shouldNotReceive( 'resolve_txn' );

		$this->assertFalse( $this->sweeper->sweep_order( $this->order ) );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_add_schedule_registers_ten_minutes(): void {
		$schedules = $this->sweeper->add_schedule( array( 'hourly' => array( 'interval' => 3600 ) ) );
		$this->assertSame( 600, $schedules[ PaymentSweeper::SCHEDULE ]['interval'] );
		$this->assertSame( 'Every 10 minutes (Windcave Terminal cleanup)', $schedules[ PaymentSweeper::SCHEDULE ]['display'] );
		$this->assertSame( array( 'interval' => 3600 ), $schedules['hourly'] );
	}

	public function test_sweep_queries_both_attempt_keys_and_deduplicates_orders(): void {
		$this->record_current( 1200 );
		Filters\expectApplied( 'wctwc_stale_payment_batch' )->once()->with( 25 )->andReturn( 7 );
		foreach ( array( PaymentAttempt::META_CURRENT_TXN_REF => array( 'pending', 'failed', 'on-hold' ), PaymentAttempt::META_ABANDONED_TXN_REFS => 'any' ) as $key => $status ) {
			Functions\expect( 'wc_get_orders' )->once()->with( array(
				'type' => 'shop_order', 'limit' => 7, 'status' => $status,
				'orderby' => 'date', 'order' => 'ASC', 'meta_key' => $key, 'meta_compare' => 'EXISTS',
			) )->andReturn( array( $this->order ) );
		}
		$this->service->shouldReceive( 'resolve_txn' )->once()->with( $this->order, 'current-ref', 'stale_sweep' )
			->andReturn( array( 'status' => 'pending' ) );

		$this->sweeper->sweep();
		$this->assertSame( 'pending', PaymentAttempt::current( $this->order )['status'] );
	}

	public function test_resolution_exception_is_caught_per_order(): void {
		$this->record_current( 1200 );
		$this->service->shouldReceive( 'resolve_txn' )->once()->with( $this->order, 'current-ref', 'stale_sweep' )
			->andThrow( new \Exception( 'Status unavailable' ) );
		$this->assertTrue( $this->sweeper->sweep_order( $this->order ) );
	}

	private function record_current( int $age ): void {
		PaymentAttempt::record_new( $this->order, 'current-ref', 'S1', '10.00', 'NZD', 'uat' );
		$this->order->update_meta_data( PaymentAttempt::META_CURRENT_CREATED_AT, gmdate( 'c', time() - $age ) );
		$this->order->save();
	}
}
