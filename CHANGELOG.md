# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-08-03

### Added
- **Group Management**: create, sync, and manage Nextcloud groups from VereinOnline groups directly
  in the admin interface
  - Hierarchical group display matching VereinOnline's parent/child structure
  - Create individual groups, or bulk-create/delete
  - Membership synced automatically on login and via a nightly background job, plus manual/selective sync
  - Detects groups renamed or deleted in VereinOnline, and flags a group whose Nextcloud
    counterpart was deleted directly (e.g. via Nextcloud's own group management) - with a
    "Recreate" action to restore it, or "Delete" to drop the stale tracking entry
  - Refuses to create or adopt a Nextcloud group already managed by a different backend (e.g.
    LDAP), so membership sync never silently takes over a group it doesn't fully control
  - Member count tracking (VereinOnline vs. other members) and a VO Groups column in the user
    sync table
- **Audit Log**: a new "Advanced" section in the admin interface recording login failures,
  account/group creation and deletion, group membership changes, sync failures, and configuration
  changes - useful for troubleshooting without needing direct access to the server's log file
  - View recent entries, download the full log as a text file, or clear it
  - Entries are kept for 7 days by default (configurable)

### User Management
- Pre-provisioning and account creation now detect when a VereinOnline username collides with an
  existing local Nextcloud account managed by a different authentication backend, and refuse to
  provision it - instead of silently creating an ambiguous identity, or misleadingly reporting
  "account exists"
- Pre-provisioned accounts now have their VereinOnline group memberships synced immediately on
  creation, instead of showing empty until first login

### Fixed
- Fatal errors on Nextcloud 33 and 34 caused by internal Nextcloud APIs being removed upstream -
  could affect login, user lookup, and the plugin's own startup registration on those versions
- Several state-changing admin actions (saving configuration, triggering sync) were missing CSRF
  protection
- Admin endpoints returned a generic server error, instead of a clear message, when VereinOnline
  is unreachable or not yet configured
- Rare data-integrity edge cases under concurrent access: two simultaneous group-creation requests,
  or two devices logging in as a brand-new user at the same time, could previously create
  duplicate/conflicting database entries
- A rare race condition where a user's group membership could be briefly restored right after
  being removed in VereinOnline, if two synchronization runs overlapped
- Several crashes triggered by unexpected or incomplete data from the VereinOnline API (missing
  member name fields, malformed responses, etc.)
- Date/time displays across the admin interface are now consistently locale-aware and
  timezone-correct

### Changed
- `sync_photo` and both nightly sync options now default to enabled for new installs (existing
  installs that already explicitly set these keep their current value)
- The `enable_nightly_sync` config key has been renamed to `enable_nightly_user_sync` (existing
  values are migrated automatically on upgrade)
- All requests to VereinOnline, including login, now time out after 10 seconds (5 seconds to
  connect) instead of being unbounded - a login against a very slow or unresponsive VereinOnline
  server will now fail faster instead of potentially hanging

## [0.3.3] - 2026-07-30

### Fixed
- Fatal error on Nextcloud 33: `QueryBuilder::execute()` was removed upstream; replaced all
  calls with `executeQuery()`/`executeStatement()` in `Base.php` (the user backend - affects
  every login and user lookup) and in legacy duplicate-management code in `AdminController.php`.

### Changed
- Raised supported Nextcloud version ceiling to 34 (`max-version`) after verifying
  compatibility against Nextcloud 33.0.7 and 34.0.2.

## [0.3.2] - 2025-10-12

### Added
- Photo sync error reporting shows specific errors and warning icons in admin UI

### Fixed
- Photo sync crop coordinates are now properly cast to integers to avoid TypeError
- Pre-provision search now handles usernames with dots (e.g., "john.doe")

## [0.3.1] - 2025-10-12

### Added
- Selective user sync in admin interface to help diagnose sync issues

### Fixed
- Photo sync now checks file size before downloading (10MB limit) to prevent memory exhaustion
- Photo sync errors no longer crash the entire user sync process
- Improved error handling catches fatal errors (Throwable instead of Exception)

## [0.3.0] - 2025-10-11

### Added
- **User Data Synchronization**: Display name, email, and profile photos are now automatically synchronized from VereinOnline
  - Syncs on every login
  - Configurable: enable/disable email sync and photo sync separately
  - VereinOnline is the source of truth - manual changes in Nextcloud will be overwritten
- **Nightly Automatic Sync**: Optional background job to keep user data up-to-date without requiring login
  - Disabled by default, enable via admin interface
  - Runs every 24 hours when enabled
  - Shows execution status and summary
- **Pre-provision User Accounts**: Create accounts for users before their first login
  - Search for users by name in VereinOnline
  - Create accounts individually or in bulk
  - Accounts are fully configured and ready to use immediately
- **Manual Sync UI**: Admin interface for managing user synchronization
  - View current user data in Nextcloud
  - Preview data from VereinOnline without syncing
  - Trigger immediate sync for all users
  - See detailed sync results

### Changed
- **Upgrade Note**: When upgrading from v0.2.2, user IDs are automatically migrated when you first run a manual sync or enable nightly sync. Users don't need to log in again - the migration happens in the background.
- Improved dark mode appearance in admin interface

## [0.2.2] - 2025-10-04

### Fixed
- Test Configuration button now works correctly when config.php is configured but database is not

## [0.2.1] - 2025-10-04

### Fixed
- Dark mode styling for admin interface - improved contrast and readability
- Status boxes now properly adapt to both light and dark themes

## [0.2.0] - 2025-10-04

### Fixed
- Case sensitivity bug causing duplicate user accounts for different username
  capitalizations (#2). New users are now created with lowercase usernames while
  maintaining backwards compatibility for existing users.

### Added
- Admin interface for managing duplicate user accounts caused by case sensitivity bug
- Admin interface for managing plugin configuration (API URL, username, password)
  with support for both config.php and database-based configuration

## [0.1.2] - 2024-06-07

### Changed
- Move to new logger syntax for Nextcloud 31 compatibility.

## [0.1.0] - 2022-09-03

### Fixed
- Fixed invalid info.xml

### Added
- Initial version of the UserVO plugin with username and password support.

[unreleased]: https://github.com/bkhoesie/user_vo/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/bkhoesie/user_vo/compare/v0.3.3...v0.4.0
[0.3.3]: https://github.com/bkhoesie/user_vo/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/bkhoesie/user_vo/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/bkhoesie/user_vo/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/bkhoesie/user_vo/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/bkhoesie/user_vo/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/bkhoesie/user_vo/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/bkhoesie/user_vo/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/bkhoesie/user_vo/compare/v0.1.0...v0.1.2
[0.1.0]: https://github.com/bkhoesie/user_vo/releases/tag/v0.1.0
