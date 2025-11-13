<?php
declare(strict_types=1);

$is_direct = (basename($_SERVER['SCRIPT_NAME']) === 'Ofertaendetalle.php');
if ($is_direct) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  $view = 'oferta_detalle';
} elseif (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require __DIR__.'/db.php';
require_once __DIR__.'/../lib/postulacion_events.php';

$vacanteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$baseHref = $is_direct ? '../index.php' : 'index.php';
$shareUrl = $vacanteId > 0
  ? $baseHref.'?view=oferta_detalle&id='.$vacanteId
  : $baseHref.'?view=oferta_detalle';

if (!function_exists('od_e')) {
  function od_e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('od_extract_lines')) {
  /**
   * @return string[]
   */
  function od_extract_lines(?string $value): array {
    $value = trim((string)$value);
    if ($value === '') { return []; }
    $parts = preg_split('/[\r\n]+/', $value);
    if (!is_array($parts)) { return []; }
    $parts = array_map(static fn($line) => trim((string)$line), $parts);
    $parts = array_filter($parts, static fn($line) => $line !== '');
    return array_values(array_unique($parts));
  }
}

if (!function_exists('od_extract_tags')) {
  /**
   * @return string[]
   */
  function od_extract_tags(?string $value): array {
    if ($value === null) { return []; }
    $parts = preg_split('/[,;|]+/', (string)$value);
    if (!is_array($parts)) { return []; }
    $parts = array_map(static fn($tag) => trim((string)$tag), $parts);
    $parts = array_filter($parts, static fn($tag) => $tag !== '');
    return array_values(array_unique($parts));
  }
}

if (!function_exists('od_format_salary')) {
  function od_format_salary(?int $min, ?int $max, string $currency): string {
    $currency = strtoupper(trim($currency ?: 'COP'));
    $format = static function (?int $value) use ($currency): string {
      if ($value === null || $value <= 0) { return ''; }
      return $currency.' '.number_format($value, 0, ',', '.');
    };
    $minLabel = $format($min);
    $maxLabel = $format($max);
    if ($minLabel && $maxLabel) { return $minLabel.' - '.$maxLabel; }
    if ($minLabel) { return 'Desde '.$minLabel; }
    if ($maxLabel) { return 'Hasta '.$maxLabel; }
    return 'Salario a convenir';
  }
}

if (!function_exists('od_human_diff')) {
  function od_human_diff(?string $date): string {
    if (!$date) { return 'Publicación sin fecha'; }
    try {
      $target = new DateTime($date);
    } catch (Throwable $e) {
      return 'Publicación reciente';
    }
    $now = new DateTime('now');
    $diff = $now->diff($target);
    $isFuture = ($diff->invert === 0);
    if ($isFuture) {
      return 'Publicación programada';
    }
    $days = (int)($diff->days ?? 0);
    $hours = (int)$diff->h;
    if ($days === 0) {
      if ($hours <= 1) { return 'Publicada hace menos de 1 hora'; }
      return 'Publicada hace '.$hours.' horas';
    }
    if ($days === 1) { return 'Publicada hace 1 día'; }
    return 'Publicada hace '.$days.' días';
  }
}

$sessionUser = $_SESSION['user'] ?? null;
$isCandidate = is_array($sessionUser) && (($sessionUser['type'] ?? '') === 'persona');
$candidateEmail = $isCandidate ? ($sessionUser['email'] ?? null) : null;

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$statusMessage = $_SESSION['flash_oferta'] ?? null;
if ($statusMessage) {
  unset($_SESSION['flash_oferta']);
}

$error = null;
$oferta = null;
$postulado = false;

if ($vacanteId <= 0) {
  $error = 'No encontramos la oferta solicitada.';
} elseif (!($pdo instanceof PDO)) {
  $error = 'No fue posible conectar con la base de datos.';
} else {
  try {
    $stmt = $pdo->prepare(
      'SELECT
          v.id,
          v.titulo,
          v.descripcion,
          v.requisitos,
          v.etiquetas,
          v.ciudad,
          v.salario_min,
          v.salario_max,
          v.moneda,
          v.publicada_at,
          v.created_at,
          v.estado,
          e.razon_social AS empresa_nombre,
          e.descripcion AS empresa_descripcion,
          e.ciudad AS empresa_ciudad,
          m.nombre AS modalidad_nombre,
          c.nombre AS contrato_nombre,
          n.nombre AS nivel_nombre,
          a.nombre AS area_nombre
       FROM vacantes v
       LEFT JOIN empresas e ON e.id = v.empresa_id
       LEFT JOIN modalidades m ON m.id = v.modalidad_id
       LEFT JOIN contratos c ON c.id = v.tipo_contrato_id
       LEFT JOIN niveles n ON n.id = v.nivel_id
       LEFT JOIN areas a ON a.id = v.area_id
       WHERE v.id = ?
       LIMIT 1'
    );
    $stmt->execute([$vacanteId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $chips = array_values(array_filter([
        $row['nivel_nombre'] ?? null,
        $row['modalidad_nombre'] ?? null,
        $row['contrato_nombre'] ?? null,
        $row['area_nombre'] ?? null,
      ]));

      $oferta = [
        'id' => (int)$row['id'],
        'titulo' => $row['titulo'] ?? 'Oferta sin título',
        'descripcion' => trim((string)($row['descripcion'] ?? '')),
        'requisitos' => od_extract_lines($row['requisitos'] ?? ''),
        'etiquetas' => od_extract_tags($row['etiquetas'] ?? ''),
        'ciudad' => $row['ciudad'] ?? ($row['empresa_ciudad'] ?? null),
        'salario' => od_format_salary(
          isset($row['salario_min']) ? (int)$row['salario_min'] : null,
          isset($row['salario_max']) ? (int)$row['salario_max'] : null,
          (string)($row['moneda'] ?? 'COP')
        ),
        'publicado' => od_human_diff($row['publicada_at'] ?? $row['created_at'] ?? null),
        'empresa' => $row['empresa_nombre'] ?? 'Empresa confidencial',
        'empresa_descripcion' => $row['empresa_descripcion'] ?? null,
        'estado' => $row['estado'] ?? 'activa',
        'chips' => $chips,
      ];
    } else {
      $error = 'La oferta indicada ya no está disponible.';
    }
  } catch (Throwable $e) {
    $error = 'No fue posible cargar la oferta.';
    error_log('[Ofertaendetalle] consulta vacante: '.$e->getMessage());
  }
}

if ($oferta && $isCandidate && $candidateEmail && ($pdo instanceof PDO)) {
  try {
    $check = $pdo->prepare('SELECT id FROM postulaciones WHERE vacante_id = ? AND candidato_email = ? LIMIT 1');
    $check->execute([$vacanteId, $candidateEmail]);
    $postulado = (bool)$check->fetchColumn();
  } catch (Throwable $e) {
    error_log('[Ofertaendetalle] check postulacion: '.$e->getMessage());
  }
}

$applyRequested = false;
$csrfError = false;
if ($oferta) {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['postular'])) {
    if (!hash_equals($csrf, $_POST['_csrf'] ?? '')) {
      $statusMessage = ['type' => 'error', 'text' => 'Por seguridad, actualiza la página e inténtalo de nuevo.'];
      $csrfError = true;
    } else {
      $applyRequested = true;
    }
  }
  if (!$applyRequested && !$csrfError && isset($_GET['apply']) && $_GET['apply'] === '1') {
    $applyRequested = true;
  }
}

