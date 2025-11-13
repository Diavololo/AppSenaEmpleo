<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__.'/db.php';
// Utilidad de escape
if (!function_exists('e')) { function e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('pc_human_diff')) {
  function pc_human_diff(?string $date): string {
    if (!$date) { return 'Reciente'; }
    try { $target = new DateTime($date); } catch (Throwable $e) { return 'Reciente'; }
    $now = new DateTime('now');
    $diff = $now->diff($target);
    if ($diff->invert === 0) { return 'Programado'; }
    if ($diff->days === 0) {
      $hours = (int)$diff->h;
      if ($hours <= 1) { return 'Hace menos de 1 hora'; }
      return 'Hace '.$hours.' horas';
    }
    if ($diff->days === 1) { return 'Hace 1 día'; }
    return 'Hace '.$diff->days.' días';
  }
}

// Solo candidatos
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['type'] ?? '') !== 'persona') {
  header('Location: index.php?view=login');
  exit;
}

// Navbar contexto usuario
$nav_context = 'user';
if (isset($_SESSION['empresa_email'])) { unset($_SESSION['empresa_email']); }

// Determinar si se accede directamente (para decidir header/footer)
$is_direct = basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__);
$view = 'postulacion_confirmada';
$base = $is_direct ? '../' : '';

