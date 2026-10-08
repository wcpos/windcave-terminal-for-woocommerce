<?php
namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Conformance;

use WCPOS\WooCommercePOS\WindcaveTerminal\Gateway;
use WCPOS\WooCommercePOS\WindcaveTerminal\Provider_Adapter;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Payments\Server\Server_Providers;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\API\V2\Payments_Webhook_Controller;

final class Windcave_Conformance_Fixture implements Conformance_Fixture {
	private $registry_property;
	private $old_registry;
	private $old_gateways;
	private $old_options;
	private $old_currency;
	private $calls = array();
	private $aliases = array();
	public $orders = array();
	public $current;
	public $raw_calls = array();
	public $response_override;
	private $scenario = 'create_ok';
	private $states = array( 'pending' );
	private $lost = false;

	public function register_gateway( array $gateways ): array { return Gateway::register_gateway( $gateways ); }
	public function gateway_id(): string { return Settings::GATEWAY_ID; }
	public function install(): void {
		$this->old_options = get_option( 'woocommerce_' . $this->gateway_id() . '_settings', array() );
		$this->old_currency = get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_' . $this->gateway_id() . '_settings', array( 'enabled' => 'yes', 'environment' => 'uat', 'hit_user' => 'fixture-user', 'hit_key' => 'fixture-hit-key', 'stations' => "station-1\nstation-2", 'fprn_enabled' => 'yes' ) );
		$this->registry_property = new \ReflectionProperty( Server_Providers::class, 'instance' );
		$this->registry_property->setAccessible( true );
		$this->old_registry = $this->registry_property->getValue();
		$this->registry_property->setValue( null, null );
		Recording_Provider_Adapter::$fixture = $this;
		wcpos_pro_register_server_provider( $this->gateway_id(), Recording_Provider_Adapter::class );
		$this->old_gateways = WC()->payment_gateways;
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		WC()->payment_gateways = new \WC_Payment_Gateways();
		Reader_Curation::forget( $this->gateway_id() );
		delete_option( 'wcpos_pro_readers_lkg_' . $this->gateway_id() );
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
		add_filter( 'woocommerce_pos_payment_gateways_settings', array( $this, 'refund_default' ), 20 );
	}
	public function uninstall(): void {
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		remove_filter( 'pre_http_request', array( $this, 'http' ), 10 );
		remove_filter( 'woocommerce_pos_payment_gateways_settings', array( $this, 'refund_default' ), 20 );
		Reader_Curation::forget( $this->gateway_id() );
		delete_option( 'wcpos_pro_readers_lkg_' . $this->gateway_id() );
		$this->registry_property->setValue( null, $this->old_registry );
		Recording_Provider_Adapter::$fixture = null;
		WC()->payment_gateways = $this->old_gateways;
		update_option( 'woocommerce_' . $this->gateway_id() . '_settings', $this->old_options );
		update_option( 'woocommerce_currency', $this->old_currency );
	}
	public function refund_default( array $settings ): array {
		if ( 0 === strpos( $this->scenario, 'refund_' ) ) {
			$settings['gateways'][ $this->gateway_id() ]['default_reader'] = 'station-1';
		}
		return $settings;
	}
	public function supports( string $capability ): bool {
		return in_array( $capability, array( 'cancel', 'cancel_unsupported', 'cancel_requested_then_completed', 'prompt', 'webhook', 'refund', 'partial_refund', 'test_live_isolation', 'legacy_adoption', 'historical_webview_refund' ), true );
	}
	public function script( string $scenario ): void {
		$scripts = array(
			'replay_busy' => array( 'pending' ), 'refund_delayed' => array( 'completed' ), 'refund_delayed_failed' => array( 'completed' ),
			'create_ok' => array( 'pending' ), 'create_indeterminate' => array( 'pending' ),
			'pending_then_completed' => array( 'pending', 'completed' ), 'declined' => array( 'failed' ),
			'cancel_requested_then_cancelled' => array( 'cancel' ), 'cancel_requested_then_completed' => array( 'cancel' ),
			'cancel_unsupported' => array( 'pending' ), 'webhook_replay' => array( 'pending' ), 'webhook_out_of_order' => array( 'pending' ),
			'amount_mismatch' => array( 'completed' ), 'currency_mismatch' => array( 'completed' ),
			'refund_ok' => array( 'completed' ), 'refund_pending' => array( 'completed' ), 'refund_failed' => array( 'completed' ),
			'test_live_isolation' => array( 'pending' ), 'prompt' => array( 'prompt' ),
		);
		if ( ! isset( $scripts[ $scenario ] ) ) { throw new \OutOfBoundsException( $scenario ); }
		$this->scenario = $scenario;
		$this->states = $scripts[ $scenario ];
		$this->lost = false;
	}
	public static function fixture( string $name ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/hit/' . $name . '.xml' );
	}
	public static function response( string $body ): array {
		return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array() );
	}
	public function http( $pre, array $args, string $url ) {
		if ( ! in_array( $url, array( Settings::ENDPOINT_UAT, Settings::ENDPOINT_PRODUCTION ), true ) ) { return $pre; }
		$xml = simplexml_load_string( $args['body'] );
		$ref = (string) $xml->TxnRef;
		$type = (string) $xml->TxnType;
		$mode = Settings::ENDPOINT_UAT === $url ? 'test' : 'live';
		$this->raw_calls[] = array( 'type' => $type, 'ref' => $ref, 'xml' => $args['body'], 'url' => $url );
		if ( null !== $this->response_override ) { return is_callable( $this->response_override ) ? ( $this->response_override )( $xml ) : $this->response_override; }
		if ( 'Purchase' === $type ) {
			if ( 'replay_busy' === $this->scenario && isset( $this->orders[ $ref ] ) ) {
				$this->orders[ $ref ]['states'] = array( 'completed' );
				return self::response( self::fixture( 'reco-pc' ) );
			}
			if ( isset( $this->orders[ $ref ] ) ) { throw new \LogicException( 'Duplicate Purchase dispatched for ' . $ref ); }
			$this->current = $ref;
			$this->orders[ $ref ] = array( 'mode' => $mode, 'amount' => (string) $xml->Amount, 'currency' => (string) $xml->Cur, 'states' => $this->states );
			if ( 'test_live_isolation' === $this->scenario ) {
				$options = get_option( 'woocommerce_' . $this->gateway_id() . '_settings' );
				$options['environment'] = 'production';
				update_option( 'woocommerce_' . $this->gateway_id() . '_settings', $options );
			}
			if ( in_array( $this->scenario, array( 'create_indeterminate', 'replay_busy' ), true ) && ! $this->lost ) {
				$this->lost = true;
				return new \WP_Error( 'http_request_failed', 'Response lost after acceptance' );
			}
			return self::response( '<Scr><TxnRef>' . $ref . '</TxnRef><Complete>0</Complete><ReCo>00</ReCo></Scr>' );
		}
		if ( 'Refund' === $type ) {
			if ( '' === (string) $xml->Station || '' === (string) $xml->DpsTxnRef ) { throw new \LogicException( 'Matched Refund requires Station and DpsTxnRef' ); }
			if ( in_array( $this->scenario, array( 'refund_pending', 'refund_delayed', 'refund_delayed_failed' ), true ) ) {
				$states = 'refund_pending' === $this->scenario ? array( 'pending' ) : array( 'pending', 'refund_delayed' === $this->scenario ? 'completed' : 'failed' );
				$this->orders[ $ref ] = array( 'mode' => $mode, 'amount' => (string) $xml->Amount, 'states' => $states );
				return 'refund_pending' === $this->scenario ? new \WP_Error( 'http_request_failed', 'Refund response lost' ) : self::response( $this->xml( $ref, 'pending' ) );
			}
			return self::response( $this->xml( $ref, 'refund_failed' === $this->scenario ? 'failed' : 'completed', (string) $xml->Amount ) );
		}
		if ( ! isset( $this->orders[ $ref ] ) ) { return self::response( str_replace( '<TxnRef>128</TxnRef>', '<TxnRef>' . $ref . '</TxnRef>', self::fixture( 'reco-pj' ) ) ); }
		if ( 'replay_busy' === $this->scenario && array( 'pending' ) === $this->orders[ $ref ]['states'] ) { return self::response( self::fixture( 'reco-pj' ) ); }
		$order = &$this->orders[ $ref ];
		if ( $mode !== $order['mode'] ) { throw new \LogicException( 'Action queried in wrong environment' ); }
		if ( 'UI' === $type ) {
			$order['states'] = array( 'CANCEL' === (string) $xml->Val && 'cancel_requested_then_completed' !== $this->scenario ? 'cancelled' : 'completed' );
			return self::response( '<Scr><TxnType>UI</TxnType><TxnRef>' . $ref . '</TxnRef><Success>1</Success><RC></RC></Scr>' );
		}
		$state = count( $order['states'] ) > 1 ? array_shift( $order['states'] ) : $order['states'][0];
		return self::response( $this->xml( $ref, $state, 'amount_mismatch' === $this->scenario ? '1.00' : $order['amount'] ) );
	}
	public function xml( string $ref, string $state, string $amount = '92.95' ): string {
		$files = array( 'pending' => 'status-in-progress', 'prompt' => 'status-signature', 'cancel' => 'status-cancel-button', 'completed' => 'status-approved', 'failed' => 'status-declined', 'cancelled' => 'status-declined' );
		$xml = self::fixture( $files[ $state ] );
		if ( 'currency_mismatch' === $this->scenario ) { $xml = str_replace( '</Scr>', '<Cur>USD</Cur></Scr>', $xml ); }
		$xml = preg_replace( '#<TxnRef>.*?</TxnRef>#', '<TxnRef>' . $ref . '</TxnRef>', $xml );
		$xml = preg_replace( '#<AmtA>.*?</AmtA>#', '<AmtA>' . (int) round( (float) $amount * 100 ) . '</AmtA>', $xml );
		if ( 'cancelled' === $state ) { $xml = str_replace( array( 'DECLINED', '<RC>51</RC>' ), array( 'CANCELLED', '<RC></RC>' ), $xml ); }
		return $xml;
	}
	public function webhook_request( string $event ): \WP_REST_Request {
		if ( 'tampered' !== $event ) { $this->orders[ $this->current ]['states'] = array( $event ); }
		$request = new \WP_REST_Request( 'GET', Payments_Webhook_Controller::ROUTE );
		$request->set_body( '' );
		$request->set_query_params( array( 'provider' => 'windcave', 'txnRef' => 'tampered' === $event ? 'unknown-ref' : $this->current ) );
		return $request;
	}
	/** Record entry before delegating; the transport only records raw wire calls. */
	public function record_entry( string $op, string $ref, array $row, string $reader, string $details ): int {
		$context = get_option( Provider_Adapter::context_key( $ref ), array() );
		$settings = $context ? Provider_Adapter::settings_for( $context ) : new Settings();
		$row = $context + $row;
		$reader = $context['station'] ?? $reader;
		$summary = 'action=' . $this->alias( $ref ) . ' amount=' . ( $row['amount'] ?? 'unknown' ) . ' currency=' . ( $row['currency'] ?? 'unknown' ) . ' reader=' . $reader;
		$summary .= ' mode=' . ( 'uat' === $settings->environment() ? 'test' : 'live' ) . ' environment=' . $settings->environment();
		$this->calls[] = array( 'op' => $op, 'request' => $summary . $details );
		return count( $this->calls ) - 1;
	}
	/** Enrich the entry with actual dispatch details, never a scripted expectation. */
	public function record_result( int $index, int $wire_start, $result ): void {
		$wire = array_slice( $this->raw_calls, $wire_start );
		$op = $this->calls[ $index ]['op'];
		if ( 'create' === $op ) {
			$types = array_column( $wire, 'type' );
			$this->calls[ $index ]['request'] .= ' sent=' . ( in_array( 'Purchase', $types, true ) ? 'purchase' : ( in_array( 'Status', $types, true ) ? 'status-recovery' : 'none' ) );
		}
		foreach ( $wire as $call ) {
			$xml = simplexml_load_string( $call['xml'] );
			if ( 'UI' === $call['type'] ) {
				$this->calls[ $index ]['request'] .= ' button=' . $xml->Name . ' value=' . $xml->Val;
			}
			if ( 'Refund' === $call['type'] ) {
				$this->calls[ $index ]['request'] .= ' transaction_id=' . $this->alias( (string) $xml->DpsTxnRef );
			}
		}
		if ( 'webhook' === $op ) {
			$this->calls[ $index ]['request'] .= ' outcome=' . ( is_wp_error( $result ) ? $result->get_error_code() : $result['patch']['status'] );
		}
	}
	private function alias( string $ref ): string {
		if ( ! isset( $this->aliases[ $ref ] ) ) { $this->aliases[ $ref ] = 'action_' . ( count( $this->aliases ) + 1 ); }
		return $this->aliases[ $ref ];
	}
	public function transcript(): array { return $this->calls; }
	public function reset_transcript(): void { $this->calls = array(); $this->aliases = array(); }
	public function transcript_dir(): ?string { return __DIR__ . '/transcripts'; }
}

