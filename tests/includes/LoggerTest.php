<?php
/**
 * Sensitive context redaction and opt-out tests.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\Logger;

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\Logger */
class LoggerTest extends TestCase {
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Logger::$logger = null;
		Logger::$log_level = null;
		Logger::$threshold = null;
		$this->logger = new class() {
			public $entries = array();
			public function log( $level, $message, $context ) {
				$this->entries[] = array( $level, $message, $context );
			}
		};
		Functions\when( 'wc_get_logger' )->justReturn( $this->logger );
		Logger::$threshold = 'debug';
	}

	protected function tearDown(): void {
		Logger::$logger = null;
		Logger::$log_level = null;
		Logger::$threshold = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_redacts_hit_key_and_receipt(): void {
		$context = array( 'order_id' => 42 );
		$keys = array( 'hit_key', 'key', 'user', 'hit_user', 'receipt', 'token', 'secret', 'authorization', 'password', 'bearer' );
		foreach ( $keys as $key ) {
			$context[ $key ] = 'sensitive-' . $key;
		}
		$context['nested'] = array( 'HIT_KEY' => 'nested-secret', 'receipt' => "PRIVATE RECEIPT\nNZD 10.00" );
		Logger::log( 'HIT transaction', $context, 'success' );
		$this->assertCount( 1, $this->logger->entries );
		list( $level, $line, $source ) = $this->logger->entries[0];
		foreach ( $keys as $key ) {
			$this->assertStringNotContainsString( 'sensitive-' . $key, $line );
		}
		$this->assertStringNotContainsString( 'nested-secret', $line );
		$this->assertStringNotContainsString( 'PRIVATE RECEIPT', $line );
		$this->assertStringContainsString( '"hit_key":"***"', $line );
		$this->assertStringContainsString( '"receipt":"***"', $line );
		$this->assertStringContainsString( '"order_id":42', $line );
		$this->assertSame( 'info', $level );
		$this->assertSame( array( 'source' => 'windcave-terminal' ), $source );
	}

	public function test_logging_filter_can_disable(): void {
		Filters\expectApplied( 'wctwc_logging' )->once()->with( true, 'suppressed' )->andReturn( false );
		Logger::log( 'suppressed', array( 'hit_key' => 'secret' ), 'error' );
		$this->assertSame( array(), $this->logger->entries );
		$this->assertNull( Logger::$logger );
	}

	public function test_source_is_windcave_terminal(): void {
		Logger::log( 'source' );
		$this->assertSame( array( 'source' => 'windcave-terminal' ), $this->logger->entries[0][2] );
	}

	public function test_off_logs_nothing(): void {
		Logger::$threshold = 'off';
		Logger::log( 'error', array(), 'error' );
		Logger::xml( 'body', '<Scr/>', array(), 'critical' );
		$this->assertSame( array(), $this->logger->entries );
		$this->assertNull( Logger::$logger );
	}

	public function test_errors_level_drops_info_and_debug(): void {
		Logger::$threshold = 'errors';
		foreach ( array( 'info', 'debug', 'warning', 'error', 'critical' ) as $level ) {
			Logger::log( $level, array(), $level );
			Logger::xml( $level, '<Scr/>', array(), $level );
		}
		$this->assertSame( array( 'warning', 'warning', 'error', 'error', 'critical', 'critical' ), array_column( $this->logger->entries, 0 ) );
	}

	public function test_debug_logs_everything(): void {
		$levels = array( 'info', 'debug', 'warning', 'error', 'critical' );
		foreach ( $levels as $level ) {
			Logger::log( $level, array(), $level );
		}
		$this->assertSame( $levels, array_column( $this->logger->entries, 0 ) );
	}

	public function test_threshold_reads_setting_once(): void {
		Logger::$threshold = null;
		Functions\expect( 'get_option' )->once()->with( 'woocommerce_windcave_terminal_for_woocommerce_settings', array() )->andReturn( array( 'log_level' => 'off' ) );
		Logger::log( 'first' );
		Logger::xml( 'second', '<Scr/>' );
		$this->assertSame( array(), $this->logger->entries );
	}

	public function test_redact_xml_masks_key_attribute(): void {
		foreach ( array( "'", '"' ) as $quote ) {
			$this->assertSame( '<Scr key="***"><broken', Logger::redact_xml( '<Scr key=' . $quote . 'secret&amp;key' . $quote . '><broken' ) );
		}
	}

	public function test_redact_xml_keeps_last_four_of_cn(): void {
		$this->assertSame( '<CN>************1111</CN>', Logger::redact_xml( '<CN>411111******1111</CN>' ) );
		$this->assertSame( '<CN>************1111</CN>', Logger::redact_xml( '<CN>4111111111111111</CN>' ) );
	}

	public function test_redact_xml_masks_cardholder(): void {
		$this->assertSame( '<CH>***</CH>', Logger::redact_xml( '<CH>VISA TEST CARD/</CH>' ) );
	}

	public function test_redact_xml_masks_receipt_card_digits_keeps_last_four(): void {
		$xml = file_get_contents( dirname( __DIR__ ) . '/fixtures/hit/status-approved.xml' );
		$redacted = Logger::redact_xml( $xml );
		$this->assertStringNotContainsString( '411111******1111', $redacted );
		$this->assertStringContainsString( 'SWIPE VISA CARD ************1111', $redacted );
		$this->assertSame( substr_count( $xml, "\n" ), substr_count( $redacted, "\n" ) );
		$this->assertSame( '<Rcpt>**** **** **** 1111</Rcpt>', Logger::redact_xml( '<Rcpt>4111 1111 1111 1111</Rcpt>' ) );
	}

	public function test_redact_xml_keeps_user_attribute(): void {
		$this->assertSame( '<Scr user="support-user" key="***"/>', Logger::redact_xml( '<Scr user="support-user" key="secret"/>' ) );
	}

	public function test_xml_allows_long_bodies_up_to_cap(): void {
		$body = '<Scr>' . str_repeat( 'x', 2000 ) . '</Scr>';
		Logger::xml( 'HIT response', $body );
		$this->assertSame( "HIT response:\n" . $body, $this->logger->entries[0][1] );
		Logger::xml( 'HIT response', str_repeat( 'x', 21000 ), array( 'hit_key' => 'secret' ) );
		$this->assertSame( 20000, strlen( explode( ' {', $this->logger->entries[1][1] )[0] ) );
		$this->assertStringContainsString( '"hit_key":"***"', $this->logger->entries[1][1] );
		Logger::xml( 'auth', '<Scr>Bearer private_token</Scr>' );
		$this->assertStringNotContainsString( 'private_token', $this->logger->entries[2][1] );
		Logger::log( str_repeat( 'x', 2000 ) );
		$this->assertSame( str_repeat( 'x', 1000 ) . '…', $this->logger->entries[3][1] );
	}

	public function test_xml_respects_logging_filter(): void {
		Filters\expectApplied( 'wctwc_logging' )->once()->with( true, 'HIT request' )->andReturn( false );
		Logger::xml( 'HIT request', '<Scr/>' );
		$this->assertSame( array(), $this->logger->entries );
	}

	public function test_context_masks_card_data_and_preserves_boolean_flags(): void {
		Logger::log( 'response', array( 'card_number' => '411111******1111', 'CH' => 'VISA TEST CARD/', 'hit_key_set' => true, 'hit_user_set' => false ) );
		$line = $this->logger->entries[0][1];
		$this->assertStringNotContainsString( '411111', $line );
		$this->assertStringNotContainsString( 'VISA TEST CARD/', $line );
		$this->assertStringContainsString( '"card_number":"************1111"', $line );
		$this->assertStringContainsString( '"hit_key_set":true', $line );
		$this->assertStringContainsString( '"hit_user_set":false', $line );
	}

	public function test_environment_reports_versions_and_flags(): void {
		Functions\when( 'get_option' )->justReturn( array( 'environment' => 'production', 'stations' => "S1\nS2\nS1", 'hit_user' => 'user', 'hit_key' => 'key', 'fprn_enabled' => 'yes', 'log_level' => 'errors' ) );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		$this->assertSame( array(
			'plugin' => WCTWC_VERSION, 'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '', 'wordpress' => '6.8', 'php' => PHP_VERSION,
			'environment' => 'production', 'station_count' => 2, 'fprn_enabled' => true, 'log_level' => 'errors', 'hit_user_set' => true, 'hit_key_set' => true,
		), Logger::environment() );
		$this->assertEqualsWithDelta( 25, Logger::elapsed_ms( microtime( true ) - 0.025 ), 5 );
	}
}
