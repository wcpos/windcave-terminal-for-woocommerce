<?php
/**
 * Minimal WooCommerce gateway stub for unit tests.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {}
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	/**
	 * Provide the settings interface used by the gateway shell.
	 */
	class WC_Payment_Gateway {
		public $id;
		public $method_title;
		public $method_description;
		public $title;
		public $description;
		public $has_fields;
		public $supports;
		public $form_fields = array();
		public $settings = array();

		/**
		 * Load saved settings and fill missing values from field defaults.
		 */
		public function init_settings() {
			$this->settings = get_option( 'woocommerce_' . $this->id . '_settings', array() );
			foreach ( $this->form_fields as $key => $field ) {
				if ( ! array_key_exists( $key, $this->settings ) ) {
					$this->settings[ $key ] = $field['default'];
				}
			}
		}

		/**
		 * Read a gateway setting.
		 *
		 * @param string $key         Setting key.
		 * @param mixed  $empty_value Default value.
		 * @return mixed
		 */
		public function get_option( $key, $empty_value = null ) {
			return $this->settings[ $key ] ?? $empty_value;
		}

		/**
		 * Stand in for WooCommerce's settings save handler.
		 *
		 * @return bool
		 */
		public function process_admin_options() {
			return true;
		}
	}
}
