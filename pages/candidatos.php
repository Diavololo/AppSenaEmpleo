<?php
declare(strict_types=1);

// Acceso solo via index.php y empresas autenticadas
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=candidatos');
  exit;
}
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$sessionUser = $_SESSION['user'] ?? null;
if (!$sessionUser || ($sessionUser['type'] ?? '') !== 'empresa') {
  header('Location: index.php?view=login');
  exit;
}

// CSRF
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

require __DIR__.'/db.php';
// Cliente OpenAI para IA
$openaiClientFile = dirname(__DIR__).'/lib/OpenAIClient.php';
if (is_file($openaiClientFile)) {
  require_once $openaiClientFile;
}

if (!function_exists('cd_e')) {
  function cd_e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('cd_split_tags')) {
  /**
   * @return string[]
   */
  function cd_split_tags(?string $value): array {
    if ($value === null) { return []; }
    $parts = preg_split('/[,;|]+/', (string)$value);
    if (!is_array($parts)) { return []; }
    $parts = array_map(static fn($tag) => trim((string)$tag), $parts);
    $parts = array_filter($parts, static fn($tag) => $tag !== '');
    return array_values(array_unique($parts));
  }
}
if (!function_exists('cd_human_time_diff')) {
  function cd_human_time_diff(?string $date): string {
    if (!$date) { return 'Publicada recientemente'; }
    try { $target = new DateTime($date); } catch (Throwable $e) { return 'Publicada recientemente'; }
    $now = new DateTime('now');
    $diff = $now->diff($target);
    if ($diff->invert === 0) {
      if ($diff->days === 0) { return 'PrÃ³ximamente'; }
      if ($diff->days === 1) { return 'En 1 dÃ­a'; }
      return 'En '.$diff->days.' dÃ­as';
    }
    if ($diff->days === 0) {
      $hours = (int)$diff->h;
      if ($hours <= 1) { return 'Publicada hoy'; }
      return 'Hace '.$hours.' horas';
    }
    if ($diff->days === 1) { return 'Hace 1 dÃ­a'; }
    if ($diff->days < 30) { return 'Hace '.$diff->days.' dÃ­as'; }
    $months = (int)floor($diff->days / 30);
    if ($months <= 1) { return 'Hace 1 mes'; }
    return 'Hace '.$months.' meses';
  }
}

if (!function_exists('cd_ai_norm')) {
  function cd_ai_norm(array $vector): float {
    $sum = 0.0;
    foreach ($vector as $v) { $sum += $v * $v; }
    return sqrt($sum);
  }
}

if (!function_exists('cd_ai_skill_overlap')) {
  function cd_ai_skill_overlap(array $candSkills, array $vacTags): int
  {
    $cand = array_filter(array_map(static fn($v) => mb_strtolower(trim((string)$v), 'UTF-8'), $candSkills));
    $vac  = array_filter(array_map(static fn($v) => mb_strtolower(trim((string)$v), 'UTF-8'), $vacTags));
    $cand = array_values(array_unique($cand));
    $vac  = array_values(array_unique($vac));
    if (!$cand || !$vac) { return 20; } // penaliza si no hay datos
    $common = array_intersect($cand, $vac);
    $max = max(count($cand), count($vac));
    if ($max === 0) { return 20; }
    return (int)round(count($common) / $max * 100);
  }
}

if (!function_exists('cd_ai_exp_score')) {
  function cd_ai_exp_score(?int $required, ?int $candYears): int
  {
    if ($required === null || $required <= 0 || $candYears === null) { return 20; }
    if ($candYears >= $required) { return 100; }
    return (int)round(max(0, ($candYears / $required) * 100));
  }
}

if (!function_exists('cd_ai_soft_score')) {
  function cd_ai_soft_score(string $candText, string $vacText): int
  {
    $keywords = ['comunicaciÃ³n','comunicacion','equipo','liderazgo','responsable','proactivo','cliente','adaptable','resoluciÃ³n','resolucion'];
    $candLow = mb_strtolower($candText, 'UTF-8');
    $vacLow  = mb_strtolower($vacText, 'UTF-8');
    $hits = 0;
    foreach ($keywords as $k) {
      if (strpos($candLow, $k) !== false || strpos($vacLow, $k) !== false) {
        $hits++;
      }
    }
    if ($hits === 0) { return 20; } // penaliza ausencia de soft skills
    if ($hits >= 4) { return 100; }
    return 60 + $hits * 10;
  }
}

