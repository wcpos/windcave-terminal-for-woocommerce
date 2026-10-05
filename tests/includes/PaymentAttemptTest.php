<?php
/**
 * Order attempt history, current pointer and receipt tests.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeOrder;

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\PaymentAttempt */
class PaymentAttemptTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
	}

	public function test_new_txn_ref_is_unique_alnum_and_short(): void {
		$refs = array();
		for ( $i = 0; $i < 100; ++$i ) {
			$ref = PaymentAttempt::new_txn_ref( PHP_INT_MAX );
			$this->assertMatchesRegularExpression( '/^' . PHP_INT_MAX . 'W[a-f0-9]{12}$/', $ref );
			$this->assertTrue( ctype_alnum( $ref ) );
			$this->assertLessThanOrEqual( 40, strlen( $ref ) );
			$refs[] = $ref;
		}
		$this->assertCount( 100, array_unique( $refs ) );
	}

	public function test_record_new_sets_current_and_history(): void {
		$order = new FakeOrder( 42 );
		$this->assertNull( PaymentAttempt::current( $order ) );
		$this->assertSame( array(), PaymentAttempt::history( $order ) );
		$entry = PaymentAttempt::record_new( $order, '42Wabc123', '1001', '10.00', 'NZD', 'uat' );
		$this->assertSame( array(
			'txn_ref' => '42Wabc123', 'station' => '1001', 'amount' => '10.00',
			'currency' => 'NZD', 'environment' => 'uat', 'status' => 'pending',
			'dps_txn_ref' => '', 'created_at' => $entry['created_at'], 'updated_at' => $entry['updated_at'],
		), $entry );
		$this->assertNotFalse( strtotime( $entry['created_at'] ) );
		$this->assertNotFalse( strtotime( $entry['updated_at'] ) );
		$this->assertSame( array(
			'txn_ref' => '42Wabc123', 'station' => '1001', 'status' => 'pending', 'created_at' => $entry['created_at'],
		), PaymentAttempt::current( $order ) );
		$this->assertSame( array( $entry ), PaymentAttempt::history( new FakeOrder( 42 ) ) );
		$this->assertSame( $entry, PaymentAttempt::find( $order, '42Wabc123' ) );
		$this->assertNull( PaymentAttempt::find( $order, 'missing' ) );
		$this->assertSame( 1, $order->save_calls );
	}

	public function test_update_old_attempt_does_not_touch_current_status(): void {
		$order = new FakeOrder( 42 );
		$old = PaymentAttempt::record_new( $order, 'old', '1001', '10.00', 'NZD', 'uat' );
		$old['updated_at'] = '2000-01-01T00:00:00+00:00';
		$order->update_meta_data( PaymentAttempt::META_ATTEMPTS, array( $old ) );
		PaymentAttempt::record_new( $order, 'new', '1002', '10.00', 'NZD', 'uat' );
		$current = PaymentAttempt::current( $order );
		PaymentAttempt::update( $order, 'old', 'approved', 'dps-old' );
		$this->assertSame( $current, PaymentAttempt::current( $order ) );
		$updated = PaymentAttempt::find( new FakeOrder( 42 ), 'old' );
		$this->assertSame( 'approved', $updated['status'] );
		$this->assertSame( 'dps-old', $updated['dps_txn_ref'] );
		$this->assertNotSame( $old['updated_at'], $updated['updated_at'] );
		PaymentAttempt::update( $order, 'old', 'approved' );
		$this->assertSame( 'dps-old', PaymentAttempt::find( $order, 'old' )['dps_txn_ref'] );
		PaymentAttempt::update( $order, 'new', 'declined' );
		$this->assertSame( 'declined', PaymentAttempt::current( new FakeOrder( 42 ) )['status'] );
	}

	public function test_update_final_forgets_abandoned(): void {
		$order = new FakeOrder( 42 );
		$order->update_meta_data( PaymentAttempt::META_ABANDONED_TXN_REFS, array( 'old', '', 'old', 'other' ) );
		$this->assertSame( array( 'old', 'other' ), PaymentAttempt::abandoned( $order ) );
		PaymentAttempt::update( $order, 'old', 'pending' );
		$this->assertSame( array( 'old', 'other' ), PaymentAttempt::abandoned( $order ) );
		PaymentAttempt::update( $order, 'old', 'approved' );
		$this->assertSame( array( 'other' ), PaymentAttempt::abandoned( new FakeOrder( 42 ) ) );
		PaymentAttempt::update( $order, 'other', 'declined' );
		$this->assertSame( array(), PaymentAttempt::abandoned( $order ) );
		$this->assertArrayNotHasKey( PaymentAttempt::META_ABANDONED_TXN_REFS, FakeOrder::$rows[42]['meta'] );
		foreach ( array( 'approved', 'declined', 'pending', 'abandoned', '' ) as $status ) {
			$this->assertSame( in_array( $status, array( 'approved', 'declined' ), true ), PaymentAttempt::is_final( $status ) );
			$this->assertSame( in_array( $status, array( 'pending', '' ), true ), PaymentAttempt::is_pending( $status ) );
		}
	}

	public function test_store_receipt_sets_meta_history_and_note(): void {
		$order = new FakeOrder( 42 );
		PaymentAttempt::record_new( $order, 'old', '1001', '10.00', 'NZD', 'uat' );
		$new = PaymentAttempt::record_new( $order, 'new', '1002', '10.00', 'NZD', 'uat' );
		$receipt = "APPROVED\nNZD 10.00";
		PaymentAttempt::store_receipt( $order, 'old', $receipt, 40 );
		$fresh = new FakeOrder( 42 );
		$this->assertSame( $receipt, $fresh->get_meta( PaymentAttempt::META_RECEIPT ) );
		$this->assertSame( 40, $fresh->get_meta( PaymentAttempt::META_RECEIPT_WIDTH ) );
		$this->assertSame( $receipt, PaymentAttempt::find( $fresh, 'old' )['receipt'] );
		$this->assertSame( 40, PaymentAttempt::find( $fresh, 'old' )['receipt_width'] );
		$this->assertSame( $new, PaymentAttempt::find( $fresh, 'new' ) );
		$this->assertSame( array( array( 'note' => "Windcave receipt (TxnRef old):\n" . $receipt, 'is_customer_note' => 0 ) ), $order->notes );
		$this->assertSame( 3, $order->save_calls );
	}

	public function test_store_receipt_ignores_empty(): void {
		$order = new FakeOrder( 42 );
		PaymentAttempt::record_new( $order, 'txn', '1001', '10.00', 'NZD', 'uat' );
		PaymentAttempt::store_receipt( $order, 'txn', 'original receipt', 40 );
		$row = FakeOrder::$rows[42];
		$notes = $order->notes;
		PaymentAttempt::store_receipt( $order, 'txn', '', 0 );
		$this->assertSame( $row, FakeOrder::$rows[42] );
		$this->assertSame( $notes, $order->notes );
		$this->assertSame( 2, $order->save_calls );
	}

	public function test_abandon_current_parks_pending_txn_ref(): void {
		$order = new FakeOrder( 42 );
		PaymentAttempt::record_new( $order, 'txn', '1001', '10.00', 'NZD', 'uat' );
		PaymentAttempt::abandon_current( $order );
		$this->assertNull( PaymentAttempt::current( new FakeOrder( 42 ) ) );
		$this->assertSame( 'abandoned', PaymentAttempt::find( $order, 'txn' )['status'] );
		$this->assertSame( array( 'txn' ), PaymentAttempt::abandoned( $order ) );
		foreach ( array( PaymentAttempt::META_CURRENT_TXN_REF, PaymentAttempt::META_CURRENT_STATION, PaymentAttempt::META_CURRENT_STATUS, PaymentAttempt::META_CURRENT_CREATED_AT ) as $key ) {
			$this->assertArrayNotHasKey( $key, FakeOrder::$rows[42]['meta'] );
		}
		PaymentAttempt::abandon_current( $order );
		$this->assertSame( array( 'txn' ), PaymentAttempt::abandoned( $order ) );
		PaymentAttempt::record_new( $order, 'paid', '1001', '10.00', 'NZD', 'uat' );
		PaymentAttempt::update( $order, 'paid', 'approved' );
		PaymentAttempt::abandon_current( $order );
		$this->assertSame( 'approved', PaymentAttempt::find( $order, 'paid' )['status'] );
		$this->assertSame( array( 'txn' ), PaymentAttempt::abandoned( $order ) );
	}

	public function test_claim_order_gateway_sets_method_and_title(): void {
		$order = new FakeOrder( 42 );
		$order->set_payment_method( 'cash' );
		PaymentAttempt::claim_order_gateway( $order, 'Counter terminal' );
		$this->assertSame( Settings::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( 'Counter terminal', $order->get_payment_method_title() );
		$this->assertSame( 0, $order->save_calls );
		PaymentAttempt::claim_order_gateway( $order, 'Changed title' );
		$this->assertSame( 'Counter terminal', $order->get_payment_method_title() );
		$order->set_payment_method_title( '' );
		PaymentAttempt::claim_order_gateway( $order, 'Filled title' );
		$this->assertSame( 'Filled title', $order->get_payment_method_title() );
	}
}