// Entrada principal
$vacanteId = isset($_GET['vacante_id']) ? (int)$_GET['vacante_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
$email     = trim((string)($user['email'] ?? ''));

// Variables de UI
$titulo = 'Oferta';
$empresa = 'Empresa';
$ubicacion = 'Remoto';
$estadoOferta = 'Activa';
$match = 0;
$chips = [];
$coincidencias = [];
$advertencias = [];
$resumen = '';
$progreso = 15; // porcentaje de barra
$pasoActual = 1; // 1=Postulación, 2=Preselección, 3=Entrevista, 4=Oferta/Cierre
$estadoPostulacion = 'recibida';
$aplicadaAt = null;

if ($vacanteId && ($pdo instanceof PDO)) {
  try {
    // Cargar datos de postulación + vacante
    $stmt = $pdo->prepare(
      'SELECT p.estado, p.match_score, p.aplicada_at,
              v.titulo, v.descripcion, v.etiquetas, v.ciudad, v.modalidad, v.jornada,
              e.razon_social AS empresa_nombre
       FROM postulaciones p
       INNER JOIN vacantes v ON v.id = p.vacante_id
       LEFT JOIN empresas e ON e.id = v.empresa_id
       WHERE p.vacante_id = ? AND p.candidato_email = ?
       LIMIT 1'
    );
    $stmt->execute([$vacanteId, $email]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $estadoPostulacion = strtolower((string)($row['estado'] ?? 'recibida'));
      $match = isset($row['match_score']) ? (int)$row['match_score'] : 0;
      $titulo = $row['titulo'] ?: 'Oferta sin título';
      $empresa = $row['empresa_nombre'] ?: 'Empresa confidencial';
      $ubicacion = $row['ciudad'] ?: 'Remoto';
      $estadoOferta = 'Activa';
      $resumen = $row['descripcion'] ?: '';
      $aplicadaAt = $row['aplicada_at'] ?? null;
      // chips desde modalidad/jornada si existen
      $chips = array_filter([
        $row['jornada'] ?? null,
        $row['modalidad'] ?? null,
      ]);
      // coincidencias/advertencias aproximadas desde etiquetas
      $tags = array_map('trim', explode(',', (string)($row['etiquetas'] ?? '')));
      $coincidencias = array_slice(array_filter($tags), 0, 3);
      $advertencias = [];
      // Progreso y paso actual en función del estado
      $mapPaso = [
        'recibida'        => 1,
        'preseleccion'    => 2,
        'entrevista'      => 3,
        'oferta'          => 4,
        'contratado'      => 4,
        'no_seleccionado' => 4,
      ];
      $pasoActual = $mapPaso[$estadoPostulacion] ?? 1;
      $mapProg = [
        1 => 15,  // Postulación enviada
        2 => 35,  // Preselección
        3 => 60,  // Entrevista
        4 => ($estadoPostulacion === 'contratado' ? 100 : 85) // Oferta/Cierre
      ];
      $progreso = $mapProg[$pasoActual] ?? 15;
      if ($estadoPostulacion === 'no_seleccionado') { $progreso = 100; }
    }
  } catch (Throwable $e) {
    error_log('[PostulacionConfirmada] '.$e->getMessage());
  }
}
?>
<?php if ($is_direct): ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Postulación confirmada</title>
  <link rel="stylesheet" href="../style.css" />
  <style>
    .grid-2{ display:grid; grid-template-columns: 2fr 1fr; gap:24px; }
    .progress{ height:8px; background:#eef6ed; border:1px solid #dce9d8; border-radius:999px; overflow:hidden; }
    .progress > span{ display:block; height:100%; background: var(--brand); }
    .success{ display:flex; align-items:center; gap:12px; }
    .success .dot{ width:28px; height:28px; border-radius:999px; background:#2db24a; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:600; }
  </style>
</head>
<body>
<?php require __DIR__.'/../templates/header.php'; ?>
<?php endif; ?>

<main class="container page space-y-24">
  <!-- Mensaje de confirmación -->
  <section class="card p-24 space-y-16">
    <div class="success">
      <div class="dot">✓</div>
      <div>
        <h1 class="h5 m-0">¡Listo! Tu postulación fue enviada</h1>
        <p class="muted m-0">Hemos recibido tu postulación a <strong class="text-strong"><?= e($titulo) ?></strong> en <?= e($empresa) ?>. Te enviaremos actualizaciones y podrás seguir el estado desde <em>Mis postulaciones</em>.</p>
      </div>
    </div>
    <div>
      <a class="btn btn-primary" href="<?= $base ?>index.php?view=mis_postulaciones">Ver mis postulaciones</a>
    </div>
  </section>

  <!-- Resumen de la oferta + pasos siguientes -->
  <div class="grid-2">
    <!-- Resumen de la oferta -->
    <section class="card p-24 space-y-16">
      <div class="stack stack--row justify-between">
        <div>
          <h2 class="h6 m-0"><?= e($titulo) ?></h2>
          <div class="muted"><strong class="text-strong"><?= e($empresa) ?></strong> · <?= e($ubicacion) ?> · <span class="badge"><?= e($estadoOferta) ?></span></div>
        </div>
        <div class="muted"><?= e(pc_human_diff($aplicadaAt)); ?></div>
      </div>

      <div class="stack stack--row stack--wrap gap-8">
        <?php foreach ($chips as $chip): ?>
          <span class="chip"><?= e($chip) ?></span>
        <?php endforeach; ?>
      </div>

      <div class="stack stack--row gap-12 align-center">
        <div class="progress" style="width:240px"><span style="width: <?= (int)$progreso ?>%"></span></div>
        <span class="muted">Progreso <?= (int)$progreso ?>%</span>
      </div>
      <?php $estadoLabel = ucfirst(str_replace('_', ' ', $estadoPostulacion)); ?>
      <p class="muted m-0">Estado de tu postulación: <span class="badge"><?= e($estadoLabel) ?></span></p>

      <div class="grid" style="grid-template-columns: 1fr 1fr; gap:16px;">
        <div>
          <h3 class="text-sm text-muted m-0">Descripción corta</h3>
          <p class="m-0"><?= e($resumen) ?></p>
        </div>
        <div>
          <h3 class="text-sm text-muted m-0">Coincidencias</h3>
          <div class="stack stack--row stack--wrap gap-8">
            <?php foreach ($coincidencias as $t): ?>
              <span class="chip chip--match"><?= e($t) ?></span>
            <?php endforeach; ?>
          </div>
          <?php if ($advertencias): ?>
          <div class="stack stack--row stack--wrap gap-8">
            <?php foreach ($advertencias as $t): ?>
              <span class="chip chip--warn"><?= e($t) ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="stack stack--row gap-12 wrap">
        <?php if ($is_direct): ?>
          <a class="btn" href="<?= $base ?>index.php?view=oferta_detalle&id=<?= (int)$vacanteId ?>">Ver detalle de la oferta</a>
        <?php endif; ?>
        <a class="btn btn-soft" href="<?= $base ?>index.php?action=comprobante_postulacion&titulo=<?= urlencode($titulo) ?>&empresa=<?= urlencode($empresa) ?>&ciudad=<?= urlencode($ubicacion) ?>">Descargar comprobante</a>
      </div>
    </section>

    <!-- ¿Qué sigue? -->
    <aside class="card p-24 space-y-16">
      <h2 class="h6 m-0">¿Qué sigue?</h2>
      <ol class="steps-list">
        <li>
          <strong<?php if ($pasoActual === 1): ?> class="text-brand"<?php endif; ?>>Postulación enviada</strong>
          <p class="muted m-0">Te enviamos un correo de confirmación.</p>
        </li>
        <li>
          <strong<?php if ($pasoActual === 2): ?> class="text-brand"<?php endif; ?>>Preselección</strong>
          <p class="muted m-0">La empresa revisará tu perfil y CV.</p>
        </li>
        <li>
          <strong<?php if ($pasoActual === 3): ?> class="text-brand"<?php endif; ?>>Entrevista</strong>
          <p class="muted m-0">Si avanzas, recibirás la invitación con fecha y medio.</p>
        </li>
        <li>
          <strong<?php if ($pasoActual === 4): ?> class="text-brand"<?php endif; ?>>Oferta / Cierre</strong>
          <p class="muted m-0">Te notificaremos el resultado final.</p>
        </li>
      </ol>

      <div class="card p-16" style="background:#f7faf7">
<p class="muted m-0">Consejo: Mantén <a href="<?= $base ?>index.php?view=perfil_usuario" class="link">tu perfil actualizado</a> y añade certificaciones para mejorar el match.</p>
      </div>
    </aside>
  </div>
</main>

<?php if ($is_direct): ?>
<?php require __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
<?php endif; ?>
