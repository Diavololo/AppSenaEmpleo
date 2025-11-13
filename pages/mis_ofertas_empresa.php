<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=mis_ofertas_empresa');
  exit;
}

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$sessionUser = $_SESSION['user'] ?? null;
if (!$sessionUser || ($sessionUser['type'] ?? '') !== 'empresa') {
  header('Location: ../index.php?view=login');
  exit;
}

require __DIR__.'/db.php';

if (!function_exists('mo_e')) {
  function mo_e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('mo_truncate')) {
  function mo_truncate(?string $text, int $limit = 220): string {
    $text = trim((string)$text);
    if ($text === '' || mb_strlen($text, 'UTF-8') <= $limit) {
      return $text;
    }
    $slice = mb_substr($text, 0, $limit - 1, 'UTF-8');
    return rtrim($slice).'…';
  }
}

if (!function_exists('mo_format_money')) {
  function mo_format_money(?int $min, ?int $max, string $currency): string {
    $currency = strtoupper(trim($currency ?: 'COP'));
    if ($min === null && $max === null) {
      return 'Salario a convenir';
    }
    $format = static function (?int $value) use ($currency): string {
      return ($value === null || $value <= 0) ? '' : $currency.' '.number_format($value, 0, ',', '.');
    };
    if ($min !== null && $max !== null) { return $format($min).' - '.$format($max); }
    if ($min !== null) { return 'Desde '.$format($min); }
    return 'Hasta '.$format($max);
  }
}

if (!function_exists('mo_human_time_diff')) {
  function mo_human_time_diff(?string $date): string {
    if (!$date) { return 'Sin fecha'; }
    try { $target = new DateTime($date); } catch (Throwable $e) { return 'Sin fecha'; }
    $now = new DateTime('now');
    $diff = $now->diff($target);
    if ($diff->invert === 0) {
      if ($diff->days === 0) { return 'Hoy'; }
      if ($diff->days === 1) { return 'En 1 día'; }
      return 'En '.$diff->days.' días';
    }
    if ($diff->days === 0) {
      $hours = (int)$diff->h;
      if ($hours <= 1) { return 'Publicada hoy'; }
      return 'Hace '.$hours.' horas';
    }
    if ($diff->days === 1) { return 'Hace 1 día'; }
    if ($diff->days < 30) { return 'Hace '.$diff->days.' días'; }
    $months = (int)floor($diff->days / 30);
    if ($months <= 1) { return 'Hace 1 mes'; }
    return 'Hace '.$months.' meses';
  }
}

if (!function_exists('mo_collect_chips')) {
  function mo_collect_chips(array $vacante): array {
    $chips = [];
    foreach (['area_nombre','nivel_nombre','modalidad_nombre','contrato_nombre'] as $key) {
      $value = trim((string)($vacante[$key] ?? ''));
      if ($value !== '') { $chips[] = $value; }
    }
    if (!empty($vacante['etiquetas'])) {
      $tags = preg_split('/[,;]+/', (string)$vacante['etiquetas']);
      if (is_array($tags)) {
        foreach ($tags as $tag) {
          $tag = trim((string)$tag);
          if ($tag !== '') { $chips[] = $tag; }
        }
      }
    }
    return array_values(array_unique($chips));
  }
}

if (!function_exists('mo_state_meta')) {
  function mo_state_meta(string $state): array {
    $state = strtolower($state);
    $map = [
      'publicada' => ['label' => 'Activa', 'class' => 'co-state is-active'],
      'pausada' => ['label' => 'Pausada', 'class' => 'co-state is-paused'],
      'cerrada' => ['label' => 'Cerrada', 'class' => 'co-state is-closed'],
      'borrador' => ['label' => 'Borrador', 'class' => 'co-state is-draft'],
    ];
    return $map[$state] ?? ['label' => ucfirst($state), 'class' => 'co-state'];
  }
}

