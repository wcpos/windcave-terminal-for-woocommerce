<?php
/**
 * Windcave terminal gateway shell.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;

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
		$this->supports           = array( 'products', 'refunds' );
		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		$this->enabled = $this->get_option( 'enabled', 'no' );
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
				'type'    => 'wctwc_secret',
				'default' => '',
			),
			'stations'        => array(
				'title'       => __( 'Station IDs', 'windcave-terminal-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'One Windcave Station ID per line. Each terminal has its own Station ID, issued by Windcave.', 'windcave-terminal-for-woocommerce' ),
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
				'description' => esc_url( add_query_arg( 'provider', 'windcave', rest_url( 'wcpos/v2/payments/webhook' ) ) ),
				'title'   => __( 'Result notifications (FPRN)', 'windcave-terminal-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ask Windcave to notify this site when a transaction result is ready (backup to polling).', 'windcave-terminal-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Require HIT credentials and a configured Station.
	 */
	public function is_available() {
		$s = new Settings();
		return parent::is_available() && '' !== $s->hit_user() && '' !== $s->hit_key() && (bool) $s->station_ids();
	}
	/**
	 * Render the shared Pro panel on order-pay pages.
	 */
	public function payment_fields() {
		global $wp;
		echo wp_kses_post( wpautop( $this->get_description() ) );
		$order = is_checkout_pay_page() ? wc_get_order( $wp->query_vars['order-pay'] ?? 0 ) : false;
		if ( $order ) {
			wcpos_pro_order_pay_panel( $this, $order );
		}
	}
	/**
	 * Delegate completion handling to Pro.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function process_payment( $order_id ) {
		return wcpos_pro_order_pay_process( wc_get_order( $order_id ) );
	}
	/**
	 * Delegate refund identity and allocation to Pro.
	 *
	 * @param int         $order_id WooCommerce order ID.
	 * @param string|null $amount Decimal refund amount.
	 * @param string      $reason Refund reason.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return wcpos_pro_order_pay_refund( wc_get_order( $order_id ), $amount, $reason );
	}
	/**
	 * Render a blank secret input without exposing the saved key.
	 *
	 * @param string $key Setting key.
	 * @param array  $data Setting field definition.
	 */
	public function generate_wctwc_secret_html( $key, $data ) {
		$saved = $this->settings[ $key ] ?? '';
		$this->settings[ $key ] = '';
		$html = $this->generate_password_html(
			$key,
			$data + array(
				'placeholder' => __( 'Leave blank to keep the saved key.', 'windcave-terminal-for-woocommerce' ),
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			)
		);
		$this->settings[ $key ] = $saved;
		return $html;
	}
	/**
	 * Keep the saved key when an administrator leaves the input blank.
	 *
	 * @param string $key Setting key.
	 * @param string $value Button label.
	 */
	public function validate_wctwc_secret_field( $key, $value ) {
		return '' === trim( $value ) ? $this->get_option( $key ) : trim( $value );
	}
	/**
	 * Save settings and clear Pro reader discovery.
	 */
	public function process_admin_options() {
		$result = parent::process_admin_options();
		Reader_Curation::forget( $this->id );
		return $result;
	}
	/**
	 * Show configuration health and the WooCommerce log link.
	 */
	public function admin_options(): void {
		parent::admin_options();
		$s = new Settings();
		echo '<p>' . esc_html( $s->environment() . ': ' . ( $s->hit_user() && $s->hit_key() ? __( 'HIT credentials configured (not verified).', 'windcave-terminal-for-woocommerce' ) : __( 'HIT credentials missing.', 'windcave-terminal-for-woocommerce' ) ) ) . '</p>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=windcave-terminal' ) ) . '">' . esc_html__( 'View logs', 'windcave-terminal-for-woocommerce' ) . '</a></p>';
	}
}
