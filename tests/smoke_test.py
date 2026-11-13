#!/usr/bin/env python3
"""
End-to-end smoke tests for the Task Tracker (HTTP level, no extra packages).

Usage:
  1. Import database.sql into a TEST database and start the app (php -S 127.0.0.1:8080 -t .)
  2. python3 tests/smoke_test.py [base_url]            e.g. http://127.0.0.1:8080
Needs the `mysql` CLI (root access via socket) only to inspect rows directly.
"""
import re, subprocess, sys, urllib.request, urllib.parse, http.cookiejar

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080").rstrip("/")
BAD_BASE = "http://127.0.0.1:8081"   # same app started with a WRONG DB password
results = []

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None

class Client:
    def __init__(self, base=BASE):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
    def req(self, path, data=None, follow=True):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        url = self.base + path
        for _ in range(5):
            try:
                r = self.op.open(urllib.request.Request(url, data=body))
                return r.status, r.read().decode("utf-8", "replace"), None
            except urllib.error.HTTPError as e:
                loc = e.headers.get("Location")
                if e.code in (301, 302, 303) and loc and follow:
                    url, body = urllib.parse.urljoin(url, loc), None
                    continue
                return e.code, e.read().decode("utf-8", "replace"), loc
        raise RuntimeError("redirect loop")
    def get(self, path, follow=True): return self.req(path, None, follow)
    def post(self, path, data, token=True, follow=True):
        d = dict(data)
        if token: d["csrf_token"] = self.token()
        return self.req(path, d, follow)
    def token(self):
        _, html, _ = self.get("/login.php") if not self.has_session() else self.get("/index.php")
        return re.search(r'name="csrf_token" value="([0-9a-f]+)"', html).group(1)
    def has_session(self): return any(c.name == "tt_session" for c in self.jar)
    def login(self, email, pw):
        self.get("/login.php")
        return self.post("/login.php", {"email": email, "password": pw})

def sql(q):
    return subprocess.run(["mysql", "-uroot", "-N", "-B", "task_tracker", "-e", q],
                          capture_output=True, text=True).stdout.strip()

def check(name, cond, detail=""):
    results.append((name, bool(cond)))
    print(("PASS  " if cond else "FAIL  ") + name + (f"   [{detail}]" if detail and not cond else ""))

def task_id(title):
    return sql(f"SELECT id FROM tasks WHERE title='{title}' ORDER BY id DESC LIMIT 1")

# ---------------------------------------------------------------- Level 3: access control basics
anon = Client()
s, _, loc = anon.get("/index.php", follow=False)
check("Logged-out visitor is redirected away from the dashboard", s == 302 and "login.php" in (loc or ""))
for p in ("/task_form.php", "/task_form.php?id=1"):
    s, _, loc = anon.get(p, follow=False)
    check(f"Logged-out visitor cannot open {p}", s == 302 and "login.php" in (loc or ""))
s, body, _ = anon.req("/task_delete.php", {"id": "1"}, follow=False)
check("Logged-out POST to task_delete.php is refused (405/302/419)", s in (302, 405, 419) and sql("SELECT COUNT(*) FROM tasks WHERE id=1") == "1")

hash_rows = sql("SELECT COUNT(*) FROM users WHERE password_hash NOT LIKE '$2y$%'")
check("All stored passwords are bcrypt hashes (none in plain text)", hash_rows == "0")

# ---------------------------------------------------------------- login
c = Client()
_, body, _ = c.login("alice@example.com", "wrong-password")
check("Wrong password shows a generic error", "Incorrect email or password." in body)
_, body, _ = c.login("nobody@example.com", "Whatever1")
check("Unknown email shows the same generic error", "Incorrect email or password." in body)
_, body, _ = c.login("alice@example.com", "Alice@1234")
check("Valid login reaches the dashboard", "My tasks" in body and "Log out" in body)

# ---------------------------------------------------------------- Level 1/2: CRUD
_, body, _ = c.post("/task_form.php", {"title": "Test task A", "description": "first one", "status": "Pending"})
check("Add task with valid data shows success message", "Task added." in body and "Test task A" in body)

_, body, _ = c.post("/task_form.php", {"title": "   ", "description": "x", "status": "Pending"})
check("Empty (whitespace-only) title is rejected with friendly error", "Please enter a title for the task." in body)
check("...and nothing was inserted", sql("SELECT COUNT(*) FROM tasks WHERE description='x' AND user_id=2") == "0")

_, body, _ = c.post("/task_form.php", {"title": "T" * 151, "description": "", "status": "Pending"})
check("Title longer than 150 chars is rejected", "150 characters or fewer" in body)
_, body, _ = c.post("/task_form.php", {"title": "Bad status", "description": "", "status": "Hacked"})
check("Invalid status value is rejected on create", "Choose Pending, In Progress or Completed." in body and task_id("Bad status") == "")

