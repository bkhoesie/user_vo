# User VO - VereinOnline User Authentication for Nextcloud

A Nextcloud authentication plugin that authenticates users against the [VereinOnline](https://vereinonline.org/) API.

## Overview

This plugin enables Nextcloud to authenticate users using their VereinOnline credentials. Passwords are never stored locally - all authentication happens against the remote VereinOnline API server.

**Key Features:**
- External authentication via VereinOnline API
- Automatic user data synchronization (display name, email, profile photo)
- Configurable nightly background sync
- Admin interface for configuration, user management, and sync control
- Support for both `config.php` and admin UI configuration
- See `appinfo/info.xml` for the supported Nextcloud version range

## Architecture

### Components

```
lib/
├── AppInfo/Application.php      # App bootstrap, backend registration
├── UserVOAuth.php               # Main authentication backend
├── Base.php                     # Base class for user backend
├── Controller/                  # HTTP/API layer
│   ├── AdminController.php      # Admin UI entry point
│   ├── ConfigController.php     # Configuration management
│   ├── UserSyncController.php   # User synchronization
│   ├── UserAccountController.php   # Duplicate management
│   ├── UserProvisioningController.php # Pre-provisioning
│   ├── GroupController.php      # Group CRUD operations
│   └── GroupSyncController.php  # Group synchronization
├── Service/                     # Business logic layer
│   ├── ApiClient.php            # VO API communication
│   ├── ConfigService.php        # Configuration management
│   ├── UserSyncService.php      # User sync logic
│   ├── UserAccountService.php   # User account operations
│   ├── UserProvisioningService.php  # Pre-provisioning logic
│   ├── GroupManagementService.php   # Group CRUD operations
│   ├── GroupSyncService.php     # Group sync logic
│   └── GroupNameHarmonizer.php  # Group name normalization
├── Settings/
│   ├── UserVOAdminSettings.php  # Admin UI template
│   └── UserVOAdminSection.php   # Admin section registration
├── Cron/
│   └── SyncUsersJob.php         # Background job for nightly sync
└── Migration/
    └── Version100XDate...php    # Database schema migrations
```

**Architecture Pattern:** Backend → Service ← Controller
- **Controllers:** Thin HTTP/routing layer, delegate to services
- **Services:** All business logic, injected dependencies
- **Backend:** Authentication, login hooks, delegates to services

### Authentication & Sync Flow

1. User enters credentials at Nextcloud login
2. `UserVOAuth::checkCanonicalPassword()` is called
3. API request sent to VereinOnline with credentials
4. On success:
   - User record created/updated in `user_vo` table
   - User data fetched from VO API (`GetMember` endpoint)
   - Display name, email, and optionally photo synced from VO
   - User metadata updated (VO user ID, last sync timestamp)
5. User logged into Nextcloud with fresh data from VO

### Configuration Precedence

The plugin supports two configuration methods with clear precedence:

1. **config.php** (highest priority) - Traditional server configuration
2. **Admin UI** (fallback) - Database-stored configuration via admin interface

`ConfigService` handles loading configuration with proper precedence.

## Installation & Configuration

### Installation

1. Place plugin in Nextcloud's `apps` directory
2. Enable via OCC: `php occ app:enable user_vo`
3. Configure via admin interface or config.php

### Configuration via config.php

Add to your Nextcloud `config/config.php`:

```php
'user_backends' => array(
    array(
        'class' => '\\OCA\\UserVO\\UserVOAuth',
        'arguments' => array(
            'https://vereinonline.org/YOUR_ORGANIZATION', // API URL
            'API_USERNAME',                                // API username
            'API_PASSWORD',                                // API password
        ),
    ),
),
```

**Note:** When configured via `config.php`, the backend is NOT auto-registered by the Application class to avoid conflicts. Nextcloud's core loads backends from this array.

### Configuration via Admin Interface

Navigate to **Settings** → **Administration** → **User VO** to:

**API Configuration:**
- Set API URL, username, and password
- Test connection to VereinOnline API
- View active configuration source

**User Data Synchronization:**
- Configure sync options (email, profile photos)
- Enable/disable nightly automatic sync
- View sync status (last run, success/failed, summary)
- Manually trigger sync for all users
- Preview local users and VO data

**User Account Management:**
- Manage duplicate users (from case-sensitivity bug)
- Scan for and manage user accounts

The admin interface shows which configuration is active and provides comprehensive sync control.

## Database Schema

The plugin uses table `oc_user_vo` (prefix may vary):

```sql
CREATE TABLE oc_user_vo (
    uid VARCHAR(64) PRIMARY KEY,
    displayname VARCHAR(64),
    backend VARCHAR(64),
    vo_user_id VARCHAR(64),           -- VereinOnline user ID
    vo_username VARCHAR(64),          -- Exact VO username (for case-insensitive matching)
    vo_group_ids TEXT,                -- Cached group memberships (JSON)
    last_synced DATETIME,             -- Last sync timestamp
    INDEX idx_vo_user_id (vo_user_id)
);
```

**Column purposes:**
- `uid`, `displayname`, `backend`: Core user identification (legacy)
- `vo_user_id`: Links to VereinOnline user ID for API calls
- `vo_username`: Stores exact VO username for case-insensitive comparison
- `vo_group_ids`: Cached group memberships for future group sync feature
- `last_synced`: Tracks when user data was last synchronized

**Important:** If you modify `user_backends` configuration, update the `backend` field to match, or users will lose their display names.

## Development

### Testing

The plugin includes a multi-layer testing strategy:

**Layer 1: Smoke Tests (Bash)**
```bash
# Run all smoke tests (~10 seconds)
./tests/smoke/test_api.sh

# Run with verbose output
./tests/smoke/test_api.sh --verbose

# Test single endpoint
./tests/smoke/test_api.sh --endpoint /admin/config-status
```

**What gets tested:**
- 8 critical read-only (GET) endpoints
- Config endpoints: status
- User sync: preview local/VO users, nightly sync status
- User account: scan duplicates
- Group endpoints: fetch all/managed groups
- Not `test-config` (POST): correctly requires CSRF protection (it makes the server POST
  to an attacker-controllable URL if unprotected), and this script's plain
  `OCS-APIRequest: true` + Basic Auth doesn't reliably bypass NC's CSRF check for POST on
  NC28/29. Covered instead by `ConfigControllerTest`'s integration tests.

**What's skipped:**
- Destructive operations (save/clear config, create/delete users/groups)
- Sync operations (would modify data)
- Slow endpoints (search takes ~8s - available as commented example)

**Use cases:**
- Quick regression check before/after refactoring
- Verify API endpoints are accessible
- Validate basic response structure
- Pre-commit sanity check

**Layer 2: Unit Tests (PHPUnit)**
```bash
# Run all unit tests
./tests/run-unit-tests.sh

# Run specific test
./tests/run-unit-tests.sh Service/ConfigServiceTest.php
```

**Current coverage:**
- `ConfigService`: Configuration loading precedence, password masking
- `GroupNameHarmonizer`: Name truncation, Unicode handling, fallbacks
- `UserSyncService`: Validation logic, input sanitization
- `UserVOAuth`: `fetchUserDataFromVO()` parsing, `fetchMembersMapForUsers()` fuzzy matching
- `ApiClient`: token creation, connection-failure handling
- `SyncUsersJob`: enable/disable flag logic (incl. legacy `enable_nightly_sync`), sync ordering rules
- Additional service unit tests as needed

**What gets tested:**
- Service layer business logic with mocked dependencies
- Pure functions and data transformations
- Edge cases and error conditions
- No external dependencies (database, filesystem, network)

**Use cases:**
- Fast feedback during development (~60ms for all tests)
- Test-driven development workflow
- Ensure logic correctness before integration
- Document expected behavior

**Layer 3: Integration Tests (PHPUnit + Nextcloud)**
```bash
# Run all integration tests
./tests/run-integration-tests.sh

# Run specific test
./tests/run-integration-tests.sh Service/GroupManagementServiceTest.php
```

**Current coverage:**
- `ConfigController`: Configuration save/clear/test
- `UserAccountController`: Duplicate scanning, expose/hide users
- `UserProvisioningController`: Account creation, bulk provisioning
- `GroupController`: Group CRUD operations
- `GroupSyncController`: Group member synchronization
- `UserSyncController`: User data synchronization
- All controllers have comprehensive integration test coverage
- `Base`/`UserVOAuth`: login flow (`checkCanonicalPassword()`), case-insensitive matching, duplicate-marker safety net
- `UserProvisioningService`, `UserSyncService`, `UserAccountService`: real-DB coverage of search, sync, and duplicate-scan logic
- `GroupDeletedListener`: managed-group cleanup on NC group deletion

**What gets tested:**
- Real database operations (not mocked)
- Nextcloud services integration (IGroupManager, etc.)
- End-to-end service workflows
- External APIs still mocked (VereinOnline)

**Use cases:**
- Verify database schema works correctly
- Test service integration with Nextcloud
- Catch SQL errors and type mismatches
- Comprehensive workflow testing (~100ms per test)

**Important:** Integration tests require `@group DB` annotation to access the database.

See `tests/README.md` for detailed testing documentation and examples.

**Layer 4: Static Analysis (Psalm)**
```bash
composer install
vendor/bin/psalm --no-cache
```

Config: `psalm.xml` (errorLevel 3, targets PHP 8.0 - this app's min supported PHP). Uses the
official `nextcloud/ocp` stub package for `OCP\*` types. Pre-existing findings that are stub-
package gaps rather than real bugs (`\OC`, `Doctrine\DBAL\*`, the legacy `\OC_User_Backend` base
class - none of these are covered by the OCP-only stub package) are recorded in
`tests/psalm-baseline.xml`. Runs once against a single `nextcloud/ocp` version in CI, not a full
NC-version matrix - a deliberate simplicity/cost tradeoff for this app's size, not an oversight.

**Layer 5: JS Tests (Jest)**
```bash
cd js
npm install
npm test
```

Covers the functions in `js/admin.js` that don't depend on closure-captured DOM state:
`renderExposeCheckbox`, `renderGroups`, `renderCreationDate`, `generateSyncSummaryHTML`,
`generatePhotoErrorsHTML`, `renderGroupStatusBadge`, `renderGroupActions`,
`addPlaceholdersForMissingParents`, `sortGroupsHierarchically`, plus `escapeHtml`/
`formatDateTime`. Most of `admin.js` is still nested inside a single `DOMContentLoaded` closure
and isn't reachable for unit testing - only functions with no closure-captured state get hoisted
to module scope for this. `js/tests/setup.js` stubs the Nextcloud-provided `t()` and `moment`
globals these functions rely on.

Closure-bound interactive behavior (button clicks, keydown listeners) is covered separately in
`js/tests/admin.interactions.test.js`, which loads the real `admin.js` into a fully isolated
per-test JSDOM instance (via `js/tests/domFixture.js`) and drives real DOM events. See that file's
header comment for a documented, narrow Jest+jsdom harness quirk it works around (not an
application bug - verified independently multiple ways).

**Layer 6: Live VO API Contract Tests**
```bash
# Fill in .env.vo-test first (see that file - gitignored, never commit it)
./tests/run-live-api-tests.sh
```

Runs against the **real** VereinOnline API using a dedicated test API account + test member
account + test group - there's no isolated VO sandbox org, so this is real production
infrastructure, just with synthetic accounts that don't touch real member data. Scope is
deliberately read-only: `VerifyLogin` with known-good credentials (called at most once per run,
cached - see `VoApiContractTest::resolveTestMemberId()`), `GetMember`, `GetMembers`, and groups
listing. Deliberately not testing a wrong-password path (real VO lockout risk). Skipped (not
failed) automatically when the `VO_TEST_*` environment variables aren't set, so it's safe to be
absent from regular dev machines. CI runs this via `.github/workflows/live-api-tests.yml` on
`workflow_dispatch` + a nightly schedule only - never on push/PR, to avoid hitting production VO
on every commit. Credentials are stored as GitHub Actions secrets, uploaded via
`scripts/upload-vo-test-secrets.sh` (reads `.env.vo-test`, pipes each value to `gh secret set` via
stdin - never as a CLI arg or in shell history).

### Creating a Release

Follow these steps to create a new release (see `readme-dev.md` for details):

1. **Update version** in `appinfo/info.xml` (e.g., to `0.3.0`)
2. **Update CHANGELOG.md** with the new version, date, and changes. Add the diff link at the bottom.
3. **Commit all changes**: `git add . && git commit -m "Release v0.3.0"`
4. **Build appstore package**: `make appstore`
5. **Verify archive contents**: `tar -tzf build/artifacts/appstore/user_vo.tar.gz`
   - Check for accidental temp/config files that shouldn't be included
6. **Test the package locally** (`<version>` = whichever nextcloud-docker-dev container you
   currently test against, e.g. `stable33`):
   - Comment out apps-extra volume mount in `docker-compose.yml`
   - Recreate container: `docker compose up -d --force-recreate <version>`
   - Copy and extract: `cp build/artifacts/appstore/user_vo.tar.gz data/shared/`
   - Install: `docker compose exec <version> tar -xzf /shared/user_vo.tar.gz -C /var/www/html/apps/ && docker compose exec <version> occ app:enable user_vo`
   - Test functionality, then clean up and restore volume mount
7. **Push to remote**: `git push origin main`
8. **Authenticate with GitHub** (if needed): `gh auth login`
9. **Create GitHub release**: `gh release create v0.3.0`
10. **Upload package**: `gh release upload v0.3.0 build/artifacts/appstore/user_vo.tar.gz`
11. **Create signature**: `openssl dgst -sha512 -sign ~/.nextcloud/certificates/user_vo.key build/artifacts/appstore/user_vo.tar.gz | openssl base64`
12. **Submit to Nextcloud appstore**: https://apps.nextcloud.com/developer/apps/releases/new
    - Use the signature and GitHub release asset link

### Building Release Packages

```bash
# Build appstore package
make appstore

# Output: build/artifacts/appstore/user_vo.tar.gz
```

The Makefile excludes development files (tests, Makefile, readme-dev.md, etc.) from the appstore package.

### ⚠️ Security: .gitignore and Makefile Exclusions

**IMPORTANT:** Whenever you add a file to `.gitignore`, also ensure it's excluded from the package in the `Makefile`.

This is **critical for PHP files** that might contain credentials (config files, test files, etc.):

1. **Add to `.gitignore`:**
   ```
   test_vo_api.php
   config_*.php
   ```

2. **Add to `Makefile` exclusions:**
   ```makefile
   --exclude="../$(app_name)/test_vo_api.php" \
   --exclude="../$(app_name)/config_*.php" \
   ```

**Why this matters:**
- `.gitignore` prevents accidental commits to version control
- Makefile exclusions prevent credentials from being packaged in releases
- Both layers of protection are necessary

**Files to watch for:**
- `test_*.php` - Test scripts with API credentials
- `config_*.php` - Configuration files with passwords
- `temp_*.php` - Temporary files that might contain sensitive data

### Commit Message Guidelines

**Before committing:**
- Always run automated tests (unit, integration, smoke)
- Always test changes manually in the UI
- Verify all functionality works as expected

**Commit message style:**
- Keep messages concise - describe what changed, not how you got there
- Use neutral, factual tone
- Avoid references to local paths, file locations, or irrelevant background info
- Focus on the actual change and its purpose

### Key Files for Development

- `appinfo/info.xml` - App metadata, version, dependencies
- `CHANGELOG.md` - Version history
- `Makefile` - Build and packaging scripts
- `readme-dev.md` - Release process documentation

### Code Structure Notes

**UserVOAuth.php:**
- Extends `Base.php` which implements Nextcloud's user backend interface
- Constructor accepts optional arguments for backward compatibility
- Falls back to `ConfigService` when no arguments provided
- `checkCanonicalPassword()` performs actual authentication via API
- `makeRequest()` handles HTTP communication with VereinOnline API
- `fetchMembersMapForUsers()` implements fuzzy name matching for auto-populate:
  - Extracts name parts from "Lastname, Firstname" format
  - Scores each member based on substring/Levenshtein distance matches
  - Prioritizes likely candidates and checks them first
  - Stops early once all target users are found

**Service Layer Architecture:**
- `ApiClient`: Centralized VO API communication
- `UserSyncService`: User data synchronization logic
- `GroupSyncService`: Group member synchronization logic
- `UserAccountService`: User account operations (duplicates, metadata)
- `UserProvisioningService`: Pre-provisioning logic (search, create accounts)
- `GroupManagementService`: Group CRUD operations
- `ConfigService`: Configuration management with precedence handling
- `GroupNameHarmonizer`: Group name normalization and special character handling

**Controller Architecture:**
- Multiple focused controllers organized by feature area
- AdminController serves as UI entry point
- Each controller delegates to corresponding service (thin HTTP wrappers)
- Clean separation: HTTP concerns in controllers, business logic in services

**SyncUsersJob.php:**
- Background job for nightly user and group synchronization
- Injects `UserSyncService` and `GroupSyncService` directly
- Proper separation: cron job → service (no HTTP layer)
- Stores execution metadata in app config for admin UI display

**Case Sensitivity Fix (v0.2.0):**
- Usernames now normalized to lowercase on creation
- Existing users with mixed-case usernames remain functional
- Admin UI provides duplicate management tools

## User Data Synchronization

### Sync Behavior

**VereinOnline is the source of truth** - all user data is automatically synchronized:

- **On every login**: User data is fetched from VO and updated in Nextcloud
- **Nightly sync** (optional): Background job runs every 24 hours when enabled
- **Manual sync**: Admins can trigger immediate sync for all users

**What gets synced:**
- ✅ Display name (firstname + lastname) - always enabled
- ✅ Email address - configurable (enabled by default)
- ✅ Profile photo - configurable (disabled by default)

**Important:** Manual changes to user data in Nextcloud will be overwritten on next sync.

### Upgrade from v0.2.2 to v0.3.0

When upgrading to v0.3.0, the first sync automatically populates VO user IDs for existing users:

1. **Database migration** adds new columns (vo_user_id, vo_username, vo_group_ids, last_synced)
2. **First sync** (manual or nightly) auto-populates missing vo_user_ids:
   - Calls VereinOnline GetMembers API to get all members
   - Uses fuzzy name matching to prioritize likely candidates
   - Matches NC usernames to VO userlogin fields
   - Typically finds all users in 3-4 API calls (instead of 100+)
3. **Subsequent syncs** use populated vo_user_ids normally

**No user action required** - users don't need to log in again after upgrade.

### Nightly Sync Configuration

Background job settings (in admin interface):
- **Disabled by default** - must be explicitly enabled
- **Interval**: Runs every 24 hours
- **Execution tracking**: Stores last run time, status, error messages, sync summary
- **Admin visibility**: Shows Last run → Status → Summary with color-coded badges

### Background Job Management

The nightly sync is implemented as a Nextcloud background job (`lib/Cron/SyncUsersJob.php`). It:
- Checks if sync is enabled before running
- Calls `AdminController::syncFromVO()` to reuse manual sync logic
- Ensures consistency between manual and automatic syncs
- Stores execution tracking in app config (no additional database tables)
- Handles errors gracefully and logs detailed information

## API Integration

### VereinOnline API Authentication

The plugin uses token-based authentication:

```php
$token = 'A/' . $username . '/' . md5($password);
```

### API Endpoints Used

**VerifyLogin** - User authentication:
```
POST {api_url}/?api=VerifyLogin
Authorization: {token}
Content-Type: application/json

{
    "user": "username",
    "password": "password",
    "result": "id"
}
```
Response on success: `["user_id"]`

**GetMember** - Fetch user data:
```
POST {api_url}/?api=GetMember
Authorization: {token}
Content-Type: application/json

{
    "id": "user_id"
}
```
Response: User object with fields `vorname`, `nachname`, `p_email`, `foto`, `gruppenids`, etc.

**GetMembers** - Fetch user list with photo URLs:
```
POST {api_url}/?api=GetMembers
Authorization: {token}
Content-Type: application/json

{}
```
Response: Array of user objects with `fotourl` field containing full photo URLs

## Troubleshooting

### Check Logs

Nextcloud logs are at `data/nextcloud.log`:

```bash
# Search for user_vo entries
grep 'user_vo' data/nextcloud.log
```

Common log messages:
- `"Using configuration from constructor parameters"` - Using config.php
- `"UserVO configuration is incomplete"` - Missing API credentials
- `"API request failed"` - Network/connectivity issues
- `"User authentication error"` - Invalid credentials

### Common Issues

**Users can't log in:**
- Check configuration is complete (API URL, username, password)
- Test API connection in admin interface
- Verify VereinOnline API is accessible
- Check logs for authentication errors

**Display names lost:**
- Verify `backend` field in `user_vo` table matches config
- Check if `user_backends` configuration changed

**Case sensitivity duplicates:**
- Use admin interface duplicate management tools (v0.2.0+)
- See CHANGELOG.md for migration details

## Version History

See [CHANGELOG.md](CHANGELOG.md) for detailed version history.

## License

AGPL-3.0 - See [LICENSE](LICENSE)

## Links

- **GitHub:** https://github.com/bkhoesie/user_vo
- **Issues:** https://github.com/bkhoesie/user_vo/issues
- **Nextcloud App Store:** https://apps.nextcloud.com/apps/user_vo

## Credits

Based on [Nextcloud External User Authentication](https://github.com/nextcloud/user_external) plugin.

Author: Nikolaus Demmel
