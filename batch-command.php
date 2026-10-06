<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wpcli_batch_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $wpcli_batch_autoloader ) ) {
	require_once $wpcli_batch_autoloader;
}

WP_CLI::add_command( 'batch', 'WP_CLI\Batch\Batch_Command' );

// The worker is an implementation detail of `wp batch run`, which starts it
// with this environment variable set. It is not registered otherwise, so it
// never shows up in `wp help`.
if ( getenv( 'WP_CLI_BATCH_WORKER' ) ) {
	WP_CLI::add_command( 'batch worker', 'WP_CLI\Batch\Worker_Command' );
}
