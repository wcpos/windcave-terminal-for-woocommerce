<?php
/**
 * Authorised AJAX routes for terminal payments.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitClient;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitPaymentService;

/**
 * Route order requests to the HIT payment service.
 */
class AjaxHandler {

	/**
	 * Payment service factory.
	 *
	 * @var callable
	 */
	private $service_factory;

	/**
	 * Register logged-in and logged-out AJAX routes.
	 *
	 * @param callable|null $service_factory Factory receiving gateway settings.
	 */
	public function __construct( ?callable $service_factory = null ) {
		$this->service_factory = $service_factory ?? function ( Settings $s ) {
			return new HitPaymentService( new HitClient( $s ), $s );
		};
		if ( ! function_exists( 'add_action' ) || ! wp_doing_ajax() ) {
			return;
		}
		foreach ( array( 'start_payment', 'poll_payment', 'answer_prompt', 'cancel_payment', 'abandon_payment' ) as $method ) {
			add_action( 'wp_ajax_wctwc_' . $method, array( $this, $method ) );
			add_action( 'wp_ajax_nopriv_wctwc_' . $method, array( $this, $method ) );
		}
	}

	/**
	 * Start a terminal payment.
	 */
	public function start_payment(): void {
		$this->with_order(
			'start_payment',
			function ( $order ) {
				$this->require_gateway_enabled();
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- with_order checks capabilities or a signed order token.
				$station = sanitize_text_field( wp_unslash( $_POST['station'] ?? '' ) );
				return $this->payment_service()->start( $order, $station );
			}
		);
	}

	/**
	 * Poll an existing payment.
	 */
	public function poll_payment(): void {
		$this->with_order(
			'poll_payment',
			function ( $order ) {
				return $this->payment_service()->poll( $order );
			}
		);
	}

