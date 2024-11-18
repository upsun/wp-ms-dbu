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

WP_CLI::add_command( 'ms-dbu', MsDbuCommand::class, [
  'before_invoke' => static function() {
    if ( !is_multisite()) {
      WP_CLI::error('Not a multisite');
    }

    if(!getenv('PLATFORM_APPLICATION_NAME')) {
      WP_CLI::error('Required environment variables are missing. Are you sure you\'re on Platform.sh?');
    }
  }
] );

WP_CLI::add_hook('after_wp_config_load', static function () {
  /**
   * @todo these can be replaced with calls to \WP_CLI\MsDbu\MsDbuCommand::getEnvVar()
   */
  if(!getenv('PLATFORM_APPLICATION_NAME') || !getenv('PLATFORM_ROUTES')) {
    WP_CLI::debug("PLATFORM_APPLICATION_NAME and/or PLATFORM_ROUTES are missing. Unable to determine default url.");
    return;
  }

//  WP_CLI::log('Do we have access to argv?');
//  global $argv;
//  WP_CLI::log(var_export($argv,true));
//  WP_CLI::log("or maybe as _SERVER[argv]?");
//  WP_CLI::log(var_export($_SERVER['argv'],true));
    WP_CLI::log('Globals?');
    WP_CLI::log(var_export($GLOBALS,true));

  /**
   * @todod Should we create a function that'll bundle all this together so we can have one call?
   */
  $rawRoutes = \WP_CLI\MsDbu\MsDbuCommand::parseRouteJson(\WP_CLI\MsDbu\MsDbuCommand::getRouteFromEnvVar());
  $appName = \WP_CLI\MsDbu\MsDbuCommand::getEnvVar('APPLICATION_NAME');
  $filteredRoutes = \WP_CLI\MsDbu\MsDbuCommand::getFilteredRoutes($rawRoutes,$appName);
  $defaultRouteInfo = \WP_CLI\MsDbu\MsDbuCommand::retrieveDefaultDomainInfo($filteredRoutes);


  //@todo we need to verify there is one and only one item in the array
  $mainRoute = reset($defaultRouteInfo);
  WP_CLI::log(sprintf('Setting the WPCLI to use %s as the url for this command',$mainRoute['production_url']));
  WP_CLI::set_url($mainRoute['production_url']);

});
