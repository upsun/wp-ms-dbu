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
 * Our command name that we're adding via this package
 */
$commandName = "ms-dbu";

/***
 * IF you change the name of the command here, make you sure you also
 */
WP_CLI::add_command( $commandName, MsDbuCommand::class, [
  'before_invoke' => static function() {
    if ( !is_multisite()) {
      WP_CLI::error('Not a multisite');
    }

    if(!getenv('PLATFORM_APPLICATION_NAME')) {
      WP_CLI::error('Required environment variables are missing. Are you sure you\'re on Platform.sh?');
    }
  }
] );

/**
 * @todo We should move this whole closure to a class
 */
WP_CLI::add_hook('after_wp_config_load', static function () use ($commandName) {
  /**
   * If they didn't call our command skip
   */
  global $argv;
  if($commandName !== $argv[1]) {
    WP_CLI::debug('ms-dbu was not called. Skipping...');
    return;
  }

  $routePattern   = '/^--routes=(.*)$/';
  $appNamePattern = '/^--app-name=(.*)$/';
  $commandDescript = "auto url set up.";


  /**
   * If they've already manually set the --url parameter we dont want to override it
   * You would think we could use WP_CLI::has_config('url'). Unfortunately, it sets 'url' with a default value of NULL
   * so it technically has the property, but it's null.
   */
  $url = WP_CLI::get_config('url');
  if(!is_null($url) && "" !== $url) {
    // if they already passed in a url, skip
    WP_CLI::debug(sprintf('url is already set. skipping %s... ', $commandDescript));
    return;
  }

  /**
   * Did they pass in a routes parameter? If they did, we should get one and only one match.
   * @todo what should we do if we get more than one?
   */
  $routeMatches = preg_grep($routePattern,$argv);
  if (1 !== count($routeMatches)) {
    /**
     * They didn't give us a parameter, did they set an env var?
     */
    if(!getenv('PLATFORM_ROUTES')) {
      //no param, no envVar, skip
      WP_CLI::debug(sprintf("Neither --routes or PLATFORM_ROUTES were provided. Skipping %s",$commandDescript));
      return;
    } else {
      /**
       * If we're getting them from the envVar, then they're bas64 encoded
       */
      $rawRoutes =  \WP_CLI\MsDbu\MsDbuCommand::parseRouteJson(\WP_CLI\MsDbu\MsDbuCommand::getRouteFromEnvVar());
    }
  } else if(1 !== preg_match($routePattern,reset($routeMatches),$capturedRoutes)) {
    //parameter but something is odd because we cant extract. skip
    WP_CLI::debug(sprintf("Unable to retrieve routes from parameter. Skipping %s", $commandDescript));
    return;
  } else {
    $rawRoutes = \WP_CLI\MsDbu\MsDbuCommand::parseRouteJson($capturedRoutes[1]);
  }

  /**
   * Now we need the same for App Name
   */
  $nameMatches = preg_grep($appNamePattern, $argv);
  if (1 !== count($nameMatches)) {
    // did they set it as an env Var?
    if(!getenv('PLATFORM_APPLICATION_NAME')) {
      // no app-name param, no envVar, skip.
      WP_CLI::debug(sprintf('App name not given as a parameter or environment variable. Skipping %s',$commandDescript));
      return;
    } else {
      $appName = \WP_CLI\MsDbu\MsDbuCommand::getEnvVar('APPLICATION_NAME');
    }
  } elseif(1 !== preg_match($appNamePattern,reset($nameMatches), $capturedNames)) {
    WP_CLI::debug(sprintf("Unable to retrieve routes from parameter. Skipping %s",$commandDescript));
    return;
  } else {
    $appName = $capturedNames[1];
  }

  WP_CLI::log(sprintf("App name is %s", $appName));
  WP_CLI::log('Route info is...');
  WP_CLI::log(var_export($rawRoutes));

  /**
   * @todod Should we create a function that'll bundle all this together so we can have one call?
   */

  $filteredRoutes = \WP_CLI\MsDbu\MsDbuCommand::getFilteredRoutes($rawRoutes,$appName);
  $defaultRouteInfo = \WP_CLI\MsDbu\MsDbuCommand::retrieveDefaultDomainInfo($filteredRoutes);


  //@todo we need to verify there is one and only one item in the array
  $mainRoute = reset($defaultRouteInfo);
  WP_CLI::log(sprintf('Setting the WPCLI to use %s as the url for this command',$mainRoute['production_url']));
  WP_CLI::set_url($mainRoute['production_url']);

});
