# Quickpoll

Create a poll in seconds, share a link, and watch the results update live. Built in PHP with file-based SQLite, so there is no database to configure.

![lang](https://img.shields.io/badge/PHP-8.2-777bb4) ![db](https://img.shields.io/badge/SQLite-zero%20config-003b57) ![deps](https://img.shields.io/badge/dependencies-none-6ce0ff) ![license](https://img.shields.io/badge/license-MIT-yellow)

## Features
- Create a poll with a question and 2-8 options, no account required
- Shareable short link for every poll
- Animated result bars with per-option percentages and totals
- Live results that refresh on their own (lightweight JSON polling)
- One vote per browser, enforced with a per-poll cookie
- Server-side validation and escaped output throughout
- Zero database setup: SQLite file is created on first run

## Run it
1. Drop this folder in your XAMPP `htdocs`.
2. Start Apache.
3. Visit `http://localhost/quickpoll/`.

The `data/polls.sqlite` database is created automatically on first load.

## Routes
| Route | Behavior |
|-------|----------|
| `GET /` | The create-a-poll form |
| `POST /` (`action=create`) | Validates and stores a poll, redirects to its page |
| `GET /?p=CODE` | Shows the poll (vote buttons, or results if you have voted) |
| `POST /?p=CODE` (`action=vote`) | Records one vote, sets the per-poll cookie |
| `GET /?p=CODE&json=1` | Returns current tallies as JSON (drives live updates) |

## Notes
Vote de-duplication uses a cookie, which is good enough for casual polls but not ballot-grade. Swapping in IP- or account-based checks would be a natural extension. All persistence lives in [db.php](db.php) and the controller/view in [index.php](index.php).

## License
MIT.
