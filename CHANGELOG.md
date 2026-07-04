# Changelog

All notable changes to Quickpoll are documented here.

## [1.1.0] - 2026-07-04

### Added
- Close a poll: the creator receives a secret token stored in a cookie when the poll is created. While that cookie is present, a Close poll action is shown. Once closed, the poll records a closed state and closed_at timestamp, voting is disabled, and only final results are shown.
- Optional expiry at creation: choose 1 hour, 1 day, or never. Expired polls stop accepting votes and display final results automatically.
- Live results now show a subtle pulse and per-bar animation when tallies change.
- Light and dark theme toggle, persisted in the browser via localStorage, applied before first paint to avoid a flash.
- Self-migrating schema: new columns (token, closed, closed_at, expires_at) are added to existing databases via a PRAGMA table_info check and guarded ALTER TABLE, keeping older data intact.
- New route POST /?p=CODE with action=close for closing a poll.

### Changed
- The live JSON endpoint now also reports locked state and the lock reason (closed or expired).
- Voting is rejected server-side when a poll is closed or expired.
- Refined result bars and general UI polish while keeping the clean look.

## [1.0.0] - 2026-07-04

Initial release.
