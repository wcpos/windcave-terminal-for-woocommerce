<?php
/**
 * Parsed Windcave HIT response.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Services;

use DOMDocument;
use DOMElement;

/**
 * Immutable snapshot of a HIT reply.
 */
final class HitResponse {
	/**
	 * Direct Scr child values.
	 *
	 * @var array
	 */
	private $fields;

	/**
	 * Direct Result child values.
	 *
	 * @var array
	 */
	private $results;

	/**
	 * Button states and labels.
	 *
	 * @var array
	 */
	private $buttons;

	/**
	 * Save parsed values privately.
	 *
	 * @param array $fields  Direct child values.
	 * @param array $results Result values.
	 * @param array $buttons Button values.
	 */
	private function __construct( array $fields, array $results, array $buttons ) {
		$this->fields  = $fields;
		$this->results = $results;
		$this->buttons = $buttons;
	}

	/**
	 * Parse a HIT XML reply.
	 *
	 * @param string $xml Reply body.
	 * @return HitResponse|\WP_Error
	 */
	public static function from_xml( string $xml ) {
		if ( '' === trim( $xml ) || false !== stripos( $xml, '<!DOCTYPE' ) ) {
			return new \WP_Error( 'wctwc_hit_parse', 'Invalid HIT response XML.' );
		}
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $document->documentElement || 'Scr' !== $document->documentElement->nodeName ) {
			return new \WP_Error( 'wctwc_hit_parse', 'Invalid HIT response XML.' );
		}
		$fields  = array();
		$results = array();
		$buttons = array();
		foreach ( $document->documentElement->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			if ( 'Result' === $child->nodeName ) {
				foreach ( $child->childNodes as $result ) {
					if ( $result instanceof DOMElement && ! array_key_exists( $result->nodeName, $results ) ) {
						$results[ $result->nodeName ] = $result->textContent;
					}
				}
			} elseif ( in_array( $child->nodeName, array( 'B1', 'B2' ), true ) && ! isset( $buttons[ $child->nodeName ] ) ) {
				$buttons[ $child->nodeName ] = array(
					'enabled' => '1' === $child->getAttribute( 'en' ),
					'label' => trim( $child->textContent ),
				);
			} elseif ( ! array_key_exists( $child->nodeName, $fields ) ) {
				$fields[ $child->nodeName ] = $child->textContent;
			}
		}
		return new self( $fields, $results, $buttons );
	}

	/**
	 * Read a direct Scr child.
	 *
	 * @param string $name Element name.
	 * @return string
	 */
	private function field( string $name ): string {
		return $this->fields[ $name ] ?? '';
	}

	/** Get the transaction type. */
	public function txn_type(): string {
		return $this->field( 'TxnType' );
	}

	/** Get the transaction reference. */
	public function txn_ref(): string {
		return $this->field( 'TxnRef' );
	}

	/** Whether the transaction is complete. */
	public function complete(): bool {
		return '1' === $this->field( 'Complete' );
	}

	/** Get the status ID. */
	public function status_id(): int {
		return (int) $this->field( 'StatusId' );
	}

	/** Get the transaction status ID. */
	public function txn_status_id(): int {
		return (int) $this->field( 'TxnStatusId' );
	}

	/** Get the response code. */
	public function reco(): string {
		return $this->field( 'ReCo' );
	}

	/** Get the timeout in seconds. */
	public function timeout_seconds(): int {
		return (int) $this->field( 'Tmo' );
	}

	/** Get the first display line. */
	public function display_line_1(): string {
		return $this->field( 'DL1' );
	}

	/** Get the second display line. */
	public function display_line_2(): string {
		return $this->field( 'DL2' );
	}

	/**
	 * Get an enabled state and label for a button.
	 *
	 * @param string $name Button name.
	 * @return array
	 */
	public function button( string $name ): array {
		return in_array( $name, array( 'B1', 'B2' ), true ) ? ( $this->buttons[ $name ] ?? array(
			'enabled' => false,
			'label' => '',
		) ) : array(
			'enabled' => false,
			'label' => '',
		);
	}

	/** Get the untrimmed receipt. */
	public function receipt(): string {
		return $this->field( 'Rcpt' );
	}

	/** Get the receipt width. */
	public function receipt_width(): int {
		return (int) $this->field( 'RcptW' );
	}

	/**
	 * Get a direct Result child.
	 *
	 * @param string $name Element name.
	 * @return string
	 */
	public function result( string $name ): string {
		return $this->results[ $name ] ?? '';
	}

	/** Whether a completed transaction was approved. */
	public function approved(): bool {
		return $this->complete() && '1' === $this->result( 'AP' );
	}

	/** Get the authorisation code. */
	public function auth_code(): string {
		return $this->result( 'AC' );
	}

	/** Get the masked card number. */
	public function card_number(): string {
		return $this->result( 'CN' );
	}

	/** Get the card type. */
	public function card_type(): string {
		return $this->result( 'CT' );
	}

	/** Get the response code. */
	public function response_code(): string {
		return $this->result( 'RC' );
	}

	/** Get the approved amount in cents. */
	public function amount_cents(): int {
		return (int) $this->result( 'AmtA' );
	}

	/** Get the charged currency when HIT reports it; absence is not confirmation. */
	public function currency(): string {
		foreach ( array( 'CurrencyInput', 'Cur', 'CurrencyName' ) as $name ) {
			$value = trim( $this->field( $name ) ? $this->field( $name ) : $this->result( $name ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/** Get the DPS transaction reference. */
	public function dps_txn_ref(): string {
		if ( '' !== $this->field( 'DpsTxnRef' ) ) {
			return $this->field( 'DpsTxnRef' );
		}
		if ( '' !== $this->result( 'DpsTxnRef' ) ) {
			return $this->result( 'DpsTxnRef' );
		}
		// Inferred from Windcave's HIT example (16-hex TR); confirm against UAT.
		return $this->result( 'TR' );
	}

	/** Whether the transaction is already in progress. */
	public function is_existing_txn_in_progress(): bool {
		return 'PC' === $this->reco();
	}

	/**
	 * Export a receipt-free summary for logs.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'txn_ref'       => $this->txn_ref(),
			'complete'      => $this->complete(),
			'status_id'     => $this->status_id(),
			'txn_status_id' => $this->txn_status_id(),
			'reco'          => $this->reco(),
			'dl1'           => $this->display_line_1(),
			'dl2'           => $this->display_line_2(),
			'b1'            => $this->button( 'B1' ),
			'b2'            => $this->button( 'B2' ),
			'approved'      => $this->approved(),
			'auth_code'     => $this->auth_code(),
			'card_number'   => $this->card_number(),
			'card_type'     => $this->card_type(),
			'response_code' => $this->response_code(),
			'dps_txn_ref'   => $this->dps_txn_ref(),
		);
	}
}
