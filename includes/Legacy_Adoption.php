<?php
/** Upgrade live 0.x attempts without sending another Purchase.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;

/** Bind pending 0.x current attempts to Pro without dispatch. */
class Legacy_Adoption {
	public const META_CURRENT_TXN_REF = '_wctwc_current_txn_ref';
	public const META_CURRENT_STATION = '_wctwc_current_station';
	public const META_CURRENT_STATUS = '_wctwc_current_status';
	public const META_CURRENT_CREATED_AT = '_wctwc_current_created_at';
	public const META_ATTEMPTS = '_wctwc_attempts';
	public const META_ABANDONED_TXN_REFS = '_wctwc_abandoned_txn_refs';
	public const META_RECEIPT = '_wctwc_receipt';
	public const META_RECEIPT_WIDTH = '_wctwc_receipt_width';
	/**
	 * Adopt a bounded page of pending attempts under Pro’s order lock.
	 */
	public static function upgrade(): void {
		wp_clear_scheduled_hook( 'wctwc_sweep_stale_payments' );
		if ( version_compare( get_option( 'wctwc_version', '0' ), '1.0.0', '>=' ) ) {
			return;
		}
		$offset = (int) get_option( 'wctwc_adoption_offset', 0 );
		$orders = wc_get_orders(
			array(
				'type' => 'shop_order',
				'limit' => 25,
				'offset' => $offset,
				'orderby' => 'ID',
				'order' => 'ASC',
				'meta_key' => self::META_CURRENT_TXN_REF,
				'meta_compare' => 'EXISTS',
			)
		);
		foreach ( $orders as $order ) {
			$result = Order_Lock::instance()->with_lock(
				$order->get_id(),
				static function () use ( $order ) {
					$order = wc_get_order( $order->get_id() );
					$ref = (string) $order->get_meta( self::META_CURRENT_TXN_REF );
					if ( '' === $ref || ! in_array( $order->get_meta( self::META_CURRENT_STATUS ), array( '', 'pending' ), true ) || wcpos_pro_payment_id_for_action( 'windcave', $ref ) ) {
						return true;
					}
					foreach ( (array) $order->get_meta( self::META_ATTEMPTS ) as $attempt ) {
						if ( ( $attempt['txn_ref'] ?? '' ) !== $ref ) {
							continue;
						}
						$c = $attempt + array(
							'created_at_gmt' => $attempt['created_at'],
							'dispatched_at_gmt' => $attempt['created_at'],
						);
						$c['environment'] = $attempt['environment'] ?? 'uat';
						update_option( Provider_Adapter::context_key( $ref ), $c, false );
						return wcpos_pro_adopt_legacy_attempt( $order, Settings::GATEWAY_ID, $ref, $attempt['amount'], $attempt['currency'] );
					}
					return new \WP_Error( 'wctwc_legacy_context_missing' );
				}
			);
			if ( is_wp_error( $result ) ) {
				wc_get_logger()->error( 'Windcave adoption failed for order ' . $order->get_id() . ': ' . $result->get_error_code(), array( 'source' => 'windcave-terminal' ) );
				continue;
			}
		}
		update_option( 'wctwc_adoption_offset', $offset + count( $orders ), false );
		if ( count( $orders ) < 25 ) {
			delete_option( 'wctwc_adoption_offset' );
			update_option( 'wctwc_version', '1.0.0', false );
		}
	}
}