tid = task_id("Test task A")
_, body, _ = c.get(f"/task_form.php?id={tid}")
check("Edit form is pre-filled", 'value="Test task A"' in body)
_, body, _ = c.post("/task_form.php?id=" + tid, {"id": tid, "title": "Test task A (edited)", "description": "changed", "status": "In Progress"})
check("Edit updates title/description/status and shows message", "Task updated." in body and "Test task A (edited)" in body)
_, body, _ = c.post("/task_form.php?id=" + tid, {"id": tid, "title": "", "description": "changed", "status": "Pending"})
check("Edit with empty title is rejected", "Please enter a title for the task." in body and sql(f"SELECT title FROM tasks WHERE id={tid}") == "Test task A (edited)")

_, body, _ = c.post("/task_status.php", {"id": tid, "status": "Completed", "f_q": "", "f_status": "", "f_page": "1", "f_scope": ""})
check("Status can be changed to Completed", "Status changed to Completed." in body and sql(f"SELECT status FROM tasks WHERE id={tid}") == "Completed")
c.post("/task_status.php", {"id": tid, "status": "Nonsense"})
check("Invalid status on status-change is refused", sql(f"SELECT status FROM tasks WHERE id={tid}") == "Completed")

# search + filter
_, body, _ = c.get("/index.php?q=review")
check("Search by title finds matching task", "Review pull request #12" in body and "Back up the database" not in body)
_, body, _ = c.get("/index.php?status=Completed")
check("Status filter shows only Completed tasks", "Back up the database" in body and "Review pull request #12" not in body)
_, body, _ = c.get("/index.php?q=back&status=Completed")
check("Search + filter combine", "Back up the database" in body and "Write project README" not in body)
_, body, _ = c.get("/index.php?q=%25")
check("A literal % in search does not act as a wildcard", "No tasks match" in body)
_, body, _ = c.get("/index.php?q=" + urllib.parse.quote("' OR '1'='1' -- "))
check("SQL-injection string in search is harmless (no error, no rows)", "No tasks match" in body and "Something went wrong" not in body)
_, body, _ = c.get("/index.php?status=" + urllib.parse.quote("Completed' OR 1=1 -- "))
check("Tampered status filter is ignored safely", "Something went wrong" not in body)

# dashboard summary
_, body, _ = c.get("/index.php")
alice_total = sql("SELECT COUNT(*) FROM tasks WHERE user_id=2")
check("Dashboard summary total matches the database", re.search(r'stat-num">%s<' % alice_total, body) is not None)

# delete
_, body, _ = c.post("/task_delete.php", {"id": tid, "f_q": "", "f_status": "", "f_page": "1", "f_scope": ""})
check("Delete removes the task and shows message", "Task deleted." in body and sql(f"SELECT COUNT(*) FROM tasks WHERE id={tid}") == "0")

# ---------------------------------------------------------------- XSS
c.post("/task_form.php", {"title": "<script>alert(1)</script>", "description": "<img src=x onerror=alert(2)>", "status": "Pending"})
_, body, _ = c.get("/index.php")
check("User content is HTML-escaped on output (XSS)", "<script>alert(1)</script>" not in body and "&lt;script&gt;alert(1)&lt;/script&gt;" in body and "<img src=x" not in body)
xid = task_id("<script>alert(1)</script>")
_, body, _ = c.get(f"/task_form.php?id={xid}")
check("...also escaped inside the edit form", "<script>alert(1)</script>" not in body)
c.post("/task_delete.php", {"id": xid})

# ---------------------------------------------------------------- CSRF
before = sql("SELECT COUNT(*) FROM tasks")
s, body, _ = c.post("/task_form.php", {"title": "CSRF attempt", "description": "", "status": "Pending"}, token=False)
check("Create without CSRF token is rejected (419)", s == 419 and sql("SELECT COUNT(*) FROM tasks") == before)
bob_task = sql("SELECT id FROM tasks WHERE user_id=3 ORDER BY id LIMIT 1")
s, _, _ = c.post("/task_delete.php", {"id": sql("SELECT id FROM tasks WHERE user_id=2 LIMIT 1")}, token=False)
check("Delete without CSRF token is rejected (419)", s == 419)
s, _, _ = c.req("/task_form.php", {"csrf_token": "0" * 64, "title": "forged", "status": "Pending"})
check("Forged CSRF token is rejected (419)", s == 419)
s, _, _ = c.get("/task_delete.php?id=" + bob_task)
check("Delete via GET is not allowed (405)", s == 405)

