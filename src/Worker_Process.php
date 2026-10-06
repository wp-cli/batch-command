<?php

namespace WP_CLI\Batch;

use WP_CLI;
use WP_CLI\Utils;

/**
 * A `wp batch worker` process, seen from the `wp batch run` side.
 *
 * Jobs are sent to the worker on file descriptor 3, one JSON object per line,
 * and the worker answers each with one JSON line on file descriptor 4. Standard
 * output and standard error are shared, so command output appears directly.
 */
class Worker_Process {

	/**
	 * @var resource
	 */
	private $process;

	/**
	 * Write end of the job pipe.
	 *
	 * @var resource
	 */
	private $jobs;

	/**
	 * Read end of the result pipe.
	 *
	 * @var resource
	 */
	private $results;

	/**
	 * @param resource $process Process handle.
	 * @param resource $jobs    Write end of the job pipe.
	 * @param resource $results Read end of the result pipe.
	 */
	private function __construct( $process, $jobs, $results ) {
		$this->process = $process;
		$this->jobs    = $jobs;
		$this->results = $results;
	}

	/**
	 * Starts a worker.
	 *
	 * @param resource|null $stdin Standard input for commands, or null for none.
	 * @return Worker_Process
	 */
	public static function start( $stdin ) {
		$command = self::wp_command( 'batch worker' );

		$descriptors = [
			0 => $stdin ?: [ 'file', '/dev/null', 'r' ],
			1 => STDOUT,
			2 => STDERR,
			3 => [ 'pipe', 'r' ],
			4 => [ 'pipe', 'w' ],
		];

		$env                        = getenv();
		$env['WP_CLI_BATCH_WORKER'] = '1';

		WP_CLI::debug( "Starting worker: {$command}", 'batch' );

		$pipes   = [];
		$process = Utils\proc_open_compat( $command, $descriptors, $pipes, null, $env );

		if ( ! is_resource( $process ) ) {
			WP_CLI::error( 'Failed to start a worker process.' );
		}

		return new self( $process, $pipes[3], $pipes[4] );
	}

	/**
	 * Builds a command line that runs a WP-CLI command with the same PHP binary,
	 * alias and global parameters as the current process.
	 *
	 * @param string $command WP-CLI command, without a leading `wp`.
	 * @return string
	 */
	public static function wp_command( $command ) {
		/** @var string[] $argv */
		$argv = $GLOBALS['argv'];

		list( , , $runtime_config ) = WP_CLI::get_configurator()->parse_args( array_slice( $argv, 1 ) );

		$line  = Utils\esc_cmd( '%s %s', Utils\get_php_binary(), $argv[0] );
		$alias = WP_CLI::get_runner()->alias;
		if ( $alias ) {
			$line .= ' ' . escapeshellarg( '@' . $alias );
		}
		if ( $runtime_config ) {
			$line .= ' ' . Utils\assoc_args_to_str( $runtime_config );
		}

		return $line . ' ' . $command;
	}

	/**
	 * Runs a job in the worker.
	 *
	 * @param Job $job Job to run.
	 * @return array{code: int, reload: bool, reasons: string[]} Exit code of the
	 *     command, and whether and why the worker has exited to reload WordPress.
	 */
	public function run( Job $job ) {
		$message = json_encode(
			[
				'args'       => $job->args,
				'assoc_args' => (object) $job->assoc_args,
				'user'       => $job->user,
				'reload'     => $job->reload,
			]
		);

		// Writing fails if the worker has already exited, in which case reading
		// the result below fails as well.
		@fwrite( $this->jobs, $message . "\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@fflush( $this->jobs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$line   = fgets( $this->results );
		$result = false === $line ? null : json_decode( $line, true );

		if ( ! is_array( $result ) || ! isset( $result['code'] ) || ! is_int( $result['code'] ) ) {
			// The worker exited before it could run the job, most likely because
			// WordPress failed to load. It has printed why to standard error.
			$code = $this->close();
			return [
				'code'    => $code ? $code : 1,
				'reload'  => true,
				'reasons' => [ 'the worker exited unexpectedly' ],
			];
		}

		$reasons = isset( $result['reasons'] ) && is_array( $result['reasons'] ) ? array_values( array_filter( $result['reasons'], 'is_string' ) ) : [];

		if ( $reasons ) {
			$this->close();
		}

		return [
			'code'    => $result['code'],
			'reload'  => (bool) $reasons,
			'reasons' => $reasons,
		];
	}

	/**
	 * Tells the worker to exit, and waits for it.
	 *
	 * @return int Exit code of the worker.
	 */
	public function close() {
		if ( is_resource( $this->jobs ) ) {
			fclose( $this->jobs );
		}
		if ( is_resource( $this->results ) ) {
			fclose( $this->results );
		}
		return proc_close( $this->process );
	}
}
