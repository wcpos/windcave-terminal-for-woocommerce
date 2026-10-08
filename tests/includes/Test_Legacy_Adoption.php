<?php
namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Includes;
use WCPOS\WooCommercePOS\WindcaveTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\WindcaveTerminal\Provider_Adapter;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
class Test_Legacy_Adoption extends \WP_UnitTestCase {
	public function test_pending_attempt_is_adopted_once_with_original_context(): void {
		$this->assertTrue( class_exists( Legacy_Adoption::class ) );
		wcpos_pro_register_server_provider( Settings::GATEWAY_ID, Provider_Adapter::class );
		delete_option( 'wctwc_version' ); delete_option( 'wctwc_adoption_offset' );
		$order = wc_create_order(); $order->set_total( '92.95' );
		$order->update_meta_data( '_wctwc_current_txn_ref', 'old-ref' );
		$order->update_meta_data( '_wctwc_current_status', 'pending' );
		$order->update_meta_data( '_wctwc_attempts', array( array( 'txn_ref' => 'old-ref', 'station' => 'old-station', 'amount' => '92.95', 'currency' => 'NZD', 'environment' => 'uat', 'created_at' => gmdate( 'c', time() - 60 ), 'status' => 'pending' ) ) );
		$order->save();
		Legacy_Adoption::upgrade(); Legacy_Adoption::upgrade();
		$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pending', $rows[0]['status'] );
		$this->assertSame( 'old-ref', $rows[0]['provider_refs']['action'] );
		$this->assertSame( 'NZD', $rows[0]['currency'] );
		$this->assertSame( $rows[0]['id'], wcpos_pro_payment_id_for_action( 'windcave', 'old-ref' ) );
		$context = get_option( Provider_Adapter::context_key( 'old-ref' ) );
		$this->assertSame( 'uat', $context['environment'] );
		$this->assertArrayNotHasKey( 'settings', $context, 'No credential snapshot per action' );
		$this->assertSame( 'old-station', $context['station'] );
		$this->assertSame( 'old-ref', wc_get_order( $order->get_id() )->get_meta( '_wctwc_current_txn_ref' ) );
	}
}
