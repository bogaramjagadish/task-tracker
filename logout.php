<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_post();
csrf_check();

// Drop all session data and issue a brand-new session id (the old one is deleted server-side).
$_SESSION = [];
session_regenerate_id(true);

flash('success', 'You have been logged out.');
redirect('login.php');
