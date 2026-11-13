<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user  = require_login();
$admin = is_admin($user);

// ---- read & sanitise filters (all values end up bound as parameters)
$allScope = $admin && (($_GET['scope'] ?? '') === 'all');
$q        = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$status   = (string) ($_GET['status'] ?? '');
if (!in_array($status, TASK_STATUSES, true)) {
    $status = '';
}
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) config('per_page', 10);

// ---- scope: regular users ALWAYS see only their own tasks
$scopeWhere  = '';
$scopeParams = [];
if (!$allScope) {
    $scopeWhere  = 't.user_id = :uid';
    $scopeParams = [':uid' => (int) $user['id']];
}

// ---- dashboard summary (bonus)
$counts = ['Pending' => 0, 'In Progress' => 0, 'Completed' => 0];
$stmt = db()->prepare(
    'SELECT t.status, COUNT(*) AS c FROM tasks t'
    . ($scopeWhere ? ' WHERE ' . $scopeWhere : '') . ' GROUP BY t.status'
);
$stmt->execute($scopeParams);
foreach ($stmt->fetchAll() as $r) {
    $counts[$r['status']] = (int) $r['c'];
}
$total = array_sum($counts);

// ---- filtered list with search + status filter + pagination
$where  = $scopeWhere ? [$scopeWhere] : [];
$params = $scopeParams;
if ($q !== '') {
    // escape LIKE wildcards typed by the user, using | as the escape character
    $where[]      = "t.title LIKE :q ESCAPE '|'";
    $params[':q'] = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $q) . '%';
}
if ($status !== '') {
    $where[]           = 't.status = :status';
    $params[':status'] = $status;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare('SELECT COUNT(*) FROM tasks t' . $whereSql);
$stmt->execute($params);
$matching   = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($matching / $perPage));
$page       = min($page, $totalPages);

$stmt = db()->prepare(
    'SELECT t.id, t.title, t.description, t.status, t.created_at, u.name AS owner_name
       FROM tasks t JOIN users u ON u.id = t.user_id'
    . $whereSql . ' ORDER BY t.created_at DESC, t.id DESC LIMIT :lim OFFSET :off'
);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$tasks = $stmt->fetchAll();

$state = ['q' => $q, 'status' => $status, 'page' => (string) $page, 'scope' => $allScope ? 'all' : ''];
$filtered = ($q !== '' || $status !== '');

render_header('Dashboard', $user);
?>
<div class="page-head">
  <h1><?= $allScope ? 'All tasks' : 'My tasks' ?></h1>
  <div class="page-actions">
    <?php if ($admin): ?>
      <a class="btn btn-ghost" href="<?= e(dashboard_url([], ['scope' => $allScope ? '' : 'all'])) ?>">
        <?= $allScope ? 'Show only my tasks' : 'Show all users’ tasks' ?>
      </a>
    <?php endif; ?>
    <a class="btn btn-primary" href="task_form.php">Add task</a>
  </div>
</div>

<section class="summary" aria-label="Task summary">
  <div class="stat"><span class="stat-num"><?= $total ?></span><span class="stat-label">Total</span></div>
  <div class="stat"><span class="stat-num"><?= $counts['Pending'] ?></span><span class="stat-label">Pending</span></div>
  <div class="stat"><span class="stat-num"><?= $counts['In Progress'] ?></span><span class="stat-label">In Progress</span></div>
  <div class="stat"><span class="stat-num"><?= $counts['Completed'] ?></span><span class="stat-label">Completed</span></div>
</section>

<form class="filters" method="get" action="index.php" role="search">
  <?php if ($allScope): ?><input type="hidden" name="scope" value="all"><?php endif; ?>
  <label class="sr-only" for="q">Search by title</label>
  <input id="q" name="q" type="search" maxlength="100" placeholder="Search by title" value="<?= e($q) ?>">
  <label class="sr-only" for="status">Filter by status</label>
  <select id="status" name="status">
    <option value="">All statuses</option>
    <?php foreach (TASK_STATUSES as $s): ?>
      <option value="<?= e($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= e($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Apply</button>
  <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= e(dashboard_url([], ['scope' => $allScope ? 'all' : ''])) ?>">Clear</a><?php endif; ?>
</form>

<?php if (!$tasks): ?>
  <div class="empty card">
    <?php if ($filtered): ?>
      <p>No tasks match your search or filter.</p>
    <?php else: ?>
      <p>You have no tasks yet.</p>
      <a class="btn btn-primary" href="task_form.php">Add your first task</a>
    <?php endif; ?>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="tasks">
  <thead>
    <tr>
      <th>Title</th><th>Status</th><?php if ($allScope): ?><th>Owner</th><?php endif; ?><th>Created</th><th class="actions-col">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($tasks as $t): ?>
    <tr>
      <td data-label="Task">
        <strong><?= e($t['title']) ?></strong>
        <?php if ($t['description'] !== ''): ?><div class="muted small"><?= e(excerpt($t['description'])) ?></div><?php endif; ?>
      </td>
      <td data-label="Status">
        <span class="badge <?= e(status_class($t['status'])) ?>"><?= e($t['status']) ?></span>
        <form class="inline-form" method="post" action="task_status.php">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <?php foreach ($state as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
          <label class="sr-only" for="st-<?= (int) $t['id'] ?>">Change status</label>
          <select id="st-<?= (int) $t['id'] ?>" name="status" data-autosubmit>
            <?php foreach (TASK_STATUSES as $s): ?>
              <option value="<?= e($s) ?>"<?= $t['status'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-small" type="submit">Set</button>
        </form>
      </td>
      <?php if ($allScope): ?><td data-label="Owner"><?= e($t['owner_name']) ?></td><?php endif; ?>
      <td data-label="Created"><?= e(date('d M Y, H:i', strtotime($t['created_at']))) ?></td>
      <td class="actions-col" data-label="Actions">
        <a class="btn btn-small" href="task_form.php?id=<?= (int) $t['id'] ?>">Edit</a>
        <form class="inline-form" method="post" action="task_delete.php" data-confirm="Delete “<?= e($t['title']) ?>”? This cannot be undone.">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <?php foreach ($state as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
          <button class="btn btn-small btn-danger" type="submit">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="Pagination">
  <?php if ($page > 1): ?><a class="btn btn-small" href="<?= e(dashboard_url($state, ['page' => (string) ($page - 1)])) ?>">Previous</a><?php endif; ?>
  <span>Page <?= $page ?> of <?= $totalPages ?> (<?= $matching ?> tasks)</span>
  <?php if ($page < $totalPages): ?><a class="btn btn-small" href="<?= e(dashboard_url($state, ['page' => (string) ($page + 1)])) ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php render_footer();
