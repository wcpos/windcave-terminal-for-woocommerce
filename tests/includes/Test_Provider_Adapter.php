<?php
namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Includes;

use WCPOS\WooCommercePOS\WindcaveTerminal\Provider_Adapter;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Conformance\Windcave_Conformance_Fixture;
require_once __DIR__ . '/Conformance/Windcave_Conformance_Fixture.php';

class Test_Provider_Adapter extends \WP_UnitTestCase {
	private $fixture;
	private $adapter;
	private $row;
	public function setUp(): void {
		parent::setUp();
		$this->assertTrue( class_exists( Provider_Adapter::class ), 'The HIT adapter must implement Pro’s provider boundary.' );
		$this->fixture = new Windcave_Conformance_Fixture();
		$this->fixture->install();
		$this->adapter = new Provider_Adapter();
		$order = wc_create_order();
		$order->set_total( '92.95' ); $order->save();
		$this->row = array( 'id' => wp_generate_uuid4(), 'order_id' => $order->get_id(), 'amount' => '92.95', 'currency' => 'EUR', 'created_at_gmt' => gmdate( 'c' ) );
	}
	public function tearDown(): void {
		if ( $this->fixture ) { $this->fixture->uninstall(); }
		parent::tearDown();
	}
	private function set_dispatch_age( string $ref, int $age ): void {
		$key = Provider_Adapter::context_key( $ref );
		$context = get_option( $key );
		$context['dispatched_at_gmt'] = gmdate( 'c', time() - $age );
		update_option( $key, $context );
	}
	public function test_replay_busy_preserves_indeterminate_error_data(): void {
		$this->fixture->script( 'replay_busy' );
		$this->assertTrue( $this->adapter->create_reader_action( $this->row, 'station-1' )->get_error_data()['indeterminate'] );
		$error = $this->adapter->create_reader_action( $this->row, 'station-1' );
		$this->assertWPError( $error );
		$this->assertSame( array( 'indeterminate' => true, 'status' => 502 ), $error->get_error_data() );
		$this->assertSame( array( 'Purchase', 'Status', 'Purchase' ), array_column( $this->fixture->raw_calls, 'type' ) );
	}
	public function test_replay_non_success_replies_are_indeterminate(): void {
		$this->fixture->script( 'create_indeterminate' );
		$this->assertWPError( $this->adapter->create_reader_action( $this->row, 'station-1' ) );
		foreach ( array( '', '00', 'PJ', 'PO', 'PD', 'PE', 'PF', '51' ) as $code ) {
			$this->fixture->response_override = static fn( $xml ) => Windcave_Conformance_Fixture::response( 'Status' === (string) $xml->TxnType ? Windcave_Conformance_Fixture::fixture( 'reco-pj' ) : '<Scr><Complete>1</Complete><ReCo>' . $code . '</ReCo><Result><AP>0</AP></Result></Scr>' );
			$error = $this->adapter->create_reader_action( $this->row, 'station-1' );
			$this->assertWPError( $error, $code );
			$this->assertSame( array( 'indeterminate' => true, 'status' => 502 ), $error->get_error_data(), $code );
		}
	}
	public function test_pj_grace_starts_at_dispatch_not_row_creation(): void {
		$this->row['created_at_gmt'] = gmdate( 'c', time() - 60 );
		$ref = $this->start();
		$c = get_option( Provider_Adapter::context_key( $ref ) );
		$this->assertSame( $this->row['created_at_gmt'], $c['created_at_gmt'] );
		$this->assertArrayHasKey( 'dispatched_at_gmt', $c );
		$this->fixture->response_override = Windcave_Conformance_Fixture::response( Windcave_Conformance_Fixture::fixture( 'reco-pj' ) );
		$this->assertSame( array( 'status' => 'pending' ), $this->adapter->fetch( $ref ) );
	}
	/** @dataProvider answer_labels */
	public function test_enabled_labels_are_displayed_and_mapped_to_protocol_values( string $first, string $second, string $slot, string $value ): void {
		$ref = $this->start( 'prompt' );
		$this->fixture->response_override = Windcave_Conformance_Fixture::response( str_replace( array( '>YES<', '>NO<' ), array( '>' . $first . '<', '>' . $second . '<' ), $this->fixture->xml( $ref, 'prompt' ) ) );
		$prompt = $this->adapter->fetch( $ref )['prompt'];
		$this->assertSame( array( array( 'id' => 'B1', 'label' => $first ), array( 'id' => 'B2', 'label' => $second ) ), $prompt['buttons'] );
		$this->assertNotWPError( $this->adapter->answer( $ref, $prompt['id'], $slot ) );
		$ui = array_values( array_filter( $this->fixture->raw_calls, static fn( $call ) => 'UI' === $call['type'] ) );
		$this->assertCount( 1, $ui );
		$xml = simplexml_load_string( $ui[0]['xml'] );
		$this->assertSame( $slot, (string) $xml->Name );
		$this->assertSame( $value, (string) $xml->Val );
	}
	public function answer_labels(): array {
		return array(
			'custom B1' => array( 'Continue', 'Back', 'B1', 'YES' ),
			'custom B2' => array( 'Continue', 'Back', 'B2', 'NO' ),
			'explicit NO overrides B1' => array( 'no', 'yes', 'B1', 'NO' ),
			'explicit YES overrides B2' => array( 'no', 'yes', 'B2', 'YES' ),
			'cancel B1' => array( 'Cancel', 'Back', 'B1', 'CANCEL' ),
			'cancel B2' => array( 'Continue', 'Cancel', 'B2', 'CANCEL' ),
		);
	}
	public function test_invalid_ui_client_error_is_plain_400(): void {
		$ref = $this->start( 'prompt' );
		// Exercise the client validation via the adapter request boundary, before HTTP.
		$request = new \ReflectionMethod( Provider_Adapter::class, 'request' );
		$request->setAccessible( true );
		$error = $request->invoke( $this->adapter, $ref, 'ui', 'B1', 'CONTINUE' );
		$this->assertSame( 'wctwc_hit_invalid_ui', $error->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $error->get_error_data() );
		$this->assertNotContains( 'UI', array_column( $this->fixture->raw_calls, 'type' ) );
	}
	public function test_decline_with_cancel_text_is_not_cancelled(): void {
		$ref = $this->start();
		foreach ( array( array( '51', '', 'failed' ), array( '', '51', 'failed' ), array( 'UNKNOWN', '', 'failed' ), array( '', '', 'cancelled' ) ) as $case ) {
			$this->fixture->response_override = Windcave_Conformance_Fixture::response( '<Scr><Complete>1</Complete><ReCo>' . $case[0] . '</ReCo><DL1>CARD CANCELLED</DL1><Result><AP>0</AP><RC>' . $case[1] . '</RC></Result></Scr>' );
			$this->assertSame( $case[2], $this->adapter->fetch( $ref )['status'] );
		}
	}
	public function test_refund_polls_until_second_status_completes(): void {
		$ref = $this->start();
		$row = $this->row;
		$row['provider_refs'] = array( 'action' => $ref, 'transaction_id' => 'original-dps' );
		foreach ( array( 'refund_delayed' => 'succeeded', 'refund_delayed_failed' => 'failed' ) as $scenario => $status ) {
			$this->fixture->script( $scenario );
			$this->fixture->raw_calls = array();
			$result = $this->adapter->refund( $row, 4567, '5.00' );
			$this->assertSame( $status, $result['status'] );
			if ( 'succeeded' === $status ) { $this->assertSame( '0000000100e1a6f9', $result['provider_ref'] ); }
			$this->assertSame( array( 'Refund', 'Status', 'Status' ), array_column( $this->fixture->raw_calls, 'type' ) );
			$this->assertSame( array( substr( md5( 'refund-4567' ), 0, 16 ) ), array_values( array_unique( array_column( $this->fixture->raw_calls, 'ref' ) ) ) );
		}
	}