if (!function_exists('mo_state_actions')) {
  function mo_state_actions(string $state): array {
    return match (strtolower($state)) {
      'publicada' => [
        ['label' => 'Pausar', 'state' => 'pausada', 'class' => 'btn btn-outline'],
        ['label' => 'Cerrar', 'state' => 'cerrada', 'class' => 'btn btn-danger'],
      ],
      'pausada' => [
        ['label' => 'Reactivar', 'state' => 'publicada', 'class' => 'btn btn-outline'],
        ['label' => 'Cerrar', 'state' => 'cerrada', 'class' => 'btn btn-danger'],
      ],
      'borrador' => [
        ['label' => 'Publicar', 'state' => 'publicada', 'class' => 'btn btn-outline'],
      ],
      'cerrada' => [
        ['label' => 'Reabrir', 'state' => 'publicada', 'class' => 'btn btn-outline'],
      ],
      default => [],
    };
  }
}

if (!function_exists('mo_slugify')) {
  function mo_slugify(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') { return ''; }

    static $replacements = [
      'á' => 'a', 'Á' => 'a', 'à' => 'a', 'À' => 'a', 'ä' => 'a', 'Ä' => 'a', 'â' => 'a', 'Â' => 'a',
      'é' => 'e', 'É' => 'e', 'è' => 'e', 'È' => 'e', 'ë' => 'e', 'Ë' => 'e', 'ê' => 'e', 'Ê' => 'e',
      'í' => 'i', 'Í' => 'i', 'ì' => 'i', 'Ì' => 'i', 'ï' => 'i', 'Ï' => 'i', 'î' => 'i', 'Î' => 'i',
      'ó' => 'o', 'Ó' => 'o', 'ò' => 'o', 'Ò' => 'o', 'ö' => 'o', 'Ö' => 'o', 'ô' => 'o', 'Ô' => 'o',
      'ú' => 'u', 'Ú' => 'u', 'ù' => 'u', 'Ù' => 'u', 'ü' => 'u', 'Ü' => 'u', 'û' => 'u', 'Û' => 'u',
      'ñ' => 'n', 'Ñ' => 'n',
    ];
    $value = strtr($value, $replacements);

    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii === false || $ascii === '') { $ascii = $value; }

    $ascii = strtolower((string)$ascii);
    $ascii = preg_replace('/[^a-z0-9]+/', '-', $ascii ?? '');
    return trim((string)$ascii, '-');
  }
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf'];

$empresaId = isset($sessionUser['empresa_id']) ? (int)$sessionUser['empresa_id'] : null;
$empresaNombre = $sessionUser['empresa'] ?? ($sessionUser['display_name'] ?? 'Mi empresa');

$filterSearch = trim((string)($_GET['buscar'] ?? ''));
$filterStates = $_GET['estado'] ?? [];
if (!is_array($filterStates)) { $filterStates = [$filterStates]; }
$filterStates = array_values(array_intersect(['publicada','pausada','cerrada','borrador'], array_map('strtolower', $filterStates)));
$filterModalidad = $_GET['modalidad'] ?? [];
if (!is_array($filterModalidad)) { $filterModalidad = [$filterModalidad]; }
$filterModalidad = array_values(array_filter(array_map('mo_slugify', $filterModalidad)));
$filterCiudad = trim((string)($_GET['ciudad'] ?? ''));
$filterSalario = preg_replace('/[^0-9]/', '', (string)($_GET['salario'] ?? ''));
$filterSalario = $filterSalario !== '' ? (int)$filterSalario : null;
$filtersApplied = ($filterSearch !== '' || $filterStates || $filterModalidad || $filterCiudad !== '' || $filterSalario !== null);

$modalidadOptions = ['remoto' => 'Remoto', 'hibrido' => 'Híbrido', 'presencial' => 'Presencial'];
$estadoOptions = ['publicada' => 'Activa', 'pausada' => 'Pausada', 'cerrada' => 'Cerrada', 'borrador' => 'Borrador'];

