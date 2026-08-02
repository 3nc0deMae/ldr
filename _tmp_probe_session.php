<?php
require_once __DIR__ . '/config.php';
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['user_email'] = 'admin@liceodebaleno.edu.ph';
$_SESSION['login_time'] = time();
generateCSRFToken();
header('Content-Type: text/plain');
echo session_id() . "\n" . $_SESSION['csrf_token'];
