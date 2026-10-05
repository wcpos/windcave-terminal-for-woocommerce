<?php
/**
 * Windcave HIT request client.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Services;

use DOMDocument;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;

/**
 * Build and send HIT commands.
 */
class HitClient {
	/**
	 * Gateway settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Set the gateway settings.
	 *
	 * @param Settings $settings Gateway settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Send a purchase command.
	 *
	 * @param string $station      Station ID.
	 * @param string $txn_ref      Transaction reference.
	 * @param string $amount       Formatted amount.
	 * @param string $currency     Currency code.
	 * @param string $merchant_ref Merchant reference.
	 * @return HitResponse|\WP_Error
	 */
	public function purchase( string $station, string $txn_ref, string $amount, string $currency, string $merchant_ref ) {
		return $this->send( 'Purchase', $this->payment_fields( 'Purchase', $station, $txn_ref, $amount, $currency, $merchant_ref ) );
	}

	/**
	 * Send a refund command.
	 *
	 * @param string $station      Station ID.
	 * @param string $txn_ref      Transaction reference.
	 * @param string $amount       Formatted amount.
	 * @param string $currency     Currency code.
	 * @param string $dps_txn_ref  Original transaction reference.
	 * @param string $merchant_ref Merchant reference.
	 * @return HitResponse|\WP_Error
	 */
	public function refund( string $station, string $txn_ref, string $amount, string $currency, string $dps_txn_ref, string $merchant_ref ) {
		$fields = $this->payment_fields( 'Refund', $station, $txn_ref, $amount, $currency, $merchant_ref );
		$fields = array_merge( array_slice( $fields, 0, 5, true ), array( 'DpsTxnRef' => $dps_txn_ref ), array_slice( $fields, 5, null, true ) );
		return $this->send( 'Refund', $fields );
	}

	/**
	 * Query the transaction status.
	 *
	 * @param string $station Station ID.
	 * @param string $txn_ref Transaction reference.
	 * @return HitResponse|\WP_Error
	 */
	public function status( string $station, string $txn_ref ) {
		return $this->send(
			'Status',
			array(
				'Station' => $station,
				'TxnType' => 'Status',
				'TxnRef' => $txn_ref,
			)
		);
	}

	/**
	 * Send a UI button response.
	 *
	 * @param string $station Station ID.
	 * @param string $txn_ref Transaction reference.
	 * @param string $button  Button name.
	 * @param string $value   Button value.
	 * @return HitResponse|\WP_Error
	 */
	public function ui( string $station, string $txn_ref, string $button, string $value ) {
		if ( ! in_array( $button, array( 'B1', 'B2' ), true ) || ! in_array( $value, array( 'YES', 'NO', 'CANCEL' ), true ) ) {
			return new \WP_Error( 'wctwc_hit_invalid_ui', 'Invalid HIT UI button or value.' );
		}
		return $this->send(
			'UI',
			array(
				'Station' => $station,
				'TxnType' => 'UI',
				'UiType' => 'Bn',
				'Name' => $button,
				'Val' => $value,
				'TxnRef' => $txn_ref,
			)
		);
	}

	/**
	 * Build the command XML without a declaration.
	 *
	 * @param string $txn_type Transaction type.
	 * @param array  $fields   Ordered element values.
	 * @return string
	 */
	public function build_request( string $txn_type, array $fields ): string {
		$document = new DOMDocument( '1.0', 'UTF-8' );
		$root     = $document->createElement( 'Scr' );
		$root->setAttribute( 'action', 'doScrHIT' );
		$root->setAttribute( 'user', $this->settings->hit_user() );
		$root->setAttribute( 'key', $this->settings->hit_key() );
		foreach ( $fields as $name => $value ) {
			if ( '' !== $value ) {
				$element = $document->createElement( $name );
				$element->appendChild( $document->createTextNode( $value ) );
				$root->appendChild( $element );
			}
		}
		$document->appendChild( $root );
		return $document->saveXML( $document->documentElement );
	}

	/**
	 * Assemble fields shared by purchase and refund.
	 *
	 * @param string $txn_type     Transaction type.
	 * @param string $station      Station ID.
	 * @param string $txn_ref      Transaction reference.
	 * @param string $amount       Formatted amount.
	 * @param string $currency     Currency code.
	 * @param string $merchant_ref Merchant reference.
	 * @return array
	 */
	private function payment_fields( string $txn_type, string $station, string $txn_ref, string $amount, string $currency, string $merchant_ref ): array {
		return array(
			'Amount'     => $amount,
			'Cur'        => $currency,
			'TxnType'    => $txn_type,
			'Station'    => $station,
			'TxnRef'     => $txn_ref,
			'DeviceId'   => $this->settings->pos_name(),
			'PosName'    => $this->settings->pos_name(),
			'PosVersion' => WCTWC_VERSION,
			'VendorId'   => $this->settings->vendor_id(),
			'MRef'       => mb_substr( $merchant_ref, 0, 64 ),
		);
	}

	/**
	 * Send one command and parse its response.
	 *
	 * @param string $txn_type Transaction type.
	 * @param array  $fields   Ordered element values.
	 * @return HitResponse|\WP_Error
	 */
	private function send( string $txn_type, array $fields ) {
		$xml      = $this->build_request( $txn_type, $fields );
		$response = wp_remote_post(
			$this->settings->endpoint_url(),
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'text/xml; charset=utf-8' ),
				'body'    => $xml,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error( 'wctwc_hit_http', 'HIT request failed.', $status_code );
		}
		return HitResponse::from_xml( wp_remote_retrieve_body( $response ) );
	}
}