if (!function_exists('cd_ai_norm')) {
  function cd_ai_norm(array $vector): float {
    $sum = 0.0;
    foreach ($vector as $v) { $sum += $v * $v; }
    return sqrt($sum);
  }
}

if (!function_exists('cd_ai_cosine')) {
  function cd_ai_cosine(array $a, float $normA, array $b, float $normB): float {
    if ($normA <= 0.0 || $normB <= 0.0) { return 0.0; }
    $len = min(count($a), count($b));
    $dot = 0.0;
    for ($i = 0; $i < $len; $i++) { $dot += $a[$i] * $b[$i]; }
    return $dot / ($normA * $normB);
  }
}

if (!function_exists('cd_ai_vacancy_text')) {
  /**
   * @param array<string,mixed> $vac
   */
  function cd_ai_vacancy_text(array $vac): string {
    $parts = array_filter([
      'TÃ­tulo: '.($vac['titulo'] ?? ''),
      'DescripciÃ³n: '.($vac['descripcion'] ?? ''),
      'Requisitos: '.($vac['requisitos'] ?? ''),
      'Ãrea: '.($vac['area'] ?? ''),
      'Nivel: '.($vac['nivel'] ?? ''),
      'Modalidad: '.($vac['modalidad'] ?? ''),
      'Ciudad: '.($vac['ciudad'] ?? ''),
      'Etiquetas: '.($vac['etiquetas'] ?? ''),
    ]);
    return implode("\n", $parts);
  }
}

if (!function_exists('cd_ai_candidate_text')) {
  /**
   * @param array<string,mixed> $cand
   */
  function cd_ai_candidate_text(array $cand): string {
    $parts = array_filter([
      'Nombre: '.(($cand['nombres'] ?? '').' '.($cand['apellidos'] ?? '')),
      'Rol deseado: '.($cand['rol_deseado'] ?? ''),
      'Nivel: '.($cand['nivel_nombre'] ?? ''),
      'Ciudad: '.($cand['ciudad'] ?? ''),
      'Modalidad: '.($cand['modalidad_nombre'] ?? ''),
      'Disponibilidad: '.($cand['disponibilidad_nombre'] ?? ''),
      'Experiencia: '.($cand['anios_experiencia'] ?? '').' aÃ±os',
      'Estudios: '.($cand['estudios_nombre'] ?? ''),
      'Resumen: '.($cand['resumen'] ?? ''),
      $cand['habilidades'] ? 'Habilidades: '.$cand['habilidades'] : null,
    ]);
    return implode("\n", $parts);
  }
}

if (!function_exists('cd_ai_required_years')) {
  function cd_ai_required_years(string $text): ?int {
    if (preg_match('/(\\d+)\\s*(?:anos|anios)/i', $text, $m)) {
      return (int)$m[1];
    }
    return null;
  }
}

if (!function_exists('cd_ai_weights')) {
  /**
   * Pesos fijos para un ?nico c?lculo en todas las vistas.
   * @return array{embed:float,skills:float,exp:float}
   */
  function cd_ai_weights(string $text): array {
    return ['embed' => 0.6, 'skills' => 0.3, 'exp' => 0.1];
  }
}

if (!function_exists('cd_ai_cosine')) {
  function cd_ai_cosine(array $a, float $normA, array $b, float $normB): float {
    if ($normA <= 0.0 || $normB <= 0.0) { return 0.0; }
    $len = min(count($a), count($b));
    $dot = 0.0;
    for ($i = 0; $i < $len; $i++) { $dot += $a[$i] * $b[$i]; }
    return $dot / ($normA * $normB);
  }
}

if (!function_exists('cd_ai_vacancy_text')) {
  /**
   * @param array<string,mixed> $vac
   */
  function cd_ai_vacancy_text(array $vac): string {
    $parts = array_filter([
      'TÃ­tulo: '.($vac['titulo'] ?? ''),
      'DescripciÃ³n: '.($vac['descripcion'] ?? ''),
      'Requisitos: '.($vac['requisitos'] ?? ''),
      'Ãrea: '.($vac['area'] ?? ''),
      'Nivel: '.($vac['nivel'] ?? ''),
      'Modalidad: '.($vac['modalidad'] ?? ''),
      'Ciudad: '.($vac['ciudad'] ?? ''),
      'Etiquetas: '.($vac['etiquetas'] ?? ''),
    ]);
    return implode("\n", $parts);
  }
}

