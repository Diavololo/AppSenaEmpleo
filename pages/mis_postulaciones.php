<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }

$userSession = $_SESSION['user'] ?? null;
if (!$userSession || ($userSession['type'] ?? '') !== 'persona') {
  header('Location: index.php?view=login');
  exit;
}

require __DIR__.'/db.php';
require_once dirname(__DIR__).'/lib/EncodingHelper.php';
require_once dirname(__DIR__).'/lib/MatchService.php';
require_once dirname(__DIR__).'/lib/match_helpers.php';
require_once dirname(__DIR__).'/lib/DocumentAnalyzer.php';

if (!function_exists('mp_e')) {
  function mp_e(?string $value): string {
    return htmlspecialchars(fix_mojibake($value ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('mp_truncate')) {
  function mp_truncate(?string $text, int $limit = 220): string {
    $text = trim((string)$text);
    if ($text === '') { return 'La empresa aún no ha agregado más detalles.'; }
    if (mb_strlen($text, 'UTF-8') <= $limit) { return $text; }
    $slice = mb_substr($text, 0, $limit - 1, 'UTF-8');
    return rtrim($slice).'…';
  }
}

if (!function_exists('mp_human_diff')) {
  function mp_human_diff(?string $date): string {
    if (!$date) { return 'Fecha no disponible'; }
    try {
      $target = new DateTime($date);
    } catch (Throwable $e) {
      return 'Fecha reciente';
    }
    $now = new DateTime('now');
    $diff = $now->diff($target);
    if ($diff->invert === 0) {
      return 'Próximamente';
    }
    $days = (int)($diff->days ?? 0);
    $hours = (int)$diff->h;
    if ($days === 0) {
      if ($hours <= 1) { return 'Aplicaste hace menos de 1 hora'; }
      return 'Aplicaste hace '.$hours.' horas';
    }
    if ($days === 1) { return 'Aplicaste hace 1 día'; }
    return 'Aplicaste hace '.$days.' días';
  }
}

if (!function_exists('mp_extract_tags')) {
  function mp_extract_tags(?string $value): array {
    if ($value === null) { return []; }
    $parts = preg_split('/[,;|]+/', (string)$value);
    if (!is_array($parts)) { return []; }
    $parts = array_map(static fn($tag) => trim((string)$tag), $parts);
    $parts = array_filter($parts, static fn($tag) => $tag !== '');
    return array_values(array_unique($parts));
  }
}

if (!function_exists('mp_format_salary')) {
  function mp_format_salary(?int $min, ?int $max, string $currency): string {
    $currency = strtoupper(trim($currency ?: 'COP'));
    $format = static function (?int $value) use ($currency): string {
      return ($value === null || $value <= 0) ? '' : $currency.' '.number_format($value, 0, ',', '.');
    };
    $minLabel = $format($min);
    $maxLabel = $format($max);
    if ($minLabel && $maxLabel) { return $minLabel.' - '.$maxLabel; }
    if ($minLabel) { return 'Desde '.$minLabel; }
    if ($maxLabel) { return 'Hasta '.$maxLabel; }
    return 'Salario a convenir';
  }
}

if (!function_exists('mp_state_meta')) {
  function mp_state_meta(string $estado): array {
    $map = [
      'recibida' => ['label' => 'Enviada', 'pill' => 'state-pill is-muted'],
      'leida' => ['label' => 'Leída', 'pill' => 'state-pill is-muted'],
      'preseleccion' => ['label' => 'En revisión', 'pill' => 'state-pill is-progress'],
      'entrevista' => ['label' => 'Entrevista programada', 'pill' => 'state-pill is-info'],
      'oferta' => ['label' => 'Oferta recibida', 'pill' => 'state-pill is-success'],
      'contratado' => ['label' => 'Contratado', 'pill' => 'state-pill is-success'],
      'no_seleccionado' => ['label' => 'No seleccionado', 'pill' => 'state-pill is-danger'],
    ];
    $estado = strtolower($estado);
    return $map[$estado] ?? ['label' => ucfirst($estado), 'pill' => 'state-pill'];
  }
}

if (!function_exists('mp_stage_key')) {
  function mp_stage_key(string $estado): string {
    return match (strtolower($estado)) {
      'recibida' => 'enviada',
      'leida' => 'leida',
      'preseleccion' => 'revision',
      'entrevista' => 'entrevista',
      'oferta', 'contratado', 'no_seleccionado' => 'decision',
      default => 'enviada',
    };
  }
}

if (!function_exists('mp_build_steps')) {
  function mp_build_steps(string $estado): array {
    $flow = [
      ['key' => 'enviada', 'label' => 'Enviada'],
      ['key' => 'leida', 'label' => 'Leída'],
      ['key' => 'revision', 'label' => 'En revisión'],
      ['key' => 'entrevista', 'label' => 'Entrevista'],
      ['key' => 'decision', 'label' => 'Decisión'],
    ];
    $current = mp_stage_key($estado);
    $currentIndex = 0;
    foreach ($flow as $idx => $step) {
      if ($step['key'] === $current) {
        $currentIndex = $idx;
        break;
      }
    }
    foreach ($flow as $idx => &$step) {
      if ($idx < $currentIndex) {
        $step['status'] = 'done';
      } elseif ($idx === $currentIndex) {
        $step['status'] = 'current';
      } else {
        $step['status'] = 'pending';
      }
    }
    return $flow;
  }
}
$chips = [];
$postulaciones = [];
$kpis = ['activas' => 0, 'entrevistas' => 0, 'ofertas' => 0, 'no_seleccion' => 0];
$upcomingEvents = [];
$email = $userSession['email'] ?? null;
$docAnalyzer = null;
try {
  $docAnalyzer = new DocumentAnalyzer(MatchService::getClient());
} catch (Throwable $e) {
  $docAnalyzer = new DocumentAnalyzer(null);
}
$docFactor = 1.0;
if ($pdo instanceof PDO && $docAnalyzer && $email) {
  try {
    $evidence = $docAnalyzer->candidateEvidence($pdo, $email, ['fullName' => $userSession['nombre'] ?? '']);
    $docFactor = isset($evidence['score']) ? (float)$evidence['score'] : 1.0;
  } catch (Throwable $e) {
    error_log('[mis_postulaciones] doc evidence: '.$e->getMessage());
  }
}
$candidateProfile = [];
$matchClient = MatchService::getClient();

if ($pdo instanceof PDO && $email) {
  // Perfil + chips
  $candidateProfile = MatchService::candidateProfile($pdo, $email, true);
  if (!empty($candidateProfile['role'])) { $chips[] = 'Rol: '.$candidateProfile['role']; }
  if (!empty($candidateProfile['city'])) { $chips[] = 'Ciudad: '.$candidateProfile['city']; }
  if (!empty($candidateProfile['modalidad'])) { $chips[] = 'Modalidad: '.$candidateProfile['modalidad']; }

  try {
    $stmt = $pdo->prepare(
      'SELECT p.id, p.estado, p.match_score, p.aplicada_at,
              v.id AS vacante_id, v.titulo, v.descripcion, v.requisitos, v.etiquetas,
              v.salario_min, v.salario_max, v.moneda,
              e.razon_social AS empresa_nombre, v.ciudad,
              a.nombre AS area_nombre, n.nombre AS nivel_nombre, m.nombre AS modalidad_nombre
       FROM postulaciones p
       INNER JOIN vacantes v ON v.id = p.vacante_id
       LEFT JOIN empresas e ON e.id = v.empresa_id
       LEFT JOIN areas a ON a.id = v.area_id
       LEFT JOIN niveles n ON n.id = v.nivel_id
       LEFT JOIN modalidades m ON m.id = v.modalidad_id
       WHERE p.candidato_email = ?
       ORDER BY p.aplicada_at DESC'
    );
    $stmt->execute([$email]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $estado = strtolower((string)($row['estado'] ?? 'recibida'));
      if (in_array($estado, ['recibida','preseleccion','entrevista'], true)) { $kpis['activas']++; }
      if ($estado === 'entrevista') { $kpis['entrevistas']++; }
      if (in_array($estado, ['oferta','contratado'], true)) { $kpis['ofertas']++; }
      if ($estado === 'no_seleccionado') { $kpis['no_seleccion']++; }

      $tags = mp_extract_tags($row['etiquetas'] ?? '');
      $stateMeta = mp_state_meta($estado);

      $vacData = [
        'id' => (int)$row['vacante_id'],
        'titulo' => $row['titulo'] ?? '',
        'descripcion' => $row['descripcion'] ?? '',
        'requisitos' => $row['requisitos'] ?? '',
        'etiquetas' => $row['etiquetas'] ?? '',
        'area_nombre' => $row['area_nombre'] ?? '',
        'nivel_nombre' => $row['nivel_nombre'] ?? '',
        'modalidad_nombre' => $row['modalidad_nombre'] ?? '',
        'ciudad' => $row['ciudad'] ?? '',
      ];
      $matchPercent = ms_score($pdo, $vacData, $email);
      $matchPercent = max(0.0, min(100.0, $matchPercent * $docFactor));

      $postulaciones[] = [
        'id' => (int)$row['id'],
        'vacante_id' => (int)$row['vacante_id'],
        'titulo' => $row['titulo'] ?? 'Oferta sin título',
        'empresa' => $row['empresa_nombre'] ?? 'Empresa confidencial',
        'ciudad' => $row['ciudad'] ?? null,
        'estado' => $estado,
        'state_meta' => $stateMeta,
        'estado_label' => $stateMeta['label'],
        'aplicada_hace' => mp_human_diff($row['aplicada_at'] ?? null),
        'aplicada_at_raw' => $row['aplicada_at'] ?? null,
        'descripcion' => mp_truncate($row['descripcion'] ?? ''),
        'etiquetas' => $tags,
        'steps' => mp_build_steps($estado),
        'match_percent' => $matchPercent,
        'salario' => mp_format_salary(
          isset($row['salario_min']) ? (int)$row['salario_min'] : null,
          isset($row['salario_max']) ? (int)$row['salario_max'] : null,
          (string)($row['moneda'] ?? 'COP')
        ),
        'can_delete' => ($estado === 'no_seleccionado'),
      ];
    }
  } catch (Throwable $error) {
    error_log('[mis_postulaciones] listado: '.$error->getMessage());
  }

  // Próximos eventos (entrevistas programadas para el candidato)
  try {
    $evStmt = $pdo->prepare(
      'SELECT p.updated_at, e.razon_social AS empresa_nombre, v.titulo, v.ciudad
       FROM postulaciones p
       INNER JOIN vacantes v ON v.id = p.vacante_id
       LEFT JOIN empresas e ON e.id = v.empresa_id
       WHERE p.candidato_email = ? AND p.estado = "entrevista"
       ORDER BY p.updated_at ASC
       LIMIT 4'
    );
    $evStmt->execute([$email]);
    $upcomingEvents = $evStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $evError) {
    error_log('[mis_postulaciones] eventos: '.$evError->getMessage());
  }
}

$stateFilters = [
  'recibida' => 'Activa',
  'preseleccion' => 'En revisión',
  'entrevista' => 'Entrevista',
  'oferta' => 'Oferta',
  'no_seleccionado' => 'No seleccionado',
];
$filterSearch = trim((string)($_GET['buscar'] ?? ''));
$filterStates = $_GET['estado'] ?? [];
if (!is_array($filterStates)) { $filterStates = [$filterStates]; }
$filterStates = array_values(array_intersect(array_keys($stateFilters), array_map('strtolower', $filterStates)));
$filterSort = strtolower((string)($_GET['orden'] ?? 'recientes'));
if (!in_array($filterSort, ['recientes','antiguas'], true)) { $filterSort = 'recientes'; }
$filtersApplied = ($filterSearch !== '' || $filterStates || $filterSort !== 'recientes');
$searchNeedle = $filterSearch !== '' ? mb_strtolower($filterSearch, 'UTF-8') : '';

$visiblePostulaciones = array_values(array_filter(
  $postulaciones,
  static function (array $item) use ($filterStates, $searchNeedle): bool {
    if ($filterStates && !in_array($item['estado'], $filterStates, true)) {
      return false;
    }
    if ($searchNeedle !== '') {
      $haystack = mb_strtolower($item['titulo'].' '.$item['empresa'].' '.implode(' ', $item['etiquetas']), 'UTF-8');
      if (mb_strpos($haystack, $searchNeedle, 0, 'UTF-8') === false) {
        return false;
      }
    }
    return true;
  }
));

usort($visiblePostulaciones, static function ($a, $b) use ($filterSort) {
  return $filterSort === 'antiguas'
    ? strcmp((string)$a['aplicada_at_raw'], (string)$b['aplicada_at_raw'])
    : strcmp((string)$b['aplicada_at_raw'], (string)$a['aplicada_at_raw']);
});

$baseHref = 'index.php';
?>

<main id="contenido-principal" tabindex="-1">
<section class="container section mp-summary">
  <div class="mp-summary-head">
    <div>
      <p class="muted">Seguimiento de todos tus procesos con empresas aliadas.</p>
      <h1>Mis postulaciones</h1>
    </div>
    <div class="mp-pref">
      <div class="pref-chips">
        <?php if ($chips): ?>
          <?php foreach ($chips as $chip): ?>
            <span class="chip"><?=mp_e($chip); ?></span>
          <?php endforeach; ?>
        <?php else: ?>
          <span class="chip">Actualiza tus preferencias para mejores coincidencias</span>
        <?php endif; ?>
      </div>
      <a class="link-edit" href="?view=editar_perfil">Editar preferencias</a>
    </div>
  </div>
  <div class="mp-kpis">
    <div class="mp-kpi card"><span class="mp-kpi-label">Activas</span><span class="mp-kpi-value"><?=mp_e((string)$kpis['activas']); ?></span></div>
    <div class="mp-kpi card"><span class="mp-kpi-label">Entrevistas</span><span class="mp-kpi-value"><?=mp_e((string)$kpis['entrevistas']); ?></span></div>
    <div class="mp-kpi card"><span class="mp-kpi-label">Ofertas</span><span class="mp-kpi-value"><?=mp_e((string)$kpis['ofertas']); ?></span></div>
    <div class="mp-kpi card"><span class="mp-kpi-label">No seleccionadas</span><span class="mp-kpi-value"><?=mp_e((string)$kpis['no_seleccion']); ?></span></div>
  </div>
</section>

<section class="container section mp-layout">
  <aside class="mp-filters card" aria-label="Filtros de mis postulaciones">
    <form class="mp-filter-form" action="" method="get">
      <input type="hidden" name="view" value="mis_postulaciones" />
      <div class="mp-filter-search">
        <label for="mp-filter-search-input">Buscar</label>
        <input id="mp-filter-search-input" type="search" name="buscar" placeholder="Cargo, empresa..." value="<?=mp_e($filterSearch); ?>" />
      </div>
      <div class="mp-filter-group">
        <span class="mp-filter-title">Estado</span>
        <?php foreach ($stateFilters as $key => $label): ?>
          <label class="mp-filter-check">
            <input type="checkbox" name="estado[]" value="<?=mp_e($key); ?>" <?=in_array($key, $filterStates, true) ? 'checked' : ''; ?> />
            <span><?=mp_e($label); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="mp-filter-group">
        <label class="mp-filter-title" for="mp-filter-order">Ordenar</label>
        <select id="mp-filter-order" name="orden">
          <option value="recientes" <?=$filterSort === 'recientes' ? 'selected' : ''; ?>>Más recientes</option>
          <option value="antiguas" <?=$filterSort === 'antiguas' ? 'selected' : ''; ?>>Más antiguas</option>
        </select>
      </div>
      <div class="mp-filter-actions" style="display:flex; gap:.6rem;">
        <button type="submit" class="btn btn-primary btn-important">Aplicar filtros</button>
        <?php if ($filtersApplied): ?>
          <a class="link-edit" href="?view=mis_postulaciones">Limpiar</a>
        <?php endif; ?>
      </div>
      <p class="mp-filter-tip">Consejo: mantén tu CV actualizado y agrega habilidades para mejorar tu match.</p>
    </form>
  </aside>

  <div class="mp-main">
    <div class="mp-main-head">
      <div>
        <h2>En curso</h2>
        <p class="muted">Procesos donde aún puedes intervenir.</p>
      </div>
    </div>

    <?php if ($visiblePostulaciones): ?>
      <?php foreach ($visiblePostulaciones as $post): ?>
        <?php
          $detalleUrl = $baseHref.'?view=oferta_detalle&id='.$post['vacante_id'];
          $comprobanteUrl = $baseHref.'?action=comprobante_postulacion'
            .'&titulo='.rawurlencode($post['titulo'])
            .'&empresa='.rawurlencode($post['empresa'])
            .'&ciudad='.rawurlencode($post['ciudad'] ?? 'Remoto');
          $matchPercent = $post['match_percent'] ?? 0;
          $matchLabel = $post['match_percent'] !== null ? 'Match '.number_format((float)$post['match_percent'], 1).'%' : 'Match no disponible';
        ?>
        <article class="mp-post card">
          <div class="mp-post-head">
            <div>
              <div class="mp-post-title">
                <h3><?=mp_e($post['titulo']); ?></h3>
                <span class="<?=mp_e($post['state_meta']['pill']); ?>"><?=mp_e($post['state_meta']['label']); ?></span>
              </div>
              <p class="mp-post-meta">
                <strong><?=mp_e($post['empresa']); ?></strong>
                <?php if (!empty($post['ciudad'])): ?> · <?=mp_e($post['ciudad']); ?><?php endif; ?> · <?=mp_e($post['aplicada_hace']); ?>
              </p>
            </div>
            <div class="mp-match">
              <span class="mp-match-label"><?=mp_e($matchLabel); ?></span>
              <div class="mp-match-bar"><span style="width: <?=$matchPercent; ?>%;"></span></div>
            </div>
          </div>

          <?php if (!empty($post['etiquetas'])): ?>
            <div class="mp-tags">
              <?php foreach ($post['etiquetas'] as $tag): ?>
                <span class="chip chip-small"><?=mp_e($tag); ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <p class="mp-post-desc"><?=mp_e($post['descripcion']); ?></p>

          <div class="mp-meta-grid">
            <div>
              <span class="mp-meta-label">Salario</span>
              <span class="mp-meta-value"><?=mp_e($post['salario']); ?></span>
            </div>
            <div>
              <span class="mp-meta-label">Estado</span>
              <span class="mp-meta-value"><?=mp_e($post['estado_label']); ?></span>
            </div>
            <div>
              <span class="mp-meta-label">Aplicaste</span>
              <span class="mp-meta-value"><?=mp_e($post['aplicada_hace']); ?></span>
            </div>
          </div>

          <div class="mp-stepper">
            <?php foreach ($post['steps'] as $step): ?>
              <div class="mp-step <?=mp_e($step['status']); ?>">
                <span class="mp-step-dot"></span>
                <span class="mp-step-label"><?=mp_e($step['label']); ?></span>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="mp-actions">
            <a class="btn btn-primary" href="<?=$detalleUrl; ?>">Ver detalle</a>
            <a class="btn btn-outline" href="<?=$comprobanteUrl; ?>" target="_blank" rel="noopener">Comprobante</a>
            <a class="btn btn-secondary" href="?view=editar_perfil">Actualizar CV</a>
            <?php if (!empty($post['can_delete'])): ?>
              <form method="POST" action="actions/delete_postulacion.php" style="display:inline;">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="postulacion_id" value="<?=$post['id']; ?>">
                <button type="submit" class="btn btn-danger" style="margin-top:8px;">Eliminar</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="card">
        <h3><?= $filtersApplied ? 'No encontramos postulaciones con esos filtros' : 'Aún no tienes postulaciones'; ?></h3>
        <p class="muted">
          <?= $filtersApplied
            ? 'Ajusta los filtros o visualiza todas tus postulaciones.'
            : 'Explora ofertas en el dashboard y postúlate para empezar a hacer seguimiento aquí.'; ?>
        </p>
      </div>
    <?php endif; ?>
  </div>

  <aside class="mp-side">
    <div class="card mp-side-card">
      <h3>Próximos eventos</h3>
      <?php if ($upcomingEvents): ?>
        <ul class="mp-events">
          <?php foreach ($upcomingEvents as $ev): ?>
            <li>
              <span class="status-dot is-online"></span>
              <div>
                <strong>Entrevista · <?= mp_e($ev['empresa_nombre'] ?? 'Empresa'); ?></strong>
                <p class="muted">
                  <?= mp_e(date('d M · h:i A', strtotime((string)($ev['updated_at'] ?? 'now')))); ?>
                  <?php if (!empty($ev['ciudad'])): ?> · <?= mp_e($ev['ciudad']); ?><?php endif; ?>
                </p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">Aún no tienes entrevistas programadas.</p>
      <?php endif; ?>
    </div>

    <div class="card mp-side-card">
      <h3>Resumen del perfil</h3>
      <div class="mp-progress">
        <div class="mp-progress-bar"><span style="width:80%"></span></div>
        <p class="muted">Perfil 80% completo</p>
      </div>
      <div class="mp-side-tags">
        <?php if ($chips): ?>
          <?php foreach (array_slice($chips, 0, 4) as $chip): ?>
            <span class="chip chip-small"><?=mp_e($chip); ?></span>
          <?php endforeach; ?>
        <?php else: ?>
          <span class="chip chip-small">Ciudad: Bogotá</span>
        <?php endif; ?>
      </div>
      <a class="btn btn-primary" href="?view=editar_perfil">Actualizar CV</a>
    </div>
  </aside>
</section>

<style>
  /* Filtros con look más dinámico y verde */
  .mp-filters.card{
    background:#ffffff;
    border-radius:16px;
    border:1px solid #e6e8ed;
    box-shadow:0 6px 18px rgba(0,0,0,0.04);
    padding:18px;
  }
  .mp-filter-form label,
  .mp-filter-title{ font-weight:700; color:#1f2937; }
  .mp-filter-form input[type="search"],
  .mp-filter-form input[type="text"],
  .mp-filter-form select{
    border-radius:12px;
    border:1px solid #e6e8ed;
    background:#ffffff;
    padding-inline:14px;
  }
  .mp-filter-check{
    display:flex; align-items:center; gap:.55rem;
    padding:.35rem .55rem;
    border-radius:12px;
    transition:background .2s ease,border-color .2s ease;
    border:1px solid transparent;
  }
  .mp-filter-check:hover{ background:#f0f8ea; border-color:#cfe5c4; }
  .mp-filter-actions .btn.btn-primary{
    border-radius:12px;
    background:#ffffff;
    border:1px solid #2f8f36;
    color:#1f9b1f;
    text-shadow:-0.4px -0.4px 0 #c58c00, 0.4px 0.4px 0 #c58c00;
    box-shadow:0 4px 10px rgba(47,143,54,0.12);
  }
  .mp-filter-tip{ color:#445; }
</style>
</main>
