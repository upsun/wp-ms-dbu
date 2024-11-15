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
  WP_CLI::log('hello from after config load');
});
