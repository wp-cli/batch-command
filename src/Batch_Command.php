<?php

namespace WP_CLI\Batch;

use WP_CLI;
use WP_CLI\Utils;
use WP_CLI_Command;

/**
 * Runs many WP-CLI commands while loading WordPress only once.
 *
 * ## EXAMPLES
 *
 *     # Run the commands in a file.
 *     $ wp batch run commands.txt
 *
 *     # Run commands from standard input.
 *     $ printf 'post create --post_title=One\npost create --post_title=Two\n' | wp batch run
 */
class Batch_Command extends WP_CLI_Command {

	/**
	 * Runs a list of WP-CLI commands.
	 *
	 * Reads one command per line, with or without a leading `wp`. Blank lines
	 * and lines starting with `#` are skipped.
	 *
	 * WordPress is loaded once, in a worker process, and each command runs in
	 * a copy of that process that is forked for it. As a result, commands run
	 * isolated from each other like separate `wp` invocations would, but
	 * without loading WordPress every time.
	 *
	 * After a command that changes something WordPress reads while it loads,
	 * like an option, the active plugins or the theme, the worker loads
	 * WordPress again before running the next command.
	 *
	 * `wp config`, `wp core`, `wp db`, `wp package`, `wp cli`, `wp server` and
	 * `wp shell` commands, and lines with global parameters other than
	 * `--user`, like `--url` or `--skip-plugins`, run in their own process
	 * instead. So does every command on Windows, or when the PHP `pcntl`
	 * extension isn't available.
	 *
	 * Global parameters given to `wp batch run` apply to every command.
	 *
	 * Commands read standard input only when the commands come from a file.
	 * Pass `--yes` to commands that would otherwise ask for confirmation.
	 *
	 * Use `--debug=batch` to see how long each command took, and why WordPress
	 * was loaded again.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : File to read commands from. Reads from standard input if omitted or `-`.
	 *
	 * [--stop-on-error]
	 * : Stop at the first command that fails, and exit with its exit code.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run the commands in a file.
	 *     $ cat commands.txt
	 *     option update blogname "My Site"
	 *     wp plugin activate hello
	 *     post create --post_title="Hello" --post_status=publish --porcelain
	 *     $ wp batch run commands.txt
	 *     Success: Updated 'blogname' option.
	 *     Plugin 'hello' activated.
	 *     Success: Activated 1 of 1 plugins.
	 *     4
	 *     Success: Ran 3 commands.
	 *
	 *     # Create many posts, loading WordPress only once.
	 *     $ for i in $(seq 1 100); do echo "post create --post_title='Post $i'"; done | wp batch run
	 *
	 *     # Run commands for another site of a multisite network.
	 *     $ wp batch run commands.txt --url=example.com/blog
	 *
	 *     # Stop at the first failing command.
	 *     $ wp batch run commands.txt --stop-on-error
	 *
	 * @when before_wp_load
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Associative arguments.
	 */
	public function run( $args, $assoc_args ) {
		$file          = isset( $args[0] ) ? $args[0] : '-';
		$stop_on_error = (bool) Utils\get_flag_value( $assoc_args, 'stop-on-error', false );

		if ( '-' === $file ) {
			$input = stream_get_contents( STDIN );
			$stdin = null;
		} else {
			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				WP_CLI::error( "Could not read file '{$file}'." );
			}
			$input = file_get_contents( $file );
			$stdin = STDIN;
		}

		$jobs = Job::parse_input( (string) $input );
		if ( empty( $jobs ) ) {
			WP_CLI::warning( 'No commands to run.' );
			return;
		}

		$fork = ! getenv( 'WP_CLI_BATCH_NO_FORK' );
		if ( $fork && Utils\is_windows() ) {
			WP_CLI::debug( 'Forking is not available on Windows, so each command runs in its own process.', 'batch' );
			$fork = false;
		} elseif ( $fork && ! $this->can_fork() ) {
			WP_CLI::warning( 'The pcntl extension is not available, so each command runs in its own process.' );
			$fork = false;
		}

		$worker = null;
		$failed = 0;
		$total  = count( $jobs );

