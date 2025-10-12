# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[unreleased]: https://github.com/bkhoesie/user_vo/compare/v0.3.1...HEAD
[0.3.1]: https://github.com/bkhoesie/user_vo/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/bkhoesie/user_vo/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/bkhoesie/user_vo/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/bkhoesie/user_vo/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/bkhoesie/user_vo/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/bkhoesie/user_vo/compare/v0.1.0...v0.1.2
[0.1.0]: https://github.com/bkhoesie/user_vo/releases/tag/v0.1.0
