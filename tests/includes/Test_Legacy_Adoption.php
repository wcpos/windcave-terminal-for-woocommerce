<?php
namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Includes;
use WCPOS\WooCommercePOS\WindcaveTerminal\Legacy_Adoption;
use WCPOS\WooCommercePOS\WindcaveTerminal\Provider_Adapter;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
class Test_Legacy_Adoption extends \WP_UnitTestCase {
	public function test_broken_middle_order_is_logged_and_skipped(): void {
		wcpos_pro_register_server_provider( Settings::GATEWAY_ID, Provider_Adapter::class );
		delete_option( 'wctwc_version' ); delete_option( 'wctwc_adoption_offset' );
		$orders = array();
		foreach ( array( 'first', 'broken', 'last' ) as $name ) {
			$order = wc_create_order(); $order->set_total( '92.95' );
			$order->update_meta_data( '_wctwc_current_txn_ref', 'legacy-' . $name );
			$order->update_meta_data( '_wctwc_current_status', 'pending' );
			if ( 'broken' !== $name ) {
				$attempt = array( 'txn_ref' => 'legacy-' . $name, 'station' => 'old-station', 'amount' => '92.95', 'currency' => 'NZD', 'created_at' => gmdate( 'c', time() - 60 ) );
				if ( 'first' === $name ) { $attempt['environment'] = 'production'; }
				$order->update_meta_data( '_wctwc_attempts', array( $attempt ) );
			}
			$order->save(); $orders[ $name ] = $order;
		}
		$messages = array();
		$one_handler = static fn( $handlers ) => array_slice( $handlers, 0, 1 );
		$filter = static function ( $message, $level, $context ) use ( &$messages ) {
			if ( 'error' === $level && 'windcave-terminal' === ( $context['source'] ?? '' ) ) { $messages[] = $message; }
			return $message;
		};
		add_filter( 'woocommerce_register_log_handlers', $one_handler, 99 );
		add_filter( 'woocommerce_logger_log_message', $filter, 10, 3 );
		try { Legacy_Adoption::upgrade(); Legacy_Adoption::upgrade(); }
		finally {
			remove_filter( 'woocommerce_logger_log_message', $filter, 10 );
			remove_filter( 'woocommerce_register_log_handlers', $one_handler, 99 );
		}
		foreach ( array( 'first', 'last' ) as $name ) {
			$rows = Ledger::instance()->read( wc_get_order( $orders[ $name ]->get_id() ) );
			$this->assertCount( 1, $rows, $name );
			$this->assertSame( 'legacy-' . $name, $rows[0]['provider_refs']['action'] );
		}
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $orders['broken']->get_id() ) ) );
		$this->assertSame( array( 'Windcave adoption failed for order ' . $orders['broken']->get_id() . ': wctwc_legacy_context_missing' ), $messages );
		$this->assertSame( '1.0.0', get_option( 'wctwc_version' ) );
		$this->assertFalse( get_option( 'wctwc_adoption_offset' ) );
		$this->assertSame( 'uat', get_option( Provider_Adapter::context_key( 'legacy-last' ) )['environment'] );
	}

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

	public function test_set_aside_ref_on_open_order_is_adopted(): void {
		$this->reset_upgrade();
		$order = $this->set_aside_order( 'pending', array( 'aside-open' ), array( 'aside-open' ) );
		Legacy_Adoption::upgrade();
		$rows = $this->rows( $order );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'aside-open', $rows[0]['provider_refs']['action'] );
		$this->assertSame( $rows[0]['id'], wcpos_pro_payment_id_for_action( 'windcave', 'aside-open' ) );
		$this->assertSame( array(), $this->notes( $order, 'aside-open' ) );
		$this->assertSame( '1.0.0', get_option( 'wctwc_version' ) );
	}

	public function test_set_aside_ref_on_closed_order_is_noted_never_adopted(): void {
		$this->reset_upgrade();
		$orders = array();
		foreach ( array( 'completed', 'cancelled' ) as $status ) {
			$orders[ $status ] = $this->set_aside_order( $status, array( 'aside-' . $status ), array( 'aside-' . $status ) );
		}
		Legacy_Adoption::upgrade();
		foreach ( $orders as $status => $order ) {
			$this->assertSame( array(), $this->rows( $order ), $status );
			$this->assertNull( wcpos_pro_payment_id_for_action( 'windcave', 'aside-' . $status ) );
			$this->assertCount( 1, $this->notes( $order, 'aside-' . $status ), $status );
			$this->assertSame( array( 'aside-' . $status ), wc_get_order( $order->get_id() )->get_meta( Legacy_Adoption::META_ABANDONED_NOTED ) );
		}
	}

	public function test_rerun_from_start_adds_no_second_note_or_row(): void {
		$this->reset_upgrade();
		$open = $this->set_aside_order( 'pending', array( 'again-open' ), array( 'again-open' ) );
		$completed = $this->set_aside_order( 'completed', array( 'again-completed' ), array( 'again-completed' ) );
		$cancelled = $this->set_aside_order( 'cancelled', array( 'again-cancelled', 'again-cancelled', '' ), array() );
		Legacy_Adoption::upgrade();
		$this->reset_upgrade();
		Legacy_Adoption::upgrade();
		$this->assertCount( 1, $this->rows( $open ) );
		$this->assertCount( 1, $this->notes( $completed, 'again-completed' ) );
		$this->assertCount( 1, $this->notes( $cancelled, 'again-cancelled' ) );
		$this->assertSame( '1.0.0', get_option( 'wctwc_version' ) );
	}

	public function test_set_aside_ref_without_attempt_is_noted_and_logged(): void {
		$this->reset_upgrade();
		$order = $this->set_aside_order( 'pending', array( 'aside-lost' ), array() );
		$messages = $this->upgrade_logging();
		$this->assertSame( array( 'Windcave adoption failed for order ' . $order->get_id() . ': wctwc_legacy_context_missing' ), $messages );
		$this->assertCount( 1, $this->notes( $order, 'aside-lost' ) );
		$this->assertSame( array(), $this->rows( $order ) );
		$this->assertSame( '1.0.0', get_option( 'wctwc_version' ) );
		$this->assertFalse( get_option( 'wctwc_adoption_abandoned_offset' ) );
	}

	public function test_current_and_set_aside_refs_each_get_a_row(): void {
		$this->reset_upgrade();
		$order = $this->set_aside_order( 'pending', array( 'both-aside' ), array( 'both-aside', 'both-current' ), 'both-current' );
		Legacy_Adoption::upgrade();
		$refs = array_map( static fn( $row ) => $row['provider_refs']['action'], $this->rows( $order ) );
		sort( $refs );
		$this->assertSame( array( 'both-aside', 'both-current' ), $refs );
		$this->assertSame( array(), $this->notes( $order, 'both-aside' ) );
	}

	public function test_set_aside_pass_pages_by_25(): void {
		$this->reset_upgrade();
		$orders = array();
		for ( $i = 0; $i < 26; $i++ ) {
			$orders[ $i ] = $this->set_aside_order( 'completed', array( 'page-' . $i ), array( 'page-' . $i ) );
		}
		Legacy_Adoption::upgrade();
		$this->assertFalse( get_option( 'wctwc_version' ) );
		$this->assertSame( 25, (int) get_option( 'wctwc_adoption_abandoned_offset' ) );
		$this->assertCount( 1, $this->notes( $orders[24], 'page-24' ) );
		$this->assertSame( array(), $this->notes( $orders[25], 'page-25' ) );
		Legacy_Adoption::upgrade();
		$this->assertSame( '1.0.0', get_option( 'wctwc_version' ) );
		$this->assertFalse( get_option( 'wctwc_adoption_abandoned_offset' ) );
		foreach ( $orders as $i => $order ) {
			$this->assertCount( 1, $this->notes( $order, 'page-' . $i ), 'order ' . $i );
		}
	}

	public function test_busy_lock_repeats_the_set_aside_page(): void {
		$this->reset_upgrade();
		$order = $this->set_aside_order( 'completed', array( 'aside-busy' ), array( 'aside-busy' ) );
		$other = ( new \ReflectionClass( Order_Lock::class ) )->newInstanceWithoutConstructor();
		$this->assertTrue( $other->acquire( $order->get_id() ) );
		try {
			$messages = $this->upgrade_logging();
		} finally {
			$other->release( $order->get_id() );
		}
		$this->assertSame( array( 'Windcave adoption failed for order ' . $order->get_id() . ': wcpos_payment_locked' ), $messages );
		$this->assertFalse( get_option( 'wctwc_version' ) );
		$this->assertSame( 0, (int) get_option( 'wctwc_adoption_abandoned_offset', -1 ) );
		$this->assertSame( array(), $this->notes( $order, 'aside-busy' ) );
		Legacy_Adoption::upgrade();
		$this->assertCount( 1, $this->notes( $order, 'aside-busy' ) );
		$this->assertSame( '1.0.0', get_option( 'wctwc_version' ) );
	}

	public function test_install_without_set_aside_refs_stamps_in_one_call(): void {
		$this->reset_upgrade();
		$this->set_aside_order( 'pending', array(), array( 'only-current' ), 'only-current' );
		Legacy_Adoption::upgrade();
		$this->assertSame( '1.0.0', get_option( 'wctwc_version' ) );
		$this->assertFalse( get_option( 'wctwc_adoption_offset' ) );
		$this->assertFalse( get_option( 'wctwc_adoption_abandoned_offset' ) );
	}

	public function test_adopted_ref_on_order_closed_later_gets_no_note(): void {
		$this->reset_upgrade();
		$order = $this->set_aside_order( 'pending', array( 'closed-later' ), array( 'closed-later' ) );
		Legacy_Adoption::upgrade();
		$this->assertCount( 1, $this->rows( $order ) );
		$closed = wc_get_order( $order->get_id() ); $closed->set_status( 'completed' ); $closed->save();
		$this->reset_upgrade();
		Legacy_Adoption::upgrade();
		$this->assertCount( 1, $this->rows( $order ) );
		$this->assertSame( array(), $this->notes( $order, 'closed-later' ) );
	}

	public function test_set_aside_ref_on_pos_status_order_is_adopted(): void {
		$this->reset_upgrade();
		$orders = array();
		foreach ( array( 'pos-open', 'pos-partial' ) as $status ) {
			$orders[ $status ] = $this->set_aside_order( $status, array( 'aside-' . $status ), array( 'aside-' . $status ) );
			$this->assertSame( $status, wc_get_order( $orders[ $status ]->get_id() )->get_status() );
		}
		Legacy_Adoption::upgrade();
		foreach ( $orders as $status => $order ) {
			$this->assertSame( array( 'aside-' . $status ), array_column( array_column( $this->rows( $order ), 'provider_refs' ), 'action' ), $status );
			$this->assertSame( array(), $this->notes( $order, 'aside-' . $status ), $status );
		}
	}

	private function reset_upgrade(): void {
		wcpos_pro_register_server_provider( Settings::GATEWAY_ID, Provider_Adapter::class );
		delete_option( 'wctwc_version' ); delete_option( 'wctwc_adoption_offset' ); delete_option( 'wctwc_adoption_abandoned_offset' );
	}

	private function set_aside_order( string $status, array $refs, array $attempt_refs, string $current = '' ): \WC_Order {
		$order = wc_create_order(); $order->set_total( '92.95' ); $order->set_status( $status );
		if ( array() !== $refs ) {
			$order->update_meta_data( '_wctwc_abandoned_txn_refs', $refs );
		}
		if ( '' !== $current ) {
			$order->update_meta_data( '_wctwc_current_txn_ref', $current );
			$order->update_meta_data( '_wctwc_current_status', 'pending' );
		}
		$attempt = static fn( $ref ) => array( 'txn_ref' => $ref, 'station' => 'old-station', 'amount' => '92.95', 'currency' => 'NZD', 'created_at' => gmdate( 'c', time() - 60 ) );
		$order->update_meta_data( '_wctwc_attempts', array_map( $attempt, $attempt_refs ) );
		$order->save();
		return $order;
	}

	private function rows( \WC_Order $order ): array {
		return Ledger::instance()->read( wc_get_order( $order->get_id() ) );
	}

	private function notes( \WC_Order $order, string $ref ): array {
		$set_aside = static fn( $note ) => false !== strpos( $note->content, 'Windcave TxnRef ' . $ref . ' was set aside before the 1.0.0 upgrade' );
		return array_values( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), $set_aside ) );
	}

	private function upgrade_logging(): array {
		$messages = array();
		$one_handler = static fn( $handlers ) => array_slice( $handlers, 0, 1 );
		$filter = static function ( $message, $level, $context ) use ( &$messages ) {
			if ( 'error' === $level && 'windcave-terminal' === ( $context['source'] ?? '' ) ) { $messages[] = $message; }
			return $message;
		};
		add_filter( 'woocommerce_register_log_handlers', $one_handler, 99 );
		add_filter( 'woocommerce_logger_log_message', $filter, 10, 3 );
		try { Legacy_Adoption::upgrade(); }
		finally {
			remove_filter( 'woocommerce_logger_log_message', $filter, 10 );
			remove_filter( 'woocommerce_register_log_handlers', $one_handler, 99 );
		}
		return $messages;
	}
}