	/** @dataProvider approved_refund_money */
	public function test_approved_refund_verifies_money( string $approved, string $currency, string $expected, bool $delayed ): void {
		$ref = $this->start();
		$row = $this->row;
		$row['provider_refs'] = array( 'action' => $ref, 'transaction_id' => 'original-dps' );
		$this->fixture->response_override = function ( $xml ) use ( $approved, $currency, $delayed ) {
			if ( $delayed && 'Refund' === (string) $xml->TxnType ) {
				return Windcave_Conformance_Fixture::response( '<Scr><Complete>0</Complete></Scr>' );
			}
			return Windcave_Conformance_Fixture::response( '<Scr><Complete>1</Complete><Cur>' . $currency . '</Cur><DpsTxnRef>refund-dps</DpsTxnRef><Result><AP>1</AP><AmtA>' . $approved . '</AmtA></Result></Scr>' );
		};
		$messages = array();
		$filter = static function ( $message, $level, $context ) use ( &$messages ) {
			if ( 'error' === $level && 'windcave-terminal' === ( $context['source'] ?? '' ) ) { $messages[] = $message; }
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $filter, 10, 3 );
		try { $result = $this->adapter->refund( $row, 4569, '5.00' ); }
		finally { remove_filter( 'woocommerce_logger_log_message', $filter, 10 ); }
		$this->assertSame( array( 'status' => $expected, 'provider_ref' => 'refund-dps' ), $result );
		$this->assertSame( $delayed ? array( 'Purchase', 'Refund', 'Status' ) : array( 'Purchase', 'Refund' ), array_column( $this->fixture->raw_calls, 'type' ) );
		if ( 'failed' === $expected ) {
			$this->assertNotEmpty( $messages );
			$this->assertStringContainsString( '4569', $messages[0] );
			$this->assertStringContainsString( 'requested 5.00 EUR', $messages[0] );
			$this->assertStringContainsString( 'approved ' . ( '500' === $approved ? '5.00' : '4.00' ) . ' ' . $currency, $messages[0] );
		} else { $this->assertSame( array(), $messages ); }
	}
	public function approved_refund_money(): array {
		$cases = array();
		foreach ( array( false, true ) as $delayed ) {
			$source = $delayed ? 'Status' : 'Refund';
			$cases[ $source . ' equal' ] = array( '500', 'EUR', 'succeeded', $delayed );
			$cases[ $source . ' amount mismatch' ] = array( '400', 'EUR', 'failed', $delayed );
			$cases[ $source . ' currency mismatch' ] = array( '500', 'USD', 'failed', $delayed );
			$cases[ $source . ' absent currency' ] = array( '500', '', 'succeeded', $delayed );
		}
		return $cases;
	}

