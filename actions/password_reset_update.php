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

$token = trim((string)($_POST['token'] ?? ''));
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');
$back = 'index.php?view=recuperar_crear'.($token ? '&token='.urlencode($token) : '');

if ($token === '') {
  $setFlash('El enlace no es válido o expiró.');
  header('Location: index.php?view=recuperar');
  exit;
}

if ($newPassword === '' || $confirmPassword === '') {
  $setFlash('Ingresa y confirma tu nueva contraseña.');
  header('Location: '.$back);
  exit;
}

if ($newPassword !== $confirmPassword) {
  $setFlash('Las contraseñas no coinciden.');
  header('Location: '.$back);
  exit;
}

$strength = strlen($newPassword) >= 8
  && preg_match('/[A-Z]/', $newPassword)
  && preg_match('/[a-z]/', $newPassword)
  && preg_match('/[0-9]/', $newPassword)
  && preg_match('/[^A-Za-z0-9]/', $newPassword);

if (!$strength) {
  $setFlash('Usa al menos 8 caracteres e incluye mayúsculas, minúsculas, números y símbolos.');
  header('Location: '.$back);
  exit;
}

require_once __DIR__.'/../pages/db.php';
require_once __DIR__.'/../lib/password_reset.php';

if (!($pdo instanceof PDO)) {
  $setFlash('No hay conexión con la base de datos.');
  header('Location: '.$back);
  exit;
}

try {
  $reset = pr_get_reset_by_token($pdo, $token);
  if (!$reset) {
    $setFlash('El enlace ya fue utilizado o expiró.');
    header('Location: index.php?view=recuperar');
    exit;
  }

  $hash = password_hash($newPassword, PASSWORD_BCRYPT);
  $email = (string)$reset['email'];
  $type = (string)$reset['user_type'];

  if ($type === 'persona') {
    $stmt = $pdo->prepare('UPDATE candidatos SET password_hash = ?, updated_at = NOW() WHERE email = ?');
  } else {
    $stmt = $pdo->prepare('UPDATE empresa_cuentas SET password_hash = ?, updated_at = NOW() WHERE email = ?');
  }

  $stmt->execute([$hash, $email]);
  if ($stmt->rowCount() === 0) {
    throw new RuntimeException('No se actualizó la contraseña.');
  }

  pr_mark_reset_used($pdo, (int)$reset['id']);
  pr_prune_password_resets($pdo);

  $setFlash('Tu contraseña se actualizó correctamente. Ahora puedes iniciar sesión.', 'success');
  header('Location: index.php?view=login');
  exit;
} catch (Throwable $e) {
  error_log('[RESET] '.$e->getMessage());
  $setFlash('No pudimos actualizar tu contraseña. Intenta nuevamente.');
  header('Location: '.$back);
  exit;
}
