#!/bin/bash
#
# User VO Plugin - API Smoke Tests
#
# Quick regression tests to verify all API endpoints are accessible
# and return expected responses. Run before/after refactoring to
# ensure nothing breaks.
#
# Usage:
#   ./tests/smoke/test_api.sh
#   ./tests/smoke/test_api.sh --verbose
#   ./tests/smoke/test_api.sh --endpoint /admin/config-status
#

# Note: deliberately no `set -e` - test_endpoint() returns 1 on a failed check by
# design, so the summary/exit-code logic at the bottom can report ALL failures
# instead of the script silently aborting after the first one.

# Configuration
BASE_URL="${BASE_URL:-http://stable33.local/index.php/apps/user_vo}"
USERNAME="${USERNAME:-admin}"
PASSWORD="${PASSWORD:-admin}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Counters
PASSED=0
FAILED=0
SKIPPED=0

# Flags
VERBOSE=0
SINGLE_ENDPOINT=""

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --verbose|-v)
            VERBOSE=1
            shift
            ;;
        --endpoint|-e)
            SINGLE_ENDPOINT="$2"
            shift 2
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --verbose, -v          Show detailed output"
            echo "  --endpoint, -e PATH    Test only specific endpoint"
            echo "  --help, -h             Show this help message"
            echo ""
            echo "Environment variables:"
            echo "  BASE_URL    Base URL (default: http://stable31.local/index.php/apps/user_vo)"
            echo "  USERNAME    Admin username (default: admin)"
            echo "  PASSWORD    Admin password (default: admin)"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Helper function to test an endpoint
test_endpoint() {
    local method=$1
    local endpoint=$2
    local expected_status=$3
    local expected_content=$4
    local description=$5
    local post_data=$6

    # Skip if testing single endpoint and this isn't it
    if [ -n "$SINGLE_ENDPOINT" ] && [ "$endpoint" != "$SINGLE_ENDPOINT" ]; then
        return
    fi

    echo -n "Testing ${method} ${endpoint}"
    if [ -n "$description" ]; then
        echo -n " (${description})"
    fi
    echo -n "... "

    # Build curl command
    local curl_cmd="curl -s -w \"\n%{http_code}\" \
        -X ${method} \
        -H \"OCS-APIRequest: true\" \
        -u ${USERNAME}:${PASSWORD}"

    # Add POST data if provided
    if [ -n "$post_data" ]; then
        curl_cmd="${curl_cmd} -H \"Content-Type: application/json\" -d '${post_data}'"
    fi

    curl_cmd="${curl_cmd} \"${BASE_URL}${endpoint}\""

    # Execute request
    response=$(eval $curl_cmd)
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')

    # Check HTTP status
    if [ "$http_code" != "$expected_status" ]; then
        echo -e "${RED}FAIL${NC} (status: $http_code, expected: $expected_status)"
        FAILED=$((FAILED + 1))
        if [ $VERBOSE -eq 1 ]; then
            echo "  Response body: $body"
        fi
        return 1
    fi

    # Check content if specified
    if [ -n "$expected_content" ]; then
        if ! echo "$body" | jq -e "$expected_content" > /dev/null 2>&1; then
            echo -e "${RED}FAIL${NC} (content check failed)"
            FAILED=$((FAILED + 1))
            if [ $VERBOSE -eq 1 ]; then
                echo "  Expected: $expected_content"
                echo "  Response: $body"
            fi
            return 1
        fi
    fi

    echo -e "${GREEN}PASS${NC}"
    PASSED=$((PASSED + 1))
    if [ $VERBOSE -eq 1 ]; then
        echo "  Response: $body" | head -c 200
        echo ""
    fi
}

# Print header
echo ""
echo "=========================================="
echo "User VO Plugin - API Smoke Tests"
echo "=========================================="
echo "Base URL: $BASE_URL"
echo "Username: $USERNAME"
echo ""

# Config Controller Endpoints
echo -e "${BLUE}Config Endpoints:${NC}"
test_endpoint "GET" "/admin" "200" "" "Admin settings page"
test_endpoint "GET" "/admin/config-status" "200" ".is_configured != null" "Check configuration status"
# Note: Not testing save-config or clear-config to avoid modifying test environment.
# Not testing test-config either (was tested here until it correctly gained CSRF
# protection - it makes the server POST to an attacker-controllable URL, so it's not
# actually safe to exempt): OCS-APIRequest + Basic Auth alone doesn't reliably bypass
# NC's CSRF check for POST requests on NC28/29 (works fine on NC30+), and this script
# doesn't fetch a request token. Covered instead by ConfigControllerTest's
# testConfiguration() integration tests, which call the controller directly.

# User Sync Controller Endpoints
echo ""
echo -e "${BLUE}User Sync Endpoints:${NC}"
test_endpoint "GET" "/admin/nightly-sync-status" "200" ".success == true" "Get nightly sync status"
test_endpoint "GET" "/admin/preview-local-users" "200" ".success == true" "Preview local users"
test_endpoint "GET" "/admin/preview-vo-users" "200" ".success == true" "Preview VO users"
# Note: Not testing sync operations to avoid modifying data

# User Account Controller Endpoints
echo ""
echo -e "${BLUE}User Account Endpoints:${NC}"
test_endpoint "GET" "/admin/scan-duplicates" "200" ".success == true" "Scan for duplicate users"
# Note: Not testing expose/hide to avoid modifying users

# User Provisioning Controller Endpoints
echo ""
echo -e "${BLUE}User Provisioning Endpoints:${NC}"
# Note: search-vo-users is slow (~8s) - uncomment if needed for thorough testing
# test_endpoint "GET" "/admin/search-vo-users?search_term=Mustermann" "200" ".success == true" "Search VO users"
# Note: Not testing account creation to avoid creating test users

# Group Controller Endpoints
echo ""
echo -e "${BLUE}Group Endpoints:${NC}"
test_endpoint "GET" "/admin/fetch-all-vo-groups" "200" ".success != null" "Fetch all VO groups"
test_endpoint "GET" "/admin/fetch-managed-groups" "200" ".success == true" "Fetch managed groups"
# Note: Not testing group create/delete to avoid modifying groups

# Group Sync Controller Endpoints
echo ""
echo -e "${BLUE}Group Sync Endpoints:${NC}"
# Note: Not testing sync operations to avoid triggering actual syncs

# Print summary
echo ""
echo "=========================================="
echo "Summary"
echo "=========================================="
echo -e "${GREEN}Passed:${NC}  $PASSED"
if [ $FAILED -gt 0 ]; then
    echo -e "${RED}Failed:${NC}  $FAILED"
fi
if [ $SKIPPED -gt 0 ]; then
    echo -e "${YELLOW}Skipped:${NC} $SKIPPED"
fi
echo ""

# Exit with appropriate code
if [ $FAILED -gt 0 ]; then
    echo -e "${RED}Some tests failed!${NC}"
    exit 1
else
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
fi
