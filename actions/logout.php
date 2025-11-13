<?php
session_start();

$wasLogged = isset($_SESSION['user']);

unset($_SESSION['user'], $_SESSION['login_old'], $_SESSION['empresa_email'], $_SESSION['empresa_nombre'], $_SESSION['empresa_sede']);

if ($wasLogged) {
  $_SESSION['flash'] = ['type' => 'info', 'message' => 'Sesión cerrada correctamente.'];
}

header('Location: index.php?view=login');
exit;
