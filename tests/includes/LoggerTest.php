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
		$this->logger = new class() {
			public $entries = array();
			public function log( $level, $message, $context ) {
				$this->entries[] = array( $level, $message, $context );
			}
		};
		Functions\when( 'wc_get_logger' )->justReturn( $this->logger );
	}

	protected function tearDown(): void {
		Logger::$logger = null;
		Logger::$log_level = null;
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
		$this->assertSame( array( 'source' => 'windcave-terminal-for-woocommerce' ), $source );
	}

	public function test_logging_filter_can_disable(): void {
		Filters\expectApplied( 'wctwc_logging' )->once()->with( true, 'suppressed' )->andReturn( false );
		Logger::log( 'suppressed', array( 'hit_key' => 'secret' ), 'error' );
		$this->assertSame( array(), $this->logger->entries );
		$this->assertNull( Logger::$logger );
	}
}
