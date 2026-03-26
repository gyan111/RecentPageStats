<?php
/**
 * PHPUnit bootstrap file for RecentPageStats extension
 *
 * When running with MediaWiki's test runner (recommended), this is not needed.
 * Use: php tests/phpunit/phpunit.php extensions/RecentPageStats/
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../../..';
}

require_once "$IP/tests/common/TestsAutoLoader.php";
