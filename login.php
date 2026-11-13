<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();

    // Always run password_verify (against a dummy hash if the user is unknown)
    // so response time does not reveal whether an email is registered.
    $dummy = '$2y$10$UKSmLzhcX79AjmhrJyO5EOMsqAISxikTStL6tUFXq1uu6eavBiVOq';
    $ok = password_verify($password, $row['password_hash'] ?? $dummy) && $row;

    if ($ok) {
        session_regenerate_id(true); // prevent session fixation
        $_SESSION['user_id'] = (int) $row['id'];
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $up = db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
            $up->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $row['id']]);
        }
        redirect('index.php');
    }
    $error = 'Incorrect email or password.'; // same message for both cases
}

render_header('Log in');
?>
<section class="card narrow">
  <h1>Log in</h1>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="login.php" novalidate data-validate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required data-required="Please enter your email address." value="<?= e($email) ?>" autocomplete="email">
      <p class="field-error"></p>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" required data-required="Please enter your password." autocomplete="current-password">
      <p class="field-error"></p>
    </div>
    <button class="btn btn-primary" type="submit">Log in</button>
    <p class="muted">New here? <a href="register.php">Create an account</a></p>
  </form>
</section>
<?php render_footer();
