<?php

require_once __DIR__ . '/config.php';

destroySession();
header('Location: ' . BASE_URL . '/index.php');
exit();