if ($applyRequested && $oferta && ($pdo instanceof PDO)) {
  if (!$isCandidate || !$candidateEmail) {
    $statusMessage = ['type' => 'error', 'text' => 'Inicia sesión como candidato para postularte.'];
  } elseif ($postulado) {
    $statusMessage = ['type' => 'info', 'text' => 'Ya te habías postulado a esta oferta.'];
  } else {
    try {
      $insert = $pdo->prepare('INSERT INTO postulaciones (vacante_id, candidato_email) VALUES (?, ?)');
      $insert->execute([$vacanteId, $candidateEmail]);
      $postulacionId = (int)$pdo->lastInsertId();
      pe_log_event(
        $pdo,
        $postulacionId,
        $vacanteId,
        $candidateEmail,
        'recibida',
        [
          'actor' => 'candidato',
          'nota' => 'Postulacion enviada desde el portal.',
        ]
      );
      $_SESSION['flash_oferta'] = [
        'type' => 'success',
        'text' => '¡Listo! Te postulaste a '.($oferta['titulo'] ?? 'la oferta').'.',
      ];
      // Redirige a la ventana dinámico de Postulación confirmada
      header('Location: index.php?view=postulacion_confirmada&vacante_id='.$vacanteId);
      exit;
    } catch (PDOException $ex) {
      if (($ex->errorInfo[1] ?? null) === 1062) {
        $postulado = true;
        $statusMessage = ['type' => 'info', 'text' => 'Ya te habías postulado a esta oferta.'];
      } else {
        $statusMessage = ['type' => 'error', 'text' => 'No pudimos registrar tu postulación. Inténtalo más tarde.'];
        error_log('[Ofertaendetalle] insertar postulacion: '.$ex->getMessage());
      }
    }
  }
}

