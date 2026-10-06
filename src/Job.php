<?php

namespace WP_CLI\Batch;

use WP_CLI;
use WP_CLI\Utils;

/**
 * A single command from a batch file.
 */
class Job {

	/**
	 * Commands that always run in a separate process.
	 *
	 * They either work without WordPress being loaded, or change files and
	 * tables that WordPress reads while it boots.
	 *
	 * @var string[]
	 */
	const ISOLATED_COMMANDS = [ 'cli', 'config', 'core', 'db', 'package', 'server', 'shell' ];

	/**
	 * Commands that change state in ways the worker can't detect through hooks.
	 *
	 * The worker reloads WordPress after running any of these.
	 *
	 * @var string[]
	 */
	const RELOAD_COMMANDS = [ 'language', 'maintenance-mode', 'search-replace', 'site' ];

	/**
	 * Line number in the batch input, starting at 1.
	 *
	 * @var int
	 */
	public $line_number;

	/**
	 * The command as written, without a leading `wp`.
	 *
	 * @var string
	 */
	public $command;

	/**
	 * Positional arguments, including the command name.
	 *
	 * @var string[]
	 */
	public $args;

	/**
	 * Associative arguments.
	 *
	 * @var array<string, string|bool>
	 */
	public $assoc_args;

	/**
	 * User to run the command as, from a `--user` global parameter on the line.
	 *
	 * @var string|null
	 */
	public $user;

	/**
	 * Why the command runs in a separate process, or an empty string if it doesn't.
	 *
	 * @var string
	 */
	public $isolated = '';

	/**
	 * Why WordPress must be reloaded after this command, or an empty string if it needn't.
	 *
	 * @var string
	 */
	public $reload = '';

	/**
	 * @param int                        $line_number Line number in the batch input.
	 * @param string                     $command     The command, without a leading `wp`.
	 * @param string[]                   $args        Positional arguments.
	 * @param array<string, string|bool> $assoc_args  Associative arguments.
	 */
	public function __construct( $line_number, $command, $args, $assoc_args ) {
		$this->line_number = $line_number;
		$this->command     = $command;
		$this->args        = $args;
		$this->assoc_args  = $assoc_args;
	}

	/**
	 * Reads jobs from batch input.
	 *
	 * Blank lines and lines starting with `#` are skipped, and a leading `wp` is
	 * removed from each command.
	 *
	 * @param string $input Contents of the batch file.
	 * @return Job[]
	 */
	public static function parse_input( $input ) {
		$jobs  = [];
		$lines = preg_split( '/\r\n|\r|\n/', $input );

		foreach ( $lines ?: [] as $index => $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}

			$jobs[] = self::from_line( $index + 1, $line );
		}

		return $jobs;
	}

	/**
	 * Creates a job from one line of batch input.
	 *
	 * @param int    $line_number Line number in the batch input.
	 * @param string $line        The trimmed line.
	 * @return Job
	 */
	public static function from_line( $line_number, $line ) {
		$command = (string) preg_replace( '/^wp\s+/', '', $line );
		$argv    = Utils\parse_str_to_argv( $command );

		if ( '' !== $argv[0] && '@' === $argv[0][0] ) {
			WP_CLI::error( "Line {$line_number}: Aliases are not supported in a batch. Pass the alias to `wp batch run` instead." );
		}

		/** @var array<string, string|bool> $assoc_args */
		list( $args, $assoc_args, $runtime_config ) = WP_CLI::get_configurator()->parse_args( $argv );

		if ( empty( $args ) ) {
			WP_CLI::error( "Line {$line_number}: Missing a command name." );
		}

		$job = new self( $line_number, $command, $args, $assoc_args );

		// The worker can switch users for a single command, but every other
		// global parameter takes effect while WordPress loads.
		if ( isset( $runtime_config['user'] ) && is_string( $runtime_config['user'] ) ) {
			$job->user = $runtime_config['user'];
			unset( $runtime_config['user'] );
		}

		if ( ! empty( $runtime_config ) ) {
			$job->isolated = 'it sets global parameters';
		} elseif ( in_array( $args[0], self::ISOLATED_COMMANDS, true ) ) {
			$job->isolated = "`wp {$args[0]}` commands always run in their own process";
		} elseif ( in_array( $args[0], self::RELOAD_COMMANDS, true ) ) {
			$job->reload = "`wp {$args[0]}` commands always reload WordPress";
		}

		return $job;
	}
}
