<?php
/**
 * Turn Windcave HIT responses into order payment state.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Services;

use WCPOS\WooCommercePOS\WindcaveTerminal\FprnHandler;
use WCPOS\WooCommercePOS\WindcaveTerminal\Logger;
use WCPOS\WooCommercePOS\WindcaveTerminal\OrderCompletion;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentLock;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;

/**
 * Start, query and resolve recorded HIT payment attempts.
 */
class HitPaymentService {

	/**
	 * Seconds after an attempt starts during which ReCo PJ ("TxnRef not matched")
	 * is read as "Windcave has not registered the Purchase yet" rather than as a
	 * decline: a Status can overtake a slow or timed-out Purchase request.
	 */
	private const PJ_GRACE_SECONDS = 30;

	/**
	 * HIT transport.
	 *
	 * @var HitClient
	 */
	private $client;

	/**
	 * Gateway settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Set the transport and settings.
	 *
	 * @param HitClient $client   HIT client.
	 * @param Settings  $settings Gateway settings.
	 */
	public function __construct( HitClient $client, Settings $settings ) {
		$this->client   = $client;
		$this->settings = $settings;
	}

	/**
	 * Start a purchase or resume the current pending attempt.
	 *
	 * @param \WC_Order $order   Order to pay.
	 * @param string    $station Requested Station ID.
	 * @return array Payment result.
	 */
	public function start( $order, string $station = '' ): array {
		if ( $this->settings->lock_station() || '' === $station ) {
			$station = $this->settings->default_station();
		}
		if ( '' === $station || ! in_array( $station, $this->settings->station_ids(), true ) ) {
			return $this->result( 'error', '', null, __( 'Choose a Windcave terminal (Station ID).', 'windcave-terminal-for-woocommerce' ) );
		}
		if ( '' === $this->settings->hit_user() || '' === $this->settings->hit_key() ) {
			return $this->result( 'error', '', null, __( 'Windcave HIT credentials are not configured.', 'windcave-terminal-for-woocommerce' ) );
		}
		return PaymentLock::with_lock(
			(int) $order->get_id(),
			'create_payment',
			function () use ( $order, $station ) {
				$current = PaymentAttempt::current( $order );
				if ( $order->is_paid() ) {
					return $this->result( 'already_paid', $current['txn_ref'] ?? '' );
				}
				if ( null !== $current && PaymentAttempt::is_pending( $current['status'] ) ) {
					$result = $this->check( $order, $current['txn_ref'], $current['station'], 'start_reuse' );
					if ( 'pending' === $result['status'] ) {
						$result['message'] = __( 'Resuming the payment already in progress on the terminal.', 'windcave-terminal-for-woocommerce' );
						return $result;
					}
					if ( in_array( $result['status'], array( 'paid', 'verification_failed', 'conflict' ), true ) ) {
						return $result;
					}
				}
				$txn_ref  = PaymentAttempt::new_txn_ref( (int) $order->get_id() );
				$amount   = number_format( (float) $order->get_total(), 2, '.', '' );
				$currency = strtoupper( $order->get_currency() );
				PaymentAttempt::record_new( $order, $txn_ref, $station, $amount, $currency, $this->settings->environment() );
				$notify_url = $this->settings->fprn_enabled() ? FprnHandler::url( $order ) : '';
				$r          = $this->client->purchase( $station, $txn_ref, $amount, $currency, 'Order #' . $order->get_order_number(), $notify_url );
				if ( $r instanceof \WP_Error ) {
					Logger::log( 'HIT Purchase transport error.', array(), 'warning' );
					return $this->result( 'pending', $txn_ref, null, __( 'Could not reach Windcave. Checking the terminal status…', 'windcave-terminal-for-woocommerce' ) );
				}
				Logger::log( 'HIT Purchase response.', $r->to_array(), 'info' );
				if ( $r->is_existing_txn_in_progress() ) {
					PaymentAttempt::update( $order, $txn_ref, 'declined' );
					$order->add_order_note( "Windcave Terminal: TxnRef {$txn_ref} not started, the terminal is still finishing an earlier transaction (PC)." );
					return $this->result( 'station_busy', $txn_ref, $r, __( 'The terminal is still finishing an earlier transaction. Complete or cancel it on the terminal, then try again.', 'windcave-terminal-for-woocommerce' ) );
				}
				return $this->apply( $order, $txn_ref, $r, 'start' );
			},
			60
		);
	}

