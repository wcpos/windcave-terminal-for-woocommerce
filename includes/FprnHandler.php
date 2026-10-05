<?php
/**
 * Receive Windcave Fail Proof Result Notifications.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitClient;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitPaymentService;

/**
 * Treat notifications as hints and fetch the recorded transaction's status.
 */
class FprnHandler {
	/**
	 * Build the payment service from gateway settings.
	 *
	 * @var callable
	 */
	private $service_factory;

	/**
	 * Register the public and authenticated notification receiver.
	 *
	 * @param callable|null $service_factory Payment service factory.
	 */
	public function __construct( ?callable $service_factory = null ) {
		$this->service_factory = $service_factory ?? function ( Settings $s ) {
			return new HitPaymentService( new HitClient( $s ), $s );
		};
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_ajax_wctwc_fprn', array( $this, 'handle' ) );
			add_action( 'wp_ajax_nopriv_wctwc_fprn', array( $this, 'handle' ) );
		}
	}

	/**
	 * Sign an order's notification URL.
	 *
	 * @param int $order_id Order ID.
	 * @return string Order signature.
	 */
	public static function signature( int $order_id ): string {
		return substr( hash_hmac( 'sha256', 'wctwc_fprn_' . $order_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Build the notification URL passed to Windcave.
	 *
	 * @param \WC_Order $order Order to resolve.
	 * @return string Notification URL.
	 */
	public static function url( $order ): string {
		$id = (int) $order->get_id();
		return add_query_arg(
			array(
				'action'   => 'wctwc_fprn',
				'order_id' => $id,
				'sig'      => self::signature( $id ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Verify the URL and resolve only a transaction recorded on that order.
	 */
	public function handle(): void {
		// The order HMAC authenticates this server notification instead of a nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id = absint( sanitize_text_field( wp_unslash( $_GET['order_id'] ?? '' ) ) );
		$sig      = sanitize_text_field( wp_unslash( $_GET['sig'] ?? '' ) );
		$txn_ref  = sanitize_text_field( wp_unslash( $_GET['txnRef'] ?? $_GET['TxnRef'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $order_id || ! hash_equals( self::signature( $order_id ), $sig ) ) {
			Logger::log( 'FPRN refused: missing order ID or invalid signature.', array( 'order_id' => $order_id ), 'warning' );
			$this->respond( 404 );
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || '' === $txn_ref || null === PaymentAttempt::find( $order, $txn_ref ) ) {
			Logger::log(
				'FPRN refused: order or recorded TxnRef not found.',
				array(
					'order_id' => $order_id,
					'txn_ref'  => $txn_ref,
				),
				'warning'
			);
			$this->respond( 404 );
			return;
		}
		try {
			$service = ( $this->service_factory )( new Settings() );
			$result  = $service->resolve_txn( $order, $txn_ref, 'fprn' );
			Logger::log(
				'FPRN resolved.',
				array(
					'order_id' => $order_id,
					'txn_ref'  => $txn_ref,
					'status'   => $result['status'],
				),
				'info'
			);
		} catch ( \Exception $e ) {
			Logger::log(
				'FPRN failed: ' . $e->getMessage(),
				array(
					'order_id' => $order_id,
					'txn_ref'  => $txn_ref,
				),
				'error'
			);
		}
		$this->respond( 200 );
	}

	/**
	 * Send the notification response and end the request.
	 *
	 * @param int $code HTTP status code.
	 */
	protected function respond( int $code ): void {
		status_header( $code );
		echo 200 === $code ? 'OK' : 'Not found';
		$this->terminate();
	}

	/**
	 * End the request; tests override this boundary.
	 */
	protected function terminate(): void {
		exit;
	}
}
