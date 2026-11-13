<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();

// Add (no id) and Edit (id) share this page.
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$task = null;
if ($id > 0) {
    $task = find_task_for_user($id, $user) ?? abort_not_found(); // ownership enforced in SQL
}

$errors = [];
$values = [
    'title'       => $task['title'] ?? '',
    'description' => $task['description'] ?? '',
    'status'      => $task['status'] ?? 'Pending',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$errors, $clean] = validate_task($_POST);
    $values = $clean;

    if (!$errors) {
        if ($task) {
            [$ownSql, $ownParams] = ownership_sql($user);
            $stmt = db()->prepare(
                'UPDATE tasks SET title = :title, description = :description, status = :status WHERE id = :id' . $ownSql
            );
            $stmt->execute([
                ':title' => $clean['title'], ':description' => $clean['description'],
                ':status' => $clean['status'], ':id' => $task['id'],
            ] + $ownParams);
            flash('success', 'Task updated.');
        } else {
            $stmt = db()->prepare(
                'INSERT INTO tasks (user_id, title, description, status) VALUES (:uid, :title, :description, :status)'
            );
            $stmt->execute([
                ':uid' => $user['id'], ':title' => $clean['title'],
                ':description' => $clean['description'], ':status' => $clean['status'],
            ]);
            flash('success', 'Task added.');
        }
        redirect('index.php');
    }
}

render_header($task ? 'Edit task' : 'Add task', $user);
?>
<section class="card narrow">
  <h1><?= $task ? 'Edit task' : 'Add a task' ?></h1>
  <?php if ($errors): ?><div class="alert alert-error" role="alert">Please fix the highlighted fields and try again.</div><?php endif; ?>
  <form method="post" action="task_form.php<?= $task ? '?id=' . (int) $task['id'] : '' ?>" novalidate data-validate>
    <?= csrf_field() ?>
    <?php if ($task): ?><input type="hidden" name="id" value="<?= (int) $task['id'] ?>"><?php endif; ?>
    <div class="field<?= isset($errors['title']) ? ' has-error' : '' ?>">
      <label for="title">Title</label>
      <input id="title" name="title" type="text" maxlength="<?= TITLE_MAX ?>" required data-required="Please enter a title for the task." value="<?= e($values['title']) ?>">
      <p class="field-error"><?= e($errors['title'] ?? '') ?></p>
    </div>
    <div class="field<?= isset($errors['description']) ? ' has-error' : '' ?>">
      <label for="description">Description <span class="muted">(optional)</span></label>
      <textarea id="description" name="description" rows="5" maxlength="<?= DESC_MAX ?>"><?= e($values['description']) ?></textarea>
      <p class="field-error"><?= e($errors['description'] ?? '') ?></p>
    </div>
    <div class="field<?= isset($errors['status']) ? ' has-error' : '' ?>">
      <label for="status">Status</label>
      <select id="status" name="status">
        <?php foreach (TASK_STATUSES as $s): ?>
          <option value="<?= e($s) ?>"<?= $values['status'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="field-error"><?= e($errors['status'] ?? '') ?></p>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= $task ? 'Save changes' : 'Add task' ?></button>
      <a class="btn btn-ghost" href="index.php">Cancel</a>
    </div>
  </form>
</section>
<?php render_footer();