	/**
	 * Query the current payment attempt.
	 *
	 * @param \WC_Order $order Order to query.
	 * @return array Payment result.
	 */
	public function poll( $order ): array {
		$current = PaymentAttempt::current( $order );
		if ( null === $current ) {
			return $this->result( 'idle' );
		}
		if ( 'approved' === $current['status'] ) {
			return $this->result( $order->is_paid() ? 'paid' : 'verification_failed', $current['txn_ref'] );
		}
		if ( in_array( $current['status'], array( 'declined', 'abandoned' ), true ) ) {
			return $this->result( $current['status'], $current['txn_ref'] );
		}
		return $this->check( $order, $current['txn_ref'], $current['station'], 'poll' );
	}

	/**
	 * Answer a YES/NO prompt and query the resulting status.
	 *
	 * @param \WC_Order $order  Order being paid.
	 * @param string    $button Button name.
	 * @param string    $value  YES or NO.
	 * @return array Payment result.
	 */
	public function answer( $order, string $button, string $value ): array {
		$current = PaymentAttempt::current( $order );
		if ( ! in_array( $button, array( 'B1', 'B2' ), true ) || ! in_array( $value, array( 'YES', 'NO' ), true ) ) {
			return $this->result( 'error', $current['txn_ref'] ?? '' );
		}
		if ( null === $current || ! PaymentAttempt::is_pending( $current['status'] ) ) {
			return $this->result( 'idle', $current['txn_ref'] ?? '' );
		}
		$r = $this->client->ui( $current['station'], $current['txn_ref'], $button, $value );
		if ( $r instanceof \WP_Error ) {
			Logger::log( 'HIT UI answer transport error.', array(), 'warning' );
			return $this->result( 'pending', $current['txn_ref'], null, __( 'Could not send the answer to the terminal. Try again.', 'windcave-terminal-for-woocommerce' ) );
		}
		Logger::log( 'HIT UI answer response.', $r->to_array(), 'info' );
		return $this->check( $order, $current['txn_ref'], $current['station'], 'answer' );
	}

	/**
	 * Use the terminal's enabled CANCEL button when available.
	 *
	 * @param \WC_Order $order Order being paid.
	 * @return array Payment result.
	 */
	public function cancel( $order ): array {
		$current = PaymentAttempt::current( $order );
		if ( null === $current || ! PaymentAttempt::is_pending( $current['status'] ) ) {
			return $this->poll( $order );
		}
		$txn_ref = $current['txn_ref'];
		$r       = $this->client->status( $current['station'], $txn_ref );
		if ( $r instanceof \WP_Error ) {
			Logger::log( 'HIT Status transport error (cancel).', array(), 'warning' );
			PaymentAttempt::abandon_current( $order );
			$order->add_order_note( 'Windcave Terminal: terminal did not respond to cancel; attempt set aside for automatic follow-up.' );
			return $this->result( 'abandoned', $txn_ref, null, __( 'The terminal did not respond, so the payment was set aside. Start a new payment or choose another method.', 'windcave-terminal-for-woocommerce' ) );
		}
		Logger::log( 'HIT Status response (cancel).', $r->to_array(), 'info' );
		if ( $r->complete() ) {
			return $this->apply( $order, $txn_ref, $r, 'cancel' );
		}
		foreach ( array( 'B1', 'B2' ) as $name ) {
			$button = $r->button( $name );
			if ( $button['enabled'] && 'CANCEL' === strtoupper( trim( $button['label'] ) ) ) {
				$reply = $this->client->ui( $current['station'], $txn_ref, $name, 'CANCEL' );
				if ( $reply instanceof \WP_Error ) {
					Logger::log( 'HIT UI cancel transport error.', array(), 'warning' );
				} else {
					Logger::log( 'HIT UI cancel response.', $reply->to_array(), 'info' );
				}
				return $this->check( $order, $txn_ref, $current['station'], 'cancel' );
			}
		}
		$result            = $this->apply( $order, $txn_ref, $r, 'cancel' );
		$result['message'] = __( 'The terminal is not offering cancel right now. Cancel on the terminal, or set the payment aside.', 'windcave-terminal-for-woocommerce' );
		return $result;
	}

