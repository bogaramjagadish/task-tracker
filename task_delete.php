<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_post();
$user = require_login();
csrf_check();

$id   = (int) ($_POST['id'] ?? 0);
$task = $id > 0 ? find_task_for_user($id, $user) : null;
if (!$task) {
    abort_not_found(); // same answer whether it does not exist or belongs to someone else
}

[$ownSql, $ownParams] = ownership_sql($user);
$stmt = db()->prepare('DELETE FROM tasks WHERE id = :id' . $ownSql);
$stmt->execute([':id' => $task['id']] + $ownParams);

flash('success', 'Task deleted.');
redirect(return_url_from_post());
