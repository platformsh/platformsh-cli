The **Platform.sh CLI** is the official command-line interface for [Platform.sh](https://platform.sh). Use this tool to interact with your [Platform.sh](https://platform.sh) projects, and to build them locally for development purposes.

[![Build Status](https://travis-ci.org/platformsh/platformsh-cli.svg)](https://travis-ci.org/platformsh/platformsh-cli) [![License](https://poser.pugx.org/platformsh/cli/license)](https://github.com/platformsh/platformsh-cli/blob/master/LICENSE)

## Requirements

* Operating system: Linux, OS X, or Windows 10
* PHP 7.1 or higher
* Git
* A Bash-like shell:
  * On OS X or Linux/Unix: SH, Bash, Dash or ZSH - usually the built-in shell will work.
  * On Windows: [Windows Subsystem for Linux](https://msdn.microsoft.com/en-gb/commandline/wsl/about) (recommended), or another Bash-compatible shell such as [Git Bash](https://git-for-windows.github.io/), Cygwin, or MinGW.
* For building locally, your project's dependencies, e.g.
  * [Composer](https://getcomposer.org/) (for many PHP projects)
  * [Drush](https://github.com/drush-ops/drush) (for Drupal projects)
  * Other build tools: [npm](https://www.npmjs.com/), [pip](http://docs.python-guide.org/en/latest/starting/installation/), [bundler](http://bundler.io/), etc.

## Installation

### Installing on OS X or Linux

This is the recommended installation method. Simply use this command:

    curl -sS https://platform.sh/cli/installer | php
    
### Installing on Windows (Git bash)
```bash
curl https://platform.sh/cli/installer -o cli-installer.php
php cli-installer.php
```

### Installing manually

1. Download the `platform.phar` file from the
  [latest release](https://github.com/platformsh/platformsh-cli/releases/latest).

2. Rename the file to `platform`, ensure it is executable, and move it into a
  directory in your PATH (use `echo $PATH` to see your options).

3. Enable autocompletion and shell aliases:

        platform self:install

## Updating

New releases of the CLI are made regularly. Update with this command:

    platform self:update

## Usage

You can run the Platform.sh CLI in your shell by typing `platform`.

    platform

Use the 'list' command to get a list of available options and commands:

    platform list

### Commands

The current output of `platform list` is as follows:

```
Platform.sh CLI

Global options:
  --help           -h Display this help message
  --quiet          -q Do not output any message
  --verbose        -v|vv|vvv Increase the verbosity of messages
  --version        -V Display this application version
  --yes            -y Answer "yes" to all prompts; disable interaction
  --no             -n Answer "no" to all prompts

Available commands:
  clear-cache (clearcache, cc)              Clear the CLI cache
  decode                                    Decode an encoded string such as PLATFORM_VARIABLES
  docs                                      Open the online documentation
  help                                      Displays help for a command
  list                                      Lists commands
  multi                                     Execute a command on multiple projects
  web                                       Open the Web UI
activity
  activity:get                              View detailed information on a single activity
  activity:list (activities, act)           Get a list of activities for an environment or project
  activity:log                              Display the log for an activity
app
  app:config-get                            View the configuration of an app
  app:list (apps)                           List apps in the project
auth
  auth:browser-login (login)                Log in to Platform.sh via a browser
  auth:info                                 Display your account information
  auth:logout (logout)                      Log out of Platform.sh
  auth:password-login                       Log in to Platform.sh using a username and password
certificate
  certificate:add                           Add an SSL certificate to the project
  certificate:delete                        Delete a certificate from the project
  certificate:get                           View a certificate
  certificate:list (certificates, certs)    List project certificates
commit
  commit:get                                Show commit details
  commit:list (commits)                     List commits
db
  db:dump                                   Create a local dump of the remote database
  db:size                                   Estimate the disk usage of a database
  db:sql (sql)                              Run SQL on the remote database
domain
  domain:add                                Add a new domain to the project
  domain:delete                             Delete a domain from the project
  domain:get                                Show detailed information for a domain
  domain:list (domains)                     Get a list of all domains
  domain:update                             Update a domain
environment
  environment:activate                      Activate an environment
  environment:branch (branch)               Branch an environment
  environment:checkout (checkout)           Check out an environment
  environment:delete                        Delete an environment
  environment:drush (drush)                 Run a drush command on the remote environment
  environment:http-access (httpaccess)      Update HTTP access settings for an environment
  environment:info                          Read or set properties for an environment
  environment:init                          Initialize an environment from a public Git repository
  environment:list (environments, env)      Get a list of environments
  environment:logs (log)                    Read an environment's logs
  environment:merge (merge)                 Merge an environment
  environment:push (push)                   Push code to an environment
  environment:redeploy (redeploy)           Redeploy an environment
  environment:relationships (relationships) Show an environment's relationships
  environment:ssh (ssh)                     SSH to the current environment
  environment:synchronize (sync)            Synchronize an environment's code and/or data from its parent
  environment:url (url)                     Get the public URLs of an environment
integration
  integration:add                           Add an integration to the project
  integration:delete                        Delete an integration from a project
  integration:get                           View details of an integration
  integration:list (integrations)           View a list of project integration(s)
  integration:update                        Update an integration
  integration:validate                      Validate an existing integration
local
  local:build (build)                       Build the current project locally
  local:dir (dir)                           Find the local project root
  local:drush-aliases (drush-aliases)       Find the project's Drush aliases
mount
  mount:download                            Download files from a mount, using rsync
  mount:list (mounts)                       Get a list of mounts
  mount:size                                Check the disk usage of mounts
  mount:upload                              Upload files to a mount, using rsync
project
  project:clear-build-cache                 Clear a project's build cache
  project:create (create)                   Create a new project
  project:delete                            Delete a project
  project:get (get)                         Clone a project locally
  project:info                              Read or set properties for a project
  project:list (projects, pro)              Get a list of all active projects
  project:set-remote                        Set the remote project for the current Git repository
repo
  repo:cat                                  Read a file in the project repository
  repo:ls                                   List files in the project repository
route
  route:get                                 View a resolved route
  route:list (routes)                       List all routes for an environment
self
  self:install                              Install or update CLI configuration files
  self:update (self-update)                 Update the CLI to the latest version
service
  service:list (services)                   List services in the project
  service:mongo:dump (mongodump)            Create a binary archive dump of data from MongoDB
  service:mongo:export (mongoexport)        Export data from MongoDB
  service:mongo:restore (mongorestore)      Restore a binary archive dump of data into MongoDB
  service:mongo:shell (mongo)               Use the MongoDB shell
  service:redis-cli (redis)                 Access the Redis CLI
snapshot
  snapshot:create                           Make a snapshot of an environment
  snapshot:list (snapshots)                 List available snapshots of an environment
  snapshot:restore                          Restore an environment snapshot
ssh-key
  ssh-key:add                               Add a new SSH key
  ssh-key:delete                            Delete an SSH key
  ssh-key:list (ssh-keys)                   Get a list of SSH keys in your account
tunnel
  tunnel:close                              Close SSH tunnels
  tunnel:info                               View relationship info for SSH tunnels
  tunnel:list (tunnels)                     List SSH tunnels
  tunnel:open                               Open SSH tunnels to an app's relationships
  tunnel:single                             Open a single SSH tunnel to an app relationship
user
  user:add                                  Add a user to the project
  user:delete                               Delete a user from the project
  user:get                                  View a user's role(s)
  user:list (users)                         List project users
  user:update                               Update user role(s) on a project
variable
  variable:create                           Create a variable
  variable:delete                           Delete a variable
  variable:get (vget)                       View a variable
  variable:list (variables, var)            List variables
  variable:update                           Update a variable
worker
  worker:list (workers)                     Get a list of all deployed workers
```

## Known issues

### Caching

The CLI caches details of your projects and their environments, and some other
information. These caches could become out-of-date. You can clear caches with
the command `platform clear-cache` (or `platform cc` for short).

## Authentication

There are currently three ways to authenticate:

1. `platform login` (AKA `platform auth:browser-login`): this opens a temporary
  local server and a browser, allowing you to log in to Platform.sh via the
  normal login form, including via services like Bitbucket, GitHub and Google.

2. `platform auth:password-login`: this allows you to log in with a username and
  password, and a two-factor token if applicable.

3. [API tokens](https://docs.platform.sh/gettingstarted/cli/api-tokens.html):
  these allow non-interactive authentication. See
  [Customization](#customization) below for how to use an API token. Remember to
  use a separate machine account if you want to limit the token's access.

## Customization

You can configure the CLI via the user configuration file `~/.platformsh/config.yaml`.
These are the possible keys, and their default values:

```yaml
api:
  # A path (relative or absolute) to a file containing an API token.
  # The file should be stored with minimal permissions.
  # Run 'platform logout --all' if you change this value.
  token_file: null

application:
  # The method used for interactive login: 'browser' or 'password' (defaults to
  # 'browser').
  login_method: browser

  # The default timezone for times displayed or interpreted by the CLI.
  # An empty (falsy) value here means the PHP or system timezone will be used.
  # For a list of timezones, see: http://php.net/manual/en/timezones.php
  timezone: ~

  # The default date format string, for dates and times displayed by the CLI.
  # For a list of formats, see: http://php.net/manual/en/function.date.php
  date_format: c

local:
  # Set this to true to avoid some Windows symlink issues.
  copy_on_windows: false

  # Configure the Drush executable to use (defaults to 'drush').
  drush_executable: null

updates:
  # Whether to check for automatic updates.
  check: true

  # The interval between checking for updates (in seconds). 604800 = 7 days.
  check_interval: 604800
```

Other customization is available via environment variables:

* `PLATFORMSH_CLI_DEBUG`: set to 1 to enable cURL debugging. _Warning_: this will print all request information in the terminal, including sensitive access tokens.
* `PLATFORMSH_CLI_DISABLE_CACHE`: set to 1 to disable caching
* `PLATFORMSH_CLI_NO_COLOR`: enable (set to 1) to force colorized output off
* `PLATFORMSH_CLI_SESSION_ID`: change user session (default 'default')
* `PLATFORMSH_CLI_SHELL_CONFIG_FILE`: specify the shell configuration file that the installer should write to (as an absolute path). If not set, a file such as `~/.bashrc` will be chosen automatically. Set this to an empty string to disable writing to a shell config file.
* `PLATFORMSH_CLI_TOKEN`: an API token. _Warning_: storing a secret in an environment variable can be insecure. It may be better to use `config.yaml` as above, depending on your system. The environment variable is preferable on CI systems like Jenkins and GitLab.
* `PLATFORMSH_CLI_UPDATES_CHECK`: set to 0 to disable the automatic updates check
* `CLICOLOR_FORCE`: set to 1 or 0 to force colorized output on or off, respectively
* `http_proxy` or `https_proxy`: specify a proxy for connecting to Platform.sh

## Migrating

See [MIGRATING.md](MIGRATING.md) for help on migrating between major versions.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to contribute to the CLI.