	public function test_unfinished_refund_is_bounded_and_logs_portal_check(): void {
		$ref = $this->start( 'refund_pending' );
		$row = $this->row;
		$row['provider_refs'] = array( 'action' => $ref, 'transaction_id' => 'original-dps' );
		$messages = array();
		$filter = static function ( $message, $level, $context ) use ( &$messages ) {
			if ( 'warning' === $level && 'windcave-terminal' === ( $context['source'] ?? '' ) ) { $messages[] = $message; }
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $filter, 10, 3 );
		$before = microtime( true );
		try { $result = $this->adapter->refund( $row, 4568, '5.00' ); }
		finally { remove_filter( 'woocommerce_logger_log_message', $filter, 10 ); }
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => substr( md5( 'refund-4568' ), 0, 16 ) ), $result );
		$this->assertNotEmpty( $messages );
		$this->assertStringContainsString( $result['provider_ref'], $messages[0] );
		$this->assertStringContainsString( 'Windcave portal', $messages[0] );
		$this->assertGreaterThanOrEqual( 19, microtime( true ) - $before );
		$this->assertLessThan( 23, microtime( true ) - $before );
		$status_calls = array_filter( $this->fixture->raw_calls, static fn( $call ) => 'Status' === $call['type'] );
		$this->assertGreaterThanOrEqual( 8, count( $status_calls ) );
		$this->assertLessThanOrEqual( 10, count( $status_calls ) );
	}

	private function start( string $scenario = 'create_ok' ): string {
		$this->fixture->script( $scenario );
		$result = $this->adapter->create_reader_action( $this->row, 'station-1' );
		$this->assertNotWPError( $result );
		return $result['ref'];
	}
	/** The per-action context pins the environment only; the HIT key is read live, never copied into an option. */
	public function test_action_context_never_stores_credentials(): void {
		update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array_replace( (array) get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array() ), array( 'hit_key' => 'HITKEYPLANTED' ) ) );
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_wctwc_action_%' AND option_value LIKE '%HITKEYPLANTED%'" ) );
		$context = array( 'environment' => 'production' );
		$this->assertSame( 'production', Provider_Adapter::settings_for( $context )->environment() );
		$this->assertSame( 'HITKEYPLANTED', Provider_Adapter::settings_for( $context )->hit_key() );
	}

	public function test_purchase_uses_row_money_and_stable_reference(): void {
		$ref = $this->start();
		$this->assertSame( substr( md5( $this->row['id'] ), 0, 16 ), $ref );
		$xml = simplexml_load_string( $this->fixture->raw_calls[0]['xml'] );
		$this->assertSame( '92.95', (string) $xml->Amount );
		$this->assertSame( 'EUR', (string) $xml->Cur );
		parse_str( wp_parse_url( (string) $xml->UrlSuccess, PHP_URL_QUERY ), $query );
		$this->assertSame( 'windcave', $query['provider'] ?? null );
		$this->assertSame( $ref, $query['txnRef'] ?? null );
	}
	public function test_lost_purchase_response_replays_status_without_second_purchase(): void {
		$this->fixture->script( 'create_indeterminate' );
		$error = $this->adapter->create_reader_action( $this->row, 'station-1' );
		$this->assertTrue( $error->get_error_data()['indeterminate'] );
		$result = $this->adapter->create_reader_action( $this->row, 'station-1' );
		$this->assertNotWPError( $result );
		$this->assertSame( array( 'Purchase', 'Status' ), array_column( $this->fixture->raw_calls, 'type' ) );
	}
	public function test_prompt_identity_is_stable_and_changes_with_question(): void {
		$ref = $this->start( 'prompt' );
		$one = $this->adapter->fetch( $ref );
		$this->assertSame( $one, $this->adapter->fetch( $ref ) );
		$this->assertSame( array( 'SIGNATURE OK?', 'CHECK SIGNATURE' ), $one['prompt']['lines'] );
		$this->assertSame( array( array( 'id' => 'B1', 'label' => 'YES' ), array( 'id' => 'B2', 'label' => 'NO' ) ), $one['prompt']['buttons'] );
		$this->fixture->response_override = Windcave_Conformance_Fixture::response( str_replace( 'CHECK SIGNATURE', 'CHECK AGAIN', $this->fixture->xml( $ref, 'prompt' ) ) );
		$this->assertNotSame( $one['prompt']['id'], $this->adapter->fetch( $ref )['prompt']['id'] );
		$error = $this->adapter->answer( $ref, $one['prompt']['id'], 'B1' );
		$this->assertSame( 'wcpos_prompt_stale', $error->get_error_code() );
		$this->assertNotContains( 'UI', array_column( $this->fixture->raw_calls, 'type' ) );
	}
	public function test_answer_uses_button_own_label_and_fetches_result(): void {
		$ref = $this->start( 'prompt' );
		$prompt = $this->adapter->fetch( $ref )['prompt'];
		$this->assertSame( 'completed', $this->adapter->answer( $ref, $prompt['id'], 'B2' )['status'] );
		$ui = array_values( array_filter( $this->fixture->raw_calls, static fn( $r ) => 'UI' === $r['type'] ) )[0];
		$xml = simplexml_load_string( $ui['xml'] );
		$this->assertSame( 'B2', (string) $xml->Name ); $this->assertSame( 'NO', (string) $xml->Val );
	}
	public function test_disabled_button_never_sends_ui(): void {
		$ref = $this->start( 'prompt' ); $prompt = $this->adapter->fetch( $ref )['prompt'];
		$this->fixture->response_override = Windcave_Conformance_Fixture::response( str_replace( '<B1 en="1">', '<B1 en="0">', $this->fixture->xml( $ref, 'prompt' ) ) );
		$this->assertSame( 'wcpos_prompt_stale', $this->adapter->answer( $ref, $prompt['id'], 'B1' )->get_error_code() );
		$this->assertNotContains( 'UI', array_column( $this->fixture->raw_calls, 'type' ) );
	}
	public function test_pj_grace_and_expiration(): void {
		foreach ( array( 0 => 'pending', 31 => 'failed' ) as $age => $status ) {
			$this->row['id'] = wp_generate_uuid4();
			$this->fixture->response_override = null; $ref = $this->start();
			$this->set_dispatch_age( $ref, $age );
			$this->fixture->response_override = Windcave_Conformance_Fixture::response( Windcave_Conformance_Fixture::fixture( 'reco-pj' ) );
			$result = $this->adapter->fetch( $ref ); $this->assertSame( $status, $result['status'] );
			if ( $age ) { $this->assertSame( 'not_registered', $result['failure_reason'] ); }
		}
	}
	public function test_pc_is_determinate_busy(): void {
		$this->fixture->response_override = Windcave_Conformance_Fixture::response( Windcave_Conformance_Fixture::fixture( 'reco-pc' ) );
		$result = $this->adapter->create_reader_action( $this->row, 'station-1' );
		$this->assertSame( 'wcpos_reader_busy', $result->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $result->get_error_data() );
	}
	public function test_receipt_has_only_last_four_and_sent_currency(): void {
		$ref = $this->start( 'refund_ok' );
		$this->fixture->response_override = Windcave_Conformance_Fixture::response( str_replace( '411111******1111', '4111111111111111', $this->fixture->xml( $ref, 'completed' ) ) );
		$result = $this->adapter->fetch( $ref );
		$this->assertSame( '92.95', $result['amount'] ); $this->assertSame( 'EUR', $result['currency'] );
		$this->assertSame( '1111', $result['receipt']['last4'] );
		$this->assertStringNotContainsString( '4111111111111111', wp_json_encode( $result ) );
	}
	public function test_completed_currency_uses_reply_or_sent_fallback(): void {
		$ref = $this->start( 'refund_ok' );
		$this->assertSame( 'EUR', $this->adapter->fetch( $ref )['currency'] );
		foreach ( array( 'CurrencyInput', 'Cur', 'CurrencyName' ) as $element ) {
			$xml = str_replace( '</Scr>', '<' . $element . '>USD</' . $element . '></Scr>', $this->fixture->xml( $ref, 'completed' ) );
			$this->fixture->response_override = Windcave_Conformance_Fixture::response( $xml );
			$this->assertSame( 'USD', $this->adapter->fetch( $ref )['currency'], $element );
		}
	}
	public function test_fprn_event_identity_dedupes_only_the_same_observation(): void {
		$ref = $this->start();
		$request = $this->fixture->webhook_request( 'completed' );
		$first = $this->adapter->verify_webhook( $request );
		$this->assertSame( $first['patch']['event_id'], $this->adapter->verify_webhook( $request )['patch']['event_id'] );
		// Hold the status id constant: outcome, not an incidental HIT revision, must distinguish these.
		$xml = preg_replace( '#<TxnStatusId>.*?</TxnStatusId>#', '<TxnStatusId>8</TxnStatusId>', $this->fixture->xml( $ref, 'failed' ) );
		$this->fixture->response_override = Windcave_Conformance_Fixture::response( $xml );
		$failed = $this->adapter->verify_webhook( $request );
		$this->assertSame( 'captured', $first['patch']['status'] );
		$this->assertSame( 'failed', $failed['patch']['status'] );
		$this->assertNotSame( $first['patch']['event_id'], $failed['patch']['event_id'] );
		$this->assertSame( $ref . ':completed:8', $first['patch']['event_id'] );
		$this->assertSame( $ref . ':failed:8', $failed['patch']['event_id'] );
	}
	public function test_unknown_fprn_never_calls_hit(): void {
		$request = new \WP_REST_Request( 'GET' ); $request->set_query_params( array( 'txnRef' => 'unknown' ) );
		$this->assertSame( 404, $this->adapter->verify_webhook( $request )->get_error_data()['status'] );
		$this->assertSame( array(), $this->fixture->raw_calls );
	}
	public function test_pending_fprn_is_ledger_pending(): void {
		$this->start();
		$result = $this->adapter->verify_webhook( $this->fixture->webhook_request( 'pending' ) );
		$this->assertSame( $this->row['id'], $result['payment_id'] );
		$this->assertSame( 'pending', $result['patch']['status'] );
	}
	public function test_cancel_complete_is_requested_not_final(): void {
		$ref = $this->start( 'refund_ok' );
		$this->assertSame( 'requested', $this->adapter->cancel( $ref ) );
		$this->assertNotContains( 'UI', array_column( $this->fixture->raw_calls, 'type' ) );
	}
	public function test_cancel_without_cancel_button_is_unsupported(): void {
		$ref = $this->start();
		$this->assertSame( 'wcpos_capture_mode_unsupported', $this->adapter->cancel( $ref )->get_error_code() );
	}
	public function test_replay_only_reissues_purchase_for_recent_pj(): void {
		foreach ( array( 0 => array( 'Purchase', 'Status', 'Purchase' ), 31 => array( 'Purchase', 'Status' ) ) as $age => $types ) {
			$this->row['id'] = wp_generate_uuid4();
			$this->fixture->raw_calls = array();
			$this->fixture->response_override = new \WP_Error( 'http_request_failed', 'Lost before registration' );
			$this->assertWPError( $this->adapter->create_reader_action( $this->row, 'station-1' ) );
			$this->set_dispatch_age( substr( md5( $this->row['id'] ), 0, 16 ), $age );
			$this->fixture->response_override = null;
			$this->assertNotWPError( $this->adapter->create_reader_action( $this->row, 'station-1' ) );
			$this->assertSame( $types, array_column( $this->fixture->raw_calls, 'type' ) );
		}
	}
	public function test_error_recos_do_not_masquerade_as_prompts_or_success(): void {
		$ref = $this->start();
		foreach ( array( 'PD', 'PE', 'PF' ) as $code ) {
			$this->fixture->response_override = Windcave_Conformance_Fixture::response( '<Scr><Complete>0</Complete><ReCo>' . $code . '</ReCo></Scr>' );
			$this->assertTrue( $this->adapter->fetch( $ref )->get_error_data()['indeterminate'] );
		}
		$this->fixture->response_override = Windcave_Conformance_Fixture::response( Windcave_Conformance_Fixture::fixture( 'reco-po' ) );
		$this->assertSame( array( 'status' => 'failed', 'failure_reason' => 'provider_error:PO' ), $this->adapter->fetch( $ref ) );
	}
	public function test_refund_maps_amount_original_reference_and_pending_outcome(): void {
		$ref = $this->start( 'refund_ok' );
		$row = $this->row;
		$row['provider_refs'] = array( 'action' => $ref, 'reader' => 'station-1', 'transaction_id' => 'original-dps' );
		$result = $this->adapter->refund( $row, 1234, '5.00' );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( '0000000100e1a6f9', $result['provider_ref'] );
		$xml = simplexml_load_string( end( $this->fixture->raw_calls )['xml'] );
		$this->assertSame( 'original-dps', (string) $xml->DpsTxnRef );
		$this->assertSame( '5.00', (string) $xml->Amount );
		$this->assertSame( substr( md5( 'refund-1234' ), 0, 16 ), (string) $xml->TxnRef );
		$this->fixture->script( 'refund_pending' );
		$result = $this->adapter->refund( $row, 1235, '5.00' );
		$this->assertSame( array( 'status' => 'pending', 'provider_ref' => substr( md5( 'refund-1235' ), 0, 16 ) ), $result );
		$this->fixture->script( 'refund_failed' );
		$this->assertSame( 'failed', $this->adapter->refund( $row, 1236, '5.00' )['status'] );
	}
	public function test_refund_without_original_reference_never_becomes_unmatched_refund(): void {
		$this->row['provider_refs'] = array( 'reader' => 'station-1' );
		$this->assertWPError( $this->adapter->refund( $this->row, 5678, '5.00' ) );
		$this->assertSame( array(), $this->fixture->raw_calls );
	}
	/** The ENVIRONMENT is pinned per action (a UAT action can never reach production); credentials are read live, never copied into an option. */
	public function test_settings_flip_keeps_original_endpoint_for_all_operations(): void {
		$ref = $this->start( 'prompt' );
		$options = get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings' );
		$options['environment'] = 'production'; $options['hit_key'] = 'different-live-key';
		update_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', $options );
		$this->fixture->raw_calls = array(); // Only the calls made AFTER the flip are under test.
		$prompt = $this->adapter->fetch( $ref )['prompt'];
		$this->adapter->answer( $ref, $prompt['id'], 'B1' );
		$this->adapter->cancel( $ref );
		$row = $this->row; $row['provider_refs'] = array( 'action' => $ref, 'transaction_id' => 'original-dps' );
		$this->adapter->refund( $row, 999, '5.00' );
		$this->assertNotEmpty( $this->fixture->raw_calls );
		foreach ( $this->fixture->raw_calls as $call ) {
			$this->assertSame( Settings::ENDPOINT_UAT, $call['url'], 'An action started on UAT is only ever queried on UAT' );
			$xml = simplexml_load_string( $call['xml'] );
			$this->assertSame( 'different-live-key', (string) $xml['key'], 'Credentials are the live setting; no per-action copy of the key exists' );
		}
	}
	public function test_logging_masks_key_card_number_and_cardholder(): void {
		$messages = array();
		$one_handler = static fn( $handlers ) => array_slice( $handlers, 0, 1 );
		add_filter( 'woocommerce_register_log_handlers', $one_handler, 99 );
		$filter = static function ( $message, $level, $context ) use ( &$messages ) {
			if ( 'windcave-terminal' === ( $context['source'] ?? '' ) ) { $messages[] = $message; }
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $filter, 10, 4 );
		try {
			$this->fixture->response_override = Windcave_Conformance_Fixture::response( '<Scr><Key>fixture-hit-key</Key><CardNumber>4111111111111111</CardNumber><CH>PRIVATE NAME</CH><Rcpt>411111******1111</Rcpt></Scr>' );
			$this->adapter->create_reader_action( $this->row, 'station-1' );
		} finally { remove_filter( 'woocommerce_logger_log_message', $filter, 10 ); remove_filter( 'woocommerce_register_log_handlers', $one_handler, 99 ); }
		$this->assertCount( 2, $messages, implode( '\n', $messages ) );
		$log = implode( '\n', $messages );
		foreach ( array( 'fixture-hit-key', '4111111111111111', 'PRIVATE NAME', '411111******1111' ) as $secret ) { $this->assertStringNotContainsString( $secret, $log ); }
	}

	public function test_historical_refund_requires_a_default_station(): void {
		$this->row['provider_refs'] = array( 'transaction_id' => 'historical-dps' );
		$this->assertWPError( $this->adapter->refund( $this->row, 9876, '5.00' ) );
		$this->assertSame( array(), $this->fixture->raw_calls );
	}

}