$vacantesRaw = [];
$dataError = null;
$kpiCounts = ['activas' => 0, 'nuevos' => 0, 'entrevistas' => 0, 'cerradas' => 0];
$statsByVacante = [];
$upcomingInterviews = [];

if ($empresaId && $pdo instanceof PDO) {
  try {
    $stmt = $pdo->prepare(
      'SELECT v.*, m.nombre AS modalidad_nombre, n.nombre AS nivel_nombre, c.nombre AS contrato_nombre, a.nombre AS area_nombre
       FROM vacantes v
       LEFT JOIN modalidades m ON m.id = v.modalidad_id
       LEFT JOIN niveles n ON n.id = v.nivel_id
       LEFT JOIN contratos c ON c.id = v.tipo_contrato_id
       LEFT JOIN areas a ON a.id = v.area_id
       WHERE v.empresa_id = ?
       ORDER BY COALESCE(v.publicada_at, v.created_at) DESC'
    );
    $stmt->execute([$empresaId]);
    $vacantesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $queryError) {
    $dataError = 'No se pudieron cargar tus vacantes.';
    error_log('[mis_ofertas_empresa] '.$queryError->getMessage());
  }
} elseif (!$empresaId) {
  $dataError = 'No encontramos la empresa asociada a tu cuenta.';
} else {
  $dataError = 'No hay conexión con la base de datos.';
}

$vacanteIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $vacantesRaw), static fn($id) => $id > 0));

if ($vacanteIds && $pdo instanceof PDO) {
  $placeholders = implode(',', array_fill(0, count($vacanteIds), '?'));
  try {
    $statSql = 'SELECT vacante_id,
        COUNT(*) AS total,
        SUM(estado = "recibida") AS recibidas,
        SUM(estado = "preseleccion") AS preseleccion,
        SUM(estado = "entrevista") AS entrevistas,
        SUM(estado = "oferta") AS ofertas,
        SUM(estado = "contratado") AS contratados
      FROM postulaciones
      WHERE vacante_id IN ('.$placeholders.')
      GROUP BY vacante_id';
    $statStmt = $pdo->prepare($statSql);
    $statStmt->execute($vacanteIds);
    foreach ($statStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $vacId = (int)$row['vacante_id'];
      $statsByVacante[$vacId] = [
        'total' => (int)$row['total'],
        'recibidas' => (int)$row['recibidas'],
        'preseleccion' => (int)$row['preseleccion'],
        'entrevista' => (int)$row['entrevistas'],
        'oferta' => (int)$row['ofertas'],
        'contratados' => (int)$row['contratados'],
      ];
    }

    $newSql = 'SELECT COUNT(*) FROM postulaciones WHERE vacante_id IN ('.$placeholders.') AND aplicada_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    $newStmt = $pdo->prepare($newSql);
    $newStmt->execute($vacanteIds);
    $kpiCounts['nuevos'] = (int)$newStmt->fetchColumn();

    $todaySql = 'SELECT COUNT(*) FROM postulaciones WHERE vacante_id IN ('.$placeholders.') AND estado = "entrevista" AND DATE(updated_at) = CURRENT_DATE';
    $todayStmt = $pdo->prepare($todaySql);
    $todayStmt->execute($vacanteIds);
    $kpiCounts['entrevistas'] = (int)$todayStmt->fetchColumn();

    $closedSql = 'SELECT COUNT(*) FROM vacantes WHERE empresa_id = ? AND estado = "cerrada" AND MONTH(updated_at) = MONTH(CURRENT_DATE) AND YEAR(updated_at) = YEAR(CURRENT_DATE)';
    $closedStmt = $pdo->prepare($closedSql);
    $closedStmt->execute([$empresaId]);
    $kpiCounts['cerradas'] = (int)$closedStmt->fetchColumn();

    $interSql = 'SELECT p.id, p.updated_at, v.titulo, c.nombres, c.apellidos
                 FROM postulaciones p
                 INNER JOIN vacantes v ON v.id = p.vacante_id
                 INNER JOIN candidatos c ON c.email = p.candidato_email
                 WHERE v.empresa_id = ? AND p.estado = "entrevista"
                 ORDER BY p.updated_at ASC
                 LIMIT 3';
    $interStmt = $pdo->prepare($interSql);
    $interStmt->execute([$empresaId]);
    $upcomingInterviews = $interStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $statsError) {
    error_log('[mis_ofertas_empresa][stats] '.$statsError->getMessage());
  }
}

