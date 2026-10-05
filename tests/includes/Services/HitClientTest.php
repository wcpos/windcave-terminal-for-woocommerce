<?php
/**
 * Tests for the Windcave HIT client.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		/** Minimal WordPress error for unit tests. */
		class WP_Error {
			private $code;
			private $message;
			private $data;

			/**
			 * Store the error details.
			 *
			 * @param string $code    Error code.
			 * @param string $message Error message.
			 * @param mixed  $data    Error data.
			 */
			public function __construct( $code, $message, $data = null ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			/** Get the error code. */
			public function get_error_code() {
				return $this->code;
			}

			/** Get the error message. */
			public function get_error_message() {
				return $this->message;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		/** Check for a WordPress error. */
		function is_wp_error( $thing ) {
			return $thing instanceof WP_Error;
		}
	}
}

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Services {
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use DOMDocument;
	use PHPUnit\Framework\TestCase;
	use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitClient;
	use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitResponse;
	use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;

	/**
	 * @covers \WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitClient
	 */
	class HitClientTest extends TestCase {
		/** @var Settings */
		private $settings;

		/** @var int */
		private $response_code;

		/** @var string */
		private $response_body;

		/**
		 * Set up the transport response and settings.
		 */
		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->settings      = new Settings(
				array(
					'environment' => 'uat',
					'hit_user'    => 'user1',
					'hit_key'     => 'key&1',
					'vendor_id'   => '',
					'pos_name'    => 'WCPOS',
				)
			);
			$this->response_code = 200;
			$this->response_body = '<Scr/>';
			Functions\when( 'wp_remote_retrieve_response_code' )->alias( function () { return $this->response_code; } );
			Functions\when( 'wp_remote_retrieve_body' )->alias( function () { return $this->response_body; } );
		}

		/**
		 * Clear function expectations.
		 */
		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * Purchase XML escapes attributes and orders fields for HIT.
		 */
		public function test_purchase_request_xml(): void {
			$request = array();
			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
				function ( $url, $args ) use ( &$request ) {
					$request = array( 'url' => $url, 'args' => $args );
					return array();
				}
			);

			$response = ( new HitClient( $this->settings ) )->purchase( 'station1', 'ref1', '12.50', 'NZD', 'merchant1' );
			$this->assertInstanceOf( HitResponse::class, $response );
			$this->assertSame( Settings::ENDPOINT_UAT, $request['url'] );
			$this->assertSame( 30, $request['args']['timeout'] );
			$this->assertSame( 'text/xml; charset=utf-8', $request['args']['headers']['Content-Type'] );
			$this->assertStringNotContainsString( '<?xml', $request['args']['body'] );

			$document = new DOMDocument();
			$this->assertTrue( $document->loadXML( $request['args']['body'] ) );
			$this->assertSame( 'Scr', $document->documentElement->nodeName );
			$this->assertSame( 'doScrHIT', $document->documentElement->getAttribute( 'action' ) );
			$this->assertSame( 'user1', $document->documentElement->getAttribute( 'user' ) );
			$this->assertSame( 'key&1', $document->documentElement->getAttribute( 'key' ) );
			$elements = iterator_to_array( $document->documentElement->childNodes );
			$this->assertSame( array( 'Amount', 'Cur', 'TxnType', 'Station', 'TxnRef', 'DeviceId', 'PosName', 'PosVersion', 'MRef' ), array_map( function ( $element ) { return $element->nodeName; }, $elements ) );
			$this->assertSame( '12.50', $elements[0]->textContent );
			$this->assertSame( 'NZD', $elements[1]->textContent );
			$this->assertSame( 'Purchase', $elements[2]->textContent );
			$this->assertSame( WCTWC_VERSION, $elements[7]->textContent );
		}

		/**
		 * A configured vendor ID follows the POS version.
		 */
		public function test_purchase_includes_vendor_id_when_set(): void {
			$settings = new Settings( array( 'vendor_id' => 'vendor1' ) );
			$body     = '';
			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
				function ( $url, $args ) use ( &$body ) {
					$body = $args['body'];
					return array();
				}
			);

			( new HitClient( $settings ) )->purchase( 'station1', 'ref1', '12.50', 'NZD', 'merchant1' );
			$document = new DOMDocument();
			$this->assertTrue( $document->loadXML( $body ) );
			$this->assertSame( 'vendor1', $document->getElementsByTagName( 'VendorId' )->item( 0 )->textContent );
			$this->assertSame( array( 'Amount', 'Cur', 'TxnType', 'Station', 'TxnRef', 'DeviceId', 'PosName', 'PosVersion', 'VendorId', 'MRef' ), array_map( function ( $element ) { return $element->nodeName; }, iterator_to_array( $document->documentElement->childNodes ) ) );
		}

		/**
		 * Merchant references are limited to 64 characters, not bytes.
		 */
		public function test_merchant_ref_is_truncated_to_64_chars(): void {
			$body = '';
			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
				function ( $url, $args ) use ( &$body ) {
					$body = $args['body'];
					return array();
				}
			);

			( new HitClient( $this->settings ) )->purchase( 'station1', 'ref1', '12.50', 'NZD', str_repeat( 'é', 65 ) );
			$document = new DOMDocument();
			$this->assertTrue( $document->loadXML( $body ) );
			$this->assertSame( str_repeat( 'é', 64 ), $document->getElementsByTagName( 'MRef' )->item( 0 )->textContent );
		}

		/**
		 * Refund XML places the original transaction reference after TxnRef.
		 */
		public function test_refund_request_includes_dps_txn_ref_after_txn_ref(): void {
			$body = '';
			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
				function ( $url, $args ) use ( &$body ) {
					$body = $args['body'];
					return array();
				}
			);

			( new HitClient( $this->settings ) )->refund( 'station1', 'ref2', '12.50', 'NZD', 'original1', 'merchant1' );
			$document = new DOMDocument();
			$this->assertTrue( $document->loadXML( $body ) );
			$this->assertSame( array( 'Amount', 'Cur', 'TxnType', 'Station', 'TxnRef', 'DpsTxnRef', 'DeviceId', 'PosName', 'PosVersion', 'MRef' ), array_map( function ( $element ) { return $element->nodeName; }, iterator_to_array( $document->documentElement->childNodes ) ) );
			$this->assertSame( 'Refund', $document->getElementsByTagName( 'TxnType' )->item( 0 )->textContent );
			$this->assertSame( 'original1', $document->getElementsByTagName( 'DpsTxnRef' )->item( 0 )->textContent );
		}

		/**
		 * Status XML contains only the three ordered fields.
		 */
		public function test_status_request_xml(): void {
			$body = '';
			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
				function ( $url, $args ) use ( &$body ) {
					$body = $args['body'];
					return array();
				}
			);

			( new HitClient( $this->settings ) )->status( 'station1', 'ref1' );
			$document = new DOMDocument();
			$this->assertTrue( $document->loadXML( $body ) );
			$this->assertSame( array( 'Station', 'TxnType', 'TxnRef' ), array_map( function ( $element ) { return $element->nodeName; }, iterator_to_array( $document->documentElement->childNodes ) ) );
			$this->assertSame( 'Status', $document->getElementsByTagName( 'TxnType' )->item( 0 )->textContent );
		}

		/**
		 * UI XML contains the button response in order.
		 */
		public function test_ui_request_xml(): void {
			$body = '';
			Functions\expect( 'wp_remote_post' )->once()->andReturnUsing(
				function ( $url, $args ) use ( &$body ) {
					$body = $args['body'];
					return array();
				}
			);

			( new HitClient( $this->settings ) )->ui( 'station1', 'ref1', 'B1', 'YES' );
			$document = new DOMDocument();
			$this->assertTrue( $document->loadXML( $body ) );
			$this->assertSame( array( 'Station', 'TxnType', 'UiType', 'Name', 'Val', 'TxnRef' ), array_map( function ( $element ) { return $element->nodeName; }, iterator_to_array( $document->documentElement->childNodes ) ) );
			$this->assertSame( 'Bn', $document->getElementsByTagName( 'UiType' )->item( 0 )->textContent );
			$this->assertSame( 'B1', $document->getElementsByTagName( 'Name' )->item( 0 )->textContent );
			$this->assertSame( 'YES', $document->getElementsByTagName( 'Val' )->item( 0 )->textContent );
		}

		/**
		 * Invalid button responses do not reach the transport.
		 */
		public function test_ui_rejects_invalid_button_or_value(): void {
			Functions\expect( 'wp_remote_post' )->never();
			$client = new HitClient( $this->settings );

			foreach ( array( array( 'B3', 'YES' ), array( 'B1', 'MAYBE' ) ) as $invalid ) {
				$response = $client->ui( 'station1', 'ref1', $invalid[0], $invalid[1] );
				$this->assertInstanceOf( \WP_Error::class, $response );
				$this->assertSame( 'wctwc_hit_invalid_ui', $response->get_error_code() );
			}
		}

		/**
		 * A non-200 HTTP status is reported with the HIT HTTP error code.
		 */
		public function test_http_error_status_returns_wp_error(): void {
			$this->response_code = 500;
			Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );

			$response = ( new HitClient( $this->settings ) )->status( 'station1', 'ref1' );
			$this->assertInstanceOf( \WP_Error::class, $response );
			$this->assertSame( 'wctwc_hit_http', $response->get_error_code() );
		}

		/**
		 * Network errors pass through unchanged.
		 */
		public function test_wp_error_from_transport_is_returned(): void {
			$error = new \WP_Error( 'network_down', 'Network unavailable.' );
			Functions\expect( 'wp_remote_post' )->once()->andReturn( $error );

			$this->assertSame( $error, ( new HitClient( $this->settings ) )->status( 'station1', 'ref1' ) );
		}

		/**
		 * A successful status request returns the parsed terminal response.
		 */
		public function test_status_returns_parsed_response(): void {
			$this->response_body = file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/hit/status-in-progress.xml' );
			Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );

			$response = ( new HitClient( $this->settings ) )->status( 'station1', 'ref1' );
			$this->assertInstanceOf( HitResponse::class, $response );
			$this->assertSame( 5, $response->txn_status_id() );
		}
	}
}
