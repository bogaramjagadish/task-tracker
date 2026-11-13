<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$errors = [];
$values = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');
    $values   = ['name' => $name, 'email' => $email];

    if ($name === '' || mb_strlen($name) > 100) {
        $errors['name'] = 'Please enter your name (up to 100 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors['password'] = 'Use at least 8 characters, including a letter and a number.';
    }
    if ($password !== $confirm) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        try {
            // Role is ALWAYS 'user' here; nobody can self-register as admin.
            $stmt = db()->prepare(
                'INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :hash, \'user\')'
            );
            $stmt->execute([
                ':name'  => $name,
                ':email' => $email,
                ':hash'  => password_hash($password, PASSWORD_DEFAULT),
            ]);
            flash('success', 'Account created. You can log in now.');
            redirect('login.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') { // duplicate email raced past the check above
                $errors['email'] = 'An account with this email already exists.';
            } else {
                throw $ex;
            }
        }
    }
}

render_header('Create account');
?>
<section class="card narrow">
  <h1>Create your account</h1>
  <form method="post" action="register.php" novalidate data-validate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="name">Name</label>
      <input id="name" name="name" type="text" maxlength="100" required data-required="Please enter your name." value="<?= e($values['name']) ?>" autocomplete="name">
      <p class="field-error"><?= e($errors['name'] ?? '') ?></p>
    </div>
    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" maxlength="190" required data-required="Please enter your email address." value="<?= e($values['email']) ?>" autocomplete="email">
      <p class="field-error"><?= e($errors['email'] ?? '') ?></p>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" minlength="8" required data-required="Please choose a password." autocomplete="new-password">
      <p class="hint">At least 8 characters, with a letter and a number.</p>
      <p class="field-error"><?= e($errors['password'] ?? '') ?></p>
    </div>
    <div class="field">
      <label for="password_confirm">Confirm password</label>
      <input id="password_confirm" name="password_confirm" type="password" required data-required="Please confirm your password." autocomplete="new-password">
      <p class="field-error"><?= e($errors['password_confirm'] ?? '') ?></p>
    </div>
    <button class="btn btn-primary" type="submit">Create account</button>
    <p class="muted">Already registered? <a href="login.php">Log in</a></p>
  </form>
</section>
<?php render_footer();
