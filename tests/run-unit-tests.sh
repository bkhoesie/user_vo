#!/bin/bash
#
# User VO Plugin - Unit Test Runner
#
# Runs PHPUnit unit tests inside the Nextcloud container
#
# Usage:
#   ./tests/run-unit-tests.sh                    # Run all unit tests
#   ./tests/run-unit-tests.sh Service/ConfigServiceTest.php  # Run specific test

set -e

CONTAINER="${CONTAINER:-stable31}"
TEST_FILE="${1:-}"

cd "$(dirname "$0")/.."

if [ -n "$TEST_FILE" ]; then
    echo "Running unit test: $TEST_FILE"
    docker compose exec -u 33 "$CONTAINER" bash -c \
        "cd /var/www/html/apps-shared/user_vo && phpunit -c tests/Unit/phpunit.xml tests/Unit/$TEST_FILE"
else
    echo "Running all unit tests..."
    docker compose exec -u 33 "$CONTAINER" bash -c \
        "cd /var/www/html/apps-shared/user_vo && phpunit -c tests/Unit/phpunit.xml"
fi
