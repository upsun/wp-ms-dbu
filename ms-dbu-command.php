<?php

namespace WP_CLI\MsDbu;

use WP_CLI;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

$wpcli_ms_dbu_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $wpcli_ms_dbu_autoloader ) ) {
  require_once $wpcli_ms_dbu_autoloader;
}

WP_CLI::add_command( 'ms-dbu', MsDbuCommand::class, [
  'before_invoke' => static function() {
    WP_CLI::log('Hello from before invoke');

    if ( !is_multisite()) {
      WP_CLI::error('Not a multisite');
    }

    if(!getenv('PLATFORM_APPLICATION_NAME')) {
      WP_CLI::error('Required environment variables are missing. Are you sure you\'re on Platform.sh?');
    }
  }
] );

WP_CLI::add_hook('after_wp_config_load', static function (){
  $rawRoutes = base64_decode(getenv('PLATFORM_ROUTES'));

  try {
    $routes = \json_decode($rawRoutes, true, 512, JSON_THROW_ON_ERROR);
  } catch (\JsonException $e) {
    WP_CLI::error(\sprintf('Unable to parse route information. Is it valid JSON? %s', $e->getMessage()));
  }

  $appName = getenv('PLATFORM_APPLICATION_NAME');
  //get all the valid routes that map to this app
  $aryUpstreamRoutes = array_filter($routes, static function ($route) use ($appName) {
    return isset($route['upstream']) && $appName === $route['upstream'] && $route['primary'];
  });

//  $site_host = parse_url(array_key_first(array_filter($aryUpstreamRoutes, static function ($route) {
//    return $route['primary'];
//  })),PHP_URL_HOST);

  WP_CLI::log('Upstream Routes');
  WP_CLI::log(var_export($aryUpstreamRoutes,true));

});
