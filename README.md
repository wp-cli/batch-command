wp-cli/batch-command
====================

Runs many WP-CLI commands while loading WordPress only once.

[![Testing](https://github.com/wp-cli/batch-command/actions/workflows/testing.yml/badge.svg)](https://github.com/wp-cli/batch-command/actions/workflows/testing.yml) [![Code Coverage](https://codecov.io/gh/wp-cli/batch-command/branch/main/graph/badge.svg)](https://codecov.io/gh/wp-cli/batch-command/tree/main)

Quick links: [Using](#using) | [Installing](#installing) | [Contributing](#contributing) | [Support](#support)

## Using

This package implements the following commands:

### wp batch

Runs many WP-CLI commands while loading WordPress only once.

~~~
wp batch
~~~

**EXAMPLES**

    # Run the commands in a file.
    $ wp batch run commands.txt

    # Run commands from standard input.
    $ printf 'post create --post_title=One\npost create --post_title=Two\n' | wp batch run



### wp batch run

Runs a list of WP-CLI commands.

~~~
wp batch run [<file>] [--stop-on-error]
~~~

Reads one command per line, with or without a leading `wp`. Blank lines
and lines starting with `#` are skipped.

WordPress is loaded once, in a worker process, and each command runs in
a copy of that process that is forked for it. As a result, commands run
isolated from each other like separate `wp` invocations would, but
without loading WordPress every time.

After a command that changes something WordPress reads while it loads,
like an option, the active plugins or the theme, the worker loads
WordPress again before running the next command.

`wp config`, `wp core`, `wp db`, `wp package`, `wp cli`, `wp server` and
`wp shell` commands, and lines with global parameters other than
`--user`, like `--url` or `--skip-plugins`, run in their own process
instead. So does every command on Windows, or when the PHP `pcntl`
extension isn't available.

Global parameters given to `wp batch run` apply to every command.

Commands read standard input only when the commands come from a file.
Pass `--yes` to commands that would otherwise ask for confirmation.

Use `--debug=batch` to see how long each command took, and why WordPress
was loaded again.

**OPTIONS**

	[<file>]
		File to read commands from. Reads from standard input if omitted or `-`.

	[--stop-on-error]
		Stop at the first command that fails, and exit with its exit code.

**EXAMPLES**

    # Run the commands in a file.
    $ cat commands.txt
    option update blogname "My Site"
    wp plugin activate hello
    post create --post_title="Hello" --post_status=publish --porcelain
    $ wp batch run commands.txt
    Success: Updated 'blogname' option.
    Plugin 'hello' activated.
    Success: Activated 1 of 1 plugins.
    4
    Success: Ran 3 commands.

    # Create many posts, loading WordPress only once.
    $ for i in $(seq 1 100); do echo "post create --post_title='Post $i'"; done | wp batch run

    # Run commands for another site of a multisite network.
    $ wp batch run commands.txt --url=example.com/blog

    # Stop at the first failing command.
    $ wp batch run commands.txt --stop-on-error

## Installing

Installing this package requires WP-CLI v3 or greater. Update to the latest stable release with `wp cli update`.

Once you've done so, you can install the latest stable version of this package with:

```bash
wp package install wp-cli/batch-command:@stable
```

To install the latest development version of this package, use the following command instead:

```bash
wp package install wp-cli/batch-command:dev-main
```

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.

For a more thorough introduction, [check out WP-CLI's guide to contributing](https://make.wordpress.org/cli/handbook/contributing/). This package follows those policy and guidelines.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/wp-cli/batch-command/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/wp-cli/batch-command/issues/new). Include as much detail as you can, and clear steps to reproduce if possible. For more guidance, [review our bug report documentation](https://make.wordpress.org/cli/handbook/bug-reports/).

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/wp-cli/batch-command/issues/new) to discuss whether the feature is a good fit for the project.

Once you've decided to commit the time to seeing your pull request through, [please follow our guidelines for creating a pull request](https://make.wordpress.org/cli/handbook/pull-requests/) to make sure it's a pleasant experience. See "[Setting up](https://make.wordpress.org/cli/handbook/pull-requests/#setting-up)" for details specific to working on this package locally.

### License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Support

GitHub issues aren't for general support questions. For support resources and next steps, see the WP-CLI Support page: https://make.wordpress.org/cli/handbook/support/


*This README.md is generated dynamically from the project's codebase using `wp scaffold package-readme` ([doc](https://github.com/wp-cli/scaffold-package-command#wp-scaffold-package-readme)). To suggest changes, please submit a pull request against the corresponding part of the codebase.*
