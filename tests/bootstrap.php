<?php
$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' ) ?: '/tmp/wordpress-tests-lib';
require_once $tests_dir . '/includes/functions.php';
tests_add_filter( 'muplugins_loaded', static function () {
	require dirname( __DIR__ ) . '/windcave-terminal-for-woocommerce.php';
}, 11 );
require dirname( __DIR__ ) . '/../woocommerce-pos-pro/tests/bootstrap.php';
require_once dirname( __DIR__ ) . '/../woocommerce-pos-pro/tests/includes/Conformance/Conformance_Fixture.php';
require_once dirname( __DIR__ ) . '/../woocommerce-pos-pro/tests/includes/Conformance/Provider_Conformance_Test_Case.php';
