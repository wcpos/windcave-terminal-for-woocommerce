<?php
namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Conformance;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Provider_Conformance_Test_Case;
require_once __DIR__ . '/Windcave_Conformance_Fixture.php';
class Test_Windcave_Provider_Conformance extends Provider_Conformance_Test_Case {
	public function test_replayed_busy_purchase_keeps_free_reservation_until_settlement(): void {
		$this->transport->script( 'replay_busy' );
		$order = $this->order();
		$id = wp_generate_uuid4();
		$reader = $this->reader();
		$this->assertTrue( $this->intent( $order, $reader, $id )->get_error_data()['indeterminate'] );
		$error = $this->intent( $order, $reader, $id );
		$this->assertWPError( $error );
		$this->assertTrue( $error->get_error_data()['indeterminate'] ?? false );
		$this->assertSame( 'pending', $this->stored( $order, $id )['status'] );
		$this->assertSame( 'wcpos_payment_in_flight', $this->intent( $order, $reader )->get_error_code() );
		$this->assertSame( array( 'Purchase', 'Status', 'Purchase' ), array_column( $this->transport->raw_calls, 'type' ) );
		$this->assertNotWPError( $this->intent( $order, $reader, $id ) );
		$this->assertSame( 'captured', $this->poll( $order, $id )['status'] );
	}
	public function test_card_cancelled_decline_after_void_request_is_failed_not_voided(): void {
		list( $order, $id ) = $this->start( 'cancel_requested_then_cancelled' );
		$this->assertNotWPError( \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->void( $order, $id, 'review regression' ) );
		$this->transport->response_override = Windcave_Conformance_Fixture::response( str_replace( 'DECLINED', 'CARD CANCELLED', Windcave_Conformance_Fixture::fixture( 'status-declined' ) ) );
		$this->assertSame( 'failed', $this->poll( $order, $id )['status'] );
	}

	protected function fixture(): Conformance_Fixture {
		return new Windcave_Conformance_Fixture();
	}
}
