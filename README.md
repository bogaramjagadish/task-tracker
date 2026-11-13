# Task Tracker (PHP + MySQL)

A small task manager: register / log in, then create, view, edit, search, filter, update the status of
and delete your own tasks. Built with **core PHP 8 (PDO)**, **MySQL/MariaDB** and plain HTML/CSS/JS.

## Levels completed

| Level | Status | What is covered |
|---|---|---|
| 1 – Basic task management | Done | `tasks` table, add, list, edit, delete with confirmation, success messages |
| 2 – Status, search, validation | Done | Pending / In Progress / Completed, change status, search by title, status filter, frontend + backend validation, friendly errors |
| 3 – Login, authorization, security | Done | Registration + login, `password_hash()` / `password_verify()`, protected dashboard, role + ownership checks in the backend, prepared statements everywhere, output escaping, CSRF tokens |
| Bonus | Done | Pagination (10 per page) and a dashboard summary (total / pending / in progress / completed) |

Not done (bonus): due dates / priority.

## Requirements

* PHP 8.1 or newer with the `pdo_mysql` extension (`mbstring` is recommended; the app has small fallbacks if it is missing)
* MySQL 5.7+ or MariaDB 10.3+
* Any web server (Apache/Nginx) **or** PHP's built-in server for local use

## Setup

1. **Get the code** and open a terminal in the project folder.
2. **Create the database** (this also loads sample users and tasks):
   ```bash
   mysql -u root -p < database.sql
   ```
   (phpMyAdmin: *Import* → choose `database.sql`.)
3. **Create a database user** with limited rights (recommended instead of `root`):
   ```sql
   CREATE USER 'tasktracker'@'localhost' IDENTIFIED BY 'choose-a-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON task_tracker.* TO 'tasktracker'@'localhost';
   ```
4. **Configure the connection** – either copy `config.local.example.php` to `config.local.php` and edit it, or set
   environment variables `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
   `config.local.php` is git-ignored, so credentials are never committed. Defaults: host `127.0.0.1`, db `task_tracker`, user `root`, empty password.
5. **Run it**
   * Built-in server: `php -S 127.0.0.1:8080 -t .` then open <http://127.0.0.1:8080/>
   * XAMPP/WAMP/Laragon: put the folder under `htdocs`/`www` and open `http://localhost/task-tracker/`

### Test logins (sample data in `database.sql` – demo only)

| Role | Email | Password |
|---|---|---|
| admin | `admin@example.com` | `Admin@1234` |
| user | `alice@example.com` | `Alice@1234` |
| user | `bob@example.com` | `Bob@12345` |

You can also create new accounts on the *Create account* page (these are always regular users).

## Roles and permissions

| Action | Not logged in | `user` | `admin` |
|---|---|---|---|
| Open dashboard / task pages | Redirected to login | Yes | Yes |
| See tasks | – | Only **own** tasks | Own tasks by default; all users' tasks via "Show all users' tasks" |
| Create task | – | Yes (owned by self) | Yes (owned by self) |
| Edit / change status / delete | – | Only **own** tasks | Any task |
| Become admin | – | No | – (admins are created directly in the database) |

Why these rules: a user's tasks are private by default (ownership), and an admin role exists so that someone
can supervise or clean up data. Registration can never grant `admin` – the role is hard-coded to `user` in the INSERT.

**How it is enforced (backend, not just the UI):** every task lookup and every UPDATE/DELETE goes through
`find_task_for_user()` and `ownership_sql()` in `includes/bootstrap.php`, which add `AND user_id = :owner_id` for
non-admins. A request for someone else's task id returns **404** (same as a missing task, so ids cannot be probed).
`?scope=all` is ignored for non-admins. The user's role is read from the database on every request, never from the browser.

## Security measures

