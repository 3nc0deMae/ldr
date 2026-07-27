<?php
require 'C:/xampp/htdocs/Tin/config.php';

$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['user_email'] = 'admin@test.com';

// Now access the handler
ob_start();
include 'C:/xampp/htdocs/Tin/global_search_handler.php';
$output = ob_get_clean();

echo "<!-- Output length: " . strlen($output) . " -->\n";
echo $output;
