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
  if(!getenv('PLATFORM_APPLICATION_NAME') || !getenv('PLATFORM_ROUTES')) {
    WP_CLI::debug("PLATFORM_APPLICATION_NAME and/or PLATFORM_ROUTES are missing. Unable to determine default url.");
    return;
  }

  $rawRoutes = \WP_CLI\MsDbu\MsDbuCommand::getRouteFromEnvVar();
  $appName = \WP_CLI\MsDbu\MsDbuCommand::getEnvVar('APPLICATION_NAME');
  $filteredRoutes = \WP_CLI\MsDbu\MsDbuCommand::getFilteredRoutes($rawRoutes,$appName);
  $defaultRouteInfo = \WP_CLI\MsDbu\MsDbuCommand::retrieveDefaultDomainInfo($filteredRoutes);


  //grab the first item
  $mainRoute = reset($defaultRouteInfo);
  WP_CLI::log(sprintf('Setting the WPCLI to use %s as the url for this command',$mainRoute['production_url']));
  WP_CLI::set_url($mainRoute['production_url']);

});