	/**
	 * Set aside the current pending attempt for follow-up.
	 *
	 * @param \WC_Order $order Order being paid.
	 * @return array Payment result.
	 */
	public function abandon( $order ): array {
		$current = PaymentAttempt::current( $order );
		if ( null === $current || ! PaymentAttempt::is_pending( $current['status'] ) ) {
			return $this->poll( $order );
		}
		$txn_ref = $current['txn_ref'];
		PaymentAttempt::abandon_current( $order );
		$order->add_order_note( "Windcave Terminal: TxnRef {$txn_ref} set aside by the cashier; automatic follow-up will check its result." );
		return $this->result( 'abandoned', $txn_ref );
	}

	/**
	 * Resolve a transaction recorded on this order, including abandoned ones.
	 *
	 * @param \WC_Order $order   Order to resolve.
	 * @param string    $txn_ref Recorded transaction reference.
	 * @param string    $source  Caller identifying the status check.
	 * @return array Payment result.
	 */
	public function resolve_txn( $order, string $txn_ref, string $source ): array {
		$attempt = PaymentAttempt::find( $order, $txn_ref );
		if ( null === $attempt ) {
			return $this->result( 'error', $txn_ref, null, __( 'Unknown TxnRef for this order.', 'windcave-terminal-for-woocommerce' ) );
		}
		return $this->check( $order, $txn_ref, $attempt['station'], $source );
	}

	/**
	 * Query Status without turning transport errors into declines.
	 *
	 * @param \WC_Order $order   Order to query.
	 * @param string    $txn_ref Transaction reference.
	 * @param string    $station Station ID.
	 * @param string    $source  Caller identifying the status check.
	 * @return array Payment result.
	 */
	private function check( $order, string $txn_ref, string $station, string $source ): array {
		$r = $this->client->status( $station, $txn_ref );
		if ( $r instanceof \WP_Error ) {
			Logger::log( 'HIT Status transport error (' . $source . ').', array(), 'warning' );
			return $this->result( 'pending', $txn_ref, null, __( 'Waiting for Windcave…', 'windcave-terminal-for-woocommerce' ) );
		}
		Logger::log( 'HIT Status response (' . $source . ').', $r->to_array(), 'info' );
		return $this->apply( $order, $txn_ref, $r, $source );
	}

