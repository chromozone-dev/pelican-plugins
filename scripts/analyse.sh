#!/usr/bin/env bash

# Runs PHPStan over every plugin, from inside the throwaway panel checkout.
# Any extra arguments are passed through to phpstan.

set -euo pipefail

# Invoked through bash rather than directly: the execute bit cannot be set on a
# Windows checkout, so relying on it makes this fail with exit 126 in CI.
bash "$(dirname "$0")/panel-setup.sh"

cd "${PANEL:-/panel}"

exec vendor/bin/phpstan analyse --memory-limit=-1 "$@"
