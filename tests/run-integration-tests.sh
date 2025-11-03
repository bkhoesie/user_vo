#!/bin/bash
#
# User VO Plugin - Integration Test Runner
#
# Runs PHPUnit integration tests inside the Nextcloud container
# with full Nextcloud environment (database, services, etc.)
#
# Usage:
#   ./tests/run-integration-tests.sh                    # Run all integration tests
#   ./tests/run-integration-tests.sh Service/GroupManagementServiceTest.php  # Run specific test

set -e

CONTAINER="${CONTAINER:-stable31}"
TEST_FILE="${1:-}"

cd "$(dirname "$0")/.."

if [ -n "$TEST_FILE" ]; then
    echo "Running integration test: $TEST_FILE"
    docker compose exec -u 33 "$CONTAINER" bash -c \
        "cd /var/www/html/apps-shared/user_vo && phpunit -c tests/Integration/phpunit.xml tests/Integration/$TEST_FILE"
else
    echo "Running all integration tests..."
    docker compose exec -u 33 "$CONTAINER" bash -c \
        "cd /var/www/html/apps-shared/user_vo && phpunit -c tests/Integration/phpunit.xml"
fi
