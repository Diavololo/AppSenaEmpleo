<?php
declare(strict_types=1);

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php?view=recuperar');
  exit;
}

$setFlash = static function (string $message, string $type = 'error'): void {
  $_SESSION['flash'] = ['type' => $type, 'message' => $message];
};

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
  $setFlash('Por seguridad, vuelve a intentarlo.');
  header('Location: index.php?view=recuperar');
  exit;
}

$emailInput = strtolower(trim((string)($_POST['email'] ?? '')));
if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
  $_SESSION['recover_old_email'] = $emailInput;
  $setFlash('Ingresa un correo válido.');
  header('Location: index.php?view=recuperar');
  exit;
}

require_once __DIR__.'/../pages/db.php';
require_once __DIR__.'/../lib/password_reset.php';

if (!($pdo instanceof PDO)) {
  $_SESSION['recover_old_email'] = $emailInput;
  $setFlash('No hay conexión con la base de datos.');
  header('Location: index.php?view=recuperar');
  exit;
}

try {
  $account = pr_find_account_by_email($pdo, $emailInput);
  if ($account) {
    pr_prune_password_resets($pdo);
    $tokenData = pr_create_reset_token(
      $pdo,
      $account['email'],
      $account['user_type'],
      $_SERVER['REMOTE_ADDR'] ?? null,
      $_SERVER['HTTP_USER_AGENT'] ?? null
    );
    if ($tokenData) {
      $resetUrl = pr_build_reset_url($tokenData['token']);
      pr_send_reset_email($account['email'], $resetUrl, $tokenData['expires_at'], $account['user_type']);
      pr_log_reset_link($account['email'], $resetUrl);
    }
  }
} catch (Throwable $e) {
  error_log('[RESET] '.$e->getMessage());
  $_SESSION['recover_old_email'] = $emailInput;
  $setFlash('No pudimos procesar la solicitud. Intenta más tarde.');
  header('Location: index.php?view=recuperar');
  exit;
}

unset($_SESSION['recover_old_email']);
$redirect = 'index.php?view=recuperar_confirmacion';
if ($emailInput !== '') {
  $redirect .= '&email='.urlencode($emailInput);
}
header('Location: '.$redirect);
exit;
