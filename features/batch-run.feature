Feature: Run a batch of WP-CLI commands

  Scenario: Run commands from a file
    Given a WP install
    And a commands.txt file:
      """
      # Comments and blank lines are skipped.

      option get blogname
      wp option get home
      post list --post_type=page --post_status=publish --field=post_title
      """

    When I run `wp batch run commands.txt`
    Then STDOUT should be:
      """
      WP CLI Site
      https://example.com
      Sample Page
      Success: Ran 3 commands.
      """
    And STDERR should be empty

  Scenario: Run commands from standard input
    Given a WP install

    When I run `printf 'option get blogname\noption get home\n' | wp batch run`
    Then STDOUT should be:
      """
      WP CLI Site
      https://example.com
      Success: Ran 2 commands.
      """

    When I run `printf 'option get blogname\n' | wp batch run -`
    Then STDOUT should contain:
      """
      WP CLI Site
      """

  Scenario: Commands see changes made by earlier commands
    Given a WP install
    And a commands.txt file:
      """
      option update blogname "Batch Site"
      option get blogname
      plugin is-active akismet
      plugin activate akismet
      plugin is-active akismet
      plugin deactivate akismet
      eval 'echo is_plugin_active( "akismet/akismet.php" ) ? "active" : "inactive";'
      """

    When I try `wp batch run commands.txt --debug=batch`
    Then the return code should be 1
    And STDOUT should contain:
      """
      Success: Updated 'blogname' option.
      Batch Site
      """
    And STDOUT should contain:
      """
      Plugin 'akismet' activated.
      """
    And STDOUT should contain:
      """
      inactive
      """
    And STDERR should contain:
      """
      Line 1: Reloading WordPress because option `blogname` changed.
      """
    And STDERR should contain:
      """
      Line 4: Reloading WordPress because
      """
    And STDERR should contain:
      """
      Error: 1 of 7 commands failed.
      """

  Scenario: Commands that change nothing WordPress loads share a worker
    Given a WP install
    And a commands.txt file:
      """
      post create --post_title=One --porcelain
      post create --post_title=Two --porcelain
      user create batchuser batchuser@example.com --porcelain
      post list --field=post_title --orderby=ID --order=ASC --post_status=draft
      """

    When I try `wp batch run commands.txt --debug=batch`
    Then the return code should be 0
    And STDOUT should contain:
      """
      One
      Two
      """
    And STDERR should contain:
      """
      Starting worker
      """
    And STDERR should not contain:
      """
      Reloading WordPress
      """

  Scenario: Each command runs in isolation
    Given a WP install
    And a commands.txt file:
      """
      eval 'define( "BATCH_TEST", 1 ); $GLOBALS["batch_test"] = 1; echo "defined\n";'
      eval 'echo defined( "BATCH_TEST" ) || isset( $GLOBALS["batch_test"] ) ? "leaked" : "isolated";'
      """

    When I run `wp batch run commands.txt`
    Then STDOUT should contain:
      """
      defined
      isolated
      """

  Scenario: Failing commands
    Given a WP install
    And a commands.txt file:
      """
      option get blogname
      option get nonexistent_option
      eval 'exit( 3 );'
      option get home
      """

    When I try `wp batch run commands.txt`
    Then the return code should be 1
    And STDOUT should contain:
      """
      WP CLI Site
      """
    And STDOUT should contain:
      """
      https://example.com
      """
    And STDERR should contain:
      """
      Error: Could not get 'nonexistent_option' option. Does it exist?
      Error: 2 of 4 commands failed.
      """

    When I try `wp batch run commands.txt --stop-on-error`
    Then the return code should be 1
    And STDOUT should be:
      """
      WP CLI Site
      """
    And STDERR should contain:
      """
      Warning: Stopped at line 2 after the command failed: option get nonexistent_option
      """

    When I try `printf 'option get blogname\neval "exit( 3 );"\noption get home\n' | wp batch run --stop-on-error`
    Then the return code should be 3
    And STDOUT should not contain:
      """
      https://example.com
      """

  Scenario: Unknown commands and fatal errors
    Given a WP install
    And a commands.txt file:
      """
      not-a-command
      eval 'this_function_does_not_exist();'
      option get blogname
      """

    When I try `wp batch run commands.txt`
    Then the return code should be 1
    And STDERR should contain:
      """
      'not-a-command' is not a registered wp command.
      """
    And STDERR should contain:
      """
      this_function_does_not_exist
      """
    And STDERR should contain:
      """
      Error: 2 of 3 commands failed.
      """
    And STDOUT should contain:
      """
      WP CLI Site
      """

  Scenario: Some commands run in their own process
    Given a WP install
    And a commands.txt file:
      """
      core version
      option get blogname --skip-plugins
      option get blogname
      """

    When I try `wp batch run commands.txt --debug=batch`
    Then the return code should be 0
    And STDERR should contain:
      """
      Line 1: Running in its own process because `wp core` commands always run in their own process.
      """
    And STDERR should contain:
      """
      Line 2: Running in its own process because it sets global parameters.
      """
    And STDOUT should contain:
      """
      WP CLI Site
      WP CLI Site
      Success: Ran 3 commands.
      """

  Scenario: Run a command as another user
    Given a WP install
    And a commands.txt file:
      """
      eval 'echo get_current_user_id(), "\n";' --user=admin
      eval 'echo get_current_user_id(), "\n";'
      eval 'echo get_current_user_id(), "\n";' --user=nobody
      """

    When I try `wp batch run commands.txt`
    Then STDOUT should contain:
      """
      1
      0
      """
    And STDERR should contain:
      """
      Error: Invalid user ID, email or login: 'nobody'
      """
    And the return code should be 1

  Scenario: Global parameters apply to every command
    Given a WP install
    And a commands.txt file:
      """
      eval 'echo get_current_user_id(), "\n";'
      """

    When I run `wp batch run commands.txt --user=admin`
    Then STDOUT should contain:
      """
      1
      """

  Scenario: Run without forking
    Given a WP install
    And a commands.txt file:
      """
      option update blogname "Batch Site"
      option get blogname
      """

    When I run `WP_CLI_BATCH_NO_FORK=1 wp batch run commands.txt`
    Then STDOUT should be:
      """
      Success: Updated 'blogname' option.
      Batch Site
      Success: Ran 2 commands.
      """

  Scenario: Invalid input
    Given an empty directory

    When I try `wp batch run missing.txt`
    Then STDERR should be:
      """
      Error: Could not read file 'missing.txt'.
      """
    And the return code should be 1

    When I try `printf '\n# Nothing to do.\n' | wp batch run`
    Then STDERR should be:
      """
      Warning: No commands to run.
      """
    And the return code should be 0

    When I try `printf 'option get blogname\n@prod option get blogname\n' | wp batch run`
    Then STDERR should be:
      """
      Error: Line 2: Aliases are not supported in a batch. Pass the alias to `wp batch run` instead.
      """
    And the return code should be 1
