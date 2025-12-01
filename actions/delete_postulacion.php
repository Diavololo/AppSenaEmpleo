<?php
declare(strict_types=1);

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ../index.php?view=mis_postulaciones');
  exit;
}

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['type'] ?? '') !== 'persona') {
  header('Location: ../index.php?view=login');
  exit;
}

$redirect = '../index.php?view=mis_postulaciones';

$setFlash = static function (string $message): void {
  $_SESSION['flash_postulaciones'] = $message;
};

// CSRF básico
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf'])) {
  $setFlash('Por seguridad, vuelve a intentarlo.');
  header('Location: '.$redirect);
  exit;
}

$postulacionId = (int)($_POST['postulacion_id'] ?? 0);
$email = strtolower(trim((string)($user['email'] ?? '')));

if ($postulacionId <= 0 || $email === '') {
  $setFlash('Datos inválidos para eliminar la postulación.');
  header('Location: '.$redirect);
  exit;
}

require __DIR__.'/../pages/db.php';

if (!($pdo instanceof PDO)) {
  $setFlash('No hay conexión con la base de datos.');
  header('Location: '.$redirect);
  exit;
}

try {
  // Verificar que la postulación pertenece al usuario
  $chk = $pdo->prepare('SELECT candidato_email FROM postulaciones WHERE id = ? LIMIT 1');
  $chk->execute([$postulacionId]);
  $owner = $chk->fetchColumn();
  if (!$owner || strtolower((string)$owner) !== $email) {
    $setFlash('No se encontró la postulación o no te pertenece.');
    header('Location: '.$redirect);
    exit;
  }

  $del = $pdo->prepare('DELETE FROM postulaciones WHERE id = ?');
  $del->execute([$postulacionId]);
  $setFlash('Postulación eliminada de tu listado.');
} catch (Throwable $e) {
  error_log('[delete_postulacion] '.$e->getMessage());
  $setFlash('No pudimos eliminar la postulación. Intenta de nuevo.');
}

header('Location: '.$redirect);
exit;
