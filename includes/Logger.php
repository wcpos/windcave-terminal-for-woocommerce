<?php
/**
 * Redacted WooCommerce status logging.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal;

/**
 * Logger for the Windcave Terminal integration.
 *
 * Follows the WooCommerce POS terminal-gateway logging convention shared by the
 * Stripe, SumUp, PayArc and Square terminal plugins: everything is written to
 * the WooCommerce status logs (WooCommerce → Status → Logs, source
 * "windcave-terminal"). Sensitive values are redacted, as the
 * PayArc/Square loggers also do, because this plugin logs Windcave API payloads.
 */
class Logger {
	/**
	 * WooCommerce log source.
	 */
	public const WC_LOG_FILENAME = 'windcave-terminal';

	/**
	 * Cached threshold, overridable in tests.
	 *
	 * @var null|string
	 */
	public static $threshold = null;

	/**
	 * Cached WooCommerce logger.
	 *
	 * @var null|\WC_Logger
	 */
	public static $logger;

	/**
	 * Default log level.
	 *
	 * @var null|string
	 */
	public static $log_level;

	/**
	 * Set the default log level.
	 *
	 * @param string $level Log level.
	 */
	public static function set_log_level( $level ): void {
		self::$log_level = $level;
	}

	/**
	 * Write a redacted message to the WooCommerce status logs.
	 *
	 * Argument order matches the PayArc terminal plugin's logger
	 * (message, context, level) so the family stays consistent.
	 *
	 * @param mixed  $message Message to log (non-strings are stringified).
	 * @param array  $context Extra context appended to the message, redacted.
	 * @param string $level   PSR-3 level; the internal "success" maps to "info".
	 */
	public static function log( $message, array $context = array(), string $level = '' ): void {
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'wctwc_logging', true, $message ) ) {
			return;
		}
		if ( '' === $level ) {
			$level = self::$log_level ? self::$log_level : 'info';
		}
		if ( null === self::$threshold ) {
			self::$threshold = ( new Settings() )->log_level();
		}
		if ( 'off' === self::$threshold || ( 'errors' === self::$threshold && ! in_array( $level, array( 'error', 'critical', 'warning' ), true ) ) ) {
			return;
		}
		if ( ! is_string( $message ) ) {
			$message = print_r( $message, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}
		$line = self::redact( (string) $message );
		$line = strlen( $line ) > 1000 ? substr( $line, 0, 1000 ) . '…' : $line;
		if ( ! empty( $context ) && function_exists( 'wp_json_encode' ) ) {
			$line .= ' ' . wp_json_encode( self::redact_context( $context ) );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}
			self::$logger->log( self::wc_level( $level ), $line, array( 'source' => self::WC_LOG_FILENAME ) );
			return;
		}
		if ( function_exists( 'error_log' ) ) {
			error_log( '[' . self::WC_LOG_FILENAME . '] [' . $level . '] ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Log a redacted HIT XML body with a larger message cap.
	 *
	 * @param string $label   Message label.
	 * @param string $xml     Request or response body.
	 * @param array  $context Diagnostic context.
	 * @param string $level   Log level.
	 */
	public static function xml( string $label, string $xml, array $context = array(), string $level = 'debug' ): void {
		if ( null === self::$threshold ) {
			self::$threshold = ( new Settings() )->log_level();
		}
		if ( 'off' === self::$threshold || ( 'errors' === self::$threshold && ! in_array( $level, array( 'error', 'critical', 'warning' ), true ) ) ) {
			return;
		}
		if ( function_exists( 'apply_filters' ) && ! apply_filters( 'wctwc_logging', true, $label ) ) {
			return;
		}
		$line = substr( self::redact( $label . ":\n" . self::redact_xml( $xml ) ), 0, 20000 );
		if ( ! empty( $context ) && function_exists( 'wp_json_encode' ) ) {
			$line .= ' ' . wp_json_encode( self::redact_context( $context ) );
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}
			self::$logger->log( self::wc_level( $level ), $line, array( 'source' => self::WC_LOG_FILENAME ) );
		} else {
			error_log( '[' . self::WC_LOG_FILENAME . '] [' . $level . '] ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Mask credentials and card data even in malformed XML.
	 *
	 * @param string $xml XML body.
	 * @return string Redacted body.
	 */
	public static function redact_xml( string $xml ): string {
		$xml = preg_replace( '/\bkey\s*=\s*([\'"]).*?\1/is', 'key="***"', $xml );
		$xml = preg_replace_callback(
			'/(<CN>)(.*?)(<\/CN>)/is',
			function ( $match ) {
				return $match[1] . str_repeat( '*', max( 0, strlen( $match[2] ) - 4 ) ) . substr( $match[2], -4 ) . $match[3];
			},
			$xml
		);
		$xml = preg_replace( '/(<CH>).*?(<\/CH>)/is', '$1***$2', $xml );
		return preg_replace_callback(
			'/(<Rcpt>)(.*?)(<\/Rcpt>)/is',
			function ( $receipt ) {
				return $receipt[1] . preg_replace_callback(
					'/[0-9* ]{8,}/',
					function ( $run ) {
						$remaining = strlen( preg_replace( '/[^0-9]/', '', $run[0] ) ) - 4;
						return preg_replace_callback(
							'/[0-9]/',
							function ( $digit ) use ( &$remaining ) {
								return $remaining-- > 0 ? '*' : $digit[0];
							},
							$run[0]
						);
					},
					$receipt[2]
				) . $receipt[3];
			},
			$xml
		);
	}

	/**
	 * Report versions and configuration flags without credentials.
	 *
	 * @return array Diagnostic environment.
	 */
	public static function environment(): array {
		$settings = new Settings();
		return array(
			'plugin'        => WCTWC_VERSION,
			'woocommerce'   => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'wordpress'     => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '',
			'php'           => PHP_VERSION,
			'environment'   => $settings->environment(),
			'station_count' => count( $settings->station_ids() ),
			'fprn_enabled'  => $settings->fprn_enabled(),
			'log_level'     => $settings->log_level(),
			'hit_user_set'  => '' !== $settings->hit_user(),
			'hit_key_set'   => '' !== $settings->hit_key(),
		);
	}

	/**
	 * Measure elapsed milliseconds.
	 *
	 * @param float $started Start time from microtime.
	 * @return int Elapsed milliseconds.
	 */
	public static function elapsed_ms( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	/**
	 * Convenience wrapper for error-level logging.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Extra context.
	 */
	public static function log_api_error( string $message, array $context = array() ): void {
		self::log( $message, $context, 'error' );
	}

	/**
	 * Redact credentials.
	 *
	 * @param string $value Message text.
	 * @return string Redacted text.
	 */
	public static function redact( string $value ): string {
		$value = preg_replace( '/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer ***', $value );
		$value = preg_replace( '/(test|live)_[A-Za-z0-9]{20,}/', '$1_***', $value );
		return $value;
	}

	/**
	 * Recursively redact sensitive context values.
	 *
	 * @param array $context Log context.
	 * @return array Redacted context.
	 */
	private static function redact_context( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( in_array( $key, array( 'hit_user_set', 'hit_key_set' ), true ) && is_bool( $value ) ) {
				continue;
			} elseif ( in_array( strtolower( (string) $key ), array( 'card_number', 'cn' ), true ) ) {
				$context[ $key ] = str_repeat( '*', max( 0, strlen( (string) $value ) - 4 ) ) . substr( (string) $value, -4 );
			} elseif ( 'ch' === strtolower( (string) $key ) || 'cardholder' === strtolower( (string) $key ) || self::is_sensitive_key( (string) $key ) ) {
				$context[ $key ] = '***';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact_context( $value );
			} elseif ( is_string( $value ) ) {
				$context[ $key ] = self::redact( $value );
				$context[ $key ] = strlen( $context[ $key ] ) > 1000 ? substr( $context[ $key ], 0, 1000 ) . '…' : $context[ $key ];
			}
		}
		return $context;
	}

	/**
	 * Identify context keys containing sensitive values.
	 *
	 * @param string $key Context key.
	 * @return bool Whether the key is sensitive.
	 */
	private static function is_sensitive_key( string $key ): bool {
		foreach ( array( 'key', 'token', 'secret', 'authorization', 'password', 'bearer', 'hit_key', 'user', 'hit_user', 'receipt' ) as $needle ) {
			if ( false !== stripos( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map the internal "success" level onto a PSR-3 / WC_Logger level.
	 *
	 * @param string $level Requested log level.
	 * @return string WooCommerce log level.
	 */
	private static function wc_level( string $level ): string {
		$level = in_array( $level, array( 'debug', 'info', 'success', 'warning', 'error', 'critical' ), true ) ? $level : 'info';
		return 'success' === $level ? 'info' : $level;
	}
}
