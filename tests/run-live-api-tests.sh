#!/bin/bash
#
# User VO Plugin - Live VO API Contract Test Runner
#
# Runs PHPUnit tests against the REAL VereinOnline API (a dedicated test
# account, but real production infrastructure - no VO sandbox org exists).
# Opt-in only: not part of run-unit-tests.sh / run-integration-tests.sh.
#
# Reads credentials from .env.vo-test in the repo root (gitignored - never
# commit it). Parses it directly rather than sourcing it, since passwords may
# contain shell metacharacters that `source` would try to interpret.
#
# Usage:
#   ./tests/run-live-api-tests.sh

set -euo pipefail

CONTAINER="${CONTAINER:-stable33}"

cd "$(dirname "$0")/.."

ENV_FILE=".env.vo-test"
if [ ! -f "$ENV_FILE" ]; then
    echo "Error: $ENV_FILE not found. See .env.vo-test for the expected format." >&2
    exit 1
fi

get_value() {
    local key="$1"
    while IFS='=' read -r line_key line_value; do
        [[ -z "$line_key" || "$line_key" == \#* ]] && continue
        if [ "$line_key" = "$key" ]; then
            printf '%s' "$line_value"
            return 0
        fi
    done < "$ENV_FILE"
}

REQUIRED_VARS=(
    VO_TEST_API_URL
    VO_TEST_API_USERNAME
    VO_TEST_API_PASSWORD
    VO_TEST_MEMBER_USERNAME
    VO_TEST_MEMBER_PASSWORD
)

ENV_ARGS=()
missing=0
for var in "${REQUIRED_VARS[@]}"; do
    value="$(get_value "$var")"
    if [ -z "$value" ]; then
        echo "Error: $var is empty in $ENV_FILE" >&2
        missing=1
        continue
    fi
    ENV_ARGS+=(-e "$var=$value")
done
if [ "$missing" -ne 0 ]; then
    exit 1
fi

echo "Running live VO API contract tests against container '$CONTAINER'..."
docker compose exec -u 33 "${ENV_ARGS[@]}" "$CONTAINER" bash -c \
    "cd /var/www/html/apps-shared/user_vo && phpunit -c tests/LiveApi/phpunit.xml"
