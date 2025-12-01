<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$userSession = $_SESSION['user'] ?? null;
if (!$userSession || ($userSession['type'] ?? '') !== 'persona') {
  header('Location: index.php?view=login');
  exit;
}

require __DIR__.'/db.php';
// Cliente OpenAI para calcular match si no hay score guardado
$openaiClientFile = dirname(__DIR__).'/lib/OpenAIClient.php';
if (is_file($openaiClientFile)) {
  require_once $openaiClientFile;
}

if (!function_exists('mp_e')) {
  function mp_e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
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

if (!function_exists('mp_ai_norm')) {
  function mp_ai_norm(array $vector): float {
    $sum = 0.0;
    foreach ($vector as $v) { $sum += $v * $v; }
    return sqrt($sum);
  }
}

if (!function_exists('mp_ai_cosine')) {
  function mp_ai_cosine(array $a, float $normA, array $b, float $normB): float {
    if ($normA <= 0.0 || $normB <= 0.0) { return 0.0; }
    $len = min(count($a), count($b));
    $dot = 0.0;
    for ($i = 0; $i < $len; $i++) { $dot += $a[$i] * $b[$i]; }
    return $dot / ($normA * $normB);
  }
}

if (!function_exists('mp_ai_required_years')) {
  function mp_ai_required_years(string $text): ?int {
    if (preg_match('/(\\d+)\\s*(?:anos|anios)/i', $text, $m)) {
      return (int)$m[1];
    }
    return null;
  }
}

if (!function_exists('mp_ai_skill_overlap')) {
  function mp_ai_skill_overlap(array $candSkills, array $vacTags): int {
    $cand = array_filter(array_map(static fn($v) => mb_strtolower(trim((string)$v), 'UTF-8'), $candSkills));
    $vac  = array_filter(array_map(static fn($v) => mb_strtolower(trim((string)$v), 'UTF-8'), $vacTags));
    $cand = array_values(array_unique($cand));
    $vac  = array_values(array_unique($vac));
    if (!$cand || !$vac) { return 20; }
    $common = array_intersect($cand, $vac);
    $max = max(count($cand), count($vac));
    if ($max === 0) { return 20; }
    return (int)round(count($common) / $max * 100);
  }
}

if (!function_exists('mp_ai_exp_score')) {
  function mp_ai_exp_score(?int $required, ?int $candYears): int {
    if ($required === null || $required <= 0 || $candYears === null) { return 20; }
    if ($candYears >= $required) { return 100; }
    return (int)round(max(0, ($candYears / $required) * 100));
  }
}

if (!function_exists('mp_ai_weights')) {
  function mp_ai_weights(): array {
    return ['embed' => 0.6, 'skills' => 0.3, 'exp' => 0.1];
  }
}

if (!function_exists('mp_ai_clean_skills')) {
  /**
   * @param array<int,string>|string|null $skills
   * @return string[]
   */
  function mp_ai_clean_skills($skills): array {
    if (is_string($skills)) {
      $skills = preg_split('/[,;|]+/', $skills) ?: [];
    }
    if (!is_array($skills)) { return []; }
    $out = [];
    foreach ($skills as $skill) {
      $s = trim((string)$skill);
      if ($s === '') { continue; }
      $s = preg_replace('/\\s*[·•]\\s*\\d+.*/u', '', $s);
      $s = preg_replace('/\\d+\\s*(anos|anios|años)?/iu', '', $s);
      $s = preg_replace('/\\s+anos?|\\s+anios?/iu', '', $s);
      $s = trim(preg_replace('/\\s+/', ' ', (string)$s));
      if ($s === '') { continue; }
      $out[] = $s;
    }
    return array_values(array_unique($out));
  }
}

if (!function_exists('mp_ai_profile_text')) {
  /**
   * @param array<string,mixed> $profile
   */
  function mp_ai_profile_text(array $profile): string {
    $parts = array_filter([
      'Rol deseado: '.($profile['rol'] ?? ''),
      'Nivel: '.($profile['nivel'] ?? ''),
      'Ciudad: '.($profile['ciudad'] ?? ''),
      'Modalidad: '.($profile['modalidad'] ?? ''),
      'Disponibilidad: '.($profile['disponibilidad'] ?? ''),
      'Resumen: '.($profile['resumen'] ?? ''),
      $profile['habilidades'] ? 'Habilidades: '.implode(', ', (array)$profile['habilidades']) : null,
    ]);
    return implode("\n", $parts);
  }
}

$chips = [];
$postulaciones = [];
$kpis = ['activas' => 0, 'entrevistas' => 0, 'ofertas' => 0, 'no_seleccion' => 0];
$upcomingEvents = [];
$email = $userSession['email'] ?? null;

$aiVec = null;
$aiNorm = 0.0;
$aiError = null;
$candSkillsGlobal = [];
$candSkillsGlobalClean = [];

if ($pdo instanceof PDO && $email) {
  // Carga perfil del candidato para embedding (opcional)
  try {
    $profStmt = $pdo->prepare(
      'SELECT c.ciudad,
              cd.perfil AS resumen, cd.areas_interes,
              cp.rol_deseado, cp.habilidades,
              a.nombre AS area_nombre,
              n.nombre AS nivel_nombre,
              m.nombre AS modalidad_nombre,
              d.nombre AS disponibilidad_nombre
       FROM candidatos c
       LEFT JOIN candidato_detalles cd ON cd.email = c.email
       LEFT JOIN candidato_perfil cp ON cp.email = c.email
       LEFT JOIN areas a ON a.id = cp.area_id
       LEFT JOIN niveles n ON n.id = cp.nivel_id
       LEFT JOIN modalidades m ON m.id = cp.modalidad_id
       LEFT JOIN disponibilidades d ON d.id = cp.disponibilidad_id
       WHERE c.email = ?
       LIMIT 1'
    );
    $profStmt->execute([$email]);
    if ($prof = $profStmt->fetch(PDO::FETCH_ASSOC)) {
      $profileText = mp_ai_profile_text([
        'rol' => $prof['rol_deseado'] ?? '',
        'nivel' => $prof['nivel_nombre'] ?? '',
        'ciudad' => $prof['ciudad'] ?? '',
        'modalidad' => $prof['modalidad_nombre'] ?? '',
        'disponibilidad' => $prof['disponibilidad_nombre'] ?? '',
        'resumen' => $prof['resumen'] ?? '',
        'habilidades' => $prof['habilidades'] ? array_filter(array_map('trim', explode(',', (string)$prof['habilidades']))) : [],
      ]);
      $candSkillsGlobal = $prof['habilidades']
        ? array_values(array_filter(array_map('trim', explode(',', (string)$prof['habilidades']))))
        : [];
      $candSkillsGlobalClean = $candSkillsGlobal
        ? array_values(array_filter(array_map(static function ($v) {
            $s = trim((string)$v);
            $s = preg_replace('/\\s*[·•]\\s*\\d+.*/u', '', $s);
            $s = preg_replace('/\\d+\\s*(anos|anios|años)?/iu', '', $s);
            $s = preg_replace('/\\s+anos?|\\s+anios?/iu', '', $s);
            return trim(preg_replace('/\\s+/', ' ', $s));
          }, $candSkillsGlobal)))
        : [];
      $apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
      $base = getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1';
      $model = getenv('OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small';
      if (class_exists('OpenAIClient') && trim((string)$apiKey) !== '') {
        $client = new OpenAIClient($apiKey, $base, $model);
        $aiVec = $client->embed($profileText);
        $aiNorm = mp_ai_norm($aiVec);
      }
    }
  } catch (Throwable $e) {
    $aiError = $e->getMessage();
  }

  try {
    $prefStmt = $pdo->prepare(
      'SELECT cp.rol_deseado, c.ciudad, m.nombre AS modalidad_nombre
       FROM candidatos c
       LEFT JOIN candidato_perfil cp ON cp.email = c.email
       LEFT JOIN modalidades m ON m.id = cp.modalidad_id
       WHERE c.email = ? LIMIT 1'
    );
    $prefStmt->execute([$email]);
    if ($pref = $prefStmt->fetch(PDO::FETCH_ASSOC)) {
      if (!empty($pref['rol_deseado'])) { $chips[] = 'Rol: '.$pref['rol_deseado']; }
      if (!empty($pref['ciudad'])) { $chips[] = 'Ciudad: '.$pref['ciudad']; }
      if (!empty($pref['modalidad_nombre'])) { $chips[] = 'Modalidad: '.$pref['modalidad_nombre']; }
    }
  } catch (Throwable $prefError) {
    error_log('[mis_postulaciones] preferencias: '.$prefError->getMessage());
  }

  try {
    $stmt = $pdo->prepare(
      'SELECT p.id, p.estado, p.match_score, p.aplicada_at,
              v.id AS vacante_id, v.titulo, v.descripcion, v.etiquetas,
              v.salario_min, v.salario_max, v.moneda,
              e.razon_social AS empresa_nombre, v.ciudad
       FROM postulaciones p
       INNER JOIN vacantes v ON v.id = p.vacante_id
       LEFT JOIN empresas e ON e.id = v.empresa_id
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
      // Recalcula con la misma fórmula que el resto de vistas para mantener un único valor de match
      $matchPercent = null;
      if ($aiVec && $aiNorm > 0.0) {
        try {
          $embStmt = $pdo->prepare('SELECT embedding, norm FROM vacante_embeddings WHERE vacante_id = ? LIMIT 1');
          $embStmt->execute([(int)$row['vacante_id']]);
          if ($emb = $embStmt->fetch(PDO::FETCH_ASSOC)) {
              $vacEmbedding = json_decode((string)($emb['embedding'] ?? ''), true);
              if (is_array($vacEmbedding)) {
                $vacNorm = isset($emb['norm']) ? (float)$emb['norm'] : mp_ai_norm($vacEmbedding);
              // Usa los mismos pesos que la vista de empresa para ser uniforme
              $vacText = ($row['descripcion'] ?? '').' '.($row['requisitos'] ?? '');
              $weights = mp_ai_weights();
              $cos = mp_ai_cosine($aiVec, $aiNorm, $vacEmbedding, $vacNorm);
              $embedScore = max(0, min(1, $cos)) * 100;
                $vacTags = mp_ai_clean_skills($row['etiquetas'] ?? '');
                $skillsScore = mp_ai_skill_overlap($candSkillsGlobalClean ?: $candSkillsGlobal, $vacTags);
              $reqYears = mp_ai_required_years($vacText);
              $expScore = mp_ai_exp_score($reqYears, null); // sin info de años exactos aquí
              $matchPercent = round(
                $weights['embed'] * $embedScore +
                $weights['skills'] * $skillsScore +
                $weights['exp'] * $expScore,
                1
              );
            }
          }
        } catch (Throwable $e) {
          // ignora y deja match en null si falla
        }
      }
      if ($matchPercent === null && isset($row['match_score'])) {
        $matchPercent = (float)$row['match_score'];
      }

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
        <button type="submit" class="btn btn-primary">Aplicar filtros</button>
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
</main>