		foreach ( $jobs as $job ) {
			$start = microtime( true );

			if ( ! $fork || $job->isolated ) {
				if ( $worker ) {
					$worker->close();
					$worker = null;
				}
				if ( $job->isolated ) {
					WP_CLI::debug( "Line {$job->line_number}: Running in its own process because {$job->isolated}.", 'batch' );
				}
				$code = $this->run_isolated( $job, $stdin );
			} else {
				if ( ! $worker ) {
					$worker = Worker_Process::start( $stdin );
				}
				$result = $worker->run( $job );
				$code   = $result['code'];
				if ( $result['reload'] ) {
					$worker = null;
					WP_CLI::debug( "Line {$job->line_number}: Reloading WordPress because " . implode( ', ', $result['reasons'] ) . '.', 'batch' );
				}
			}

			WP_CLI::debug( sprintf( 'Line %d: Exited with %d after %.0f ms.', $job->line_number, $code, 1000 * ( microtime( true ) - $start ) ), 'batch' );

			if ( 0 !== $code ) {
				++$failed;
				if ( $stop_on_error ) {
					if ( $worker ) {
						$worker->close();
					}
					WP_CLI::warning( "Stopped at line {$job->line_number} after the command failed: {$job->command}" );
					WP_CLI::halt( $code );
				}
			}
		}

		if ( $worker ) {
			$worker->close();
		}

		if ( $failed ) {
			WP_CLI::error( "{$failed} of {$total} commands failed." );
		}

		WP_CLI::success( 1 === $total ? 'Ran 1 command.' : "Ran {$total} commands." );
	}

	/**
	 * Runs a job in a new `wp` process.
	 *
	 * @param Job           $job   Job to run.
	 * @param resource|null $stdin Standard input for the command, or null for none.
	 * @return int Exit code.
	 */
	private function run_isolated( Job $job, $stdin ) {
		if ( Utils\is_windows() && PHP_VERSION_ID >= 70400 ) {
			return $this->run_isolated_on_windows( $job, $stdin );
		}

		$command     = Worker_Process::wp_command( $job->command );
		$descriptors = [
			0 => $stdin ?: [ 'file', Utils\is_windows() ? 'NUL' : '/dev/null', 'r' ],
			1 => STDOUT,
			2 => STDERR,
		];

		$pipes   = [];
		$process = Utils\proc_open_compat( $command, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			WP_CLI::warning( "Line {$job->line_number}: Failed to start a process for the command." );
			return 1;
		}

		return proc_close( $process );
	}

	/**
	 * Runs a job in a new `wp` process on Windows.
	 *
	 * The arguments are passed as an array so that they don't go through
	 * cmd.exe, which doesn't understand single quotes. Output goes to temporary
	 * files first, because child processes that write to the files this process
	 * writes to can overwrite each other's output.
	 *
	 * @param Job           $job   Job to run.
	 * @param resource|null $stdin Standard input for the command, or null for none.
	 * @return int Exit code.
	 */
	private function run_isolated_on_windows( Job $job, $stdin ) {
		$argv        = Worker_Process::wp_argv( Utils\parse_str_to_argv( $job->command ) );
		$stdout_file = (string) tempnam( sys_get_temp_dir(), 'wp-batch-' );
		$stderr_file = (string) tempnam( sys_get_temp_dir(), 'wp-batch-' );
		$descriptors = [
			0 => $stdin ?: [ 'file', 'NUL', 'r' ],
			1 => [ 'file', $stdout_file, 'w' ],
			2 => [ 'file', $stderr_file, 'w' ],
		];

		$pipes   = [];
		$process = proc_open( $argv, $descriptors, $pipes );
		$code    = is_resource( $process ) ? proc_close( $process ) : null;

		fwrite( STDOUT, (string) file_get_contents( $stdout_file ) );
		fwrite( STDERR, (string) file_get_contents( $stderr_file ) );
		unlink( $stdout_file );
		unlink( $stderr_file );

		if ( null === $code ) {
			WP_CLI::warning( "Line {$job->line_number}: Failed to start a process for the command." );
			return 1;
		}

		return $code;
	}

	/**
	 * Whether commands can run in forked processes.
	 *
	 * @return bool
	 */
	private function can_fork() {
		foreach ( [ 'pcntl_fork', 'pcntl_waitpid', 'proc_open', 'stream_socket_pair' ] as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}

		return true;
	}
}