$enriched = [];
foreach ($vacantesRaw as $vacante) {
  $estado = strtolower((string)($vacante['estado'] ?? 'borrador'));
  if ($estado === 'publicada') { $kpiCounts['activas']++; }
  $id = (int)$vacante['id'];
  $chips = mo_collect_chips($vacante);
  $stats = $statsByVacante[$id] ?? ['total' => 0, 'recibidas' => 0, 'preseleccion' => 0, 'entrevista' => 0, 'oferta' => 0, 'contratados' => 0];
  $progress = $stats['total'] > 0 ? (int)min(100, round((($stats['preseleccion'] + $stats['entrevista'] + $stats['oferta'] + $stats['contratados']) / max(1, $stats['total'])) * 100)) : 0;
  $warnings = [];
  if (empty($vacante['salario_min']) && empty($vacante['salario_max'])) { $warnings[] = 'Falta rango salarial y beneficios.'; }
  if (empty(trim((string)$vacante['etiquetas']))) { $warnings[] = 'Agrega habilidades clave como chips.'; }
  if ($estado === 'borrador') { $warnings[] = 'Completa la vacante y publícala cuando esté lista.'; }
  $summary = trim($empresaNombre.' · '.(($vacante['ciudad'] ?? 'Remoto')).' · '.mo_human_time_diff($vacante['publicada_at'] ?? $vacante['created_at']));
  $searchBlob = mb_strtolower(($vacante['titulo'] ?? '').' '.($vacante['descripcion'] ?? '').' '.($vacante['ciudad'] ?? '').' '.implode(' ', $chips), 'UTF-8');

  $enriched[] = [
    'raw' => $vacante,
    'id' => $id,
    'estado' => $estado,
    'state_meta' => mo_state_meta($estado),
    'actions' => mo_state_actions($estado),
    'chips' => $chips,
    'stats' => $stats,
    'progress' => $progress,
    'warning' => $warnings ? implode(' ', $warnings) : null,
    'summary' => $summary,
    'modalidad_slug' => mo_slugify($vacante['modalidad_nombre'] ?? ''),
    'ciudad_slug' => mo_slugify($vacante['ciudad'] ?? ''),
    'search_blob' => $searchBlob,
    'salario_min_raw' => isset($vacante['salario_min']) ? (int)$vacante['salario_min'] : null,
    'salario_max_raw' => isset($vacante['salario_max']) ? (int)$vacante['salario_max'] : null,
  ];
}

$visibleVacantes = array_values(array_filter(
  $enriched,
  static function (array $vac) use ($filterStates, $filterModalidad, $filterSearch, $filterCiudad, $filterSalario): bool {
    if ($filterStates && !in_array($vac['estado'], $filterStates, true)) { return false; }
    if ($filterModalidad && !in_array($vac['modalidad_slug'], $filterModalidad, true)) { return false; }
    if ($filterCiudad !== '' && strpos($vac['ciudad_slug'], mo_slugify($filterCiudad)) === false) { return false; }
    if ($filterSalario !== null && (int)($vac['salario_min_raw'] ?? 0) < $filterSalario) { return false; }
    if ($filterSearch !== '' && mb_strpos($vac['search_blob'], mb_strtolower($filterSearch, 'UTF-8')) === false) { return false; }
    return true;
  }
));

