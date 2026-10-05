<?php
/**
 * Atomic payment claim tests.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentLock;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeWpdb;

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\PaymentLock */
class PaymentLockTest extends TestCase {
	private $previous_wpdb;
	private const KEY = 'wctwc_lock_order_42_payment';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new FakeWpdb();
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		} );
		Functions\when( 'wp_generate_uuid4' )->alias( function () {
			static $n = 0;
			return 'uuid-' . ++$n;
		} );
	}

	protected function tearDown(): void {
		PaymentLock::release( 42, 'payment' );
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_acquire_is_exclusive_until_release(): void {
		$this->assertTrue( PaymentLock::acquire( 42, 'payment' ) );
		$this->assertFalse( PaymentLock::acquire( 42, 'payment' ) );
		PaymentLock::release( 42, 'payment' );
		$this->assertTrue( PaymentLock::acquire( 42, 'payment' ) );
	}

	public function test_expired_lock_can_be_taken_over(): void {
		$GLOBALS['wpdb']->rows[ self::KEY ] = json_encode( array( 'token' => 'expired', 'expires_at' => time() - 1 ) );
		$this->assertTrue( PaymentLock::acquire( 42, 'payment' ) );
		$claim = json_decode( $GLOBALS['wpdb']->rows[ self::KEY ], true );
		$this->assertNotSame( 'expired', $claim['token'] );
		$this->assertGreaterThan( time(), $claim['expires_at'] );
		$this->assertFalse( PaymentLock::acquire( 42, 'payment' ) );
	}

	public function test_release_does_not_delete_a_replacement_claim(): void {
		$this->assertTrue( PaymentLock::acquire( 42, 'payment' ) );
		$replacement = json_encode( array( 'token' => 'another-request', 'expires_at' => time() + 30 ) );
		$GLOBALS['wpdb']->rows[ self::KEY ] = $replacement;
		PaymentLock::release( 42, 'payment' );
		$this->assertSame( $replacement, $GLOBALS['wpdb']->rows[ self::KEY ] );
		$this->assertFalse( PaymentLock::acquire( 42, 'payment' ) );
	}

	public function test_with_lock_throws_when_held(): void {
		$this->assertTrue( PaymentLock::acquire( 42, 'payment' ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Another Windcave Terminal operation is already running for this order.' );
		PaymentLock::with_lock( 42, 'payment', function () {
			$this->fail( 'A held claim must prevent the callback from running.' );
		} );
	}
}