# ---------------------------------------------------------------- Authorization (IDOR)
bt = bob_task
bob_title = sql(f"SELECT title FROM tasks WHERE id={bt}")
s, _, _ = c.get(f"/task_form.php?id={bt}")
check("Alice cannot open Bob's task edit page by changing the URL (404)", s == 404)
s, _, _ = c.post(f"/task_form.php?id={bt}", {"id": bt, "title": "HACKED", "description": "", "status": "Completed"})
check("Alice cannot update Bob's task by POSTing his id (404)", s == 404 and sql(f"SELECT title FROM tasks WHERE id={bt}") == bob_title)
s, _, _ = c.post("/task_status.php", {"id": bt, "status": "Completed"})
check("Alice cannot change Bob's task status (404)", s == 404)
s, _, _ = c.post("/task_delete.php", {"id": bt})
check("Alice cannot delete Bob's task (404)", s == 404 and sql(f"SELECT COUNT(*) FROM tasks WHERE id={bt}") == "1")
_, body, _ = c.get("/index.php?scope=all")
check("Regular user passing scope=all still sees only own tasks", bob_title not in body and "My tasks" in body)

# ---------------------------------------------------------------- Admin role
a = Client(); a.login("admin@example.com", "Admin@1234")
_, body, _ = a.get("/index.php?scope=all")
check("Admin can see all users' tasks", bob_title in body and "All tasks" in body)
_, body, _ = a.get("/index.php")
check("Admin default view is only the admin's own tasks", bob_title not in body)
s, body, _ = a.get(f"/task_form.php?id={bt}")
check("Admin can open another user's task", s == 200 and bob_title in body)

# ---------------------------------------------------------------- Registration
r = Client(); r.get("/register.php")
_, body, _ = r.post("/register.php", {"name": "Dup", "email": "alice@example.com", "password": "Password1", "password_confirm": "Password1"})
check("Duplicate email is rejected", "already exists" in body)
_, body, _ = r.post("/register.php", {"name": "Weak", "email": "weak@example.com", "password": "short", "password_confirm": "short"})
check("Weak password is rejected", "at least 8 characters" in body.replace("At least", "at least") and sql("SELECT COUNT(*) FROM users WHERE email='weak@example.com'") == "0")
_, body, _ = r.post("/register.php", {"name": "Eve", "email": "eve@example.com", "password": "Password1", "password_confirm": "Different1"})
check("Mismatched confirmation is rejected", "do not match" in body)
_, body, _ = r.post("/register.php", {"name": "Carol", "email": "carol@example.com", "password": "Password1", "password_confirm": "Password1", "role": "admin"})
check("New account is created", "Account created" in body and sql("SELECT COUNT(*) FROM users WHERE email='carol@example.com'") == "1")
check("Self-registration can never create an admin (role field ignored)", sql("SELECT role FROM users WHERE email='carol@example.com'") == "user")
check("New user's password is stored as a bcrypt hash", sql("SELECT password_hash FROM users WHERE email='carol@example.com'").startswith("$2y$"))

# ---------------------------------------------------------------- Pagination
p = Client(); p.login("carol@example.com", "Password1")
for i in range(1, 13):
    p.post("/task_form.php", {"title": f"Bulk {i:02d}", "description": "", "status": "Pending"})
_, body, _ = p.get("/index.php")
check("Pagination: page 1 shows 10 tasks and a Next link", body.count("data-label=\"Task\"") == 10 and "Page 1 of 2" in body and "Next" in body)
_, body, _ = p.get("/index.php?page=2")
check("Pagination: page 2 shows the remaining 2 tasks", body.count("data-label=\"Task\"") == 2 and "Previous" in body)
_, body, _ = p.get("/index.php?page=999")
check("Out-of-range page number is clamped, not an error", "Page 2 of 2" in body)

# ---------------------------------------------------------------- Logout / session
_, body, _ = c.post("/logout.php", {})
check("Logout works and shows a message", "logged out" in body)
s, _, loc = c.get("/index.php", follow=False)
check("After logout the dashboard is no longer accessible", s == 302 and "login.php" in (loc or ""))
s, _, _ = c.get("/logout.php")
check("Logout via GET is not allowed (405)", s == 405)

# ---------------------------------------------------------------- Error handling
bad = Client(BAD_BASE)
s, body, _ = bad.post("/login.php", {"email": "alice@example.com", "password": "Alice@1234"}, token=False)
bad.get("/login.php")
s, body, _ = bad.post("/login.php", {"email": "alice@example.com", "password": "Alice@1234"})
leak = any(w in body.lower() for w in ("wrong-password", "tasktracker", "access denied", "pdoexception", "stack trace", "sqlstate"))
check("Database failure shows a generic 500 page without credentials/raw errors", s == 500 and "Something went wrong" in body and not leak, f"status={s}")

# ---------------------------------------------------------------- summary
failed = [n for n, ok in results if not ok]
print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
sys.exit(1 if failed else 0)
