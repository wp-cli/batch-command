<?php

namespace WP_CLI\Batch;

use WP_CLI;
use WP_CLI\Fetchers;
use WP_CLI_Command;

/**
 * Runs batch jobs in forked copies of a loaded WordPress.
 *
 * Only `wp batch run` starts this command, and only registers it for the
 * process it starts.
 */
class Worker_Command extends WP_CLI_Command {

	/**
	 * Options whose changes the next command can see without reloading WordPress.
	 *
	 * Every command starts with an empty object cache, so it reads options
	 * afresh. Changes to other options cause a reload, because WordPress and
	 * plugins read many of them only once while loading. Transients and the
	 * cached term hierarchies (`{$taxonomy}_children`) are ignored as well.
	 *
	 * @var string[]
	 */
	const IGNORED_OPTIONS = [ 'cron', 'user_count' ];

	/**
	 * Reasons to reload WordPress, collected while a command runs.
	 *
	 * @var string[]
	 */
	private $reasons = [];

	/**
	 * Runs jobs from file descriptor 3 until it closes.
	 *
	 * @param string[] $args       Positional arguments. Unused.
	 * @param array    $assoc_args Associative arguments. Unused.
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Handled below.
		$jobs    = @fopen( 'php://fd/3', 'r' );
		$results = @fopen( 'php://fd/4', 'w' );
		// phpcs:enable

		if ( ! $jobs || ! $results ) {
			WP_CLI::error( 'The worker must be started by `wp batch run`.' );
		}

		$forked = false;

		$line = fgets( $jobs );
		while ( false !== $line ) {
			$job = json_decode( $line, true );
			if ( ! is_array( $job ) || ! isset( $job['args'], $job['assoc_args'] ) || ! is_array( $job['args'] ) || ! is_array( $job['assoc_args'] ) ) {
				WP_CLI::error( 'Received an invalid job.' );
			}

			// Each command connects to the database itself. A connection must not
			// be shared between processes.
			$wpdb->close();

			$sockets = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
			if ( ! $sockets ) {
				WP_CLI::error( 'Failed to create a socket pair.' );
			}

			$forked = true;
			$pid    = pcntl_fork();
			if ( -1 === $pid ) {
				WP_CLI::error( 'Failed to fork the worker.' );
			}

			if ( 0 === $pid ) {
				fclose( $sockets[0] );
				fclose( $jobs );
				fclose( $results );
				$this->run_child( $job, $sockets[1] );
			}

			fclose( $sockets[1] );
			$reported = stream_get_contents( $sockets[0] );
			fclose( $sockets[0] );

			$status = 0;
			pcntl_waitpid( $pid, $status );

			if ( pcntl_wifexited( $status ) ) {
				$code    = pcntl_wexitstatus( $status );
				$reasons = json_decode( (string) $reported, true );
				$reasons = is_array( $reasons ) ? $reasons : [ 'the command did not report its changes' ];
			} else {
				$signal  = pcntl_wifsignaled( $status ) ? pcntl_wtermsig( $status ) : 0;
				$code    = 128 + (int) $signal;
				$reasons = [ "the command was terminated by signal {$signal}" ];
			}

			fwrite(
				$results,
				json_encode(
					[
						'code'    => $code,
						'reasons' => $reasons,
					]
				) . "\n"
			);
			fflush( $results );

			if ( $reasons ) {
				break;
			}

			$line = fgets( $jobs );
		}

		// Shutdown functions may still need the database.
		if ( $forked ) {
			$wpdb->db_connect();
		}
	}

	/**
	 * Runs a job in the forked process, then exits.
	 *
	 * @param array    $job    Decoded job.
	 * @param resource $report Socket on which to report reasons to reload WordPress.
	 * @return never
	 */
	private function run_child( $job, $report ) {
		global $wpdb;

		$wpdb->db_connect();
		$this->reset_object_cache();

		if ( ! empty( $job['reload'] ) && is_string( $job['reload'] ) ) {
			$this->reasons[] = $job['reload'];
		}
		$this->watch_for_changes();

		if ( isset( $job['user'] ) && is_string( $job['user'] ) ) {
			$user = ( new Fetchers\User() )->get_check( $job['user'] );
			wp_set_current_user( $user->ID );
		}

		// Report after every other shutdown function has run, as those can
		// change state too. A shutdown function registered while shutdown
		// functions run is appended to the end of the queue.
		register_shutdown_function(
			function () use ( $report ) {
				register_shutdown_function(
					function () use ( $report ) {
						fwrite( $report, (string) json_encode( array_values( array_unique( $this->reasons ) ) ) );
						fclose( $report );
					}
				);
			}
		);

		/** @var string[] $args */
		$args = $job['args'];
		/** @var array<string, string|bool> $assoc_args */
		$assoc_args = $job['assoc_args'];

		WP_CLI::get_runner()->run_command( $args, $assoc_args, [ 'back_compat_conversions' => true ] );

		exit( 0 );
	}

	/**
	 * Empties the in-memory object cache, so the command reads current data.
	 */
	private function reset_object_cache() {
		if ( ! wp_using_ext_object_cache() ) {
			wp_cache_flush();
			return;
		}

		if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
			return;
		}

		// Connect to the cache server afresh rather than sharing the parent's
		// connection, and restore the cache groups that WordPress configures.
		wp_cache_init();
		wp_start_object_cache();
	}

	/**
	 * Watches for changes that the next command would not see without a reload.
	 */
	private function watch_for_changes() {
		$option_changed = function ( $option ) {
			if ( is_string( $option ) && ! $this->is_ignored_option( $option ) ) {
				$this->reasons[] = "option `{$option}` changed";
			}
		};
		foreach ( [ 'added_option', 'updated_option', 'deleted_option', 'add_site_option', 'update_site_option', 'delete_site_option' ] as $hook ) {
			add_action( $hook, $option_changed );
		}

		$events = [
			'activated_plugin'           => 'a plugin was activated',
			'deactivated_plugin'         => 'a plugin was deactivated',
			'deleted_plugin'             => 'a plugin was deleted',
			'switch_theme'               => 'the theme was switched',
			'deleted_theme'              => 'a theme was deleted',
			'upgrader_process_complete'  => 'files were installed or updated',
			'_core_updated_successfully' => 'WordPress was updated',
		];
		foreach ( $events as $hook => $reason ) {
			add_action(
				$hook,
				function () use ( $reason ) {
					$this->reasons[] = $reason;
				}
			);
		}
	}

	/**
	 * Whether a change to an option can be ignored.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function is_ignored_option( $option ) {
		return in_array( $option, self::IGNORED_OPTIONS, true )
			|| 0 === strpos( $option, '_transient_' )
			|| 0 === strpos( $option, '_site_transient_' )
			|| '_children' === substr( $option, -9 );
	}
}
