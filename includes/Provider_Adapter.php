<?php
/** Windcave HIT boundary; Free and Pro own the payment lifecycle.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

use WCPOS\WooCommercePOSPro\Payments\Server\Abstract_Provider_Adapter;
use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\Payments\Server\Redactor;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitClient;

/** Translate HIT exchanges into the shared provider contract. */
class Provider_Adapter extends Abstract_Provider_Adapter {
	/** Bound synchronous refund reconciliation to 20 seconds before asking for a portal check. */
	private const REFUND_POLL_SECONDS = 20;
	/**
	 * Return the HIT provider family.
	 */
	public function provider(): string {
		return 'windcave';
	}
	/**
	 * Declare no terminal tipping.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		return array( 'capabilities' => array( 'tips' => 'none' ) );
	}
	/**
	 * Return configured Stations without an outbound discovery request.
	 */
	public function list_readers() {
		$s = new Settings();
		return '' === $s->hit_user() || '' === $s->hit_key() ? array() : array_map(
			static fn( $id ) => array(
				'id' => $id,
				'label' => $id,
				'status' => 'online',
			),
			$s->station_ids()
		);
	}
	/**
	 * Durable dispatch context also indexes FPRN hints; no payment lifecycle is stored here.
	 *
	 * @param string $ref HIT action reference.
	 */
	public static function context_key( string $ref ): string {
		return '_wctwc_action_' . md5( $ref );
	}
	/**
	 * Live credentials with the action's own environment pinned over the current setting.
	 *
	 * @param array $context Stored dispatch context.
	 */
	public static function settings_for( array $context ): Settings {
		return new Settings( array_replace( get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array() ), array( 'environment' => $context['environment'] ?? 'uat' ) ) );
	}
	/**
	 * Persist dispatch context, then create or recover the deterministic action.
	 *
	 * @param array  $row Ledger row.
	 * @param string $reader_id Selected Station ID.
	 */
	public function create_reader_action( array $row, string $reader_id ) {
		$ref = substr( md5( $row['id'] ), 0, 16 );
		$key = self::context_key( $ref );
		$context = get_option( $key );
		$replay = false !== $context;
		if ( ! $replay ) {
			$context = $row;
			$context['dispatched_at_gmt'] = gmdate( 'c' );
			$context['station'] = $reader_id;
			// Pin only the ENVIRONMENT per action (an attempt started on UAT is always queried on UAT).
			// Credentials are read live at call time; a copy of the HIT key never lands in an option.
			$context['environment'] = ( new Settings() )->environment();
			if ( ! add_option( $key, $context, '', false ) ) {
				return $this->indeterminate( 'wctwc_context_unsaved', 'Could not record the HIT dispatch context.' );
			}
		}
		$client = new HitClient( self::settings_for( $context ) );
		if ( $replay ) {
			$r = $this->request( $ref, 'status' );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			if ( 'PJ' !== $r->reco() || time() - strtotime( $context['dispatched_at_gmt'] ) >= 30 ) {
				return array(
					'ref' => $ref,
					'expires_at' => null,
				);
			}
		}
		$order = wc_get_order( $context['order_id'] );
		$url = self::settings_for( $context )->fprn_enabled() ? add_query_arg(
			array(
				'provider' => 'windcave',
				'txnRef' => $ref,
			),
			rest_url( 'wcpos/v2/payments/webhook' )
		) : '';
		$r = $client->purchase( $context['station'], $ref, $context['amount'], $context['currency'], 'Order #' . $order->get_order_number(), $url );
		// A replay refusal may concern our first Purchase, which can still collect money.
		if ( $replay && ( is_wp_error( $r ) || ! in_array( $r->reco(), array( '', '00' ), true ) || ( $r->complete() && ! $r->approved() ) ) ) {
			return $this->indeterminate( 'wctwc_hit_indeterminate', __( 'Could not confirm the terminal result. Check Windcave before taking payment again.', 'windcave-terminal-for-woocommerce' ) );
		}
		if ( ! is_wp_error( $r ) && 'PC' === $r->reco() ) {
			return new \WP_Error( 'wcpos_reader_busy', __( 'The terminal is still finishing an earlier transaction. Complete or cancel it on the terminal, then try again.', 'windcave-terminal-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$r = $this->checked( $r );
		return is_wp_error( $r ) ? $r : array(
			'ref' => $ref,
			'expires_at' => null,
		);
	}
	/**
	 * Transport and network ambiguity must not release the pending reservation.
	 *
	 * @param \WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitResponse|\WP_Error $r HIT reply.
	 */
	private function checked( $r ) {
		if ( is_wp_error( $r ) && 'wctwc_hit_invalid_ui' === $r->get_error_code() ) {
			return $r;
		}
		return is_wp_error( $r ) || in_array( $r->reco(), array( 'PD', 'PE', 'PF' ), true ) ? $this->indeterminate( 'wctwc_hit_indeterminate', __( 'Could not confirm the terminal result. Check Windcave before taking payment again.', 'windcave-terminal-for-woocommerce' ) ) : $r;
	}
	/**
	 * Send a HIT operation using the original action settings.
	 *
	 * @param string $ref HIT action reference.
	 * @param string $method HIT operation.
	 * @param string $button Button slot.
	 * @param string $value Button label.
	 */
	private function request( string $ref, string $method, string $button = '', string $value = '' ) {
		$c = get_option( self::context_key( $ref ) );
		if ( ! $c ) {
			return new \WP_Error( 'wctwc_unknown_action', 'Unknown HIT transaction.', array( 'status' => 404 ) );
		}
		$client = new HitClient( self::settings_for( $c ) );
		return $this->checked( 'ui' === $method ? $client->ui( $c['station'], $ref, $button, $value ) : $client->status( $c['station'], $ref ) );
	}
	/**
	 * Fetch the authoritative HIT observation.
	 *
	 * @param string $ref HIT action reference.
	 */
	public function fetch( string $ref ) {
		$r = $this->request( $ref, 'status' );
		return is_wp_error( $r ) ? $r : $this->observation( $ref, $r );
	}
	/**
	 * Both polling and webhook settlement consume this one HIT interpretation.
	 *
	 * @param string                                                      $ref HIT action reference.
	 * @param \WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitResponse $r HIT reply.
	 */
	private function observation( string $ref, $r ): array {
		$c = get_option( self::context_key( $ref ) );
		$reco = $r->reco();
		if ( 'PJ' === $reco ) {
			return time() - strtotime( $c['dispatched_at_gmt'] ) < 30 ? array( 'status' => 'pending' ) : array(
				'status' => 'failed',
				'failure_reason' => 'not_registered',
			);
		}
		if ( 'PC' === $reco ) {
			return array( 'status' => 'pending' );
		}
		if ( $r->complete() ) {
			if ( ! $r->approved() ) {
				// No known HIT cancel codes: a declined card whose DL1 says CARD CANCELLED is still a decline.
				$cancelled = false !== stripos( $r->display_line_1(), 'cancel' ) && in_array( $reco, array( '', '00' ), true ) && in_array( $r->response_code(), array( '', '00' ), true );
				return array(
					'status' => $cancelled ? 'cancelled' : 'failed',
					'failure_reason' => $reco . ':' . $r->display_line_1(),
				);
			}
			// HIT echoes the currency it charged when it reports one; the sent currency is the fallback, never the truth.
			return array(
				'status' => 'completed',
				'amount' => number_format( $r->amount_cents() / 100, 2, '.', '' ),
				'currency' => $r->currency() ? $r->currency() : $c['currency'],
				'provider_refs' => array( 'transaction_id' => $r->dps_txn_ref() ),
				'receipt' => array(
					'card_brand' => $r->card_type(),
					'auth_code' => $r->auth_code(),
					'last4' => substr( $r->card_number(), -4 ),
					'text' => Redactor::message( $r->receipt() ),
					'width' => $r->receipt_width(),
				),
			);
		}
		if ( '' !== $reco && '00' !== $reco ) {
			return array(
				'status' => 'failed',
				'failure_reason' => 'provider_error:' . $reco,
			);
		}
		$buttons = array();
		$names = array();
		foreach ( array( 'B1', 'B2' ) as $id ) {
			$button = $r->button( $id );
			if ( $button['enabled'] ) {
				$names[] = $id . ':' . $button['label'];
				$buttons[] = array(
					'id' => $id,
					'label' => $button['label'],
				);
			}
		}
		$lines = array_values( array_filter( array( $r->display_line_1(), $r->display_line_2() ), static fn( $line ) => '' !== $line ) );
		$id = sha1( $r->display_line_1() . "\n" . $r->display_line_2() . "\n" . implode( "\n", $names ) . "\n" . $r->txn_status_id() );
		return $lines || $buttons ? array(
			'status' => 'in_progress',
			'prompt' => array(
				'id' => $id,
				'lines' => $lines,
				'buttons' => $buttons,
			),
		) : array( 'status' => 'pending' );
	}
	/**
	 * Recheck the prompt and enabled button before answering.
	 *
	 * @param string $ref HIT action reference.
	 * @param string $prompt_id Prompt revision.
	 * @param string $button_id Enabled button slot.
	 */
	public function answer( string $ref, string $prompt_id, string $button_id ) {
		$observation = $this->fetch( $ref );
		if ( is_wp_error( $observation ) ) {
			return $observation;
		}
		foreach ( $observation['prompt']['buttons'] ?? array() as $button ) {
			if ( $prompt_id === $observation['prompt']['id'] && $button_id === $button['id'] ) {
				$value = strtoupper( $button['label'] );
				// Preserve protocol labels; custom labels use the 0.x panel's slot mapping.
				$value = in_array( $value, array( 'YES', 'NO', 'CANCEL' ), true ) ? $value : ( 'B1' === $button_id ? 'YES' : 'NO' );
				$r = $this->request( $ref, 'ui', $button_id, $value );
				return is_wp_error( $r ) ? $r : $this->fetch( $ref );
			}
		}
		return new \WP_Error(
			'wcpos_prompt_stale',
			__( 'The terminal has moved on from that question.', 'windcave-terminal-for-woocommerce' ),
			array(
				'status' => 409,
				'observation' => $observation,
			)
		);
	}
	/**
	 * Request terminal cancellation without inventing finality.
	 *
	 * @param string $ref HIT action reference.
	 */
	public function cancel( string $ref ) {
		$r = $this->request( $ref, 'status' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $r->complete() ) {
			return 'requested';
		}
		foreach ( array( 'B1', 'B2' ) as $id ) {
			$button = $r->button( $id );
			if ( $button['enabled'] && 'CANCEL' === strtoupper( $button['label'] ) ) {
				$r = $this->request( $ref, 'ui', $id, 'CANCEL' );
				return is_wp_error( $r ) ? $r : 'requested';
			}
		}
		return new \WP_Error( 'wcpos_capture_mode_unsupported', __( 'The terminal is not offering cancel right now. Cancel on the terminal.', 'windcave-terminal-for-woocommerce' ), array( 'status' => 501 ) );
	}
	/**
	 * Send a matched refund with its own deterministic TxnRef.
	 *
	 * @param array       $row Ledger row.
	 * @param int         $refund_id WooCommerce refund ID.
	 * @param string|null $amount Decimal refund amount.
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		if ( empty( $row['provider_refs']['transaction_id'] ) ) {
			return new \WP_Error( 'wctwc_refund_reference_missing', __( 'The original Windcave transaction reference is required for a refund.', 'windcave-terminal-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$c = get_option( self::context_key( $row['provider_refs']['action'] ?? '' ), array() );
		$s = $c ? self::settings_for( $c ) : new Settings();
		$station = $row['provider_refs']['reader'] ?? $c['station'] ?? Reader_Curation::settings( Settings::GATEWAY_ID )['default_reader'];
		if ( '' === $station ) {
			return new \WP_Error( 'wcpos_reader_required', __( 'Configure a default Station in POS settings before refunding this sale.', 'windcave-terminal-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$ref = substr( md5( 'refund-' . $refund_id ), 0, 16 );
		$client = new HitClient( $s );
		$r = $this->checked( $client->refund( $station, $ref, $amount, $row['currency'], $row['provider_refs']['transaction_id'], 'Refund #' . $refund_id ) );
		// HIT Refund is a terminal transaction like Purchase; this polling behavior is unverified live.
		$deadline = microtime( true ) + self::REFUND_POLL_SECONDS;
		while ( ( is_wp_error( $r ) || ! $r->complete() ) && microtime( true ) < $deadline ) {
			usleep( (int) ( min( 2, max( 0, $deadline - microtime( true ) ) ) * 1000000 ) );
			$remaining = $deadline - microtime( true );
			if ( $remaining <= 0 ) {
				break;
			}
			$r = $this->checked( $client->status( $station, $ref, $remaining ) );
		}
		if ( ! is_wp_error( $r ) && $r->approved() && ( $r->amount_cents() !== (int) round( (float) $amount * 100 ) || ( '' !== $r->currency() && $row['currency'] !== $r->currency() ) ) ) {
			wc_get_logger()->error( 'Windcave refund ' . $refund_id . ' mismatch: requested ' . $amount . ' ' . $row['currency'] . ', approved ' . number_format( $r->amount_cents() / 100, 2, '.', '' ) . ' ' . ( $r->currency() ? $r->currency() : '(currency not reported)' ) . '. Check the Windcave portal.', array( 'source' => 'windcave-terminal' ) );
			return array(
				'status' => 'failed',
				'provider_ref' => $r->dps_txn_ref(),
			);
		}
		if ( is_wp_error( $r ) || ! $r->complete() ) {
			wc_get_logger()->warning( 'Windcave refund ' . $refund_id . ' is still pending (TxnRef ' . $ref . '). Check the Windcave portal before any further refund.', array( 'source' => 'windcave-terminal' ) );
		}
		return array(
			'status' => is_wp_error( $r ) || ! $r->complete() ? 'pending' : ( $r->approved() ? 'succeeded' : 'failed' ),
			'provider_ref' => is_wp_error( $r ) || ! $r->complete() ? $ref : $r->dps_txn_ref(),
		);
	}
	/**
	 * Resolve the unsigned hint before querying HIT for a ledger patch.
	 *
	 * @param \WP_REST_Request $request FPRN request.
	 */
	public function verify_webhook( \WP_REST_Request $request ) {
		$ref = $request->get_query_params()['txnRef'] ?? '';
		$c = is_string( $ref ) ? get_option( self::context_key( $ref ), array() ) : array();
		$id = is_string( $ref ) ? ( wcpos_pro_payment_id_for_action( 'windcave', $ref ) ?? $c['id'] ?? null ) : null;
		if ( ! $id ) {
			return new \WP_Error( 'wctwc_unknown_action', 'Unknown HIT transaction.', array( 'status' => 404 ) );
		}
		$r = $this->request( $ref, 'status' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$patch = $this->observation( $ref, $r );
		$event_id = $ref . ':' . $patch['status'] . ':' . ( $r->txn_status_id() ? $r->txn_status_id() : (int) $r->complete() );
		$patch['status'] = array(
			'completed' => ! empty( $patch['authorized'] ) ? 'authorized' : 'captured',
			'failed' => 'failed',
			'expired' => 'failed',
			'cancelled' => 'voided',
			'pending' => 'pending',
			'in_progress' => 'pending',
		)[ $patch['status'] ];
		$patch = array_intersect_key( $patch, array_flip( array( 'status', 'amount', 'currency', 'provider_refs', 'receipt' ) ) );
		$patch['event_id'] = $event_id;
		// Records receipt of a verified hint, not settlement; Free/Pro exposes no post-settlement hook.
		update_option( 'wctwc_last_fprn', gmdate( 'c' ), false );
		return array(
			'payment_id' => $id,
			'patch' => $patch,
		);
	}
	/**
	 * Return non-secret health facts.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function diagnostics( \WC_Payment_Gateway $gateway ): array {
		$s = new Settings();
		return array(
			'log_source' => 'windcave-terminal',
			'environment' => $s->environment(),
			'station_count' => count( $s->station_ids() ),
			'fprn' => $s->fprn_enabled(),
			'last_fprn' => get_option( 'wctwc_last_fprn', null ),
		);
	}
}
