<?php
/**
 * Windcave terminal gateway shell.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

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
		);
	}
}