$headKpis = [
  ['label' => 'Activas', 'value' => $kpiCounts['activas']],
  ['label' => 'Postulados nuevos', 'value' => $kpiCounts['nuevos']],
  ['label' => 'Entrevistas hoy', 'value' => $kpiCounts['entrevistas']],
  ['label' => 'Cerradas este mes', 'value' => $kpiCounts['cerradas']],
];

$flash = $_SESSION['flash_mis_ofertas'] ?? null;
unset($_SESSION['flash_mis_ofertas']);
?>

<section class="section co-wrapper">
  <div class="container">
    <div class="co-head">
      <div>
        <h1>Mis ofertas</h1>
        <p class="muted">Administra vacantes, candidatos y entrevistas de <?=mo_e($empresaNombre); ?>.</p>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="card co-alert co-alert--success"><strong><?=mo_e($flash); ?></strong></div>
    <?php endif; ?>

    <?php if ($dataError): ?>
      <div class="card co-alert co-alert--error"><strong><?=mo_e($dataError); ?></strong></div>
    <?php endif; ?>

    <div class="co-kpis">
      <?php foreach ($headKpis as $kpi): ?>
        <div class="card co-kpi">
          <span class="co-kpi-label"><?=mo_e($kpi['label']); ?></span>
          <span class="co-kpi-value"><?=mo_e((string)$kpi['value']); ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="co-layout">
      <aside class="co-filters card">
        <form class="co-filter-form" action="" method="get">
          <input type="hidden" name="view" value="mis_ofertas_empresa" />
          <label class="co-filter-field">
            <span>Buscar</span>
            <input type="search" name="buscar" placeholder="Título o palabra clave" value="<?=mo_e($filterSearch); ?>" />
          </label>
          <div class="co-filter-group">
            <span>Estado</span>
            <?php foreach ($estadoOptions as $value => $label): ?>
              <label class="check"><input type="checkbox" name="estado[]" value="<?=mo_e($value); ?>" <?=in_array($value, $filterStates, true) ? 'checked' : ''; ?> /> <?=mo_e($label); ?></label>
            <?php endforeach; ?>
          </div>
          <div class="co-filter-group">
            <span>Modalidad</span>
            <?php foreach ($modalidadOptions as $slug => $label): ?>
              <label class="check"><input type="checkbox" name="modalidad[]" value="<?=mo_e($slug); ?>" <?=in_array($slug, $filterModalidad, true) ? 'checked' : ''; ?> /> <?=mo_e($label); ?></label>
            <?php endforeach; ?>
          </div>
          <label class="co-filter-field">
            <span>Ciudad</span>
            <input type="text" name="ciudad" placeholder="Bogotá, Medellín..." value="<?=mo_e($filterCiudad); ?>" />
          </label>
          <label class="co-filter-field">
            <span>Salario mínimo</span>
            <input type="text" name="salario" placeholder="$2.000.000" value="<?=$filterSalario ? mo_e(number_format($filterSalario, 0, ',', '.')) : ''; ?>" />
          </label>
          <div class="co-filter-actions">
            <button type="submit" class="btn btn-primary">Aplicar filtros</button>
            <?php if ($filtersApplied): ?>
              <a class="link-edit" href="?view=mis_ofertas_empresa">Limpiar</a>
            <?php endif; ?>
          </div>
          <p class="co-tip">Usa títulos claros y agrega 5-7 habilidades para mejorar la calidad de postulantes.</p>
        </form>
      </aside>

      <div class="co-main">
        <div class="co-main-head">
          <div>
            <h2>Vacantes publicadas</h2>
            <p class="muted">Resumen de estado, candidatos y próximas acciones.</p>
          </div>
        </div>

        <?php if ($visibleVacantes): ?>
          <?php foreach ($visibleVacantes as $vac): ?>
            <?php $row = $vac['raw']; ?>
            <article class="card co-card">
              <div class="co-card-head">
                <div>
                  <h3><?=mo_e($row['titulo'] ?? 'Vacante sin título'); ?></h3>
                  <p class="muted"><?=mo_e($vac['summary']); ?></p>
                </div>
                <span class="<?=mo_e($vac['state_meta']['class']); ?>"><?=mo_e($vac['state_meta']['label']); ?></span>
              </div>
              <?php if ($vac['chips']): ?>
                <div class="co-chips">
                  <?php foreach ($vac['chips'] as $chip): ?>
                    <span class="chip chip-small"><?=mo_e($chip); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($row['descripcion'])): ?>
                <p class="co-desc"><?=mo_e(mo_truncate($row['descripcion'])); ?></p>
              <?php endif; ?>
              <?php if ($vac['warning']): ?>
                <div class="co-warning"><?=mo_e($vac['warning']); ?></div>
              <?php endif; ?>
              <div class="co-meta-row">
                <div>
                  <span class="co-meta-label">Salario</span>
                  <span class="co-meta-value"><?=mo_e(mo_format_money($vac['salario_min_raw'], $vac['salario_max_raw'], (string)($row['moneda'] ?? 'COP'))); ?></span>
                </div>
                <?php if (!empty($row['requisitos'])): ?>
                  <div>
                    <span class="co-meta-label">Requisitos</span>
                    <span class="co-meta-value">Revisa las preguntas de screening.</span>
                  </div>
                <?php endif; ?>
              </div>
              <div class="co-progress">
                <div class="co-progress-bar"><span style="width: <?=$vac['progress']; ?>%;"></span></div>
                <div class="co-stats-grid">
                  <div><span>Recibidos</span><strong><?=$vac['stats']['recibidas']; ?></strong></div>
                  <div><span>Preselección</span><strong><?=$vac['stats']['preseleccion']; ?></strong></div>
                  <div><span>Entrevistas</span><strong><?=$vac['stats']['entrevista']; ?></strong></div>
                  <div><span>Oferta</span><strong><?=$vac['stats']['oferta']; ?></strong></div>
                  <div><span>Contratados</span><strong><?=$vac['stats']['contratados']; ?></strong></div>
                </div>
              </div>
              <div class="co-actions">
                <a class="btn btn-secondary" href="index.php?view=candidatos&vacante_id=<?=$vac['id']; ?>">Ver candidatos</a>
                <a class="btn btn-outline" href="index.php?view=editar_oferta&id=<?=$vac['id']; ?>">Editar</a>
                <?php foreach ($vac['actions'] as $action): ?>
                  <form method="post" action="index.php?action=vacante_estado" class="co-inline-form">
                    <input type="hidden" name="csrf" value="<?=mo_e($csrfToken); ?>" />
                    <input type="hidden" name="vacante_id" value="<?=$vac['id']; ?>" />
                    <input type="hidden" name="estado" value="<?=mo_e($action['state']); ?>" />
                    <button type="submit" class="<?=mo_e($action['class']); ?>"><?=mo_e($action['label']); ?></button>
                  </form>
                <?php endforeach; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="card co-empty">
            <h3><?= $filtersApplied ? 'No hay vacantes con los filtros aplicados' : 'Aún no tienes vacantes publicadas'; ?></h3>
            <p class="muted">
              <?= $filtersApplied ? 'Ajusta los filtros o muestra todas tus ofertas.' : 'Crea tu primera vacante desde el botón “Crear vacante”.'; ?>
            </p>
          </div>
        <?php endif; ?>
      </div>

      <aside class="co-side">
        <div class="card co-side-card">
          <h3>Próximas entrevistas</h3>
          <?php if ($upcomingInterviews): ?>
            <ul class="co-interview-list">
              <?php foreach ($upcomingInterviews as $interview): ?>
                <li>
                  <div>
                    <strong><?=mo_e(($interview['nombres'] ?? '').' '.($interview['apellidos'] ?? '')); ?></strong>
                    <p class="muted"><?=mo_e($interview['titulo']); ?> · <?=mo_e(date('d M · H:i', strtotime((string)$interview['updated_at']))); ?></p>
                  </div>
                  <button type="button"
                          class="btn btn-outline btn-sm is-green co-reprogram-btn"
                          data-postulacion-id="<?= mo_e((string)($interview['id'] ?? '')); ?>"
                          data-current="<?= mo_e(date('Y-m-d\\TH:i', strtotime((string)$interview['updated_at']))); ?>"
                          data-nombre="<?= mo_e(((string)($interview['nombres'] ?? '')) . ' ' . ((string)($interview['apellidos'] ?? ''))); ?>"
                          data-titulo="<?= mo_e((string)($interview['titulo'] ?? '')); ?>"
                  >Cambiar fecha</button>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="muted">Aún no tienes entrevistas programadas.</p>
          <?php endif; ?>
        </div>
        <div class="card co-side-card">
          <h3>Mejora tus vacantes</h3>
          <ul class="co-tip-list">
            <li>Incluye rango salarial y beneficios.</li>
            <li>Agrega 3-5 habilidades como chips.</li>
            <li>Define preguntas de pre-screening.</li>
          </ul>
        </div>
      </aside>
      
      <!-- Mini-modal para reprogramar entrevista -->
      <div id="co-reprogram-modal" class="card" style="position:fixed; right:24px; bottom:24px; width:320px; max-width:90vw; display:none; z-index:1000; box-shadow:0 8px 24px rgba(0,0,0,.12);">
        <div class="co-card-head" style="display:flex; align-items:center; justify-content:space-between;">
          <h3 class="h5" style="margin:0;">Reprogramar entrevista</h3>
          <button type="button" id="co-reprogram-close" class="btn btn-outline" style="padding:.25rem .5rem;">×</button>
        </div>
        <form id="co-reprogram-form" method="post" action="index.php?action=reprogramar_entrevista" style="margin-top:.5rem;">
          <input type="hidden" name="csrf" value="<?= mo_e($csrfToken); ?>" />
          <input type="hidden" name="postulacion_id" id="co-reprogram-id" />
          <div class="co-filter-field">
            <span>Fecha y hora</span>
            <input type="datetime-local" name="nueva_fecha" id="co-reprogram-date" required />
          </div>
          <p class="muted" id="co-reprogram-meta" style="margin:.5rem 0 1rem 0;"></p>
          <div style="display:flex; gap:.5rem; justify-content:flex-end;">
            <button type="button" id="co-reprogram-cancel" class="btn btn-outline">Cancelar</button>
            <button type="submit" class="btn btn-primary">Guardar</button>
          </div>
        </form>
      </div>
      
      <script>
        (function(){
          const modal = document.getElementById('co-reprogram-modal');
          const closeBtn = document.getElementById('co-reprogram-close');
          const cancelBtn = document.getElementById('co-reprogram-cancel');
          const form = document.getElementById('co-reprogram-form');
          const idInput = document.getElementById('co-reprogram-id');
          const dateInput = document.getElementById('co-reprogram-date');
          const meta = document.getElementById('co-reprogram-meta');
          function openModal(options){
            idInput.value = options.id || '';
            dateInput.value = options.date || '';
            meta.textContent = (options.nombre && options.titulo) ? (options.nombre + ' · ' + options.titulo) : '';
            modal.style.display = 'block';
          }
          function closeModal(){ modal.style.display = 'none'; }
          document.querySelectorAll('.co-reprogram-btn').forEach(btn => {
            btn.addEventListener('click', function(){
              openModal({
                id: this.dataset.postulacionId || '',
                date: this.dataset.current || '',
                nombre: this.dataset.nombre || '',
                titulo: this.dataset.titulo || ''
              });
            });
          });
          closeBtn.addEventListener('click', closeModal);
          cancelBtn.addEventListener('click', closeModal);
        })();
      </script>
    </div>
  </div>
</section>
