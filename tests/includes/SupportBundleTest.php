<?php
/**
 * Support bundle privacy and download tests.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\Logger;
use WCPOS\WooCommercePOS\WindcaveTerminal\PaymentAttempt;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\WindcaveTerminal\SupportBundle;
use WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support\FakeOrder;

/** @covers \WCPOS\WooCommercePOS\WindcaveTerminal\SupportBundle */
class SupportBundleTest extends TestCase {
	private $log_dir;
	private $orders;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->log_dir = sys_get_temp_dir() . '/wctwc-support-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->log_dir );
		Filters\expectApplied( 'wctwc_support_log_dir' )->andReturn( $this->log_dir );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'esc_html__' )->returnArg();
		$this->orders = array();
		Functions\when( 'wc_get_orders' )->alias( function ( $args ) {
			$this->assertSame( array(
				'type' => 'shop_order', 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC',
				'meta_key' => PaymentAttempt::META_ATTEMPTS, 'meta_compare' => 'EXISTS',
			), $args );
			return $this->orders;
		} );
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
	}

	protected function tearDown(): void {
		foreach ( glob( $this->log_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->log_dir );
		FakeOrder::$rows = array();
		FakeOrder::$completion_calls = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_settings_mask_hit_key_and_user(): void {
		$options = array( 'hit_key' => 'private-hit-key', 'hit_user' => 'merchant123', 'stations' => "S1\nS2", 'enabled' => 'no' );
		Functions\when( 'get_option' )->alias( function ( $name, $default ) use ( $options ) {
			$this->assertSame( 'woocommerce_' . Settings::GATEWAY_ID . '_settings', $name );
			return $options;
		} );
		$bundle = ( new SupportBundle() )->build();
		$this->assertSame( array( 'generated_at', 'plugin', 'environment', 'settings', 'recent_attempts', 'log_files', 'log_tail', 'notes' ), array_keys( $bundle ) );
		$this->assertSame( 'windcave-terminal-for-woocommerce', $bundle['plugin'] );
		$this->assertSame( Logger::environment(), $bundle['environment'] );
		$this->assertSame( gmdate( 'c', strtotime( $bundle['generated_at'] ) ), $bundle['generated_at'] );
		$expected = $options;
		$expected['hit_key'] = '***';
		$expected['hit_user'] = 'mer***';
		$expected['hit_key_length'] = strlen( $options['hit_key'] );
		$this->assertSame( $expected, $bundle['settings'] );
		$this->assertStringNotContainsString( $options['hit_key'], wp_json_encode( $bundle ) );
		$this->assertStringNotContainsString( $options['hit_user'], wp_json_encode( $bundle ) );
		Functions\when( 'get_option' )->justReturn( array( 'hit_key' => '' ) );
		$empty = ( new SupportBundle() )->build();
		$this->assertSame( '', $empty['settings']['hit_key'] );
		$this->assertSame( 0, $empty['settings']['hit_key_length'] );
	}

	public function test_non_array_settings_option_is_handled(): void {
		Functions\when( 'get_option' )->justReturn( false );
		$bundle = ( new SupportBundle() )->build();
		$this->assertSame( '', $bundle['settings']['hit_key'] );
		$this->assertSame( 0, $bundle['settings']['hit_key_length'] );
	}

	public function test_recent_attempts_exclude_receipts(): void {
		$history = array(
			array( 'txn_ref' => 'first', 'status' => 'declined', 'receipt' => '411111******1111', 'receipt_width' => 40 ),
			array( 'txn_ref' => 'second', 'status' => 'approved', 'receipt' => 'private receipt', 'receipt_width' => 32 ),
		);
		$order = new class( 42 ) extends FakeOrder {
			public function get_status() { return 'processing'; }
		};
		$order->set_payment_method( Settings::GATEWAY_ID );
		$order->payment_complete( 'transaction-42' );
		$order->update_meta_data( PaymentAttempt::META_ATTEMPTS, $history );
		$older = new class( 41 ) extends FakeOrder {
			public function get_status() { return 'pending'; }
		};
		$older->update_meta_data( PaymentAttempt::META_ATTEMPTS, array( $history[0] ) );
		$this->orders = array( $order, $older );
		$bundle = ( new SupportBundle() )->build();
		$json = wp_json_encode( $bundle );
		$this->assertStringNotContainsString( '"receipt":', $json );
		$this->assertStringNotContainsString( '411111******1111', $json );
		$this->assertStringNotContainsString( 'private receipt', $json );
		$expected = $history;
		unset( $expected[0]['receipt'], $expected[1]['receipt'] );
		$this->assertSame( array(
			'order_id' => 42, 'status' => 'processing', 'payment_method' => Settings::GATEWAY_ID,
			'total' => '10.00', 'currency' => 'NZD', 'transaction_id' => 'transaction-42',
			'is_paid' => true, 'attempts' => $expected,
		), $bundle['recent_attempts'][0] );
		$this->assertSame( array( 42, 41 ), array_column( $bundle['recent_attempts'], 'order_id' ) );
		$this->assertFalse( $bundle['recent_attempts'][1]['is_paid'] );
		$this->assertSame( $history, $order->get_meta( PaymentAttempt::META_ATTEMPTS ) );
	}

	public function test_log_tail_reads_two_newest_files_last_1000_lines(): void {
		file_put_contents( $this->log_dir . '/other-source-2026-10-06-hash.log', 'unrelated' );
		$names = array();
		for ( $day = 3; $day <= 5; ++$day ) {
			$name = 'windcave-terminal-2026-10-0' . $day . '-hash.log';
			$lines = array();
			for ( $line = 1; $line <= 600; ++$line ) {
				$lines[] = $day . ':' . $line;
			}
			file_put_contents( $this->log_dir . '/' . $name, implode( "\n", $lines ) . "\n" );
			touch( $this->log_dir . '/' . $name, 1700000000 + $day );
			$names[] = $name;
		}
		$bundle = ( new SupportBundle() )->build();
		$expected = array();
		for ( $line = 201; $line <= 600; ++$line ) { $expected[] = '4:' . $line; }
		for ( $line = 1; $line <= 600; ++$line ) { $expected[] = '5:' . $line; }
		$this->assertSame( array_slice( $names, -2 ), $bundle['log_files'] );
		$this->assertSame( implode( "\n", $expected ), $bundle['log_tail'] );
		$this->assertCount( 1000, explode( "\n", $bundle['log_tail'] ) );
		$this->assertSame( array(), $bundle['notes'] );
	}

	public function test_log_tail_reads_only_the_end_of_large_files(): void {
		file_put_contents(
			$this->log_dir . '/windcave-terminal-2026-10-05-hash.log',
			"FIRST-LINE-MARKER\n" . str_repeat( str_repeat( 'x', 12000 ) . "\n", 100 ) . "LAST-LINE-MARKER\n"
		);
		$bundle = ( new SupportBundle() )->build();
		$this->assertStringContainsString( 'LAST-LINE-MARKER', $bundle['log_tail'] );
		$this->assertStringNotContainsString( 'FIRST-LINE-MARKER', $bundle['log_tail'] );
	}

	public function test_log_tail_is_redacted(): void {
		file_put_contents( $this->log_dir . '/windcave-terminal-2026-10-05-hash.log', '<Request key="secret123"><CH>Jane Tester</CH><CN>4111111111111111</CN><Rcpt>Receipt 411111******1111</Rcpt></Request>' );
		$bundle = ( new SupportBundle() )->build();
		$this->assertStringContainsString( 'key="***"', $bundle['log_tail'] );
		$this->assertStringContainsString( '<CH>***</CH>', $bundle['log_tail'] );
		$this->assertStringContainsString( '<CN>************1111</CN>', $bundle['log_tail'] );
		$this->assertStringContainsString( '<Rcpt>Receipt ************1111</Rcpt>', $bundle['log_tail'] );
		foreach ( array( 'secret123', 'Jane Tester', '4111111111111111', '411111******1111' ) as $private ) {
			$this->assertStringNotContainsString( $private, wp_json_encode( $bundle ) );
		}
	}

	public function test_no_log_files_adds_note(): void {
		$bundle = ( new SupportBundle() )->build();
		$this->assertSame( array(), $bundle['log_files'] );
		$this->assertSame( '', $bundle['log_tail'] );
		$this->assertSame( array( 'No windcave-terminal log files found in the log directory. If WooCommerce uses the database log handler, export the logs from WooCommerce → Status → Logs.' ), $bundle['notes'] );
	}

	public function test_download_refuses_without_capability(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );
		Functions\expect( 'check_admin_referer' )->never();
		$this->assert_download_refused();
	}

	public function test_download_refuses_without_valid_nonce(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		Functions\expect( 'check_admin_referer' )->once()->with( 'wctwc_support_bundle' )->andReturn( false );
		$this->assert_download_refused();
	}

	private function assert_download_refused(): void {
		Functions\expect( 'nocache_headers' )->never();
		Functions\expect( 'wp_die' )->once()->with(
			'You are not allowed to download the support bundle.', '', array( 'response' => 403 )
		)->andThrow( new \RuntimeException( 'forbidden' ) );
		$download = $this->download_handler();
		ob_start();
		try {
			$download->download();
			$this->fail( 'Download should refuse access.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'forbidden', $error->getMessage() );
			$this->assertFalse( $download->built );
			$this->assertSame( array(), $download->headers );
			$this->assertSame( '', ob_get_contents() );
		} finally {
			ob_end_clean();
		}
	}

	public function test_download_sends_json_attachment_headers(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		Functions\expect( 'check_admin_referer' )->once()->with( 'wctwc_support_bundle' )->andReturn( 1 );
		Functions\expect( 'nocache_headers' )->once();
		$download = $this->download_handler();
		ob_start();
		try {
			$download->download();
			$this->fail( 'Download should terminate.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'terminated', $error->getMessage() );
			$this->assertTrue( $download->built );
			$this->assertCount( 2, $download->headers );
			$this->assertSame( 'Content-Type: application/json; charset=utf-8', $download->headers[0] );
			$this->assertMatchesRegularExpression( '/^Content-Disposition: attachment; filename="windcave-terminal-support-[0-9]{14}\.json"$/', $download->headers[1] );
			$json = ob_get_contents();
			$this->assertSame( array( 'diagnostic' => 'local/value' ), json_decode( $json, true ) );
			$this->assertSame( json_encode( array( 'diagnostic' => 'local/value' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), $json );
		} finally {
			ob_end_clean();
		}
	}

	private function download_handler(): SupportBundle {
		return new class() extends SupportBundle {
			public $built = false;
			public $headers = array();
			public function build(): array {
				$this->built = true;
				return array( 'diagnostic' => 'local/value' );
			}
			protected function send_header( string $header ): void { $this->headers[] = $header; }
			protected function terminate(): void { throw new \RuntimeException( 'terminated' ); }
		};
	}
}