$messageStyles = [
  'success' => 'border-color:#d6f5d6;background:#f3fff3;',
  'info' => 'border-color:#d2e7ff;background:#f5f9ff;',
  'error' => 'border-color:#f5c7c7;background:#fff5f5;',
];

?>
<?php if ($is_direct): ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Detalle de oferta</title>
  <link rel="stylesheet" href="../style.css" />
</head>
<body>
  <?php require __DIR__.'/../templates/header.php'; ?>
<?php endif; ?>

<section class="container section">
  <div style="margin-bottom: var(--sp-3);">
    <a href="<?=$baseHref; ?>?view=dashboard" class="btn btn-secondary">← Volver a recomendaciones</a>
  </div>

  <?php if ($error): ?>
    <div class="card" style="border-color:#f5c7c7;background:#fff5f5;">
      <strong><?=od_e($error); ?></strong>
      <p class="muted m-0">Explora otras oportunidades en el panel principal.</p>
    </div>
  <?php elseif ($oferta): ?>
    <?php if ($statusMessage): ?>
      <?php
        $type = $statusMessage['type'] ?? 'info';
        $style = $messageStyles[$type] ?? $messageStyles['info'];
      ?>
      <div class="card" style="<?=$style; ?>">
        <strong><?=od_e($statusMessage['text'] ?? '');?></strong>
      </div>
    <?php endif; ?>

    <div class="card" style="display:grid; gap: var(--sp-3);">
      <div style="display:flex; justify-content:space-between; align-items:start; gap: var(--sp-4);">
        <div>
          <h1 class="h2 m-0"><?=od_e($oferta['titulo']); ?></h1>
          <p class="muted m-0">
            <strong><?=od_e($oferta['empresa']); ?></strong>
            <?php if (!empty($oferta['ciudad'])): ?>
              · <?=od_e($oferta['ciudad']); ?>
            <?php endif; ?>
          </p>
        </div>
        <span class="badge"><?=od_e($oferta['publicado']); ?></span>
      </div>

      <?php if (!empty($oferta['chips'])): ?>
        <div class="tags">
          <?php foreach ($oferta['chips'] as $chip): ?>
            <span class="chip"><?=od_e($chip); ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="row-cta" style="flex-wrap:wrap;">
        <?php if ($isCandidate && $candidateEmail && !$postulado): ?>
          <form method="post" style="display:flex; gap:.6rem; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="_csrf" value="<?=od_e($csrf); ?>" />
            <button class="btn btn-brand" type="submit" name="postular" value="1">Postular</button>
          </form>
        <?php elseif ($postulado): ?>
          <span class="chip">Ya postulaste</span>
          <a class="btn btn-secondary" href="<?=$baseHref; ?>?view=mis_postulaciones">Ver mis postulaciones</a>
        <?php else: ?>
          <a class="btn btn-brand" href="<?=$baseHref; ?>?view=login">Inicia sesión para postular</a>
        <?php endif; ?>
        <a class="btn btn-outline" href="mailto:?subject=<?=rawurlencode('Oferta: '.($oferta['titulo'] ?? 'Vacante SENA')); ?>&body=<?=rawurlencode('Te comparto esta oferta que encontré en la Bolsa de Empleo SENA: '.(isset($oferta['titulo']) ? $oferta['titulo'].' - ' : '').$shareUrl); ?>">Compartir</a>
      </div>
    </div>

    <div class="grid" style="display:grid; grid-template-columns: 2fr 1fr; gap: var(--sp-4); margin-top: var(--sp-4); align-items:start;">
      <div style="display:grid; gap: var(--sp-4);">
        <div class="card" style="display:grid; gap: var(--sp-3);">
          <h2 class="h5">Descripción</h2>
          <p><?=nl2br(od_e($oferta['descripcion'] !== '' ? $oferta['descripcion'] : 'La empresa aún no ha agregado una descripción detallada.')); ?></p>
          <div style="display:flex; gap: var(--sp-4); flex-wrap:wrap;">
            <div style="flex:1; min-width:180px;">
              <h3 class="h6 m-0">Salario estimado</h3>
              <p class="h4 m-0"><?=od_e($oferta['salario']); ?></p>
            </div>
            <?php if (!empty($oferta['chips'][0])): ?>
              <div style="flex:1; min-width:160px;">
                <h3 class="h6 m-0">Nivel</h3>
                <p class="m-0"><?=od_e($oferta['chips'][0]); ?></p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card" style="display:grid; gap: .6rem;">
          <h2 class="h5">Requisitos principales</h2>
          <?php if (!empty($oferta['requisitos'])): ?>
            <ul role="list" style="list-style: disc; padding-left: 1.2rem; display:grid; gap:.3rem;">
              <?php foreach ($oferta['requisitos'] as $req): ?>
                <li><?=od_e($req); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="muted m-0">El empleador no detalló requisitos específicos.</p>
          <?php endif; ?>
        </div>

        <div class="card" style="display:grid; gap: .6rem;">
          <h2 class="h5">Etiquetas</h2>
          <?php if (!empty($oferta['etiquetas'])): ?>
            <div class="tags">
              <?php foreach ($oferta['etiquetas'] as $tag): ?>
                <span class="chip"><?=od_e($tag); ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="muted m-0">Sin etiquetas asociadas.</p>
          <?php endif; ?>
        </div>
      </div>

      <aside style="display:grid; gap: var(--sp-4);">
        <div class="card" style="display:grid; gap:.6rem;">
          <h3 class="h5">Resumen de la oferta</h3>
          <ul style="list-style:none; padding:0; margin:0; display:grid; gap:.4rem;">
            <li><strong>Empresa:</strong> <?=od_e($oferta['empresa']); ?></li>
            <li><strong>Ubicación:</strong> <?=od_e($oferta['ciudad'] ?? 'No informada'); ?></li>
            <li><strong>Publicación:</strong> <?=od_e($oferta['publicado']); ?></li>
            <li><strong>Estado:</strong> <?=od_e(ucfirst((string)$oferta['estado'])); ?></li>
          </ul>
        </div>

        <?php if (!empty($oferta['empresa_descripcion'])): ?>
          <div class="card" style="display:grid; gap:.6rem;">
            <h3 class="h5">Sobre la empresa</h3>
            <p class="m-0"><?=nl2br(od_e($oferta['empresa_descripcion'])); ?></p>
          </div>
        <?php endif; ?>
      </aside>
    </div>
  <?php endif; ?>
</section>

<?php if ($is_direct): ?>
  <?php require __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
<?php endif; ?>