if (!function_exists('cd_ai_candidate_text')) {
  /**
   * @param array<string,mixed> $cand
   */
  function cd_ai_candidate_text(array $cand): string {
    $parts = array_filter([
      'Nombre: '.(($cand['nombres'] ?? '').' '.($cand['apellidos'] ?? '')),
      'Rol deseado: '.($cand['rol_deseado'] ?? ''),
      'Nivel: '.($cand['nivel_nombre'] ?? ''),
      'Ciudad: '.($cand['ciudad'] ?? ''),
      'Modalidad: '.($cand['modalidad_nombre'] ?? ''),
      'Disponibilidad: '.($cand['disponibilidad_nombre'] ?? ''),
      'Experiencia: '.($cand['anios_experiencia'] ?? '').' aÃ±os',
      'Estudios: '.($cand['estudios_nombre'] ?? ''),
      'Resumen: '.($cand['resumen'] ?? ''),
      $cand['habilidades'] ? 'Habilidades: '.$cand['habilidades'] : null,
    ]);
    return implode("\n", $parts);
  }
}

$empresaId = isset($sessionUser['empresa_id']) ? (int)$sessionUser['empresa_id'] : null;
$vacId = isset($_GET['vacante_id']) ? (int)$_GET['vacante_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);

$vac = null;
$vacEmbedding = null;
$vacNorm = 0.0;
$aiClient = null;
$headerChips = [];
$kpis = [ 'total' => 0, 'preseleccion' => 0, 'entrevista' => 0, 'descartados' => 0 ];
$includeDescartados = (isset($_GET['include_descartados']) && $_GET['include_descartados'] === '1');
$error = null;

if ($pdo instanceof PDO && $empresaId && $vacId) {
  try {
    $stmt = $pdo->prepare(
      'SELECT v.id, v.titulo, v.descripcion, v.requisitos, v.ciudad, v.etiquetas, v.publicada_at,
              m.nombre AS modalidad, n.nombre AS nivel, a.nombre AS area,
              e.id AS empresa_id, e.razon_social AS empresa_nombre
         FROM vacantes v
         LEFT JOIN modalidades m ON m.id = v.modalidad_id
         LEFT JOIN niveles n ON n.id = v.nivel_id
         LEFT JOIN areas a ON a.id = v.area_id
         LEFT JOIN empresas e ON e.id = v.empresa_id
        WHERE v.id = ? AND v.empresa_id = ?
        LIMIT 1'
    );
    $stmt->execute([$vacId, $empresaId]);
    $vac = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($vac) {
      foreach (['area','nivel','modalidad'] as $key) {
        $val = trim((string)($vac[$key] ?? ''));
        if ($val !== '') { $headerChips[] = $val; }
      }
      $headerChips = array_merge($headerChips, cd_split_tags($vac['etiquetas'] ?? ''));
    } else {
      $error = 'No encontramos la vacante o no pertenece a tu empresa.';
    }
  } catch (Throwable $e) {
    $error = 'Error al cargar la vacante.';
    error_log('[candidatos] '.$e->getMessage());
  }

  if (!$error) {
    try {
      $kstmt = $pdo->prepare('SELECT estado, COUNT(*) AS cnt FROM postulaciones WHERE vacante_id = ? GROUP BY estado');
      $kstmt->execute([$vacId]);
      $rows = $kstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $row) {
        $cnt = (int)$row['cnt'];
        $estado = strtolower((string)$row['estado']);
        if ($estado === 'no_seleccionado') { $kpis['descartados'] += $cnt; }
        // Total dinÃ¡mico: incluye o excluye descartados segÃºn toggle.
        if ($includeDescartados || $estado !== 'no_seleccionado') { $kpis['total'] += $cnt; }
        if ($estado === 'preseleccion') { $kpis['preseleccion'] += $cnt; }
        if ($estado === 'entrevista')   { $kpis['entrevista']   += $cnt; }
      }
    } catch (Throwable $ke) {
      error_log('[candidatos] KPIs: '.$ke->getMessage());
    }
  }
} else {
  if (!$vacId) { $error = 'Vacante no especificada.'; }
  elseif (!$empresaId) { $error = 'No encontramos la empresa asociada a tu cuenta.'; }
}

$vacTitle   = $vac['titulo'] ?? 'Vacante sin tÃ­tulo';
$company    = $vac['empresa_nombre'] ?? ($sessionUser['empresa'] ?? ($sessionUser['display_name'] ?? 'Mi empresa'));
$companyId  = isset($vac['empresa_id']) ? (int)$vac['empresa_id'] : null;
$location   = !empty($vac['ciudad']) ? (string)$vac['ciudad'] : 'Remoto';
$published  = !empty($vac['publicada_at']) ? cd_human_time_diff($vac['publicada_at']) : 'Publicada recientemente';
$candidatos = [];
$aiClient = null;
$vacEmbedding = null;
$vacNorm = 0.0;
$aiError = null;

