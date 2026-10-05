<?php
/**
 * Tests for HIT payment state and verified order completion.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Services;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentLock;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitClient;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitPaymentService;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitResponse;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeOrder;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeWpdb;

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitPaymentService */
class HitPaymentServiceTest extends TestCase {
	private $previous_wpdb;
	private $options;
	private $client;
	private $service;
	private $order;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new FakeWpdb();
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		$this->options = array(
			'environment'     => 'uat',
			'hit_user'        => 'u',
			'hit_key'         => 'k',
			'stations'        => "S1\nS2",
			'default_station' => 'S1',
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( $this->options );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'wp_generate_uuid4' )->alias( function () {
			static $n = 0;
			return 'payment-service-uuid-' . ++$n;
		} );
		Functions\when( 'wc_get_order' )->alias( function ( $id ) {
			return new FakeOrder( $id );
		} );
		Filters\expectApplied( 'wctwc_logging' )->andReturn( false );
		$this->client  = Mockery::mock( HitClient::class );
		$this->service = new HitPaymentService( $this->client, new Settings( $this->options ) );
		$this->order   = new FakeOrder( 42, '1.00', 'NZD' );
	}

	protected function tearDown(): void {
		PaymentLock::release( 42, 'create_payment' );
		PaymentLock::release( 42, 'complete_payment' );
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_start_records_attempt_before_purchase(): void {
		$response = $this->response( 'status-in-progress.xml' );
		$this->client->shouldReceive( 'purchase' )->once()->andReturnUsing(
			function ( $station, $txn_ref, $amount, $currency, $merchant_ref ) use ( $response ) {
				$this->assertNotSame( '', $txn_ref );
				$this->assertSame( $txn_ref, $this->order->get_meta( '_wctwc_current_txn_ref' ) );
				$saved = new FakeOrder( 42 );
				$this->assertSame( $txn_ref, $saved->get_meta( '_wctwc_current_txn_ref' ) );
				$attempt = PaymentAttempt::find( $saved, $txn_ref );
				$this->assertSame( 'pending', $attempt['status'] );
				$this->assertSame( 'uat', $attempt['environment'] );
				$this->assertSame( $station, $attempt['station'] );
				$this->assertSame( $amount, $attempt['amount'] );
				$this->assertSame( $currency, $attempt['currency'] );
				return $response;
			}
		);

		$result = $this->service->start( $this->order );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( $this->order->get_meta( '_wctwc_current_txn_ref' ), $result['txn_ref'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	public function test_start_sends_amount_currency_and_station_and_returns_prompt(): void {
		$this->client->shouldReceive( 'purchase' )->once()
			->with( 'S1', Mockery::type( 'string' ), '1.00', 'NZD', 'Order #42' )
			->andReturn( $this->response( 'status-in-progress.xml' ) );

		$result = $this->service->start( $this->order );
		$this->assertSame( array( 'status', 'txn_ref', 'prompt', 'txn_status_id', 'retry_allowed', 'message' ), array_keys( $result ) );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( array( 'line1' => 'PROCESSING', 'line2' => '', 'buttons' => array() ), $result['prompt'] );
		$this->assertSame( 5, $result['txn_status_id'] );
		$this->assertFalse( $result['retry_allowed'] );
		$this->assertSame( '', $result['message'] );
		$this->assertSame( 1, $this->order->save_calls );
	}

	public function test_start_rejects_unknown_station(): void {
		$this->client->shouldNotReceive( 'purchase' );
		$this->client->shouldNotReceive( 'status' );

		$result = $this->service->start( $this->order, 'S9' );
		$this->assertSame( 'error', $result['status'] );
		$this->assertTrue( $result['retry_allowed'] );
		$this->assertSame( 'Choose a Windcave terminal (Station ID).', $result['message'] );
		$this->assertSame( '', $result['txn_ref'] );
		$this->assertSame( array( 'line1' => '', 'line2' => '', 'buttons' => array() ), $result['prompt'] );
		$this->assertSame( 0, $result['txn_status_id'] );
		$this->assertSame( 0, $this->order->save_calls );
	}

	public function test_start_uses_default_station_when_locked(): void {
		$this->options['lock_station'] = 'yes';
		$service = new HitPaymentService( $this->client, new Settings( $this->options ) );
		$this->client->shouldReceive( 'purchase' )->once()
			->with( 'S1', Mockery::type( 'string' ), '1.00', 'NZD', 'Order #42' )
			->andReturn( $this->response( 'status-in-progress.xml' ) );

		$result = $service->start( $this->order, 'S2' );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'S1', PaymentAttempt::current( $this->order )['station'] );
	}

	public function test_start_reuses_pending_attempt_without_new_purchase(): void {
		$this->record_attempt();
		$this->client->shouldNotReceive( 'purchase' );
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )
			->andReturn( $this->response( 'status-in-progress.xml' ) );

		$result = $this->service->start( $this->order, 'S2' );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'ref', $result['txn_ref'] );
		$this->assertSame( 'Resuming the payment already in progress on the terminal.', $result['message'] );
		$this->assertCount( 1, PaymentAttempt::history( $this->order ) );
		$this->assertSame( 1, $this->order->save_calls );
	}

	public function test_start_transport_error_keeps_attempt_pending(): void {
		$this->client->shouldReceive( 'purchase' )->once()->andReturn( new \WP_Error( 'network_down', 'Network unavailable.' ) );
		$this->client->shouldNotReceive( 'status' );

		$result = $this->service->start( $this->order );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertFalse( $result['retry_allowed'] );
		$this->assertSame( 'Could not reach Windcave. Checking the terminal status…', $result['message'] );
		$this->assertSame( 'pending', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( 'pending', PaymentAttempt::find( new FakeOrder( 42 ), $result['txn_ref'] )['status'] );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_start_pc_marks_attempt_declined_and_returns_station_busy(): void {
		$this->client->shouldReceive( 'purchase' )->once()->andReturn( $this->response( 'reco-pc.xml' ) );

		$result = $this->service->start( $this->order );
		$this->assertSame( 'station_busy', $result['status'] );
		$this->assertTrue( $result['retry_allowed'] );
		$this->assertSame( 'declined', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( 'declined', PaymentAttempt::find( $this->order, $result['txn_ref'] )['status'] );
		$this->assertSame( 'EXISTING TXN', $result['prompt']['line1'] );
		$this->assertSame( 'The terminal is still finishing an earlier transaction. Complete or cancel it on the terminal, then try again.', $result['message'] );
		$this->assertSame( 'Windcave Terminal: TxnRef ' . $result['txn_ref'] . ' not started, the terminal is still finishing an earlier transaction (PC).', $this->order->notes[0]['note'] );
		$this->assertSame( 0, $this->order->notes[0]['is_customer_note'] );
		$this->assertSame( array(), FakeOrder::$completion_calls );
	}

	public function test_poll_approved_completes_order_once_and_stores_receipt(): void {
		$this->record_attempt();
		$response = $this->response( 'status-approved.xml' );
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $response );

		$first = $this->service->poll( $this->order );
		$fresh = new FakeOrder( 42 );
		$second = $this->service->poll( $fresh );
		$this->assertSame( 'paid', $first['status'] );
		$this->assertSame( 'paid', $second['status'] );
		$this->assertFalse( $first['retry_allowed'] );
		$this->assertFalse( $second['retry_allowed'] );
		$this->assertSame( 1, FakeOrder::$completion_calls[42] );
		$this->assertTrue( $fresh->is_paid() );
		$this->assertSame( '0000000100e1a6f9', $fresh->get_transaction_id() );
		$this->assertSame( $response->receipt(), $fresh->get_meta( PaymentAttempt::META_RECEIPT ) );
		$this->assertSame( 30, $fresh->get_meta( PaymentAttempt::META_RECEIPT_WIDTH ) );
		$this->assertSame( 'approved', PaymentAttempt::find( $fresh, 'ref' )['status'] );
		$this->assertSame( '0000000100e1a6f9', PaymentAttempt::find( $fresh, 'ref' )['dps_txn_ref'] );
		$this->assertSame( 'Windcave Terminal payment approved. TxnRef ref, auth 000289, Visa 411111******1111.', $this->order->notes[1]['note'] );
	}

	public function test_poll_declined_marks_declined_and_allows_retry(): void {
		$this->order = new FakeOrder( 42, '1.76', 'NZD' );
		$this->record_attempt();
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $this->response( 'status-declined.xml' ) );

		$result = $this->service->poll( $this->order );
		$this->assertSame( 'declined', $result['status'] );
		$this->assertTrue( $result['retry_allowed'] );
		$this->assertSame( 'DECLINED', $result['message'] );
		$this->assertSame( 'declined', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( 'declined', PaymentAttempt::find( $this->order, 'ref' )['status'] );
		$this->assertSame( 'Windcave Terminal payment declined. TxnRef ref, response 51, DECLINED.', $this->order->notes[0]['note'] );
		$this->assertSame( 'declined', $this->service->poll( $this->order )['status'] );
		$this->assertSame( array(), FakeOrder::$completion_calls );
	}

	public function test_poll_amount_mismatch_is_verification_failed_and_not_completed(): void {
		$this->order = new FakeOrder( 42, '2.00', 'NZD' );
		$this->record_attempt();
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $this->response( 'status-approved.xml' ) );

		$result = $this->service->poll( $this->order );
		$this->assertSame( 'verification_failed', $result['status'] );
		$this->assertFalse( $result['retry_allowed'] );
		$this->assertFalse( ( new FakeOrder( 42 ) )->is_paid() );
		$this->assertSame( array(), FakeOrder::$completion_calls );
		$this->assertSame( 'approved', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( '0000000100e1a6f9', PaymentAttempt::find( $this->order, 'ref' )['dps_txn_ref'] );
		$this->assertSame( 'Windcave approved TxnRef ref but it was not applied: amount mismatch (expected 200 cents, got 100). Check the Windcave portal before taking payment again.', $this->order->notes[1]['note'] );
		$this->assertSame( 'verification_failed', $this->service->poll( $this->order )['status'] );
	}

	public function test_poll_order_total_changed_is_verification_failed(): void {
		$this->record_attempt();
		FakeOrder::$rows[42]['total'] = '2.00';
		$edited_order = new FakeOrder( 42 );
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $this->response( 'status-approved.xml' ) );

		$result = $this->service->poll( $edited_order );
		$this->assertSame( 'verification_failed', $result['status'] );
		$this->assertSame( 0, $edited_order->payment_complete_calls );
		$this->assertSame( array(), FakeOrder::$completion_calls );
		$this->assertSame( 'Windcave approved TxnRef ref but it was not applied: order total changed (attempt 1.00, order 2.00). Check the Windcave portal before taking payment again.', $edited_order->notes[1]['note'] );
	}

	public function test_poll_currency_changed_is_verification_failed(): void {
		$this->record_attempt();
		FakeOrder::$rows[42]['currency'] = 'AUD';
		$edited_order = new FakeOrder( 42 );
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $this->response( 'status-approved.xml' ) );

		$result = $this->service->poll( $edited_order );
		$this->assertSame( 'verification_failed', $result['status'] );
		$this->assertSame( 0, $edited_order->payment_complete_calls );
		$this->assertSame( array(), FakeOrder::$completion_calls );
		$this->assertSame( 'Windcave approved TxnRef ref but it was not applied: currency changed (attempt NZD, order AUD). Check the Windcave portal before taking payment again.', $edited_order->notes[1]['note'] );
	}

	public function test_poll_environment_mismatch_is_verification_failed(): void {
		$this->record_attempt( 'production' );
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $this->response( 'status-approved.xml' ) );

		$result = $this->service->poll( $this->order );
		$this->assertSame( 'verification_failed', $result['status'] );
		$this->assertFalse( $result['retry_allowed'] );
		$this->assertSame( array(), FakeOrder::$completion_calls );
		$this->assertFalse( ( new FakeOrder( 42 ) )->is_paid() );
		$this->assertSame( 'approved', PaymentAttempt::find( $this->order, 'ref' )['status'] );
		$this->assertSame( 'Windcave approved TxnRef ref but it was not applied: environment mismatch (attempt production, settings uat). Check the Windcave portal before taking payment again.', $this->order->notes[1]['note'] );
	}

	public function test_poll_pj_marks_declined(): void {
		$this->record_attempt();
		$history                  = $this->order->get_meta( PaymentAttempt::META_ATTEMPTS );
		$history[0]['created_at'] = gmdate( 'c', time() - 31 );
		$this->order->update_meta_data( PaymentAttempt::META_ATTEMPTS, $history );
		$this->order->save();
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $this->response( 'reco-pj.xml' ) );

		$result = $this->service->poll( $this->order );
		$this->assertSame( 'declined', $result['status'] );
		$this->assertTrue( $result['retry_allowed'] );
		$this->assertSame( 'Windcave has no record of this transaction. Start the payment again.', $result['message'] );
		$this->assertSame( 'declined', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( 'Windcave Terminal: Windcave has no record of TxnRef ref (PJ); marked declined.', $this->order->notes[0]['note'] );
		$this->assertSame( array(), FakeOrder::$completion_calls );
	}

	public function test_poll_pj_within_grace_stays_pending_without_writes(): void {
		$this->record_attempt();
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $this->response( 'reco-pj.xml' ) );

		$result = $this->service->poll( $this->order );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'Waiting for Windcave to register the transaction…', $result['message'] );
		$this->assertSame( 'pending', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( 'pending', PaymentAttempt::find( $this->order, 'ref' )['status'] );
		$this->assertSame( 1, $this->order->save_calls );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_poll_transport_error_stays_pending(): void {
		$this->record_attempt();
		$before = PaymentAttempt::history( $this->order );
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( new \WP_Error( 'network_down', 'Network unavailable.' ) );

		$result = $this->service->poll( $this->order );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertFalse( $result['retry_allowed'] );
		$this->assertSame( 'Waiting for Windcave…', $result['message'] );
		$this->assertSame( $before, PaymentAttempt::history( $this->order ) );
		$this->assertSame( 1, $this->order->save_calls );
		$this->assertSame( array(), $this->order->notes );
	}

	public function test_answer_sends_ui_then_returns_status(): void {
		$this->record_attempt();
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->ordered()->andReturn( $this->response( 'status-signature.xml' ) );
		$this->client->shouldReceive( 'ui' )->once()->with( 'S1', 'ref', 'B1', 'YES' )->ordered()->andReturn( $this->response( 'status-in-progress.xml' ) );
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->ordered()->andReturn( $this->response( 'status-approved.xml' ) );

		$prompt = $this->service->poll( $this->order );
		$this->assertSame( 'pending', $prompt['status'] );
		$this->assertSame( 'SIGNATURE OK?', $prompt['prompt']['line1'] );
		$this->assertSame( 'CHECK SIGNATURE', $prompt['prompt']['line2'] );
		$this->assertSame( array( array( 'name' => 'B1', 'label' => 'YES' ), array( 'name' => 'B2', 'label' => 'NO' ) ), $prompt['prompt']['buttons'] );
		$this->assertSame( 7, $prompt['txn_status_id'] );
		$result = $this->service->answer( $this->order, 'B1', 'YES' );
		$this->assertSame( 'paid', $result['status'] );
		$this->assertSame( 1, FakeOrder::$completion_calls[42] );
	}

	public function test_answer_rejects_cancel_value(): void {
		$this->record_attempt();
		$this->client->shouldNotReceive( 'ui' );
		$this->client->shouldNotReceive( 'status' );

		$result = $this->service->answer( $this->order, 'B2', 'CANCEL' );
		$this->assertSame( 'error', $result['status'] );
		$this->assertTrue( $result['retry_allowed'] );
		$this->assertSame( 'pending', PaymentAttempt::current( $this->order )['status'] );
	}

	public function test_cancel_presses_enabled_cancel_button(): void {
		$this->record_attempt();
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->ordered()->andReturn( $this->response( 'status-cancel-button.xml' ) );
		$this->client->shouldReceive( 'ui' )->once()->with( 'S1', 'ref', 'B2', 'CANCEL' )->ordered()->andReturn( $this->response( 'status-in-progress.xml' ) );
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->ordered()->andReturn( $this->response( 'status-declined.xml' ) );

		$result = $this->service->cancel( $this->order );
		$this->assertSame( 'declined', $result['status'] );
		$this->assertTrue( $result['retry_allowed'] );
		$this->assertSame( 'declined', PaymentAttempt::current( $this->order )['status'] );
	}

	public function test_cancel_without_cancel_button_reports_unavailable(): void {
		$this->record_attempt();
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $this->response( 'status-signature.xml' ) );
		$this->client->shouldNotReceive( 'ui' );

		$result = $this->service->cancel( $this->order );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertFalse( $result['retry_allowed'] );
		$this->assertSame( 'The terminal is not offering cancel right now. Cancel on the terminal, or set the payment aside.', $result['message'] );
		$this->assertSame( 'SIGNATURE OK?', $result['prompt']['line1'] );
		$this->assertSame( array( array( 'name' => 'B1', 'label' => 'YES' ), array( 'name' => 'B2', 'label' => 'NO' ) ), $result['prompt']['buttons'] );
		$this->assertSame( 'pending', PaymentAttempt::current( $this->order )['status'] );
		$this->assertSame( 1, $this->order->save_calls );
	}

	public function test_cancel_unreachable_abandons(): void {
		$this->record_attempt();
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( new \WP_Error( 'network_down', 'Network unavailable.' ) );
		$this->client->shouldNotReceive( 'ui' );

		$result = $this->service->cancel( $this->order );
		$this->assertSame( 'abandoned', $result['status'] );
		$this->assertSame( 'ref', $result['txn_ref'] );
		$this->assertTrue( $result['retry_allowed'] );
		$this->assertSame( 'The terminal did not respond, so the payment was set aside. Start a new payment or choose another method.', $result['message'] );
		$this->assertNull( PaymentAttempt::current( $this->order ) );
		$this->assertSame( array( 'ref' ), PaymentAttempt::abandoned( $this->order ) );
		$this->assertSame( 'abandoned', PaymentAttempt::find( $this->order, 'ref' )['status'] );
		$this->assertSame( 'Windcave Terminal: terminal did not respond to cancel; attempt set aside for automatic follow-up.', $this->order->notes[0]['note'] );
	}

	public function test_abandon_parks_txn_ref(): void {
		$this->record_attempt();
		$this->client->shouldNotReceive( 'status' );
		$this->client->shouldNotReceive( 'ui' );

		$result = $this->service->abandon( $this->order );
		$this->assertSame( 'abandoned', $result['status'] );
		$this->assertSame( 'ref', $result['txn_ref'] );
		$this->assertTrue( $result['retry_allowed'] );
		$this->assertNull( PaymentAttempt::current( $this->order ) );
		$this->assertSame( array( 'ref' ), PaymentAttempt::abandoned( new FakeOrder( 42 ) ) );
		$this->assertSame( 'abandoned', PaymentAttempt::find( $this->order, 'ref' )['status'] );
		$this->assertSame( 'Windcave Terminal: TxnRef ref set aside by the cashier; automatic follow-up will check its result.', $this->order->notes[0]['note'] );
		$this->assertSame( 0, $this->order->notes[0]['is_customer_note'] );
		$this->assertSame( 'idle', $this->service->poll( $this->order )['status'] );
	}

	public function test_resolve_txn_completes_abandoned_approved_attempt(): void {
		$this->record_attempt();
		$this->service->abandon( $this->order );
		$this->client->shouldReceive( 'status' )->once()->with( 'S1', 'ref' )->andReturn( $this->response( 'status-approved.xml' ) );

		$result = $this->service->resolve_txn( $this->order, 'ref', 'sweeper' );
		$this->assertSame( 'paid', $result['status'] );
		$this->assertFalse( $result['retry_allowed'] );
		$this->assertSame( 1, FakeOrder::$completion_calls[42] );
		$this->assertTrue( ( new FakeOrder( 42 ) )->is_paid() );
		$this->assertSame( '0000000100e1a6f9', ( new FakeOrder( 42 ) )->get_transaction_id() );
		$this->assertSame( array(), PaymentAttempt::abandoned( $this->order ) );
		$this->assertNull( PaymentAttempt::current( $this->order ) );
		$this->assertSame( 'approved', PaymentAttempt::find( $this->order, 'ref' )['status'] );
	}

	public function test_repeat_complete_status_does_not_duplicate_receipt_note(): void {
		$this->record_attempt();
		$response = $this->response( 'status-approved.xml' );
		$this->client->shouldReceive( 'status' )->twice()->with( 'S1', 'ref' )->andReturn( $response );

		$first = $this->service->resolve_txn( $this->order, 'ref', 'fprn' );
		$second = $this->service->resolve_txn( $this->order, 'ref', 'sweeper' );
		$this->assertSame( 'paid', $first['status'] );
		$this->assertSame( 'paid', $second['status'] );
		$this->assertSame( 1, FakeOrder::$completion_calls[42] );
		$receipt_notes = array_filter( $this->order->notes, function ( $note ) {
			return 0 === strpos( $note['note'], 'Windcave receipt (TxnRef ref):' );
		} );
		$this->assertCount( 1, $receipt_notes );
		$this->assertSame( "Windcave receipt (TxnRef ref):\n" . $response->receipt(), array_values( $receipt_notes )[0]['note'] );
		$this->assertSame( 0, array_values( $receipt_notes )[0]['is_customer_note'] );
		$this->assertSame( $response->receipt(), PaymentAttempt::find( $this->order, 'ref' )['receipt'] );
	}

	public function test_resolve_txn_unknown_ref_makes_no_call(): void {
		$this->record_attempt();
		$this->client->shouldNotReceive( 'status' );
		$this->client->shouldNotReceive( 'purchase' );
		$this->client->shouldNotReceive( 'ui' );

		$result = $this->service->resolve_txn( $this->order, 'unknown', 'fprn' );
		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'unknown', $result['txn_ref'] );
		$this->assertSame( 'Unknown TxnRef for this order.', $result['message'] );
		$this->assertSame( 1, $this->order->save_calls );
		$this->assertSame( array(), FakeOrder::$completion_calls );
	}

	private function record_attempt( string $environment = 'uat' ): void {
		PaymentAttempt::record_new( $this->order, 'ref', 'S1', $this->order->get_total(), $this->order->get_currency(), $environment );
	}

	private function response( string $fixture ): HitResponse {
		return HitResponse::from_xml( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/hit/' . $fixture ) );
	}
}
