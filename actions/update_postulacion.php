<?php
declare(strict_types=1);

session_start();

// Solo acepta POST desde empresa autenticada
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ../index.php?view=mis_ofertas_empresa');
  exit;
}

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['type'] ?? '') !== 'empresa') {
  header('Location: ../index.php?view=login');
  exit;
}

$redirectBase = '../index.php?view=candidatos';
$setFlash = static function (string $message): void {
  $_SESSION['flash_candidatos'] = $message;
};

// CSRF básico
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf'])) {
  $setFlash('Por seguridad, vuelve a intentarlo.');
  header('Location: '.$redirectBase);
  exit;
}

$vacanteId = (int)($_POST['vacante_id'] ?? 0);
$candidatoEmail = strtolower(trim((string)($_POST['candidato_email'] ?? '')));
$nuevoEstado = strtolower(trim((string)($_POST['nuevo_estado'] ?? '')));

$permitidos = ['preseleccion', 'entrevista', 'oferta', 'contratado', 'no_seleccionado', 'leida'];
if ($vacanteId <= 0 || $candidatoEmail === '' || !in_array($nuevoEstado, $permitidos, true)) {
  $setFlash('Datos de actualización inválidos.');
  header('Location: '.$redirectBase.'&vacante_id='.$vacanteId);
  exit;
}

require __DIR__.'/../pages/db.php';
require_once __DIR__.'/../lib/postulacion_events.php';

if (!($pdo instanceof PDO)) {
  $setFlash('No hay conexión con la base de datos.');
  header('Location: '.$redirectBase.'&vacante_id='.$vacanteId);
  exit;
}

$empresaId = (int)($user['empresa_id'] ?? 0);
if ($empresaId <= 0) {
  $setFlash('No encontramos la empresa asociada a tu cuenta.');
  header('Location: '.$redirectBase.'&vacante_id='.$vacanteId);
  exit;
}

try {
  // Verificar que la vacante pertenezca a la empresa de la sesión
  $vStmt = $pdo->prepare('SELECT COUNT(*) FROM vacantes WHERE id = ? AND empresa_id = ?');
  $vStmt->execute([$vacanteId, $empresaId]);
  if ((int)$vStmt->fetchColumn() === 0) {
    $setFlash('La vacante no pertenece a tu empresa.');
    header('Location: '.$redirectBase);
    exit;
  }

  // Verificar existencia de la postulación
  $pStmt = $pdo->prepare('SELECT id, estado FROM postulaciones WHERE vacante_id = ? AND candidato_email = ? LIMIT 1');
  $pStmt->execute([$vacanteId, $candidatoEmail]);
  $postulacion = $pStmt->fetch(PDO::FETCH_ASSOC);
  if (!$postulacion) {
    $setFlash('No encontramos la postulacion del candidato.');
    header('Location: '.$redirectBase.'&vacante_id='.$vacanteId);
    exit;
  }

  $estadoActual = strtolower((string)($postulacion['estado'] ?? ''));
  $postulacionId = (int)($postulacion['id'] ?? 0);

  if ($estadoActual === $nuevoEstado) {
    $setFlash('La postulacion ya esta en ese estado.');
    header('Location: '.$redirectBase.'&vacante_id='.$vacanteId);
    exit;
  }

  $upd = $pdo->prepare('UPDATE postulaciones SET estado = ?, updated_at = NOW() WHERE vacante_id = ? AND candidato_email = ?');
  $upd->execute([$nuevoEstado, $vacanteId, $candidatoEmail]);

  $labels = [
    'preseleccion'    => 'Preselección',
    'entrevista'      => 'Entrevista',
    'oferta'          => 'Oferta',
    'contratado'      => 'Contratado',
    'no_seleccionado' => 'No seleccionado',
    'leida'           => 'Leída',
  ];
  $label = $labels[$nuevoEstado] ?? ucfirst($nuevoEstado);
  $setFlash('Postulación actualizada a '.$label.'.');
  pe_log_event(
    $pdo,
    $postulacionId,
    $vacanteId,
    $candidatoEmail,
    $nuevoEstado,
    [
      'actor' => 'empresa',
      'nota' => 'La empresa actualizo el estado a '.$label.'.',
    ]
  );
} catch (Throwable $e) {
  error_log('[update_postulacion] '.$e->getMessage());
  $setFlash('No pudimos actualizar la postulación. Intenta de nuevo.');
}

header('Location: '.$redirectBase.'&vacante_id='.$vacanteId);
exit;