// Inicializa OpenAI y embedding de la vacante
try {
  $apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
  $base = getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1';
  $model = getenv('OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small';
  if (class_exists('OpenAIClient') && trim((string)$apiKey) !== '' && $vac && $pdo instanceof PDO) {
    $aiClient = new OpenAIClient($apiKey, $base, $model);
    $embStmt = $pdo->prepare('SELECT embedding, norm FROM vacante_embeddings WHERE vacante_id = ? LIMIT 1');
    $embStmt->execute([$vacId]);
    if ($embRow = $embStmt->fetch(PDO::FETCH_ASSOC)) {
      $vacEmbedding = json_decode((string)($embRow['embedding'] ?? ''), true);
      if (is_array($vacEmbedding)) {
        $vacNorm = isset($embRow['norm']) ? (float)$embRow['norm'] : cd_ai_norm($vacEmbedding);
      } else {
        $vacEmbedding = null;
      }
    }
    if (!$vacEmbedding) {
      $text = cd_ai_vacancy_text($vac);
      $vec = $aiClient->embed($text);
      $vacEmbedding = $vec;
      $vacNorm = cd_ai_norm($vec);
      try {
        $ins = $pdo->prepare('INSERT INTO vacante_embeddings (vacante_id, embedding, norm, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), norm = VALUES(norm), updated_at = VALUES(updated_at)');
        $ins->execute([$vacId, json_encode($vec), $vacNorm]);
      } catch (Throwable $e) {
        error_log('[candidatos] no se pudo guardar embedding de vacante: '.$e->getMessage());
      }
    }
  }
} catch (Throwable $e) {
  $aiError = $e->getMessage();
}
$aiClient = null;
$vacEmbedding = null;
$vacNorm = 0.0;
$aiError = null;

// Inicializa OpenAI y embedding de la vacante
try {
  $apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
  $base = getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1';
  $model = getenv('OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small';
  if (class_exists('OpenAIClient') && trim((string)$apiKey) !== '' && $vac && $pdo instanceof PDO) {
    $aiClient = new OpenAIClient($apiKey, $base, $model);
    $embStmt = $pdo->prepare('SELECT embedding, norm FROM vacante_embeddings WHERE vacante_id = ? LIMIT 1');
    $embStmt->execute([$vacId]);
    if ($embRow = $embStmt->fetch(PDO::FETCH_ASSOC)) {
      $vacEmbedding = json_decode((string)($embRow['embedding'] ?? ''), true);
      if (is_array($vacEmbedding)) {
        $vacNorm = isset($embRow['norm']) ? (float)$embRow['norm'] : cd_ai_norm($vacEmbedding);
      } else {
        $vacEmbedding = null;
      }
    }
    if (!$vacEmbedding) {
      $text = cd_ai_vacancy_text($vac);
      $vec = $aiClient->embed($text);
      $vacEmbedding = $vec;
      $vacNorm = cd_ai_norm($vec);
      try {
        $ins = $pdo->prepare('INSERT INTO vacante_embeddings (vacante_id, embedding, norm, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), norm = VALUES(norm), updated_at = VALUES(updated_at)');
        $ins->execute([$vacId, json_encode($vec), $vacNorm]);
      } catch (Throwable $e) {
        error_log('[candidatos] no se pudo guardar embedding de vacante: '.$e->getMessage());
      }
    }
  }
} catch (Throwable $e) {
  $aiError = $e->getMessage();
}

if ($pdo instanceof PDO && !$error && $vacId) {
  try {
    $whereCond = $includeDescartados
      ? 'WHERE p.vacante_id = ?'
      : 'WHERE p.vacante_id = ? AND (p.estado IS NULL OR LOWER(p.estado) <> "no_seleccionado")';
    $sql = 'SELECT
          p.estado,
          p.match_score,
          p.candidato_email AS email,
          p.aplicada_at,
          c.nombres,
          c.apellidos,
          c.ciudad,
          cp.rol_deseado,
          cp.anios_experiencia,
          cp.habilidades,
          cp.resumen,
          n.nombre  AS nivel_nombre,
          m.nombre  AS modalidad_nombre,
          ne.nombre AS estudios_nombre,
          d.nombre  AS disponibilidad_nombre
       FROM postulaciones p
       INNER JOIN candidatos c ON c.email = p.candidato_email
       LEFT JOIN candidato_perfil cp ON cp.email = p.candidato_email
       LEFT JOIN niveles n ON n.id = cp.nivel_id
       LEFT JOIN modalidades m ON m.id = cp.modalidad_id
       LEFT JOIN niveles_estudio ne ON ne.id = cp.estudios_id
       LEFT JOIN disponibilidades d ON d.id = cp.disponibilidad_id
       ' . $whereCond . '
       ORDER BY p.match_score DESC, p.aplicada_at DESC';
    $cStmt = $pdo->prepare($sql);
    $cStmt->execute([$vacId]);
    $candidatos = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $ce) {
    error_log('[candidatos] listado: '.$ce->getMessage());
  }

  // Calcula score IA ponderado si falta y tenemos embeddings
  if ($aiClient && $vacEmbedding) {
    $vacText = ($vac['descripcion'] ?? '').' '.($vac['requisitos'] ?? '');
    $weights = cd_ai_weights($vacText);
    $requiredYears = cd_ai_required_years($vacText);
    $vacTags = cd_ai_clean_skills(cd_split_tags($vac['etiquetas'] ?? ''));

    foreach ($candidatos as &$cand) {
      // Siempre recalculamos con IA para reflejar diferencias (valor ?nico y consistente).
      // Embedding candidato vs vacante
      try {
        $candText = cd_ai_candidate_text($cand);
        $candVec = $aiClient->embed($candText);
        $candNorm = cd_ai_norm($candVec);
        $cos = cd_ai_cosine($candVec, $candNorm, $vacEmbedding, $vacNorm);
        $embedScore = max(0, min(1, $cos)) * 100;
      } catch (Throwable $e) {
        error_log('[candidatos] no se pudo calcular embedding de candidato: '.$e->getMessage());
        $embedScore = 0;
      }
      // Skills
      $candSkills = cd_ai_clean_skills($cand['habilidades'] ?? '');
      $skillsScore = cd_ai_skill_overlap($candSkills, $vacTags);
      // Experiencia
      $expScore = cd_ai_exp_score($requiredYears, null);
      $scoreBase = round(
        $weights['embed'] * $embedScore +
        $weights['skills'] * $skillsScore +
        $weights['exp'] * $expScore,
        1
      );


      $cand['match_score_calc'] = max(0.0, min(100.0, (float)$scoreBase));
    }
    unset($cand);
    usort($candidatos, static fn($a, $b) => ($b['match_score_calc'] ?? 0) <=> ($a['match_score_calc'] ?? 0));
  }
}

$flash = $_SESSION['flash_candidatos'] ?? null;
unset($_SESSION['flash_candidatos']);
?>

<!-- Vista parcial: Lista de candidatos -->

  <!-- ===== Encabezado + KPIs ===== -->
  <section class="container section candidate-list" style="margin-top: var(--sp-5);">
    <div class="card" style="padding: var(--sp-4);">
    <div class="dash-head" style="display:flex; flex-direction:column; align-items:center; gap:16px;">
      <div style="max-width:820px; min-width:320px; text-align:center; margin:0 auto;">
        <h1 class="m-0"><?=cd_e($vacTitle); ?></h1>
        <p class="muted" style="color:#667085; font-size:16px;">
          <?php if ($companyId): ?>
            <a class="link text-brand" href="index.php?view=PerfilEmpresaVistaCandidato&empresa_id=<?=cd_e((string)$companyId); ?>"><?=cd_e($company); ?></a>
          <?php else: ?>
            <?=cd_e($company); ?>
          <?php endif; ?>
           Â· <?=cd_e($location); ?> Â· <?=cd_e($published); ?>
        </p>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:.4rem; justify-content:center;">
          <?php if ($headerChips): ?>
            <?php foreach ($headerChips as $chip): ?>
              <span class="chip"><?=cd_e($chip); ?></span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="chip">Laravel</span>
            <span class="chip">MySQL</span>
            <span class="chip">Git</span>
            <span class="chip">API REST</span>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <div class="kpi-strip" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; justify-content:center; padding:.6rem .8rem; background:#fff; border:1px solid var(--border); border-radius:12px;">
          <div class="kpi card" style="flex:1 1 180px; min-width:180px; padding:.5rem .75rem;">
            <span class="kpi-label" style="font-size:14px; font-weight:500;">Candidatos</span>
            <span class="kpi-value" style="font-size:20px; font-weight:700;"><?=cd_e((string)$kpis['total']); ?></span>
          </div>
          <div class="kpi card" style="flex:1 1 180px; min-width:180px; padding:.5rem .75rem;">
            <span class="kpi-label" style="font-size:14px; font-weight:500;">PreselecciÃ³n</span>
            <span class="kpi-value" style="font-size:20px; font-weight:700;"><?=cd_e((string)$kpis['preseleccion']); ?></span>
          </div>
          <div class="kpi card" style="flex:1 1 180px; min-width:180px; padding:.5rem .75rem;">
            <span class="kpi-label" style="font-size:14px; font-weight:500;">Entrevista</span>
            <span class="kpi-value" style="font-size:20px; font-weight:700;"><?=cd_e((string)$kpis['entrevista']); ?></span>
          </div>
          <div class="kpi card" style="flex:1 1 180px; min-width:180px; padding:.5rem .75rem;">
            <span class="kpi-label" style="font-size:14px; font-weight:500;">Descartados</span>
            <span class="kpi-value" style="font-size:20px; font-weight:700;"><?=cd_e((string)$kpis['descartados']); ?></span>
          </div>
          <div style="display:flex; align-items:center; gap:8px;">
            <label for="orden" class="muted" style="font-weight:600;">Ordenar por</label>
            <select id="orden" class="select-pill" aria-label="Ordenar candidatos">
              <option selected>Score IA (desc)</option>
              <option>Score IA (asc)</option>
              <option>MÃ¡s recientes</option>
            </select>
            <a class="btn btn-outline" style="min-height:36px; border-radius:24px;" href="index.php?view=candidatos&vacante_id=<?=cd_e((string)$vacId); ?>&include_descartados=<?= $includeDescartados ? '0' : '1'; ?>">
              Incluir descartados: <?= $includeDescartados ? 'SÃ­' : 'No'; ?>
            </a>
          </div>
        </div>
      </div>
    </div>
    </div>

    <?php if ($error): ?>
      <div class="card" style="margin-top:.75rem; border-color:#f5c7c7; background:#fff5f5;">
        <strong><?=cd_e($error); ?></strong>
      </div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="card" style="margin-top:.75rem; border-color:#d6f5d6; background:#f3fff3;">
        <strong><?=cd_e($flash); ?></strong>
      </div>
    <?php endif; ?>

    <div class="kpis" style="display:none;"></div>
  </section>

  <!-- Acciones masivas: removidas; acciones por tarjeta -->

  <!-- ===== Layout principal: filtros + resultados ===== -->
  <main id="contenido-principal" class="container section" tabindex="-1">
    <div class="page" style="display:flex; gap:24px;">
      <!-- Sidebar de filtros (reutiliza .filters) -->
      <aside class="filters" style="flex:0 0 268px;" aria-label="Filtros de candidatos">
        <div class="card">
          <h2 class="card-title">Filtros</h2>

          <div class="field">
            <label for="buscar">Buscar</label>
            <input id="buscar" type="text" placeholder="Nombre, rol..." />
          </div>

          <div class="field">
            <label>Estado</label>
            <label class="check"><input type="checkbox" checked /> Activo</label>
            <label class="check"><input type="checkbox" /> A revisar</label>
            <label class="check"><input type="checkbox" /> Entrevista</label>
            <label class="check"><input type="checkbox" /> Oferta</label>
            <label class="check"><input type="checkbox" /> Descartado</label>
          </div>

          <div class="field">
            <label id="filter-score-label">Score mÃ­nimo (IA)</label>
            <div class="progress" role="progressbar" aria-labelledby="filter-score-label" aria-valuenow="60" aria-valuemin="0" aria-valuemax="100" aria-valuetext="60%">
              <div class="progress-bar" style="height:8px;"><span style="width:60%; height:6px;"></span></div>
              <div class="progress-label">60%</div>
            </div>
          </div>

          <button class="btn btn-outline full">Aplicar filtros</button>
        </div>

        <div class="card tips">
          <h2 class="card-title">Consejo</h2>
          <p class="muted">Usa los filtros para descubrir <strong>candidatos con mejor match</strong>.</p>
        </div>
      </aside>

      <!-- Resultados dinÃ¡micos -->
      <section style="flex:1; display:flex; flex-direction:column; gap:24px;">
        <?php if ($candidatos): ?>
          <?php foreach ($candidatos as $idx => $cand): ?>
            <?php
              $rank = $idx + 1;
              $fullName = trim((string)($cand['nombres'] ?? '') . ' ' . (string)($cand['apellidos'] ?? ''));
              $metaParts = [];
              if (!empty($cand['rol_deseado'])) { $metaParts[] = (string)$cand['rol_deseado']; }
              if (!empty($cand['anios_experiencia'])) { $metaParts[] = (int)$cand['anios_experiencia'].' aÃ±os'; }
              if (!empty($cand['ciudad'])) { $metaParts[] = (string)$cand['ciudad']; }
              if (!empty($cand['modalidad_nombre'])) { $metaParts[] = (string)$cand['modalidad_nombre']; }
              $meta = implode(' Â· ', $metaParts);

              $estado = strtolower((string)($cand['estado'] ?? 'recibida'));
              $estadoLabel = ucfirst(str_replace('_',' ', $estado));
              $estadoClass = ($estado === 'preseleccion') ? 'review' : (($estado === 'entrevista') ? 'interview' : '');

              $calcScore = $cand['match_score_calc'] ?? $cand['match_score'] ?? null;
              $score = $calcScore !== null ? max(0, min(100, (int)$calcScore)) : 0;
              $tags = cd_split_tags($cand['habilidades'] ?? '');
              $displayTags = array_slice($tags, 0, 6);
            ?>
            <article class="card candidate-card" aria-label="Candidato" style="position:relative;">
              <?php if ($rank <= 3): ?>
                <span class="pill pill-cta" aria-hidden="true" title="Top <?=cd_e((string)$rank); ?>" style="position:absolute; top:8px; left:8px;">Top <?=cd_e((string)$rank); ?></span>
              <?php endif; ?>
              <header class="profile-head" style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px;">
                <div style="display:flex; align-items:center; gap:.8rem;">
                  <div class="avatar" aria-hidden="true"></div>
                  <div>
                    <h3 class="m-0"><?=cd_e($fullName ?: 'Candidato sin nombre'); ?></h3>
                    <p class="meta m-0"><?=cd_e($meta ?: 'Perfil sin resumen'); ?></p>
                    <?php if ($displayTags): ?>
                      <div class="pills" style="margin-top:.35rem;">
                        <?php foreach ($displayTags as $t): ?><span class="pill"><?=cd_e($t); ?></span><?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div style="display:flex; align-items:center; gap:16px;">
                  <span class="status <?=cd_e($estadoClass); ?>"><?=cd_e($estadoLabel); ?></span>
                    <div style="display:flex; align-items:center; gap:8px;">
                    <span class="muted" style="font-weight:600;">Score IA</span>
                    <?php
                      $scoreFloat = max(0.0, min(100.0, (float)$score));
                      $scoreText = number_format($scoreFloat, 1);
                    ?>
                    <div class="progress-bar" style="height:8px; min-inline-size:180px;"><span style="width:<?=$scoreText; ?>%; height:6px;"></span></div>
                    <span class="muted" style="font-weight:600;"><?=$scoreText; ?></span>
                  </div>
                </div>
              </header>

              <section style="display:flex; flex-wrap:wrap; gap:16px;">
                <?php if ($displayTags): ?>
                  <div class="card" style="padding:12px; flex:1 1 240px;">
                    <strong class="muted">Habilidades</strong>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:.35rem;">
                      <?php foreach ($displayTags as $tag): ?>
                        <span class="chip" style="padding:.45rem .8rem; background:#EEF6EA; color:#1D7C1D; border-color:#CDE9CB;"><?=cd_e($tag); ?></span>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
                <div class="card" style="padding:12px; flex:1 1 240px;">
                  <strong class="muted">Resumen</strong>
                  <p class="muted" style="margin-top:.35rem;"><?=cd_e($cand['resumen'] ?? ''); ?></p>
                </div>
                <div class="card" style="padding:12px; flex:1 1 240px;">
                  <strong class="muted">Datos</strong>
                  <p class="muted" style="margin-top:.35rem;">
                    <?php if (!empty($cand['nivel_nombre'])): ?>Nivel: <?=cd_e((string)$cand['nivel_nombre']); ?><br><?php endif; ?>
                    <?php if (!empty($cand['estudios_nombre'])): ?>Estudios: <?=cd_e((string)$cand['estudios_nombre']); ?><br><?php endif; ?>
                    <?php if (!empty($cand['disponibilidad_nombre'])): ?>Disponibilidad: <?=cd_e((string)$cand['disponibilidad_nombre']); ?><?php endif; ?>
                  </p>
                </div>
              </section>

              <footer class="actions" style="justify-content:flex-start; gap:14px; flex-wrap:wrap;">
                <a class="btn btn-ghost" style="min-height:40px; border-radius:24px;" href="<?= 'index.php?view=perfil_publico&email='.rawurlencode((string)$cand['email']); ?>">Ver perfil</a>
                <form method="post" action="index.php?action=update_postulacion" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?=cd_e($csrf); ?>" />
                  <input type="hidden" name="vacante_id" value="<?=cd_e((string)$vacId); ?>" />
                  <input type="hidden" name="candidato_email" value="<?=cd_e((string)$cand['email']); ?>" />
                  <input type="hidden" name="nuevo_estado" value="preseleccion" />
                  <button class="btn btn-outline" style="min-height:40px; border-radius:24px;" type="submit">Mover a preselecciÃ³n</button>
                </form>
                 <form method="post" action="index.php?action=update_postulacion" style="display:inline;">
                   <input type="hidden" name="csrf" value="<?=cd_e($csrf); ?>" />
                   <input type="hidden" name="vacante_id" value="<?=cd_e((string)$vacId); ?>" />
                   <input type="hidden" name="candidato_email" value="<?=cd_e((string)$cand['email']); ?>" />
                   <input type="hidden" name="nuevo_estado" value="entrevista" />
                   <button class="btn btn-outline" style="min-height:40px; border-radius:24px;" type="submit">Invitar a entrevista</button>
                 </form>
                 <form method="post" action="index.php?action=update_postulacion" style="display:inline;">
                   <input type="hidden" name="csrf" value="<?=cd_e($csrf); ?>" />
                   <input type="hidden" name="vacante_id" value="<?=cd_e((string)$vacId); ?>" />
                   <input type="hidden" name="candidato_email" value="<?=cd_e((string)$cand['email']); ?>" />
                   <input type="hidden" name="nuevo_estado" value="no_seleccionado" />
                   <button class="btn btn-outline text-danger" style="min-height:40px; border-radius:24px;" type="submit">Descartar</button>
                 </form>
              </footer>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="card"><p class="muted m-0">AÃºn no hay postulaciones para esta vacante.</p></div>
        <?php endif; ?>

        <!-- PaginaciÃ³n futura -->
        <nav class="actions" role="navigation" aria-label="PaginaciÃ³n" style="justify-content:center; gap:10px; display:none;"></nav>
      </section>
    </div>
  </main>

  <style>
    /* Variantes y utilidades locales para la vista de candidatos */
    .chip{ display:inline-flex; align-items:center; gap:.35rem; padding:.45rem .8rem; border:1px solid var(--border); border-radius: var(--pill-radius); background: var(--white); font-weight:600; font-size:.9rem; }
    .pill{ display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .7rem; border:1px solid var(--border); border-radius: var(--pill-radius); background:#F5F7F4; font-weight:600; font-size:.85rem; }
    .pill-cta{ background: var(--brand-50); color: var(--brand-700); border:1px solid #DDE8D6; }

    .avatar{ inline-size:44px; block-size:44px; border-radius: 999px; background: linear-gradient(135deg,#E6F5DC,#FFFFFF); border:1px solid var(--border); }

    .progress{ display:flex; align-items:center; gap:.6rem; }
    .progress-bar{ position:relative; inline-size:200px; max-inline-size:100%; background:#EDF6E7; border:1px solid #DDE8D6; border-radius:999px; overflow:hidden; }
    .progress-bar span{ position:absolute; inset-block:2px; inset-inline-start:2px; display:block; background: var(--grad-cta); border-radius:999px; }
    .progress-label{ color: var(--muted); font-weight:600; }

    .status{ display:inline-flex; align-items:center; padding:.35rem .7rem; border-radius: var(--pill-radius); font-weight:700; font-size:.85rem; }
    .status.review{ background:#EEF6EA; color:#1D7C1D; border:1px solid #CDE9CB; }
    .status.interview{ background: var(--warn-bg); color: var(--warn-text); border: 1px solid var(--warn-border); }

    /* Variantes de botÃ³n locales compatibles con el sistema de botones global */
    .btn-outline{ background: var(--white); color: var(--brand-700); border:1px solid var(--border); }
    .btn-ghost{ background: transparent; color: var(--brand-700); border:1px solid transparent; }
    .btn-brand{ background: var(--grad-cta); color: var(--white); border:none; box-shadow: var(--shadow-md); }

    /* Quita reordenamiento del sidebar en tamaÃ±os medianos; mantiene filtros a la izquierda */
  </style>
if (!function_exists('cd_ai_clean_skills')) {
  /**
   * Normaliza habilidades quitando números/años y símbolos.
   * @param array<int,string>|string|null $skills
   * @return string[]
   */
  function cd_ai_clean_skills($skills): array
  {
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