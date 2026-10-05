<?php
/**
 * Tests for signed order payment tokens.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentRequestToken;

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\PaymentRequestToken */
class PaymentRequestTokenTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'test-auth-salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_for_order_verifies_for_same_order(): void {
		$before = time();
		$token = PaymentRequestToken::for_order( 42 );
		$this->assertTrue( PaymentRequestToken::verify( $token, 42 ) );
		$payload = json_decode( base64_decode( explode( '.', $token )[0] ), true );
		$this->assertSame( 8 * 3600, PaymentRequestToken::TTL );
		$this->assertGreaterThanOrEqual( $before + PaymentRequestToken::TTL, $payload['expires'] );
		$this->assertLessThanOrEqual( time() + PaymentRequestToken::TTL, $payload['expires'] );
	}

	public function test_rejects_other_order(): void {
		$this->assertFalse( PaymentRequestToken::verify( PaymentRequestToken::for_order( 42 ), 43 ) );
	}

	public function test_rejects_expired(): void {
		$this->assertFalse( PaymentRequestToken::verify( PaymentRequestToken::create( 42, time() - 1 ), 42 ) );
	}

	public function test_rejects_tampered_signature(): void {
		$token = PaymentRequestToken::for_order( 42 );
		$token = substr( $token, 0, -1 ) . ( '0' === substr( $token, -1 ) ? '1' : '0' );
		$this->assertFalse( PaymentRequestToken::verify( $token, 42 ) );
	}
}
