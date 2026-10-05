<?php
/**
 * Short-lived signed payment request tokens.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

/**
 * Payment request token service.
 */
final class PaymentRequestToken {
	/**
	 * One till shift: the order-pay page can stay open while the cashier serves the customer.
	 */
	public const TTL = 8 * 3600;

	/**
	 * Create a token for the current till shift.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function for_order( int $order_id ): string {
		return self::create( $order_id, time() + self::TTL );
	}

	/**
	 * Create an order-bound token.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $expires  Unix expiration timestamp.
	 */
	public static function create( int $order_id, int $expires ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode the signed JSON payload, not executable code.
		$payload = base64_encode(
			wp_json_encode(
				array(
					'order_id' => $order_id,
					'expires'  => $expires,
				)
			)
		);
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );

		return $payload . '.' . $signature;
	}

	/**
	 * Verify an order-bound token.
	 *
	 * @param string $token    Signed token.
	 * @param int    $order_id Expected order ID.
	 */
	public static function verify( string $token, int $order_id ): bool {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		list( $payload, $signature ) = $parts;
		$expected_signature         = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode only the authenticated JSON payload.
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			return false;
		}

		$data = json_decode( $decoded, true );

		return is_array( $data )
			&& (int) ( $data['order_id'] ?? 0 ) === $order_id
			&& (int) ( $data['expires'] ?? 0 ) >= time();
	}
}
