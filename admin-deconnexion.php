<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

start_secure_session();
$_SESSION = [];
session_destroy();
header('Location: admin-connexion.php');
exit;
