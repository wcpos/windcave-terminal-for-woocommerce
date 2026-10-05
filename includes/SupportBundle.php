<?php
/**
 * Downloadable diagnostics for Windcave terminal support.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

/**
 * Collect masked settings, attempts and recent logs.
 */
class SupportBundle {
	/** At most the last 1 MB of each log file: debug logs grow large and the bundle must work on small hosts. */
	private const TAIL_BYTES = 1048576;

	/**
	 * Build the support bundle.
	 *
	 * @return array Support diagnostics.
	 */
	public function build(): array {
		$settings                   = get_option( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$key                        = (string) ( $settings['hit_key'] ?? '' );
		$settings['hit_key']        = '' !== $key ? '***' : '';
		$settings['hit_key_length'] = strlen( $key );
		$settings['hit_user']       = substr( (string) ( $settings['hit_user'] ?? '' ), 0, 3 ) . '***';
		$recent_attempts            = array();
		$orders                     = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'limit'        => 20,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'meta_key'     => PaymentAttempt::META_ATTEMPTS,
				'meta_compare' => 'EXISTS',
			)
		);
		foreach ( $orders as $order ) {
			$attempts = PaymentAttempt::history( $order );
			foreach ( $attempts as &$attempt ) {
				unset( $attempt['receipt'] );
			}
			unset( $attempt );
			$recent_attempts[] = array(
				'order_id'       => $order->get_id(),
				'status'         => $order->get_status(),
				'payment_method' => $order->get_payment_method(),
				'total'          => $order->get_total(),
				'currency'       => $order->get_currency(),
				'transaction_id' => $order->get_transaction_id(),
				'is_paid'        => $order->is_paid(),
				'attempts'       => $attempts,
			);
		}

		$log_dir = $this->log_dir();
		$files   = '' !== $log_dir ? glob( rtrim( $log_dir, '/\\' ) . '/' . Logger::WC_LOG_FILENAME . '-*.log' ) : array();
		$files   = $files ? $files : array();
		usort(
			$files,
			static function ( $first, $second ) {
				return filemtime( $first ) <=> filemtime( $second );
			}
		);
		$files = array_slice( $files, -2 );
		$lines = array();
		foreach ( $files as $file ) {
			$lines = array_merge( $lines, $this->tail_lines( $file ) );
		}

		return array(
			'generated_at'    => gmdate( 'c' ),
			'plugin'          => 'windcave-terminal-for-woocommerce',
			'environment'     => Logger::environment(),
			'settings'        => $settings,
			'recent_attempts' => $recent_attempts,
			'log_files'       => array_map( 'basename', $files ),
			'log_tail'        => Logger::redact_xml( implode( "\n", array_slice( $lines, -1000 ) ) ),
			'notes'           => empty( $files ) ? array( 'No windcave-terminal log files found in the log directory. If WooCommerce uses the database log handler, export the logs from WooCommerce → Status → Logs.' ) : array(),
		);
	}

	/**
	 * Read the end of a log file.
	 *
	 * @param string $file Log file path.
	 * @return array Log lines.
	 */
	private function tail_lines( string $file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- A log may disappear before opening; a stream is needed for bounded reads.
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return array();
		}
		$size = fstat( $handle )['size'];
		if ( $size > self::TAIL_BYTES ) {
			if ( 0 !== fseek( $handle, $size - self::TAIL_BYTES ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the log stream on seek failure.
				fclose( $handle );
				return array();
			}
			fgets( $handle );
		}
		$contents = stream_get_contents( $handle, self::TAIL_BYTES );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the bounded log stream.
		fclose( $handle );
		$lines = explode( "\n", $contents );
		if ( '' === end( $lines ) ) {
			array_pop( $lines );
		}
		return $lines;
	}

	/**
	 * Get the WooCommerce log directory.
	 *
	 * @return string Log directory.
	 */
	protected function log_dir(): string {
		return (string) apply_filters( 'wctwc_support_log_dir', defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : '' );
	}

	/**
	 * Stream a bundle to an authorized administrator.
	 */
	public function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'wctwc_support_bundle' ) ) {
			wp_die( esc_html__( 'You are not allowed to download the support bundle.', 'windcave-terminal-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		nocache_headers();
		$this->send_header( 'Content-Type: application/json; charset=utf-8' );
		$this->send_header( 'Content-Disposition: attachment; filename="windcave-terminal-support-' . gmdate( 'YmdHis' ) . '.json"' );
		echo wp_json_encode( $this->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$this->terminate();
	}

	/**
	 * Emit a download header.
	 *
	 * @param string $header HTTP header.
	 */
	protected function send_header( string $header ): void {
		header( $header );
	}

	/**
	 * End the download request.
	 */
	protected function terminate(): void {
		exit;
	}

	/**
	 * Get the nonce-protected download URL.
	 *
	 * @return string Download URL.
	 */
	public static function download_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=wctwc_support_bundle' ), 'wctwc_support_bundle' );
	}

	/**
	 * Get the WooCommerce logs URL for this plugin.
	 *
	 * @return string Logs URL.
	 */
	public static function logs_url(): string {
		return admin_url( 'admin.php?page=wc-status&tab=logs&source=' . Logger::WC_LOG_FILENAME );
	}
}
