#!/bin/bash
# Manual testing script for refactored controllers
# Tests UserAccountController and GroupController via curl

set -e

BASE_URL="${BASE_URL:-http://stable31.local/index.php/apps/user_vo}"
USERNAME="${USERNAME:-admin}"
PASSWORD="${PASSWORD:-admin}"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "=========================================="
echo "Manual Controller Testing Script"
echo "=========================================="
echo "Base URL: $BASE_URL"
echo "Username: $USERNAME"
echo ""

# Helper function to make API calls
api_call() {
    local method=$1
    local endpoint=$2
    local data=$3
    local description=$4

    echo -e "${BLUE}Testing:${NC} $description"
    echo "  Endpoint: $method $endpoint"

    if [ -n "$data" ]; then
        response=$(curl -s -w "\n%{http_code}" -X "$method" \
            -H "OCS-APIRequest: true" \
            -H "Content-Type: application/json" \
            -u "$USERNAME:$PASSWORD" \
            "$BASE_URL$endpoint" \
            -d "$data")
    else
        response=$(curl -s -w "\n%{http_code}" -X "$method" \
            -H "OCS-APIRequest: true" \
            -u "$USERNAME:$PASSWORD" \
            "$BASE_URL$endpoint")
    fi

    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')

    if [ "$http_code" -eq 200 ]; then
        echo -e "  ${GREEN}✓ PASS${NC} (HTTP $http_code)"
        echo "  Response: $(echo "$body" | jq -c '.' 2>/dev/null || echo "$body" | head -c 100)"
        echo ""
        return 0
    else
        echo -e "  ${RED}✗ FAIL${NC} (HTTP $http_code)"
        echo "  Response: $body"
        echo ""
        return 1
    fi
}

# Track test results
total_tests=0
passed_tests=0

run_test() {
    total_tests=$((total_tests + 1))
    if "$@"; then
        passed_tests=$((passed_tests + 1))
    fi
}

echo "=========================================="
echo "Part 1: UserAccountController Tests"
echo "=========================================="
echo ""

# 1. Scan for duplicate users
run_test api_call "GET" "/admin/scan-duplicates" "" \
    "Scan for duplicate user accounts"

# 2. Test expose/hide user (need a test user first)
# Note: These tests require an actual user to exist, so they may fail
# if no suitable test user is present
echo -e "${YELLOW}Note: Expose/Hide tests require existing users with specific UIDs${NC}"
echo ""

echo "=========================================="
echo "Part 2: GroupController Tests"
echo "=========================================="
echo ""

# 3. Fetch all VO groups
run_test api_call "GET" "/admin/fetch-all-vo-groups" "" \
    "Fetch all groups from VereinOnline"

# 4. Fetch managed groups
run_test api_call "GET" "/admin/fetch-managed-groups" "" \
    "Fetch managed groups from database"

# 5. Create a test group (use a group that exists in VO)
echo -e "${YELLOW}Note: Create group test requires a valid VO group ID${NC}"
echo "First, let's fetch available groups..."
response=$(curl -s -H "OCS-APIRequest: true" -u "$USERNAME:$PASSWORD" \
    "$BASE_URL/admin/fetch-all-vo-groups")

# Try to find a group that's not managed
test_group_id=$(echo "$response" | jq -r '.groups[]? | select(.is_managed == false) | .vo_group_id' | head -n1)

if [ -n "$test_group_id" ] && [ "$test_group_id" != "null" ]; then
    echo "Found unmanaged group: $test_group_id"
    echo ""

    # 6. Create single group
    run_test api_call "POST" "/admin/create-group" \
        "{\"vo_group_id\": \"$test_group_id\"}" \
        "Create single group from VO"

    # 7. Try to create same group again (should fail with 409)
    echo -e "${BLUE}Testing:${NC} Create duplicate group (should fail)"
    response=$(curl -s -w "\n%{http_code}" -X POST \
        -H "OCS-APIRequest: true" \
        -H "Content-Type: application/json" \
        -u "$USERNAME:$PASSWORD" \
        "$BASE_URL/admin/create-group" \
        -d "{\"vo_group_id\": \"$test_group_id\"}")

    http_code=$(echo "$response" | tail -n1)
    if [ "$http_code" -eq 409 ]; then
        echo -e "  ${GREEN}✓ PASS${NC} (HTTP $http_code - correctly rejected duplicate)"
        passed_tests=$((passed_tests + 1))
    else
        echo -e "  ${RED}✗ FAIL${NC} (HTTP $http_code - expected 409)"
    fi
    total_tests=$((total_tests + 1))
    echo ""

    # 8. Delete the test group
    run_test api_call "POST" "/admin/delete-group" \
        "{\"vo_group_id\": \"$test_group_id\"}" \
        "Delete single group"

    # 9. Try to delete same group again (should fail with 404)
    echo -e "${BLUE}Testing:${NC} Delete non-existent group (should fail)"
    response=$(curl -s -w "\n%{http_code}" -X POST \
        -H "OCS-APIRequest: true" \
        -H "Content-Type: application/json" \
        -u "$USERNAME:$PASSWORD" \
        "$BASE_URL/admin/delete-group" \
        -d "{\"vo_group_id\": \"$test_group_id\"}")

    http_code=$(echo "$response" | tail -n1)
    if [ "$http_code" -eq 404 ]; then
        echo -e "  ${GREEN}✓ PASS${NC} (HTTP $http_code - correctly rejected non-existent)"
        passed_tests=$((passed_tests + 1))
    else
        echo -e "  ${RED}✗ FAIL${NC} (HTTP $http_code - expected 404)"
    fi
    total_tests=$((total_tests + 1))
    echo ""
else
    echo -e "${YELLOW}Skipping create/delete tests: No unmanaged groups available${NC}"
    echo ""
fi

# 10. Test bulk operations (need multiple unmanaged groups)
unmanaged_groups=$(echo "$response" | jq -r '.groups[]? | select(.is_managed == false) | .vo_group_id' | head -n3)
group_count=$(echo "$unmanaged_groups" | wc -l | tr -d ' ')

if [ "$group_count" -ge 2 ]; then
    group_ids=$(echo "$unmanaged_groups" | jq -R -s -c 'split("\n") | map(select(length > 0))')

    echo -e "${BLUE}Testing:${NC} Bulk create groups ($group_count groups)"
    run_test api_call "POST" "/admin/bulk-create-groups" \
        "{\"vo_group_ids\": $group_ids}" \
        "Bulk create multiple groups"

    echo -e "${BLUE}Testing:${NC} Bulk delete groups ($group_count groups)"
    run_test api_call "POST" "/admin/bulk-delete-groups" \
        "{\"vo_group_ids\": $group_ids}" \
        "Bulk delete multiple groups"
else
    echo -e "${YELLOW}Skipping bulk tests: Need at least 2 unmanaged groups (found $group_count)${NC}"
    echo ""
fi

echo "=========================================="
echo "Summary"
echo "=========================================="
echo -e "Total tests: $total_tests"
echo -e "${GREEN}Passed: $passed_tests${NC}"
echo -e "${RED}Failed: $((total_tests - passed_tests))${NC}"
echo ""

if [ $passed_tests -eq $total_tests ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed!${NC}"
    exit 1
fi
