#!/bin/bash
#
# Uploads VO test-org credentials from .env.vo-test to GitHub Actions secrets.
# Values are piped to `gh secret set` via stdin - never passed as CLI args
# (which would be briefly visible to other processes via `ps`) and never
# printed or logged.
#
# Usage: ./scripts/upload-vo-test-secrets.sh

set -euo pipefail

cd "$(dirname "$0")/.."

ENV_FILE=".env.vo-test"
REPO="bkhoesie/user_vo"

if [ ! -f "$ENV_FILE" ]; then
    echo "Error: $ENV_FILE not found. Copy/create it first (see .env.vo-test in the repo root)." >&2
    exit 1
fi

REQUIRED_VARS=(
    VO_TEST_API_URL
    VO_TEST_API_USERNAME
    VO_TEST_API_PASSWORD
    VO_TEST_MEMBER_USERNAME
    VO_TEST_MEMBER_PASSWORD
)

# Parse KEY=VALUE lines directly rather than `source`-ing the file: passwords
# can contain shell metacharacters (;, &, backticks, ...) that source/eval
# would try to interpret instead of treating as a literal value. Avoids
# associative arrays (declare -A) since macOS ships bash 3.2, which lacks them.
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

missing=0
for var in "${REQUIRED_VARS[@]}"; do
    if [ -z "$(get_value "$var")" ]; then
        echo "Error: $var is empty in $ENV_FILE" >&2
        missing=1
    fi
done
if [ "$missing" -ne 0 ]; then
    exit 1
fi

for var in "${REQUIRED_VARS[@]}"; do
    echo "Setting $var..."
    get_value "$var" | gh secret set "$var" --repo "$REPO"
done

echo "Done. Verify with: gh secret list --repo $REPO"
