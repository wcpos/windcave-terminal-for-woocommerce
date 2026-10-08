<?php
namespace WCPOS\WooCommercePOS\WindcaveTerminal\Tests\Conformance;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Provider_Conformance_Test_Case;
require_once __DIR__ . '/Windcave_Conformance_Fixture.php';
class Test_Windcave_Provider_Conformance extends Provider_Conformance_Test_Case {
	protected function fixture(): Conformance_Fixture {
		return new Windcave_Conformance_Fixture();
	}
}
