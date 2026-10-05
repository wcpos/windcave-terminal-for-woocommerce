<?php
/**
 * HIT payment attempts recorded on an order.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

/**
 * Track the current transaction, history, receipts and abandoned transactions.
 */
class PaymentAttempt {
	public const META_CURRENT_TXN_REF    = '_wctwc_current_txn_ref';
	public const META_CURRENT_STATION    = '_wctwc_current_station';
	public const META_CURRENT_STATUS     = '_wctwc_current_status';
	public const META_CURRENT_CREATED_AT = '_wctwc_current_created_at';
	public const META_ATTEMPTS           = '_wctwc_attempts';
	public const META_ABANDONED_TXN_REFS = '_wctwc_abandoned_txn_refs';
	public const META_RECEIPT            = '_wctwc_receipt';
	public const META_RECEIPT_WIDTH      = '_wctwc_receipt_width';

	/**
	 * Generate a unique alphanumeric reference for an attempt.
	 *
	 * @param int $order_id Order ID.
	 * @return string Transaction reference.
	 */
	public static function new_txn_ref( int $order_id ): string {
		return $order_id . 'W' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Read the current attempt pointer.
	 *
	 * @param \WC_Order $order Order to read.
	 * @return array|null Current attempt, if present.
	 */
	public static function current( $order ): ?array {
		$txn_ref = $order->get_meta( self::META_CURRENT_TXN_REF );
		if ( ! $txn_ref ) {
			return null;
		}
		return array(
			'txn_ref'    => (string) $txn_ref,
			'station'    => (string) $order->get_meta( self::META_CURRENT_STATION ),
			'status'     => (string) $order->get_meta( self::META_CURRENT_STATUS ),
			'created_at' => (string) $order->get_meta( self::META_CURRENT_CREATED_AT ),
		);
	}

	/**
	 * Make this gateway the order's payment method once payment is confirmed.
	 *
	 * An abandoned attempt must not leave Windcave on an order paid another way.
	 * Does not save; the caller saves the order right after.
	 *
	 * @param \WC_Order $order Order to claim.
	 * @param string    $title Gateway title.
	 */
	public static function claim_order_gateway( $order, string $title ): void {
		if ( Settings::GATEWAY_ID === (string) $order->get_payment_method() && '' !== (string) $order->get_payment_method_title() ) {
			return;
		}
		$order->set_payment_method( Settings::GATEWAY_ID );
		$order->set_payment_method_title( $title );
	}

	/**
	 * Record a new pending transaction and make it current.
	 *
	 * @param \WC_Order $order       Order to update.
	 * @param string    $txn_ref     Transaction reference.
	 * @param string    $station     Station ID.
	 * @param string    $amount      Transaction amount.
	 * @param string    $currency    Currency code.
	 * @param string    $environment HIT environment.
	 * @return array Recorded history entry.
	 */
	public static function record_new( $order, string $txn_ref, string $station, string $amount, string $currency, string $environment ): array {
		$attempt = array(
			'txn_ref'     => $txn_ref,
			'station'     => $station,
			'amount'      => $amount,
			'currency'    => $currency,
			'environment' => $environment,
			'status'      => 'pending',
			'dps_txn_ref' => '',
			'created_at'  => gmdate( 'c' ),
			'updated_at'  => gmdate( 'c' ),
		);
		$order->update_meta_data( self::META_CURRENT_TXN_REF, $txn_ref );
		$order->update_meta_data( self::META_CURRENT_STATION, $station );
		$order->update_meta_data( self::META_CURRENT_STATUS, $attempt['status'] );
		$order->update_meta_data( self::META_CURRENT_CREATED_AT, $attempt['created_at'] );
		$history   = self::history( $order );
		$history[] = $attempt;
		$order->update_meta_data( self::META_ATTEMPTS, $history );
		$order->save();
		return $attempt;
	}

	/**
	 * Find an attempt by its transaction reference.
	 *
	 * @param \WC_Order $order   Order to read.
	 * @param string    $txn_ref Transaction reference.
	 * @return array|null Matching history entry.
	 */
	public static function find( $order, string $txn_ref ): ?array {
		foreach ( self::history( $order ) as $attempt ) {
			if ( ( $attempt['txn_ref'] ?? '' ) === $txn_ref ) {
				return $attempt;
			}
		}
		return null;
	}

	/**
	 * Update an attempt without changing another attempt's current status.
	 *
	 * @param \WC_Order $order       Order to update.
	 * @param string    $txn_ref     Transaction reference.
	 * @param string    $status      Transaction status.
	 * @param string    $dps_txn_ref Windcave transaction ID, when available.
	 */
	public static function update( $order, string $txn_ref, string $status, string $dps_txn_ref = '' ): void {
		// Only the current attempt owns the current-status pointer. A reconcile of
		// an abandoned transaction must not stamp its status onto whatever attempt
		// the cashier is running now.
		if ( (string) $order->get_meta( self::META_CURRENT_TXN_REF ) === $txn_ref ) {
			$order->update_meta_data( self::META_CURRENT_STATUS, $status );
		}
		$history = self::history( $order );
		foreach ( $history as &$attempt ) {
			if ( ( $attempt['txn_ref'] ?? '' ) === $txn_ref ) {
				$attempt['status']     = $status;
				$attempt['updated_at'] = gmdate( 'c' );
				if ( '' !== $dps_txn_ref ) {
					$attempt['dps_txn_ref'] = $dps_txn_ref;
				}
			}
		}
		$order->update_meta_data( self::META_ATTEMPTS, $history );
		if ( self::is_final( $status ) ) {
			self::forget_abandoned( $order, $txn_ref );
		}
		$order->save();
	}

	/**
	 * Store a non-empty receipt in history, order meta and a private note.
	 *
	 * @param \WC_Order $order   Order to update.
	 * @param string    $txn_ref Transaction reference.
	 * @param string    $receipt Receipt text.
	 * @param int       $width   Receipt width.
	 */
	public static function store_receipt( $order, string $txn_ref, string $receipt, int $width ): void {
		if ( '' === $receipt ) {
			return;
		}
		$history = self::history( $order );
		foreach ( $history as &$attempt ) {
			if ( ( $attempt['txn_ref'] ?? '' ) === $txn_ref ) {
				$attempt['receipt']       = $receipt;
				$attempt['receipt_width'] = $width;
			}
		}
		$order->update_meta_data( self::META_ATTEMPTS, $history );
		$order->update_meta_data( self::META_RECEIPT, $receipt );
		$order->update_meta_data( self::META_RECEIPT_WIDTH, $width );
		$order->add_order_note( "Windcave receipt (TxnRef {$txn_ref}):\n" . $receipt );
		$order->save();
	}

	/**
	 * Detach the current attempt and park a pending reference for reconciliation.
	 *
	 * @param \WC_Order $order Order to update.
	 */
	public static function abandon_current( $order ): void {
		$txn_ref = (string) $order->get_meta( self::META_CURRENT_TXN_REF );
		if ( '' !== $txn_ref ) {
			$status  = (string) $order->get_meta( self::META_CURRENT_STATUS );
			$history = self::history( $order );
			foreach ( $history as &$attempt ) {
				if ( ( $attempt['txn_ref'] ?? '' ) === $txn_ref && self::is_pending( (string) ( $attempt['status'] ?? '' ) ) ) {
					$attempt['status']     = 'abandoned';
					$attempt['updated_at'] = gmdate( 'c' );
				}
			}
			unset( $attempt );
			$order->update_meta_data( self::META_ATTEMPTS, $history );
			// Keep the still-pending transaction reachable after clearing the pointer.
			if ( self::is_pending( $status ) ) {
				$abandoned = self::abandoned( $order );
				if ( ! in_array( $txn_ref, $abandoned, true ) ) {
					$abandoned[] = $txn_ref;
					$order->update_meta_data( self::META_ABANDONED_TXN_REFS, $abandoned );
				}
			}
		}
		$order->delete_meta_data( self::META_CURRENT_TXN_REF );
		$order->delete_meta_data( self::META_CURRENT_STATION );
		$order->delete_meta_data( self::META_CURRENT_STATUS );
		$order->delete_meta_data( self::META_CURRENT_CREATED_AT );
		$order->save();
	}

	/**
	 * Read the attempt history.
	 *
	 * @param \WC_Order $order Order to read.
	 * @return array History entries.
	 */
	public static function history( $order ): array {
		$history = $order->get_meta( self::META_ATTEMPTS );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Read unique non-empty references detached while still pending.
	 *
	 * @param \WC_Order $order Order to read.
	 * @return array Abandoned references.
	 */
	public static function abandoned( $order ): array {
		$ids = $order->get_meta( self::META_ABANDONED_TXN_REFS );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$unique = array();
		foreach ( $ids as $id ) {
			$id = (string) $id;
			if ( '' !== $id && ! in_array( $id, $unique, true ) ) {
				$unique[] = $id;
			}
		}
		return $unique;
	}

	/**
	 * Stop reconciling an abandoned transaction once it reaches a final state.
	 *
	 * @param \WC_Order $order   Order to update.
	 * @param string    $txn_ref Transaction reference.
	 */
	public static function forget_abandoned( $order, string $txn_ref ): void {
		$abandoned = self::abandoned( $order );
		if ( ! in_array( $txn_ref, $abandoned, true ) ) {
			return;
		}
		$remaining = array_values( array_diff( $abandoned, array( $txn_ref ) ) );
		// Delete rather than store an empty array: reconciliation finds the meta
		// key by its existence, not by its contents.
		if ( empty( $remaining ) ) {
			$order->delete_meta_data( self::META_ABANDONED_TXN_REFS );
		} else {
			$order->update_meta_data( self::META_ABANDONED_TXN_REFS, $remaining );
		}
		$order->save();
	}

	/**
	 * Whether a transaction has a final result.
	 *
	 * @param string $status Transaction status.
	 * @return bool Whether approved or declined.
	 */
	public static function is_final( string $status ): bool {
		return in_array( $status, array( 'approved', 'declined' ), true );
	}

	/**
	 * Whether a transaction is still pending.
	 *
	 * @param string $status Transaction status.
	 * @return bool Whether pending or not yet known.
	 */
	public static function is_pending( string $status ): bool {
		return in_array( $status, array( 'pending', '' ), true );
	}
}
