#!/usr/bin/env bash
# Tests the CLI installer.
# This must be run from the repository root.

cat ./dist/installer.php | php -- --manifest ./dist/manifest.json --dev