/** Spy on the real adapter boundary, including creates recovered without a Purchase. */
class Recording_Provider_Adapter extends Provider_Adapter {
	public static $fixture;
	private function record( string $op, string $ref, callable $delegate, array $row = array(), string $reader = '', string $details = '' ) {
		$index = self::$fixture->record_entry( $op, $ref, $row, $reader, $details );
		$wire_start = count( self::$fixture->raw_calls );
		$result = $delegate();
		self::$fixture->record_result( $index, $wire_start, $result );
		return $result;
	}
	public function create_reader_action( array $row, string $reader_id ) {
		return $this->record( 'create', substr( md5( $row['id'] ), 0, 16 ), fn() => parent::create_reader_action( $row, $reader_id ), $row, $reader_id );
	}
	public function fetch( string $ref ) {
		return $this->record( 'fetch', $ref, fn() => parent::fetch( $ref ) );
	}
	public function cancel( string $ref ) {
		return $this->record( 'cancel', $ref, fn() => parent::cancel( $ref ) );
	}
	public function answer( string $ref, string $prompt_id, string $button_id ) {
		return $this->record( 'answer', $ref, fn() => parent::answer( $ref, $prompt_id, $button_id ), array(), '', ' selected_button=' . $button_id );
	}
	public function refund( array $row, int $refund_id, string $amount ) {
		$context = get_option( self::context_key( $row['provider_refs']['action'] ?? '' ), array() );
		$reader = $row['provider_refs']['reader'] ?? $context['station'] ?? Reader_Curation::settings( Settings::GATEWAY_ID )['default_reader'];
		return $this->record( 'refund', substr( md5( 'refund-' . $refund_id ), 0, 16 ), fn() => parent::refund( $row, $refund_id, $amount ), array_replace( $context, $row, array( 'amount' => $amount ) ), $reader );
	}
	public function verify_webhook( \WP_REST_Request $request ) {
		return $this->record( 'webhook', $request->get_query_params()['txnRef'] ?? '', fn() => parent::verify_webhook( $request ) );
	}
}
