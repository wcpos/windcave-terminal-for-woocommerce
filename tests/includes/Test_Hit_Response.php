<?php
/**
 * Tests for Windcave HIT response parsing.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Includes;

use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitResponse;

/**
 * @covers \WCPOS\WooCommercePOS\WindcaveTerminal\Services\HitResponse
 */
class Test_Hit_Response extends \WP_UnitTestCase {
	/**
	 * An active terminal has no completed result or enabled buttons.
	 */
	public function test_in_progress_status_is_not_complete(): void {
		$response = HitResponse::from_xml( file_get_contents( dirname( __DIR__ ) . '/fixtures/hit/status-in-progress.xml' ) );

		$this->assertInstanceOf( HitResponse::class, $response );
		$this->assertFalse( $response->complete() );
		$this->assertSame( 5, $response->txn_status_id() );
		$this->assertSame( 'PROCESSING', $response->display_line_1() );
		$this->assertSame( 20, $response->timeout_seconds() );
		$this->assertSame( array( 'enabled' => false, 'label' => '' ), $response->button( 'B1' ) );
		$this->assertSame( array( 'enabled' => false, 'label' => '' ), $response->button( 'B2' ) );
	}

	/**
	 * Approval details and receipt preserve the published response.
	 */
	public function test_approved_status(): void {
		$response = HitResponse::from_xml( file_get_contents( dirname( __DIR__ ) . '/fixtures/hit/status-approved.xml' ) );

		$this->assertInstanceOf( HitResponse::class, $response );
		$this->assertTrue( $response->approved() );
		$this->assertSame( '000289', $response->auth_code() );
		$this->assertSame( '411111******1111', $response->card_number() );
		$this->assertSame( 'Visa', $response->card_type() );
		$this->assertSame( 100, $response->amount_cents() );
		$this->assertSame( '0000000100e1a6f9', $response->dps_txn_ref() );
		$this->assertSame( 30, $response->receipt_width() );
		$this->assertStringContainsString( "AUTHORISATION 000289\n", $response->receipt() );
		$this->assertStringContainsString( 'CUSTOMER COPY PLEASE RETAIN FOR YOUR RECORDS', $response->receipt() );
	}

	/**
	 * A complete transaction can still be declined.
	 */
	public function test_declined_status_is_not_approved(): void {
		$response = HitResponse::from_xml( file_get_contents( dirname( __DIR__ ) . '/fixtures/hit/status-declined.xml' ) );

		$this->assertInstanceOf( HitResponse::class, $response );
		$this->assertTrue( $response->complete() );
		$this->assertFalse( $response->approved() );
		$this->assertSame( '51', $response->response_code() );
	}

	/**
	 * Signature buttons expose their states and labels.
	 */
	public function test_signature_prompt_buttons(): void {
		$response = HitResponse::from_xml( file_get_contents( dirname( __DIR__ ) . '/fixtures/hit/status-signature.xml' ) );

		$this->assertInstanceOf( HitResponse::class, $response );
		$this->assertSame( array( 'enabled' => true, 'label' => 'YES' ), $response->button( 'B1' ) );
		$this->assertSame( array( 'enabled' => true, 'label' => 'NO' ), $response->button( 'B2' ) );
		$this->assertSame( array( 'enabled' => false, 'label' => '' ), $response->button( 'B3' ) );
		$this->assertSame( 'CHECK SIGNATURE', $response->display_line_2() );
	}

	/**
	 * The PC response means an existing transaction is in progress.
	 */
	public function test_pc_reco_is_existing_txn_in_progress(): void {
		$response = HitResponse::from_xml( file_get_contents( dirname( __DIR__ ) . '/fixtures/hit/reco-pc.xml' ) );

		$this->assertInstanceOf( HitResponse::class, $response );
		$this->assertTrue( $response->is_existing_txn_in_progress() );
	}

	/**
	 * A missing approval flag must never count as approval.
	 */
	public function test_complete_without_ap_is_not_approved(): void {
		$response = HitResponse::from_xml( '<Scr><Complete>1</Complete><Result></Result></Scr>' );

		$this->assertInstanceOf( HitResponse::class, $response );
		$this->assertTrue( $response->complete() );
		$this->assertFalse( $response->approved() );
	}

	/**
	 * An empty body is not a response.
	 */
	public function test_rejects_empty_body(): void {
		$response = HitResponse::from_xml( " \n " );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'wctwc_hit_parse', $response->get_error_code() );
	}

	/**
	 * Malformed XML is rejected.
	 */
	public function test_rejects_malformed_xml(): void {
		$response = HitResponse::from_xml( '<Scr><Complete>1</Scr>' );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'wctwc_hit_parse', $response->get_error_code() );
	}

	/**
	 * A DOCTYPE cannot be used to read external entities.
	 */
	public function test_rejects_doctype(): void {
		$response = HitResponse::from_xml( '<?xml version="1.0"?><!DOCTYPE Scr [<!ENTITY x SYSTEM "file:///etc/passwd">]><Scr><DL1>&x;</DL1></Scr>' );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'wctwc_hit_parse', $response->get_error_code() );
	}

	/**
	 * A response must have the Scr root element.
	 */
	public function test_rejects_wrong_root(): void {
		$response = HitResponse::from_xml( '<Other><Complete>1</Complete></Other>' );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'wctwc_hit_parse', $response->get_error_code() );
	}

	/**
	 * Log summaries never include receipt text.
	 */
	public function test_to_array_excludes_receipt(): void {
		$response = HitResponse::from_xml( file_get_contents( dirname( __DIR__ ) . '/fixtures/hit/status-approved.xml' ) );

		$this->assertInstanceOf( HitResponse::class, $response );
		$this->assertSame(
			array( 'txn_ref', 'complete', 'status_id', 'txn_status_id', 'reco', 'dl1', 'dl2', 'b1', 'b2', 'approved', 'auth_code', 'card_number', 'card_type', 'response_code', 'dps_txn_ref' ),
			array_keys( $response->to_array() )
		);
		$this->assertArrayNotHasKey( 'receipt', $response->to_array() );
		$this->assertStringNotContainsString( 'CUSTOMER COPY', json_encode( $response->to_array() ) );
	}
}
