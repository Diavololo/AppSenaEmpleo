<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?view=register'); exit; }
if (!isset($_POST['csrf']) || !isset($_SESSION['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) { $_SESSION['flash'] = 'csrf_error'; header('Location: index.php?view=register'); exit; }
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$password_confirm = $_POST['password_confirm'] ?? '';
if ($name === '' || $email === '' || $password === '' || $password_confirm === '') { $_SESSION['flash'] = 'missing_fields'; header('Location: index.php?view=register'); exit; }
if ($password !== $password_confirm) { $_SESSION['flash'] = 'password_mismatch'; header('Location: index.php?view=register'); exit; }
$_SESSION['user'] = ['email' => $email, 'name' => $name];
header('Location: index.php');
exit;