#!/usr/bin/env bash

# Runs the plugins' tests using the panel's PHPUnit installation, from inside the
# throwaway panel checkout. Extra arguments pass through, e.g.
#
#     scripts/test.sh --filter NpmFamilyDriver
#
# Only the plugins' own test directories are run, not the panel's whole suite:
# collecting the panel's tests pulls in pre-existing warnings of its own, which
# make PHPUnit exit non-zero and would report a passing plugin as a failure.

set -euo pipefail

"$(dirname "$0")/panel-setup.sh"

PANEL="${PANEL:-/panel}"
cd "$PANEL"

suites=()
for dir in plugins/*/tests/Unit/*/; do
    [ -d "$dir" ] || continue
    suites+=("tests/Unit/$(basename "$dir")")
done

if [ "${#suites[@]}" -eq 0 ]; then
    echo "No plugin tests found."
    exit 0
fi

status=0
for suite in "${suites[@]}"; do
    echo "==> $suite"
    vendor/bin/phpunit "$suite" "$@" || status=$?
done

exit "$status"
