# Test cases and results

Run: 2026-11-13, PHP 8.3.6 (built-in server) + MariaDB 10.11, automated by `tests/smoke_test.py` (HTTP level, real database).

Result: **53 / 53 checks passed.**

## Checklist from the assignment

| Assignment question | Result | Covered by |
|---|---|---|
| Can a user add a task with valid information? | Pass | "Add task with valid data…" |
| Does the application reject an empty task title? | Pass | "Empty (whitespace-only) title is rejected…", "Edit with empty title is rejected" |
| Can a user edit, update status and delete a task? | Pass | Edit / status / delete checks |
| Do search and status filters return the expected tasks? | Pass | Search, filter, combined, wildcard checks |
| Can a logged-out visitor access the dashboard directly? | No (redirect to login) | "Logged-out visitor…" checks |
| Are passwords stored as hashes? | Yes (bcrypt `$2y$`) | "All stored passwords are bcrypt hashes" |
| Can a user access or modify another user's tasks? | No (404, data unchanged) | "Alice cannot…" checks |
| Do database operations use prepared statements? | Yes (PDO, emulation off) | Code review + SQL-injection checks |
| Invalid input without exposing credentials or raw errors? | Yes | "Database failure shows a generic 500 page…" |

## All test cases

| # | Test case | Result |
|---|---|---|
| 1 | Logged-out visitor is redirected away from the dashboard | PASS |
| 2 | Logged-out visitor cannot open /task_form.php | PASS |
| 3 | Logged-out visitor cannot open /task_form.php?id=1 | PASS |
| 4 | Logged-out POST to task_delete.php is refused (405/302/419) | PASS |
| 5 | All stored passwords are bcrypt hashes (none in plain text) | PASS |
| 6 | Wrong password shows a generic error | PASS |
| 7 | Unknown email shows the same generic error | PASS |
| 8 | Valid login reaches the dashboard | PASS |
| 9 | Add task with valid data shows success message | PASS |
| 10 | Empty (whitespace-only) title is rejected with friendly error | PASS |
| 11 | ...and nothing was inserted | PASS |
| 12 | Title longer than 150 chars is rejected | PASS |
| 13 | Invalid status value is rejected on create | PASS |
| 14 | Edit form is pre-filled | PASS |
| 15 | Edit updates title/description/status and shows message | PASS |
| 16 | Edit with empty title is rejected | PASS |
| 17 | Status can be changed to Completed | PASS |
| 18 | Invalid status on status-change is refused | PASS |
| 19 | Search by title finds matching task | PASS |
| 20 | Status filter shows only Completed tasks | PASS |
| 21 | Search + filter combine | PASS |
| 22 | A literal % in search does not act as a wildcard | PASS |
| 23 | SQL-injection string in search is harmless (no error, no rows) | PASS |
| 24 | Tampered status filter is ignored safely | PASS |
| 25 | Dashboard summary total matches the database | PASS |
| 26 | Delete removes the task and shows message | PASS |
| 27 | User content is HTML-escaped on output (XSS) | PASS |
| 28 | ...also escaped inside the edit form | PASS |
| 29 | Create without CSRF token is rejected (419) | PASS |
| 30 | Delete without CSRF token is rejected (419) | PASS |
| 31 | Forged CSRF token is rejected (419) | PASS |
| 32 | Delete via GET is not allowed (405) | PASS |
| 33 | Alice cannot open Bob's task edit page by changing the URL (404) | PASS |
| 34 | Alice cannot update Bob's task by POSTing his id (404) | PASS |
| 35 | Alice cannot change Bob's task status (404) | PASS |
| 36 | Alice cannot delete Bob's task (404) | PASS |
| 37 | Regular user passing scope=all still sees only own tasks | PASS |
| 38 | Admin can see all users' tasks | PASS |
| 39 | Admin default view is only the admin's own tasks | PASS |
| 40 | Admin can open another user's task | PASS |
| 41 | Duplicate email is rejected | PASS |
| 42 | Weak password is rejected | PASS |
| 43 | Mismatched confirmation is rejected | PASS |
| 44 | New account is created | PASS |
| 45 | Self-registration can never create an admin (role field ignored) | PASS |
| 46 | New user's password is stored as a bcrypt hash | PASS |
| 47 | Pagination: page 1 shows 10 tasks and a Next link | PASS |
| 48 | Pagination: page 2 shows the remaining 2 tasks | PASS |
| 49 | Out-of-range page number is clamped, not an error | PASS |
| 50 | Logout works and shows a message | PASS |
| 51 | After logout the dashboard is no longer accessible | PASS |
| 52 | Logout via GET is not allowed (405) | PASS |
| 53 | Database failure shows a generic 500 page without credentials/raw errors | PASS |

## Manual checks to do in a browser (not covered by the script)

* Delete shows a confirmation dialog and cancelling keeps the task.
* Changing the status dropdown on the dashboard saves immediately (JavaScript on); with JavaScript off the *Set* button does it.
* Client-side validation shows "Please enter a title for the task." before submitting.
* The layout is usable at phone width (stacked cards) – see `docs/screenshots/08-mobile-dashboard.png`.

## Bugs found and fixed

1. **Status change would have cleared the active filter** (found in code review before the first run): the hidden filter field and the status dropdown shared the name `status`. Fixed by prefixing filter fields with `f_`.
2. **Dashboard crashed with HTTP 500 when PHP had no `mbstring` extension** (first test run). Added small fallbacks for `mb_strlen` / `mb_substr` in `includes/bootstrap.php`.
3. **Logout message was lost** (first test run: 1 failure): destroying the session and deleting the cookie meant the flash message had no session to live in. Replaced with `session_regenerate_id(true)` after clearing the data.

After the fixes all checks passed, both without and with `mbstring` installed. Raw output: `docs/test_run_output.txt`.

## Not tested

* Other browsers/devices, MySQL 5.7, PHP 8.1/8.2, HTTPS-only cookie behaviour, concurrent users, load.
