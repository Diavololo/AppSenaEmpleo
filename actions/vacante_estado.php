<?php
declare(strict_types=1);

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ../index.php?view=mis_ofertas_empresa');
  exit;
}

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['type'] ?? '') !== 'empresa') {
  header('Location: ../index.php?view=login');
  exit;
}

$redirect = '../index.php?view=mis_ofertas_empresa';
$setFlash = static function (string $message): void {
  $_SESSION['flash_mis_ofertas'] = $message;
};

if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
  $setFlash('Por seguridad, vuelve a intentarlo.');
  header('Location: '.$redirect);
  exit;
}

$vacanteId = (int)($_POST['vacante_id'] ?? 0);
$nuevoEstado = strtolower(trim((string)($_POST['estado'] ?? '')));
$permitidos = ['publicada', 'pausada', 'cerrada', 'borrador'];

if ($vacanteId <= 0 || !in_array($nuevoEstado, $permitidos, true)) {
  $setFlash('Estado de vacante no válido.');
  header('Location: '.$redirect);
  exit;
}

require __DIR__.'/../pages/db.php';

if (!($pdo instanceof PDO)) {
  $setFlash('No hay conexión con la base de datos.');
  header('Location: '.$redirect);
  exit;
}

$empresaId = (int)($user['empresa_id'] ?? 0);
if ($empresaId <= 0) {
  $setFlash('No se encontró tu empresa.');
  header('Location: '.$redirect);
  exit;
}

try {
  $stmt = $pdo->prepare('SELECT estado FROM vacantes WHERE id = ? AND empresa_id = ? LIMIT 1');
  $stmt->execute([$vacanteId, $empresaId]);
  $actual = $stmt->fetchColumn();

  if ($actual === false) {
    $setFlash('No encontramos la vacante seleccionada.');
    header('Location: '.$redirect);
    exit;
  }

  if ($actual === $nuevoEstado) {
    $setFlash('La vacante ya está en ese estado.');
    header('Location: '.$redirect);
    exit;
  }

  $sql = 'UPDATE vacantes SET estado = ?, updated_at = NOW()';
  $params = [$nuevoEstado];

  if ($nuevoEstado === 'publicada') {
    $sql .= ', publicada_at = COALESCE(publicada_at, NOW())';
  }

  $sql .= ' WHERE id = ? AND empresa_id = ?';
  $params[] = $vacanteId;
  $params[] = $empresaId;

  $update = $pdo->prepare($sql);
  $update->execute($params);

  $labels = [
    'publicada' => 'activa',
    'pausada' => 'pausada',
    'cerrada' => 'cerrada',
    'borrador' => 'borrador',
  ];

  $setFlash('La vacante se marcó como '.$labels[$nuevoEstado].'.');
} catch (Throwable $e) {
  error_log('[vacante_estado] '.$e->getMessage());
  $setFlash('No pudimos actualizar la vacante. Intenta de nuevo.');
}

header('Location: '.$redirect);
exit;