	/**
	 * Answer a terminal prompt.
	 */
	public function answer_prompt(): void {
		$this->with_order(
			'answer_prompt',
			function ( $order ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- with_order checks capabilities or a signed order token.
				$button = sanitize_text_field( wp_unslash( $_POST['button'] ?? '' ) );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- with_order checks capabilities or a signed order token.
				$value = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );
				return $this->payment_service()->answer( $order, $button, $value );
			}
		);
	}

	/**
	 * Cancel an existing payment.
	 */
	public function cancel_payment(): void {
		$this->with_order(
			'cancel_payment',
			function ( $order ) {
				return $this->payment_service()->cancel( $order );
			}
		);
	}

	/**
	 * Set an existing payment aside.
	 */
	public function abandon_payment(): void {
		$this->with_order(
			'abandon_payment',
			function ( $order ) {
				return $this->payment_service()->abandon( $order );
			}
		);
	}

	/**
	 * Thank-you URL for a paid order. Inside the WooCommerce POS the standard
	 * order-received page is not what the POS watches for, so POS requests
	 * (detected via the X-WCPOS header the frontend sends) get the
	 * /wcpos-checkout/order-received/ variant instead — the same URL the
	 * Stripe/SumUp terminal gateways redirect to.
	 *
	 * @param \WC_Order $order Paid order.
	 */
	public static function order_return_url( $order ): string {
		if ( function_exists( 'woocommerce_pos_request' ) && woocommerce_pos_request() ) {
			return add_query_arg(
				array( 'key' => $order->get_order_key() ),
				get_home_url( null, '/wcpos-checkout/order-received/' . $order->get_id() )
			);
		}
		return (string) $order->get_checkout_order_received_url();
	}

	/**
	 * Attach the thank-you URL to a completed payment result.
	 *
	 * @param array     $result Payment result.
	 * @param \WC_Order $order  Requested order.
	 */
	public static function with_paid_redirect( array $result, $order ): array {
		// The completed order may be a fresh copy, not $order; the panel needs
		// the thank-you URL for every paid answer.
		if ( $order->is_paid() || in_array( $result['status'] ?? '', array( 'paid', 'already_paid', 'conflict' ), true ) ) {
			$result['redirect_url'] = self::order_return_url( $order );
		}
		return $result;
	}

	/**
	 * Authorise and load the order before routing a payment request.
	 *
	 * @param string   $operation Operation name for logs.
	 * @param callable $callback Payment operation.
	 */
	private function with_order( string $operation, callable $callback ): void {
		$started = microtime( true );
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Access is checked using capabilities or a signed order token below.
			$order_id = absint( $_POST['order_id'] ?? 0 );
			$context  = array(
				'operation' => $operation,
				'order_id' => $order_id,
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only record whether the credential was supplied.
				'credential_sent' => ! empty( $_POST['order_token'] ),
				'logged_in' => function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : null,
			);
			if ( ! $order_id ) {
				Logger::log( 'Windcave Terminal AJAX request refused.', $context + array( 'reason' => 'missing_order_id' ), 'warning' );
				wp_send_json_error( __( 'Order ID is required.', 'windcave-terminal-for-woocommerce' ), 400 );
			}
			if ( ! $this->can_access_order( $order_id ) ) {
				Logger::log( 'Windcave Terminal AJAX request refused.', $context + array( 'reason' => 'unauthorised' ), 'warning' );
				wp_send_json_error( __( 'Unauthorized request.', 'windcave-terminal-for-woocommerce' ), 403 );
			}
			Logger::log(
				'Windcave Terminal AJAX request received.',
				array(
					'operation'  => $operation,
					'order_id'   => $order_id,
					'elapsed_ms' => Logger::elapsed_ms( $started ),
				),
				'info'
			);
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				Logger::log( 'Windcave Terminal AJAX request refused.', $context + array( 'reason' => 'invalid_order' ), 'warning' );
				wp_send_json_error( __( 'Invalid order.', 'windcave-terminal-for-woocommerce' ), 404 );
			}
			$result = $callback( $order );
			if ( is_array( $result ) ) {
				// The order is already reconciled and paid, so re-submitting the
				// order-pay form would hit WooCommerce's "already paid" guard.
				// Hand the frontend the thank-you URL to navigate to directly.
				$result = self::with_paid_redirect( $result, $order );
			}
			Logger::log(
				'Windcave Terminal AJAX request completed.',
				array(
					'operation'     => $operation,
					'order_id'      => $order_id,
					'status'        => is_array( $result ) ? ( $result['status'] ?? '' ) : '',
					'elapsed_ms'    => Logger::elapsed_ms( $started ),
					'result_status' => is_array( $result ) ? ( $result['status'] ?? '' ) : '',
					'txn_ref'       => is_array( $result ) ? ( $result['txn_ref'] ?? '' ) : '',
					'message'       => is_array( $result ) ? ( $result['message'] ?? '' ) : '',
				),
				'success'
			);
			wp_send_json_success( $result );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( $e->getMessage(), 409 );
		} catch ( \Exception $e ) {
			Logger::log( 'Windcave Terminal AJAX failed: ' . $e->getMessage(), array( 'operation' => $operation ), 'error' );
			wp_send_json_error( $e->getMessage(), 500 );
		}
	}

	/**
	 * Accept a privileged user or a valid signed token for this order.
	 *
	 * @param int $order_id Requested order ID.
	 */
	private function can_access_order( int $order_id ): bool {
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_order', $order_id ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The signed order token is the authorisation credential.
		$token = sanitize_text_field( wp_unslash( $_POST['order_token'] ?? '' ) );
		return PaymentRequestToken::verify( $token, $order_id );
	}

	/**
	 * A gateway switched off everywhere must stay off: the checkout actions
	 * stay registered while the plugin is active, so starting a payment checks
	 * the switches itself. There are two: the WooCommerce → Payments checkbox
	 * (online store) and POS → Settings → Checkout (WooCommerce POS), and either
	 * one counts — POS merchants routinely leave the WooCommerce one off.
	 * Poll, answer, cancel and abandon are deliberately not gated: a payment
	 * already in flight must still settle, and the cashier must keep the ability
	 * to cancel it, even if the gateway was switched off meanwhile.
	 */
	private function require_gateway_enabled(): void {
		if ( ! $this->settings()->active() ) {
			Logger::log(
				'Windcave Terminal AJAX request refused.',
				array(
					'operation' => 'start_payment',
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- with_order has already authorised this request.
					'order_id' => absint( $_POST['order_id'] ?? 0 ),
					'reason' => 'gateway_disabled',
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only record whether the credential was supplied.
					'credential_sent' => ! empty( $_POST['order_token'] ),
					'logged_in' => function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : null,
				),
				'warning'
			);
			wp_send_json_error( __( 'Windcave Terminal is disabled.', 'windcave-terminal-for-woocommerce' ), 403 );
		}
	}

	/**
	 * Read gateway settings.
	 */
	private function settings(): Settings {
		return new Settings();
	}

	/**
	 * Construct the payment service for this request.
	 */
	private function payment_service(): HitPaymentService {
		return ( $this->service_factory )( $this->settings() );
	}
}
