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
			$this->row['id'] = wp_generate_uuid4(); $this->row['created_at_gmt'] = gmdate( 'c', time() - $age );
			$this->fixture->response_override = null; $ref = $this->start();
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
			$this->row['created_at_gmt'] = gmdate( 'c', time() - $age );
			$this->fixture->raw_calls = array();
			$this->fixture->response_override = new \WP_Error( 'http_request_failed', 'Lost before registration' );
			$this->assertWPError( $this->adapter->create_reader_action( $this->row, 'station-1' ) );
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
