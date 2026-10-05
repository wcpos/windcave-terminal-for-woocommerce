<?php
/**
 * In-memory orders with optional shared persisted rows for stale-copy tests.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Support;

class FakeOrder extends \WC_Order {
	public static $rows = array();
	public static $completion_calls = array();
	public $save_calls = 0;
	public $payment_complete_calls = 0;
	public $notes = array();
	private $id;
	private $row;
	private $changed_fields = array();
	private $changed_meta = array();

	public function __construct( int $id, string $total = '10.00', string $currency = 'NZD' ) {
		$this->id  = $id;
		$this->row = self::$rows[ $id ] ?? array(
			'total'                => $total,
			'currency'             => $currency,
			'paid'                 => false,
			'transaction_id'       => '',
			'payment_method'       => '',
			'payment_method_title' => '',
			'meta'                 => array(),
		);
	}

	public function get_id() {
		return $this->id;
	}

	public function get_order_number() {
		return (string) $this->id;
	}

	public function get_total() {
		return $this->row['total'];
	}

	public function get_currency() {
		return $this->row['currency'];
	}

	public function get_meta( $key, $single = true ) {
		return $this->row['meta'][ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		if ( ! array_key_exists( $key, $this->row['meta'] ) || $this->row['meta'][ $key ] !== $value ) {
			$this->row['meta'][ $key ]  = $value;
			$this->changed_meta[ $key ] = true;
		}
	}

	public function delete_meta_data( $key ) {
		if ( array_key_exists( $key, $this->row['meta'] ) ) {
			unset( $this->row['meta'][ $key ] );
			$this->changed_meta[ $key ] = true;
		}
	}

	public function read_meta_data( $force = false ) {
		if ( $force && isset( self::$rows[ $this->id ] ) ) {
			$this->row['meta'] = self::$rows[ $this->id ]['meta'];
		}
	}

	/** Write only changed fields, as WC_Data does; a stale save cannot undo payment. */
	public function save() {
		++$this->save_calls;
		if ( ! isset( self::$rows[ $this->id ] ) ) {
			self::$rows[ $this->id ] = $this->row;
		}
		foreach ( array_keys( $this->changed_fields ) as $field ) {
			self::$rows[ $this->id ][ $field ] = $this->row[ $field ];
		}
		foreach ( array_keys( $this->changed_meta ) as $key ) {
			if ( array_key_exists( $key, $this->row['meta'] ) ) {
				self::$rows[ $this->id ]['meta'][ $key ] = $this->row['meta'][ $key ];
			} else {
				unset( self::$rows[ $this->id ]['meta'][ $key ] );
			}
		}
		$this->changed_fields = array();
		$this->changed_meta   = array();
		return $this->id;
	}

	public function is_paid() {
		return $this->row['paid'];
	}

	/** Count every call, including an erroneous repeat on a stale unpaid copy. */
	public function payment_complete( $transaction_id = '' ) {
		++$this->payment_complete_calls;
		self::$completion_calls[ $this->id ] = ( self::$completion_calls[ $this->id ] ?? 0 ) + 1;
		$this->set_field( 'paid', true );
		$this->set_transaction_id( $transaction_id );
		$this->save();
	}

	public function set_transaction_id( $transaction_id ) {
		$this->set_field( 'transaction_id', $transaction_id );
	}

	public function get_transaction_id() {
		return $this->row['transaction_id'];
	}

	public function add_order_note( $note, $is_customer_note = 0 ) {
		$this->notes[] = array( 'note' => $note, 'is_customer_note' => $is_customer_note );
	}

	public function get_payment_method() {
		return $this->row['payment_method'];
	}

	public function set_payment_method( $method ) {
		$this->set_field( 'payment_method', $method );
	}

	public function get_payment_method_title() {
		return $this->row['payment_method_title'];
	}

	public function set_payment_method_title( $title ) {
		$this->set_field( 'payment_method_title', $title );
	}

	private function set_field( string $field, $value ): void {
		if ( $this->row[ $field ] !== $value ) {
			$this->row[ $field ] = $value;
			$this->changed_fields[ $field ] = true;
		}
	}
}