	/**
	 * Apply a HIT response, verifying approval before completing the order.
	 *
	 * @param \WC_Order   $order   Order to update.
	 * @param string      $txn_ref Transaction reference being resolved.
	 * @param HitResponse $r       HIT response.
	 * @param string      $source  Caller identifying the response.
	 * @return array Payment result.
	 */
	private function apply( $order, string $txn_ref, HitResponse $r, string $source ): array {
		if ( 'PJ' === $r->reco() ) {
			$attempt    = PaymentAttempt::find( $order, $txn_ref );
			$created_at = $attempt ? strtotime( $attempt['created_at'] ?? '' ) : false;
			if ( false !== $created_at && time() - $created_at < self::PJ_GRACE_SECONDS ) {
				return $this->result( 'pending', $txn_ref, $r, __( 'Waiting for Windcave to register the transaction…', 'windcave-terminal-for-woocommerce' ) );
			}
			PaymentAttempt::update( $order, $txn_ref, 'declined' );
			$order->add_order_note( "Windcave Terminal: Windcave has no record of TxnRef {$txn_ref} (PJ); marked declined." );
			return $this->result( 'declined', $txn_ref, $r, __( 'Windcave has no record of this transaction. Start the payment again.', 'windcave-terminal-for-woocommerce' ) );
		}
		if ( ! $r->complete() ) {
			return $this->result( 'pending', $txn_ref, $r );
		}
		$attempt = PaymentAttempt::find( $order, $txn_ref );
		if ( empty( $attempt['receipt'] ) ) {
			PaymentAttempt::store_receipt( $order, $txn_ref, $r->receipt(), $r->receipt_width() );
		}
		if ( $r->approved() ) {
			$reasons = array();
			if ( null === $attempt ) {
				$reasons[] = 'TxnRef not recorded on this order';
			} else {
				$expected = (int) round( (float) $attempt['amount'] * 100 );
				if ( $r->amount_cents() !== $expected ) {
					$reasons[] = sprintf( 'amount mismatch (expected %d cents, got %d)', $expected, $r->amount_cents() );
				}
				if ( $attempt['environment'] !== $this->settings->environment() ) {
					$reasons[] = sprintf( 'environment mismatch (attempt %s, settings %s)', $attempt['environment'], $this->settings->environment() );
				}
				$order_total = number_format( (float) $order->get_total(), 2, '.', '' );
				if ( $order_total !== $attempt['amount'] ) {
					$reasons[] = sprintf( 'order total changed (attempt %s, order %s)', $attempt['amount'], $order_total );
				}
				if ( strtoupper( $order->get_currency() ) !== strtoupper( $attempt['currency'] ) ) {
					$reasons[] = sprintf( 'currency changed (attempt %s, order %s)', $attempt['currency'], $order->get_currency() );
				}
			}
			PaymentAttempt::update( $order, $txn_ref, 'approved', $r->dps_txn_ref() );
			if ( ! empty( $reasons ) ) {
				$order->add_order_note( "Windcave approved TxnRef {$txn_ref} but it was not applied: " . implode( '; ', $reasons ) . '. Check the Windcave portal before taking payment again.' );
				return $this->result( 'verification_failed', $txn_ref, $r );
			}
			$transaction_id = $r->dps_txn_ref() ? $r->dps_txn_ref() : $txn_ref;
			$completion     = OrderCompletion::complete( $order, $transaction_id );
			if ( OrderCompletion::COMPLETED === $completion ) {
				$order->add_order_note( sprintf( 'Windcave Terminal payment approved. TxnRef %s, auth %s, %s %s.', $txn_ref, $r->auth_code(), $r->card_type(), $r->card_number() ) );
				return $this->result( 'paid', $txn_ref, $r );
			}
			if ( OrderCompletion::BUSY === $completion ) {
				return $this->result( 'paid', $txn_ref, $r, __( 'Completing the order…', 'windcave-terminal-for-woocommerce' ) );
			}
			$fresh = OrderCompletion::reload_order( $order );
			if ( $fresh->get_transaction_id() === $transaction_id ) {
				return $this->result( 'paid', $txn_ref, $r );
			}
			$order->add_order_note( "Windcave approved TxnRef {$txn_ref} but the order was already paid by another transaction. Refund it in the Windcave portal." );
			return $this->result( 'conflict', $txn_ref, $r );
		}
		PaymentAttempt::update( $order, $txn_ref, 'declined' );
		$order->add_order_note( sprintf( 'Windcave Terminal payment declined. TxnRef %s, response %s, %s.', $txn_ref, $r->response_code(), $r->display_line_1() ) );
		return $this->result( 'declined', $txn_ref, $r, $r->display_line_1() ? $r->display_line_1() : __( 'The payment was declined.', 'windcave-terminal-for-woocommerce' ) );
	}

	/**
	 * Build the common result and enabled terminal prompt buttons.
	 *
	 * @param string           $status  Payment status.
	 * @param string           $txn_ref Transaction reference, if any.
	 * @param HitResponse|null $r       HIT response, if any.
	 * @param string           $message Cashier message.
	 * @return array Payment result.
	 */
	private function result( string $status, string $txn_ref = '', ?HitResponse $r = null, string $message = '' ): array {
		$prompt = array(
			'line1'   => null !== $r ? $r->display_line_1() : '',
			'line2'   => null !== $r ? $r->display_line_2() : '',
			'buttons' => array(),
		);
		if ( null !== $r ) {
			foreach ( array( 'B1', 'B2' ) as $name ) {
				$button = $r->button( $name );
				if ( $button['enabled'] ) {
					$prompt['buttons'][] = array(
						'name'  => $name,
						'label' => $button['label'],
					);
				}
			}
		}
		return array(
			'status'        => $status,
			'txn_ref'       => $txn_ref,
			'prompt'        => $prompt,
			'txn_status_id' => null !== $r ? $r->txn_status_id() : 0,
			'retry_allowed' => in_array( $status, array( 'declined', 'station_busy', 'abandoned', 'idle', 'error' ), true ),
			'message'       => $message,
		);
	}
}