| Risk | What the app does |
|---|---|
| Plain-text passwords | `password_hash()` (bcrypt) on registration, `password_verify()` on login, rehash when needed |
| SQL injection | PDO with real prepared statements (`ATTR_EMULATE_PREPARES = false`); LIKE wildcards typed by users are escaped; page numbers bound as integers |
| XSS | All user content passes through `e()` (`htmlspecialchars`, ENT_QUOTES, UTF-8) before output; a Content-Security-Policy header blocks inline scripts |
| CSRF | Random per-session token in every POST form, checked with `hash_equals()`; failures return 419. Session cookie is `SameSite=Lax` and `HttpOnly`. Logout and delete are POST-only |
| Session fixation | `session_regenerate_id(true)` after login and logout |
| Account enumeration | Same error message for unknown email and wrong password; dummy hash verified for unknown emails |
| Information leak | `display_errors` off; uncaught errors are logged and the user sees a generic message (no credentials, no SQL) |
| Broken access control | Ownership/role checks described above; unauthenticated requests are redirected to login |

## Database design

* **users** – `id`, `name`, `email` (UNIQUE), `password_hash` (VARCHAR 255 so the algorithm can change later), `role` ENUM(`user`,`admin`), `created_at`.
* **tasks** – `id`, `user_id` (FK → users, `ON DELETE CASCADE`), `title` VARCHAR(150), `description` TEXT, `status` ENUM(Pending, In Progress, Completed) default Pending, `created_at`, `updated_at`.
* One-to-many (user → tasks). The FK guarantees every task has an owner; cascade removes a user's tasks with the user.
* ENUM for `status` and `role` lets the database itself reject invalid values (in addition to PHP validation).
* Indexes `(user_id, status)` and `(user_id, created_at)` match the dashboard queries (own tasks + status filter + newest first). `email` is unique-indexed for login lookup.
* InnoDB + utf8mb4 for foreign keys, transactions and full Unicode.

## Project structure

```
index.php            dashboard: summary, search, filter, list, pagination
task_form.php        add / edit task (GET shows form, POST validates and saves)
task_status.php      change a task's status (POST)
task_delete.php      delete a task (POST, confirmed in the browser)
login.php register.php logout.php
includes/bootstrap.php   session, headers, CSRF, DB, auth + authorization, validation, layout helpers
assets/style.css  assets/app.js   responsive layout; confirm dialog, friendly client-side validation
database.sql         schema + sample data
tests/smoke_test.py  automated end-to-end checks (53)
docs/                test cases, screenshots, raw test output
```

## Testing

See `docs/TEST_CASES.md` (results, bugs found and fixed). To re-run the automated checks:

```bash
mysql -u root < database.sql
php -S 127.0.0.1:8080 -t . &
DB_PASS=wrong php -S 127.0.0.1:8081 -t . &     # second copy with a wrong DB password (error-handling test)
python3 tests/smoke_test.py
```

## Backup and restore

```bash
# Backup (schema + data, consistent snapshot, safe for InnoDB)
mysqldump -u root -p --single-transaction --routines task_tracker > task_tracker_backup_$(date +%F).sql

# Restore into an empty/existing database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS task_tracker CHARACTER SET utf8mb4"
mysql -u root -p task_tracker < task_tracker_backup_2026-11-13.sql
```
Schedule the backup with cron / Task Scheduler, keep copies off the server, store backups securely (they contain password hashes),
and test a restore regularly. For point-in-time recovery enable the binary log.

## Known limitations

* No login rate-limiting / account lockout, email verification or password reset.
* No "remember me"; sessions end when the browser closes (or per PHP session settings).
* Admin accounts are created in SQL only (there is no admin UI for managing users).
* Serve over HTTPS in production (the session cookie is marked `Secure` automatically when HTTPS is detected).
* Screenshots were taken with an old WebKit renderer; real browsers render the same pages slightly more polished.
* Tested manually/with the included script on PHP 8.3 + MariaDB 10.11; not tested on MySQL 5.7 or PHP 8.1/8.2.

## AI assistant disclosure

* Tool used: **Claude (Anthropic)** generated the first version of the code, SQL, test script and documentation.
* One change made after reviewing/testing the generated code: the status-change form first reused the field name `status`
  for both the "current filter" hidden input and the new-status dropdown, so changing a task's status would have wiped the
  active filter. The filter fields were renamed with an `f_` prefix (`return_url_from_post()`). Two further fixes came from test runs – see `docs/TEST_CASES.md`.
* **Before submitting, replace or confirm this section with your own review notes** – you should be able to explain every file in the review.

## Screenshots

`docs/screenshots/`: login, register, dashboard, search + filter, validation error, edit task, admin "all tasks" view, mobile dashboard.
