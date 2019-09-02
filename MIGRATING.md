## Migrating from version 3.x to 4.x

Most users will not be affected by changes in version 4.x of the CLI.

A few may be, particularly those writing or maintaining scripts or snippets.

### PHP version

The minimum PHP version has increased from 5.5.9 to 7.1.0.

### Property display

There are several commands that display raw properties from the API:

  - environment:info
  - project:info
  - subscription:info
  - activity:get
  - certificate:get
  - commit:get
  - domain:get
  - integration:get
  - route:get
  - user:get
  - variable:get (vget)

In version 3.x, all non-string properties were formatted as YAML.

This naturally led to a confusion between the string `null` and a real null
value, both of which would have been formatted as `null`.

In version 4.x, null values are output as empty strings.

Additionally, when viewing a single property (via the `[property]` argument for
the "info" commands or the `--property` option for the "get" commands), in
version 4.x the trailing newline character has been removed.

Example:

    # in version 3.x
    $ platform environment:info parent
    null
    $

    # in version 4.x
    $ platform environment:info parent
    $

Search for comparisons to the string `null` in your scripts. Change these to
compare with an empty string, e.g.

    # previous comparison:

    [ "$parent" = null ]

    # becomes:

    [ -z "$parent" ]

> _Note:_ Boolean properties are still formatted as the YAML `true` and
> `false`. These are considered OK and left as-is, because there are no
> properties that accept both Boolean and string types.
