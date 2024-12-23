<?php

namespace WP_CLI\MsDbu;

use WP_CLI;
use WP_CLI\MsDbu\MsDbuCommand;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

$wpcli_ms_dbu_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $wpcli_ms_dbu_autoloader ) ) {
  require_once $wpcli_ms_dbu_autoloader;
}

/**
 * Our command namespace that we're adding via this package
 */
$commandName = "ms-dbu";
/**
 * Internal version number
 */
$version="0.7.1";

/**
 * Update database command
 */
WP_CLI::add_command( $commandName . " update", MsDbuCommand::class, [
  'before_invoke' => static function() {
    if ( !is_multisite()) {
      WP_CLI::error('Not a multisite');
    }
  }
] );

/**
 * Outputs the package's version
 *
 * Currently used for verification of the installed version for debugging.
 * Will potentially be expanded later
 */
WP_CLI::add_command($commandName . " version", static function () use ($version){
  WP_CLI::log(sprintf("Version: %s",$version));
});

