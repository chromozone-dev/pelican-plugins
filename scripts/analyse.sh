#!/usr/bin/env bash

# Runs PHPStan over every plugin, from inside the throwaway panel checkout.
# Any extra arguments are passed through to phpstan.

set -euo pipefail

"$(dirname "$0")/panel-setup.sh"

cd "${PANEL:-/panel}"

exec vendor/bin/phpstan analyse --memory-limit=-1 "$@"
