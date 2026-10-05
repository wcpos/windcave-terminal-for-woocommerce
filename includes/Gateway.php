<?php
/**
 * Windcave terminal gateway shell.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitClient;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitPaymentService;

/**
 * Register the gateway and its settings form.
 */
class Gateway extends \WC_Payment_Gateway {
	/**
	 * Initialize the gateway settings.
	 */
	public function __construct() {
		$this->id                 = Settings::GATEWAY_ID;
		$this->method_title       = __( 'Windcave Terminal', 'windcave-terminal-for-woocommerce' );
		$this->method_description = __( 'Take in-person card payments on a Windcave terminal using Windcave HIT.', 'windcave-terminal-for-woocommerce' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_scripts' ) );
	}

	/**
	 * Append this gateway to WooCommerce's gateway list.
	 *
	 * @param array $methods Registered gateway classes.
	 * @return array
	 */
	public static function register_gateway( array $methods ): array {
		$methods[] = __CLASS__;
		return $methods;
	}

	/**
	 * Define the gateway settings fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'         => array(
				'title'       => __( 'Enable/Disable', 'windcave-terminal-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => sprintf(
					/* translators: %s: Link to WooCommerce POS. */
					__( 'Enable Windcave Terminal for web checkout (not necessary for %s)', 'windcave-terminal-for-woocommerce' ),
					'<a href="https://wcpos.com" target="_blank">WooCommerce POS</a>'
				),
				'description' => __( 'This enables the gateway for online store checkout. WooCommerce POS uses this gateway once it is enabled under POS → Settings → Checkout, whether or not it is enabled here.', 'windcave-terminal-for-woocommerce' ),
				'default'     => 'no',
			),
			'title'           => array(
				'title'   => __( 'Title', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Windcave Terminal', 'windcave-terminal-for-woocommerce' ),
			),
			'description'     => array(
				'title'   => __( 'Description', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay in person on the Windcave terminal.', 'windcave-terminal-for-woocommerce' ),
			),
			'environment'     => array(
				'title'       => __( 'Environment', 'windcave-terminal-for-woocommerce' ),
				'type'        => 'select',
				'default'     => 'uat',
				'options'     => array(
					'uat'        => __( 'UAT (testing)', 'windcave-terminal-for-woocommerce' ),
					'production' => __( 'Production', 'windcave-terminal-for-woocommerce' ),
				),
				'description' => __( 'Windcave issues separate UAT and production HIT credentials. Production requires Windcave certification.', 'windcave-terminal-for-woocommerce' ),
			),
			'hit_user'        => array(
				'title'   => __( 'HIT username', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'hit_key'         => array(
				'title'   => __( 'HIT key', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			),
			'stations'        => array(
				'title'       => __( 'Station IDs', 'windcave-terminal-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'One Windcave Station ID per line. Each terminal has its own Station ID, issued by Windcave.', 'windcave-terminal-for-woocommerce' ),
			),
			'default_station' => array(
				'title'   => __( 'Default Station ID', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'text',
				'default' => '',
			),
			'lock_station'    => array(
				'title'   => __( 'Lock terminal selection', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Cashiers cannot change the terminal at checkout; the default Station is always used.', 'windcave-terminal-for-woocommerce' ),
				'default' => 'no',
			),
			'vendor_id'       => array(
				'title'       => __( 'Vendor ID', 'windcave-terminal-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Agreed with Windcave during certification. Leave empty until Windcave issues one.', 'windcave-terminal-for-woocommerce' ),
			),
			'pos_name'        => array(
				'title'   => __( 'POS name', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'text',
				'default' => 'WCPOS',
			),
			'fprn_enabled'    => array(
				'title'   => __( 'Result notifications (FPRN)', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ask Windcave to notify this site when a transaction result is ready (backup to polling).', 'windcave-terminal-for-woocommerce' ),
				'default' => 'no',
			),
			'show_logs'       => array(
				'title'   => __( 'Checkout logs', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'log_level'       => array(
				'title'       => __( 'Log level', 'windcave-terminal-for-woocommerce' ),
				'type'        => 'select',
				'default'     => 'debug',
				'options'     => array(
					'off'    => __( 'Off', 'windcave-terminal-for-woocommerce' ),
					'errors' => __( 'Errors only', 'windcave-terminal-for-woocommerce' ),
					'debug'  => __( 'Debug (everything, recommended while testing)', 'windcave-terminal-for-woocommerce' ),
				),
				'description' => __( 'Logs go to WooCommerce → Status → Logs, source windcave-terminal. Debug logs every Windcave request and response with keys and card data masked.', 'windcave-terminal-for-woocommerce' ),
			),
		);
	}

	/**
	 * Render the order-pay terminal panel.
	 */
	public function payment_fields(): void {
		global $wp;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is WooCommerce's gateway description filter.
		$description = apply_filters( 'woocommerce_gateway_description', $this->get_option( 'description' ), $this->id );
		if ( $description ) {
			echo '<p>' . wp_kses_post( $description ) . '</p>';
		}

		$settings    = new Settings();
		$order_id    = 0;
		$order_token = '';
		$order       = null;
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order ) {
				$order_token = PaymentRequestToken::for_order( $order_id );
			} else {
				$order_id = 0;
			}
		}

		// Resume the poll loop on reload when an unfinished payment is still open
		// for this order — otherwise a refresh mid-payment drops the cashier back
		// to an idle panel while the payment lingers open on Windcave.
		$resume = false;
		if ( $order && ! $order->is_paid() ) {
			$current = PaymentAttempt::current( $order );
			$resume  = $current && PaymentAttempt::is_pending( (string) ( $current['status'] ?? '' ) );
		}

		$is_pos = function_exists( 'woocommerce_pos_request' ) && woocommerce_pos_request();
		$locked = $settings->lock_station();
		echo '<div id="wctwc-payment-interface" class="wctwc-payment-interface" data-order-id="' . esc_attr( $order_id ) . '" data-order-token="' . esc_attr( $order_token ) . '" data-default-station="' . esc_attr( $settings->default_station() ) . '" data-lock-station="' . esc_attr( $locked ? '1' : '0' ) . '" data-resume="' . esc_attr( $resume ? '1' : '0' ) . '" data-pos="' . esc_attr( $is_pos ? '1' : '0' ) . '" data-gateway-id="' . esc_attr( Settings::GATEWAY_ID ) . '">';
		echo '<div class="wctwc-payment-card">';
		if ( $order_id ) {
			echo '<h4>' . esc_html__( 'Windcave Terminal', 'windcave-terminal-for-woocommerce' ) . '</h4>';
			echo '<p class="wctwc-payment-help">' . esc_html__( 'Send this order to a Windcave terminal. Follow any prompts shown below; the order completes when the terminal approves the payment.', 'windcave-terminal-for-woocommerce' ) . '</p>';
			echo '<label for="wctwc-station-select">' . esc_html__( 'Terminal', 'windcave-terminal-for-woocommerce' ) . '</label>';
			echo '<select id="wctwc-station-select" class="wctwc-station-select"' . ( $locked ? ' disabled' : '' ) . '>';
			$stations = $settings->station_ids();
			foreach ( $stations as $station ) {
				echo '<option value="' . esc_attr( $station ) . '"' . ( $station === $settings->default_station() ? ' selected' : '' ) . '>' . esc_html( $station ) . '</option>';
			}
			if ( empty( $stations ) ) {
				echo '<option disabled>' . esc_html__( 'No Station IDs configured', 'windcave-terminal-for-woocommerce' ) . '</option>';
			}
			echo '</select>';
			// One button that toggles between Start and Cancel: while a payment is
			// in flight the panel polls automatically, so a single control both
			// starts and cancels the terminal payment (no separate status button).
			$action_mode  = $resume ? 'cancel' : 'start';
			$action_label = $resume
				? __( 'Cancel Terminal Payment', 'windcave-terminal-for-woocommerce' )
				: __( 'Start Terminal Payment', 'windcave-terminal-for-woocommerce' );
			echo '<div class="wctwc-payment-actions">';
			echo '<button type="button" class="button button-primary wctwc-primary-action" data-wctwc-mode="' . esc_attr( $action_mode ) . '">' . esc_html( $action_label ) . '</button>';
			echo '<button type="button" class="button wctwc-abandon" hidden>' . esc_html__( 'Set payment aside', 'windcave-terminal-for-woocommerce' ) . '</button>';
			echo '</div>';
			echo '<div class="wctwc-prompt" hidden aria-live="assertive"><p class="wctwc-prompt-line1"></p><p class="wctwc-prompt-line2"></p><div class="wctwc-prompt-buttons"></div></div>';
			echo '<div class="wctwc-payment-status" role="status" aria-live="polite"></div>';
		} else {
			echo '<p class="wctwc-payment-help">' . esc_html__( 'Payment activity logs will appear here during checkout. If payment creation fails, copy these logs for support.', 'windcave-terminal-for-woocommerce' ) . '</p>';
		}
		echo '</div>';

		// The log tools are a support aid, hidden unless the merchant opts in.
		// The textarea itself is always present (JS writes to it) but stays
		// collapsed; only the toolbar visibility is gated.
		$show_logs = $settings->show_logs();
		echo '<div class="wctwc-logging-section' . ( $show_logs ? '' : ' wctwc-logging-hidden' ) . '">';
		if ( $show_logs ) {
			echo '<div class="wctwc-logging-header">';
			echo '<h4>' . esc_html__( 'Logs', 'windcave-terminal-for-woocommerce' ) . '</h4>';
			echo '<div class="wctwc-logging-actions">';
			echo '<button type="button" class="button wctwc-toggle-log" data-expanded="false">' . esc_html__( 'Show logs', 'windcave-terminal-for-woocommerce' ) . '</button>';
			echo '<button type="button" class="button wctwc-copy-log">' . esc_html__( 'Copy', 'windcave-terminal-for-woocommerce' ) . '</button>';
			echo '<button type="button" class="button wctwc-clear-log">' . esc_html__( 'Clear', 'windcave-terminal-for-woocommerce' ) . '</button>';
			echo '</div>';
			echo '</div>';
		}
		echo '<div class="wctwc-log-content" style="display: none;">';
		echo '<textarea class="wctwc-payment-log-textarea" readonly placeholder="' . esc_attr__( 'Windcave Terminal payment activity will appear here...', 'windcave-terminal-for-woocommerce' ) . '"></textarea>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<noscript>' . esc_html__( 'Please enable JavaScript to use the Windcave Terminal integration.', 'windcave-terminal-for-woocommerce' ) . '</noscript>';
	}

	/**
	 * Enqueue the payment panel assets and translated messages.
	 */
	public function enqueue_payment_scripts(): void {
		wp_enqueue_script( 'wctwc-payment', WCTWC_PLUGIN_URL . 'assets/js/payment.js', array(), WCTWC_VERSION, true );
		wp_enqueue_style( 'wctwc-payment', WCTWC_PLUGIN_URL . 'assets/css/payment.css', array(), WCTWC_VERSION );
		wp_localize_script(
			'wctwc-payment',
			'wctwcPaymentData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'pollIntervalMs' => (int) apply_filters( 'wctwc_poll_interval_ms', 1500 ),
				'pollTimeoutMs'  => (int) apply_filters( 'wctwc_poll_timeout_ms', 300000 ),
				'i18n'           => array(
					'startAction'        => __( 'Start Terminal Payment', 'windcave-terminal-for-woocommerce' ),
					'cancelAction'       => __( 'Cancel Terminal Payment', 'windcave-terminal-for-woocommerce' ),
					'abandonAction'      => __( 'Set payment aside', 'windcave-terminal-for-woocommerce' ),
					'sending'            => __( 'Sending to terminal…', 'windcave-terminal-for-woocommerce' ),
					'waiting'            => __( 'Waiting for terminal…', 'windcave-terminal-for-woocommerce' ),
					'completing'         => __( 'Payment complete — finishing order…', 'windcave-terminal-for-woocommerce' ),
					'selectStation'      => __( 'Select a terminal first.', 'windcave-terminal-for-woocommerce' ),
					'declined'           => __( 'Payment declined. You can try again.', 'windcave-terminal-for-woocommerce' ),
					'stationBusy'        => __( 'The terminal is still finishing an earlier transaction. Complete or cancel it on the terminal, then try again.', 'windcave-terminal-for-woocommerce' ),
					'abandoned'          => __( 'The terminal did not respond, so the payment was set aside. Start a new payment or choose another method.', 'windcave-terminal-for-woocommerce' ),
					'timedOut'           => __( 'Timed out waiting for the terminal. Check the terminal or try again.', 'windcave-terminal-for-woocommerce' ),
					'requestFailed'      => __( 'Windcave Terminal request failed. Copy logs for support.', 'windcave-terminal-for-woocommerce' ),
					'verificationFailed' => __( 'The terminal payment could not be verified. Check the payment before trying again.', 'windcave-terminal-for-woocommerce' ),
					'conflict'           => __( 'This order was already paid by another payment. Check the terminal payment before trying again.', 'windcave-terminal-for-woocommerce' ),
					'logsShown'          => __( 'Hide logs', 'windcave-terminal-for-woocommerce' ),
					'logsHidden'         => __( 'Show logs', 'windcave-terminal-for-woocommerce' ),
					'copied'             => __( 'Logs copied to clipboard.', 'windcave-terminal-for-woocommerce' ),
					'copyFailed'         => __( 'Unable to copy logs automatically.', 'windcave-terminal-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Complete the form submit when the terminal payment has already succeeded.
	 *
	 * The terminal payment is created and confirmed out-of-band via AJAX,
	 * so by the time WooCommerce submits the order-pay form the payment is
	 * usually already reconciled. We confirm it is paid (polling Windcave once
	 * more if needed) and hand WooCommerce the thank-you redirect the POS listens for.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array Payment result and redirect when paid.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		if ( ! $order->is_paid() ) {
			try {
				$settings = new Settings();
				$service  = new HitPaymentService( new HitClient( $settings ), $settings );
				$result   = $service->poll( $order );
				if ( 'paid' === ( $result['status'] ?? '' ) ) {
					$refreshed = wc_get_order( $order_id );
					if ( $refreshed ) {
						$order = $refreshed;
					}
				}
			} catch ( \Exception $e ) {
				Logger::log( 'Windcave Terminal process_payment could not verify payment: ' . $e->getMessage(), array(), 'error' );
			}
		}
		if ( $order->is_paid() ) {
			return array(
				'result'   => 'success',
				'redirect' => AjaxHandler::order_return_url( $order ),
			);
		}
		wc_add_notice( __( 'This order has not been paid yet. Start the payment above and wait for the terminal to approve it; the order finishes on its own.', 'windcave-terminal-for-woocommerce' ), 'notice' );
		return array( 'result' => 'failure' );
	}
}
