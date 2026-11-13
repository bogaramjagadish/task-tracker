<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_post();
$user = require_login();
csrf_check();

$id     = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
$task   = $id > 0 ? find_task_for_user($id, $user) : null;
if (!$task) {
    abort_not_found();
}

if (!in_array($status, TASK_STATUSES, true)) {
    flash('error', 'That status is not valid.');
    redirect(return_url_from_post());
}

[$ownSql, $ownParams] = ownership_sql($user);
$stmt = db()->prepare('UPDATE tasks SET status = :status WHERE id = :id' . $ownSql);
$stmt->execute([':status' => $status, ':id' => $task['id']] + $ownParams);

flash('success', 'Status changed to ' . $status . '.');
redirect(return_url_from_post());
