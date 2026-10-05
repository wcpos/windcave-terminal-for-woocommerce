<?php
/**
 * Windcave terminal settings.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

/**
 * Read WooCommerce and POS gateway settings.
 */
class Settings {
	public const GATEWAY_ID          = 'windcave_terminal_for_woocommerce';
	public const ENDPOINT_UAT        = 'https://uat.windcave.com/hit/pos.aspx';
	public const ENDPOINT_PRODUCTION = 'https://sec.windcave.com/hit/pos.aspx';

	/**
	 * Supplied settings, or null to read WordPress options.
	 *
	 * @var array|null
	 */
	private $options;

	/**
	 * Set an optional settings override.
	 *
	 * @param array|null $options Gateway settings.
	 */
	public function __construct( ?array $options = null ) {
		$this->options = $options;
	}

	/**
	 * Read a setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) {
		$options = $this->options;
		if ( null === $options ) {
			$options = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', array() );
		}
		return $options[ $key ] ?? $default;
	}

	/**
	 * Whether the gateway is enabled for the online store.
	 */
	public function enabled(): bool {
		return 'yes' === $this->get( 'enabled', 'no' );
	}

	/**
	 * Whether the gateway is enabled in WooCommerce POS settings.
	 */
	public function enabled_for_pos(): bool {
		$getter = function_exists( 'wcpos_get_settings' ) ? 'wcpos_get_settings' : 'woocommerce_pos_get_settings';
		if ( ! function_exists( $getter ) ) {
			return false;
		}
		$settings = $getter( 'payment_gateways' );
		return is_array( $settings ) && ! empty( $settings['gateways'][ self::GATEWAY_ID ]['enabled'] );
	}

	/**
	 * Whether the gateway is enabled for the store or POS.
	 */
	public function active(): bool {
		return $this->enabled() || $this->enabled_for_pos();
	}

	/**
	 * Get the customer-facing gateway title.
	 */
	public function title(): string {
		$title = trim( (string) $this->get( 'title', '' ) );
		if ( '' !== $title ) {
			return $title;
		}
		return __( 'Windcave Terminal', 'windcave-terminal-for-woocommerce' );
	}

	/**
	 * Get the selected Windcave environment.
	 */
	public function environment(): string {
		return 'production' === $this->get( 'environment' ) ? 'production' : 'uat';
	}

	/**
	 * Get the HIT endpoint for the selected environment.
	 */
	public function endpoint_url(): string {
		return 'production' === $this->environment() ? self::ENDPOINT_PRODUCTION : self::ENDPOINT_UAT;
	}

	/**
	 * Get the HIT username.
	 */
	public function hit_user(): string {
		return trim( (string) $this->get( 'hit_user' ) );
	}

	/**
	 * Get the HIT key.
	 */
	public function hit_key(): string {
		return trim( (string) $this->get( 'hit_key' ) );
	}

	/**
	 * Get unique Station IDs in configured order, including the default.
	 */
	public function station_ids(): array {
		$ids = array();
		foreach ( explode( "\n", (string) $this->get( 'stations' ) ) as $line ) {
			$id = trim( $line );
			if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		$default = $this->default_station();
		if ( '' !== $default && ! in_array( $default, $ids, true ) ) {
			$ids[] = $default;
		}
		return $ids;
	}

	/**
	 * Get the default Station ID.
	 */
	public function default_station(): string {
		return trim( (string) $this->get( 'default_station' ) );
	}

	/**
	 * Whether checkout must use the configured default Station.
	 */
	public function lock_station(): bool {
		return 'yes' === $this->get( 'lock_station' ) && '' !== $this->default_station();
	}

	/**
	 * Get the certified Vendor ID.
	 */
	public function vendor_id(): string {
		return trim( (string) $this->get( 'vendor_id' ) );
	}

	/**
	 * Get the POS name sent to Windcave.
	 */
	public function pos_name(): string {
		$name = trim( (string) $this->get( 'pos_name' ) );
		return '' !== $name ? $name : 'WCPOS';
	}

	/**
	 * Whether result notifications are enabled.
	 */
	public function fprn_enabled(): bool {
		return 'yes' === $this->get( 'fprn_enabled' );
	}

	/**
	 * Whether checkout log tools are shown.
	 */
	public function show_logs(): bool {
		return 'yes' === $this->get( 'show_logs' );
	}

	/**
	 * Get the diagnostic log threshold.
	 */
	public function log_level(): string {
		$level = $this->get( 'log_level', 'debug' );
		return in_array( $level, array( 'off', 'errors', 'debug' ), true ) ? $level : 'debug';
	}
}
