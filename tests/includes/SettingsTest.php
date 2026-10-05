<?php
/**
 * Tests for the settings reader.
 *
 * @package WCPOS\WooCommercePOS\WindcaveTerminal
 */

namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WCPOS\WooCommercePOS\WindcaveTerminal\Settings;

/**
 * @covers \WCPOS\WooCommercePOS\WindcaveTerminal\Settings
 */
class SettingsTest extends TestCase {
	/**
	 * Set up WordPress function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
	}

	/**
	 * Clear the function stubs.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Only the exact production value selects production.
	 */
	public function test_environment_defaults_to_uat(): void {
		$this->assertSame( 'uat', ( new Settings() )->environment() );
		foreach ( array( '', 'invalid', 'Production', ' production ' ) as $value ) {
			$this->assertSame( 'uat', ( new Settings( array( 'environment' => $value ) ) )->environment() );
		}
	}

	/**
	 * Each environment uses its specified endpoint.
	 */
	public function test_endpoint_url_uat_and_production(): void {
		$this->assertSame( 'https://uat.windcave.com/hit/pos.aspx', ( new Settings() )->endpoint_url() );
		$settings = new Settings( array( 'environment' => 'production' ) );
		$this->assertSame( 'production', $settings->environment() );
		$this->assertSame( 'https://sec.windcave.com/hit/pos.aspx', $settings->endpoint_url() );
	}

	/**
	 * Station lines are trimmed and deduplicated without reordering.
	 */
	public function test_station_ids_parses_lines_trims_and_dedupes(): void {
		$settings = new Settings( array( 'stations' => " 1001 \r\n\n1002\n1001\n" ) );
		$this->assertSame( array( '1001', '1002' ), $settings->station_ids() );
	}

	/**
	 * The default is appended only when absent and non-empty.
	 */
	public function test_station_ids_appends_default_station(): void {
		$settings = new Settings( array( 'stations' => '1001', 'default_station' => ' 1002 ' ) );
		$this->assertSame( array( '1001', '1002' ), $settings->station_ids() );
		$settings = new Settings( array( 'stations' => "1001\n1002", 'default_station' => '1001' ) );
		$this->assertSame( array( '1001', '1002' ), $settings->station_ids() );
		$this->assertSame( array( '1002' ), ( new Settings( array( 'default_station' => '1002' ) ) )->station_ids() );
		$this->assertSame( array(), ( new Settings() )->station_ids() );
	}

	/**
	 * Locking requires both the switch and a default Station.
	 */
	public function test_lock_station_requires_default_station(): void {
		$this->assertFalse( ( new Settings( array( 'lock_station' => 'yes' ) ) )->lock_station() );
		$this->assertFalse( ( new Settings( array( 'lock_station' => 'yes', 'default_station' => ' ' ) ) )->lock_station() );
		$this->assertFalse( ( new Settings( array( 'lock_station' => 'no', 'default_station' => '1001' ) ) )->lock_station() );
		$this->assertTrue( ( new Settings( array( 'lock_station' => 'yes', 'default_station' => ' 1001 ' ) ) )->lock_station() );
	}

	/**
	 * An empty POS name falls back to WCPOS.
	 */
	public function test_pos_name_falls_back_to_wcpos(): void {
		$this->assertSame( 'WCPOS', ( new Settings() )->pos_name() );
		$this->assertSame( 'WCPOS', ( new Settings( array( 'pos_name' => ' ' ) ) )->pos_name() );
		$this->assertSame( 'Shop', ( new Settings( array( 'pos_name' => ' Shop ' ) ) )->pos_name() );
	}

	/**
	 * POS enablement is independent of the online-store switch.
	 */
	public function test_enabled_for_pos_reads_wcpos_settings(): void {
		Functions\when( 'wcpos_get_settings' )->alias(
			function ( $section ) {
				$this->assertSame( 'payment_gateways', $section );
				return array( 'gateways' => array( 'windcave_terminal_for_woocommerce' => array( 'enabled' => true ) ) );
			}
		);
		$settings = new Settings();
		$this->assertFalse( $settings->enabled() );
		$this->assertTrue( $settings->enabled_for_pos() );
		$this->assertTrue( $settings->active() );
	}

	/**
	 * A blank title uses the translated gateway title.
	 */
	public function test_title_falls_back_when_empty(): void {
		$this->assertSame( 'Windcave Terminal', ( new Settings() )->title() );
		$this->assertSame( 'Windcave Terminal', ( new Settings( array( 'title' => ' ' ) ) )->title() );
		$this->assertSame( 'Counter', ( new Settings( array( 'title' => ' Counter ' ) ) )->title() );
	}

	public function test_log_level_defaults_to_debug_and_rejects_unknown(): void {
		$this->assertSame( 'debug', ( new Settings() )->log_level() );
		foreach ( array( '', 'unknown', 'DEBUG', false ) as $level ) {
			$this->assertSame( 'debug', ( new Settings( array( 'log_level' => $level ) ) )->log_level() );
		}
		foreach ( array( 'off', 'errors', 'debug' ) as $level ) {
			$this->assertSame( $level, ( new Settings( array( 'log_level' => $level ) ) )->log_level() );
		}
	}
}
