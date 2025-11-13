<?php
declare(strict_types=1);

// Solo accesible a través de index.php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php');
  exit;
}

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['type'] ?? '') !== 'empresa') {
  header('Location: ../index.php?view=login');
  exit;
}

require __DIR__.'/../pages/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ../index.php?view=mis_ofertas_empresa');
  exit;
}

// CSRF
$csrfSession = $_SESSION['csrf'] ?? '';
$csrfPosted = (string)($_POST['csrf'] ?? '');
if (!is_string($csrfSession) || $csrfSession === '' || !hash_equals($csrfSession, $csrfPosted)) {
  $_SESSION['flash_mis_ofertas'] = 'Por seguridad, recarga e inténtalo de nuevo (CSRF).';
  header('Location: ../index.php?view=mis_ofertas_empresa');
  exit;
}

$empresaId = (int)($user['empresa_id'] ?? 0);
$postulacionId = isset($_POST['postulacion_id']) ? (int)$_POST['postulacion_id'] : 0;
$rawDate = trim((string)($_POST['nueva_fecha'] ?? ''));

if ($empresaId <= 0 || $postulacionId <= 0 || $rawDate === '') {
  $_SESSION['flash_mis_ofertas'] = 'Datos incompletos para reprogramar la entrevista.';
  header('Location: ../index.php?view=mis_ofertas_empresa');
  exit;
}

// Validación y formato de fecha (datetime-local => Y-m-d H:i:s)
$dt = DateTime::createFromFormat('Y-m-d\TH:i', $rawDate);
if (!$dt) {
  $_SESSION['flash_mis_ofertas'] = 'Formato de fecha inválido.';
  header('Location: ../index.php?view=mis_ofertas_empresa');
  exit;
}
$targetDate = $dt->format('Y-m-d H:i:00');

if (!($pdo instanceof PDO)) {
  $_SESSION['flash_mis_ofertas'] = 'No hay conexión con la base de datos.';
  header('Location: ../index.php?view=mis_ofertas_empresa');
  exit;
}

try {
  // Verifica que la postulación pertenezca a una vacante de esta empresa
  $check = $pdo->prepare('SELECT p.id FROM postulaciones p INNER JOIN vacantes v ON v.id = p.vacante_id WHERE p.id = ? AND v.empresa_id = ? LIMIT 1');
  $check->execute([$postulacionId, $empresaId]);
  $exists = $check->fetchColumn();
  if (!$exists) {
    $_SESSION['flash_mis_ofertas'] = 'No puedes editar esta entrevista.';
    header('Location: ../index.php?view=mis_ofertas_empresa');
    exit;
  }

  // Actualiza la fecha programada (se usa updated_at para las entrevistas en el dashboard)
  $upd = $pdo->prepare('UPDATE postulaciones SET updated_at = ? WHERE id = ?');
  $upd->execute([$targetDate, $postulacionId]);

  $_SESSION['flash_mis_ofertas'] = 'Entrevista reprogramada correctamente.';
} catch (Throwable $e) {
  error_log('[reprogramar_entrevista] '.$e->getMessage());
  $_SESSION['flash_mis_ofertas'] = 'Ocurrió un error al reprogramar la entrevista.';
}

header('Location: ../index.php?view=mis_ofertas_empresa');
exit;
?>