<?php

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }

// Carga variables desde el .env de Laravel (si existe) para reutilizar claves/DB/API en la app plana.
if (!function_exists('dash_load_env_file')) {
  function dash_load_env_file(string $path): void
  {
    if (!is_file($path)) { return; }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) { return; }
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) { continue; }
      $parts = explode('=', $line, 2);
      if (count($parts) !== 2) { continue; }
      [$key, $value] = $parts;
      $key = trim($key);
      $value = trim($value);
      $value = trim($value, "\"'"); // quita comillas simples o dobles
      if ($key === '') { continue; }
      if (getenv($key) === false && !isset($_ENV[$key])) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
      }
    }
  }
}
$envLaravel = dirname(__DIR__).'/laravel-app/.env';
dash_load_env_file($envLaravel);

if (!function_exists('dash_seed_modalidades')) {
  /**
   * Se asegura de que existan las modalidades b�sicas (Presencial, Hibrido, Remoto) en BD.
   * @param array<int,array{id?:int,nombre?:string}> $options
   * @return array<int,array<string,mixed>>
   */
  function dash_seed_modalidades(PDO $pdo, array $options): array
  {
    $required = ['Presencial', 'Hibrido', 'Remoto'];
    $normalize = static function ($value): string {
      $label = trim((string)$value);
      $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
      $ascii = $ascii !== false ? $ascii : $label;
      return strtolower($ascii);
    };

    $current = [];
    foreach ($options as $row) {
      $current[] = $normalize($row['nombre'] ?? '');
    }

    $missing = [];
    foreach ($required as $name) {
      if (!in_array($normalize($name), $current, true)) {
        $missing[] = $name;
      }
    }

    if ($missing) {
      try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO modalidades (nombre) VALUES (?)');
        foreach ($missing as $name) {
          $stmt->execute([$name]);
        }
        $refetch = $pdo->query('SELECT id, nombre FROM modalidades ORDER BY nombre ASC');
        if ($refetch) {
          $options = $refetch->fetchAll(PDO::FETCH_ASSOC) ?: $options;
        }
      } catch (Throwable $e) {
        error_log('[dashboard] modalidades seed: '.$e->getMessage());
      }
    }

    return $options;
  }
}

// Mapea variables de Laravel a las usadas por la app plana (DB y OpenAI)
if (!getenv('DB_NAME') && getenv('DB_DATABASE')) { putenv('DB_NAME='.getenv('DB_DATABASE')); $_ENV['DB_NAME'] = getenv('DB_DATABASE'); }
if (!getenv('DB_USER') && getenv('DB_USERNAME')) { putenv('DB_USER='.getenv('DB_USERNAME')); $_ENV['DB_USER'] = getenv('DB_USERNAME'); }
if (!getenv('DB_PASS') && getenv('DB_PASSWORD')) { putenv('DB_PASS='.getenv('DB_PASSWORD')); $_ENV['DB_PASS'] = getenv('DB_PASSWORD'); }



$userSession = $_SESSION['user'] ?? null;

if (!$userSession || ($userSession['type'] ?? '') !== 'persona') {

  header('Location: index.php?view=login');

  exit;

}



require_once __DIR__.'/db.php';
require_once dirname(__DIR__).'/lib/EncodingHelper.php';
// Cliente OpenAI para embeddings
$openaiClientFile = dirname(__DIR__).'/lib/OpenAIClient.php';
if (is_file($openaiClientFile)) {
  require_once $openaiClientFile;
}
require_once dirname(__DIR__).'/lib/MatchService.php';
require_once dirname(__DIR__).'/lib/match_helpers.php';
require_once dirname(__DIR__).'/lib/DocumentAnalyzer.php';

if (!function_exists('dash_ensure_saved_table')) {
  function dash_ensure_saved_table(PDO $pdo): void
  {
    $pdo->exec(
      'CREATE TABLE IF NOT EXISTS vacantes_guardadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        candidato_email VARCHAR(255) NOT NULL,
        vacante_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cand_vac (candidato_email, vacante_id),
        INDEX idx_cand (candidato_email),
        INDEX idx_vac (vacante_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
  }
}

$docAnalyzer = null;
try {
  $docAnalyzer = new DocumentAnalyzer(MatchService::getClient());
} catch (Throwable $e) {
  $docAnalyzer = new DocumentAnalyzer(null);
  error_log('[dashboard] doc analyzer fallback: '.$e->getMessage());
}
require_once dirname(__DIR__).'/lib/DocumentAnalyzer.php';

$userEmail = $userSession['email'] ?? '';

// Guardar vacante como favorita (solo para candidatos)
if ($pdo instanceof PDO && $userEmail !== '' && isset($_GET['save'])) {
  $saveId = (int)$_GET['save'];
  if ($saveId > 0) {
    try {
      dash_ensure_saved_table($pdo);
      $ins = $pdo->prepare('INSERT INTO vacantes_guardadas (candidato_email, vacante_id, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE created_at = VALUES(created_at)');
      $ins->execute([$userEmail, $saveId]);
      $_SESSION['flash_dashboard_save'] = 'Vacante guardada para revisar luego.';
    } catch (Throwable $e) {
      error_log('[dashboard] guardar vacante: '.$e->getMessage());
      $_SESSION['flash_dashboard_save'] = 'No se pudo guardar la vacante.';
    }
    header('Location: index.php?view=dashboard');
    exit;
  }
}



$fullName = trim(($userSession['nombre'] ?? '').' '.($userSession['apellidos'] ?? ''));

if ($fullName === '') {

  $fullName = $userSession['display_name'] ?? $userSession['email'];

}

$fullName = trim($fullName);

$firstName = $fullName;

$nameParts = preg_split('/\s+/', (string)$userSession['nombre']);

if (!empty($nameParts[0])) {

  $firstName = trim($nameParts[0]);

} elseif ($fullName !== '') {

  $firstName = preg_split('/\s+/', $fullName)[0] ?? $fullName;

}



$profileData = [

  'fullName' => $fullName,

  'firstName' => $firstName,

  'area_id' => null,

  'nivel_id' => null,

  'modalidad_id' => null,

  'disponibilidad_id' => null,

  'headline' => null,

  'level' => null,

  'city' => null,

  'modalidad' => null,

  'disponibilidad' => null,

  'chips' => [],

  'pills' => [],

  'skills' => [],

  'experiences' => [],

  'education' => [],

  'photo' => null,

  'summary' => null,

  'email_verified' => false,

  'cv_uploaded' => false,

  'certifications' => 0,

];



$splitList = static function (?string $value): array {

  if ($value === null) { return []; }

  $items = preg_split('/[,;\r\n]+/', $value);

  if (!is_array($items)) { return []; }

  $items = array_map('trim', $items);

  $items = array_filter($items, static function ($item) { return $item !== ''; });

  return array_values(array_unique($items));

};



if ($pdo instanceof PDO) {

  $stmt = $pdo->prepare(

    'SELECT c.nombres, c.apellidos, c.ciudad, c.email_verificado_at,

            cd.perfil AS resumen, cd.areas_interes, cd.foto_ruta,

            cp.rol_deseado, cp.habilidades, cp.area_id, cp.nivel_id, cp.modalidad_id, cp.disponibilidad_id,

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

  $stmt->execute([$userSession['email']]);

  if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $dbFullName = trim(($row['nombres'] ?? '').' '.($row['apellidos'] ?? ''));

    if ($dbFullName !== '') {

      $profileData['fullName'] = $dbFullName;

      $profileData['firstName'] = preg_split('/\s+/', (string)$row['nombres'])[0] ?? $dbFullName;

    }

    $profileData['city'] = $row['ciudad'] ?? null;

    $profileData['headline'] = $row['rol_deseado'] ?: ($row['area_nombre'] ?? null);

    $profileData['area_id'] = isset($row['area_id']) ? (int)$row['area_id'] : null;

    $profileData['nivel_id'] = isset($row['nivel_id']) ? (int)$row['nivel_id'] : null;

    $profileData['modalidad_id'] = isset($row['modalidad_id']) ? (int)$row['modalidad_id'] : null;

    $profileData['disponibilidad_id'] = isset($row['disponibilidad_id']) ? (int)$row['disponibilidad_id'] : null;

    $profileData['level'] = $row['nivel_nombre'] ?? null;

    $profileData['modalidad'] = $row['modalidad_nombre'] ?? null;

    $profileData['disponibilidad'] = $row['disponibilidad_nombre'] ?? null;

    $profileData['summary'] = $row['resumen'] ?? null;

    $profileData['email_verified'] = !empty($row['email_verificado_at']);

    if (!empty($row['foto_ruta'])) {

      $profileData['photo'] = $row['foto_ruta'];

    }




    // Cargamos habilidades detectando din?micamente el nombre de la columna de experiencia.
    $yearColumns = ['anios_experiencia', 'anos_experiencia', 'años_experiencia'];
    $existingCols = [];
    try {
      $colStmt = $pdo->query('SHOW COLUMNS FROM candidato_habilidades');
      if ($colStmt) {
        $existingCols = $colStmt->fetchAll(PDO::FETCH_COLUMN, 0);
      }
    } catch (Throwable $e) {
      $existingCols = [];
    }

    $yearCol = null;
    foreach ($yearColumns as $col) {
      if (in_array($col, $existingCols, true)) {
        $yearCol = $col;
        break;
      }
    }

    if ($yearCol) {
      $skillsStmt = $pdo->prepare(
        "SELECT nombre, `{$yearCol}` AS exp_years\n         FROM candidato_habilidades\n         WHERE email = ?\n         ORDER BY exp_years DESC, nombre ASC"
      );
    } else {
      // Si no hay columna de a?os, evitamos error y devolvemos nulos.
      $skillsStmt = $pdo->prepare(
        "SELECT nombre, NULL AS exp_years\n         FROM candidato_habilidades\n         WHERE email = ?\n         ORDER BY nombre ASC"
      );
    }

    $skillsStmt->execute([$userSession['email']]);

    $skillRecords = $skillsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($skillRecords) {

      $skills = [];

      foreach ($skillRecords as $skill) {

        $name = trim((string)$skill['nombre']);

        if ($name === '') { continue; }

        $years = $skill['exp_years'];

        if ($years !== null && $years !== '') {

          $yearsFloat = (float)$years;

          $yearsLabel = rtrim(rtrim(number_format($yearsFloat, 1, '.', ''), '0'), '.');

          $skills[] = sprintf('%s - %s %s', $name, $yearsLabel, $yearsFloat == 1.0 ? 'año' : 'años');

        } else {

          $skills[] = $name;

        }

      }

      if ($skills) {

        $profileData['skills'] = array_slice($skills, 0, 8);

      }

    }
    if (!$profileData['skills']) {

      $skills = $splitList($row['habilidades'] ?? '');

      if (!$skills) {

        $skills = $splitList($row['areas_interes'] ?? '');

      }

      $profileData['skills'] = array_slice($skills, 0, 8);

    }



    $profileData['chips'] = array_filter([

      $profileData['headline'] ? 'Rol: '.$profileData['headline'] : null,

      $profileData['city'] ? 'Ciudad: '.$profileData['city'] : null,

      $profileData['modalidad'] ? 'Modalidad: '.$profileData['modalidad'] : null,

      $profileData['level'] ? 'Nivel: '.$profileData['level'] : null,

    ]);



    $profileData['pills'] = array_filter([

      $profileData['modalidad'],

      $profileData['city'],

      $profileData['disponibilidad'] ? 'Disponibilidad '.$profileData['disponibilidad'] : null,

    ]);

  }

}



if (!function_exists('dash_extract_tags')) {

  function dash_extract_tags(?string $value): array {

    if ($value === null) { return []; }

    $parts = preg_split('/[,;|]+/', (string)$value);

    if (!is_array($parts)) { return []; }

    $parts = array_map(static fn($item) => trim((string)$item), $parts);

    $parts = array_filter($parts, static fn($item) => $item !== '');

    return array_values(array_unique($parts));

  }

}



if (!function_exists('dash_truncate_text')) {

  function dash_truncate_text(?string $text, int $limit = 200): string {

    $text = fix_mojibake(trim((string)$text));

    if ($text === '') { return 'Sin descripción disponible.'; }

    $lengthFn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';

    $substrFn = function_exists('mb_substr') ? 'mb_substr' : 'substr';

    if ($lengthFn($text, 'UTF-8') <= $limit) { return $text; }

    $slice = $substrFn($text, 0, $limit - 1, 'UTF-8');

    return rtrim($slice).'…';

  }

}



if (!function_exists('dash_format_salary')) {

  function dash_format_salary(?int $min, ?int $max, string $currency): string {

    $currency = strtoupper(trim($currency ?: 'COP'));

    $format = static function (?int $value) use ($currency): string {

      if ($value === null || $value <= 0) { return ''; }

      return $currency.' '.number_format($value, 0, ',', '.');

    };

    $minText = $format($min);

    $maxText = $format($max);

    if ($minText && $maxText) {

      return $minText.' - '.$maxText;

    }

    if ($minText) {

      return 'Desde '.$minText;

    }

    if ($maxText) {

      return 'Hasta '.$maxText;

    }

    return 'Salario a convenir';

  }

}



if (!function_exists('dash_publication_info')) {

  /**

   * @return array{badge:?string,meta:string,is_new:bool}

   */

  function dash_publication_info(?string $value): array {

    if (!$value) {

      return ['badge' => null, 'meta' => 'Publicación sin fecha', 'is_new' => false];

    }

    try {

      $date = new DateTime($value);

    } catch (Throwable $e) {

      return ['badge' => null, 'meta' => 'Publicación reciente', 'is_new' => false];

    }

    $now = new DateTime('now');

    $diff = $now->diff($date);

    $isFuture = ($diff->invert === 0);

    if ($isFuture) {

      return ['badge' => 'Próxima', 'meta' => 'Publicación programada', 'is_new' => false];

    }

    $days = (int)($diff->days ?? 0);

    $hours = (int)$diff->h;

    if ($days === 0) {

      if ($hours <= 1) {

        return ['badge' => 'Nueva', 'meta' => 'Publicada hace menos de 1 hora', 'is_new' => true];

      }

      return ['badge' => 'Nueva', 'meta' => 'Publicada hace '.$hours.' horas', 'is_new' => true];

    }

    if ($days === 1) {

      return ['badge' => 'Hace 1 día', 'meta' => 'Publicada hace 1 día', 'is_new' => true];

    }

    $meta = 'Publicada hace '.$days.' días';

    $badge = $days <= 3 ? 'Nueva' : 'Hace '.$days.' días';

    return ['badge' => $badge, 'meta' => $meta, 'is_new' => $days <= 3];

  }

}



if (!function_exists('dash_match_score')) {
  /**
   * Cálculo unificado de match usando MatchService (mismos pesos y datos en todas las vistas).
   * @param array<string,mixed> $vacante
   * @param string[] $keywords
   * @param array<string,mixed> $profile
   * @return float
   */
  function dash_match_score(array $vacante, array $keywords, array $profile): float {
    global $pdo, $userSession;
    if (!($pdo instanceof PDO)) { return 0.0; }
    $email = isset($userSession['email']) ? (string)$userSession['email'] : '';
    if ($email === '') { return 0.0; }
    return ms_score($pdo, $vacante, $email);
  }
}



if (!function_exists('dash_ai_norm')) {
  function dash_ai_norm(array $vector): float {
    return MatchService::norm($vector);
  }
}


if (!function_exists('dash_ai_cosine')) {
  function dash_ai_cosine(array $a, float $normA, array $b, float $normB): float {
    return MatchService::cosine($a, $normA, $b, $normB);
  }
}

if (!function_exists('dash_ai_weights')) {
  function dash_ai_weights(string $text): array {
    // Pesos fijos para todas las vistas (embed 60%, skills 30%, experiencia 10%)
    return ['embed' => 0.6, 'skills' => 0.3, 'exp' => 0.1];
  }
}

if (!function_exists('dash_ai_skill_overlap')) {
  function dash_ai_skill_overlap(array $candSkills, array $vacTags): int {
    return MatchService::skillOverlap($candSkills, $vacTags);
  }
}

if (!function_exists('dash_ai_required_years')) {
  function dash_ai_required_years(string $text): ?int {
    return MatchService::requiredYears($text);
  }
}

if (!function_exists('dash_ai_exp_score')) {
  function dash_ai_exp_score(?int $required, ?int $candYears): int {
    return MatchService::expScore($required, $candYears);
  }
}

if (!function_exists('dash_ai_clean_skills')) {
  /**
   * @param array<int,string>|string|null $skills
   * @return string[]
   */
  function dash_ai_clean_skills($skills): array {
    return MatchService::cleanList($skills);
  }
}

if (!function_exists('dash_ai_profile_text')) {
  /**
   * @param array<string,mixed> $profile
   */
  function dash_ai_profile_text(array $profile, string $email): string {
    $parts = array_filter([
      'Email: '.$email,
      'Nombre: '.($profile['fullName'] ?? ''),
      'Rol deseado: '.($profile['headline'] ?? ''),
      'Nivel: '.($profile['level'] ?? ''),
      'Ciudad: '.($profile['city'] ?? ''),
      'Modalidad: '.($profile['modalidad'] ?? ''),
      'Disponibilidad: '.($profile['disponibilidad'] ?? ''),
      'Resumen: '.($profile['summary'] ?? ''),
      $profile['skills'] ? 'Habilidades: '.implode(', ', (array)$profile['skills']) : null,
      $profile['pills'] ? 'Preferencias: '.implode(', ', (array)$profile['pills']) : null,
    ]);
    return implode("\n", $parts);
  }
}

if (!function_exists('dash_ai_weights')) {
  /**
   * Pesos base para IA: embed 60%, skills 30%, exp 10%. Ajustables.
   * @return array{embed:float,skills:float,exp:float}
   */
  function dash_ai_weights(string $text): array {
    return ['embed' => 0.6, 'skills' => 0.3, 'exp' => 0.1];
  }
}

if (!function_exists('dash_ai_skill_overlap')) {
  function dash_ai_skill_overlap(array $candSkills, array $vacTags): int {
    $cand = array_filter(array_map(static fn($v) => dash_lower(trim((string)$v)), $candSkills));
    $vac  = array_filter(array_map(static fn($v) => dash_lower(trim((string)$v)), $vacTags));
    $cand = array_values(array_unique($cand));
    $vac  = array_values(array_unique($vac));
    if (!$cand || !$vac) { return 20; }
    $common = array_intersect($cand, $vac);
    $max = max(count($cand), count($vac));
    if ($max === 0) { return 20; }
    return (int)round(count($common) / $max * 100);
  }
}

if (!function_exists('dash_ai_exp_score')) {
  function dash_ai_exp_score(?int $required, ?int $candYears): int {
    if ($required === null || $required <= 0 || $candYears === null) { return 20; }
    if ($candYears >= $required) { return 100; }
    return (int)round(max(0, ($candYears / $required) * 100));
  }
}

/**
 * Construye el texto base de una vacante para generar su embedding.
 *
 * @param array<string,mixed> $vac
 */
function dash_ai_vacancy_text(array $vac): string
{
    $parts = array_filter([
        'Título: '.($vac['titulo'] ?? ''),
        'Descripción: '.($vac['descripcion'] ?? ''),
        'Requisitos: '.($vac['requisitos'] ?? ''),
        'Área: '.($vac['area_nombre'] ?? ''),
        'Nivel: '.($vac['nivel_nombre'] ?? ''),
        'Modalidad: '.($vac['modalidad_nombre'] ?? ''),
        'Ciudad: '.($vac['ciudad'] ?? ''),
        'Etiquetas: '.($vac['etiquetas'] ?? ''),
    ]);
    return implode("\n", $parts);
}

/**
 * Genera embeddings para vacantes que no los tengan aún.
 *
 * @param array<int,string> $conditions
 * @param array<int,mixed>  $params
 */
function dash_ai_generate_missing_embeddings(PDO $pdo, OpenAIClient $client, array $conditions, array $params, int $limit = 50): ?string
{
    // Busca vacantes sin embedding que cumplan las condiciones (limit para no saturar)
    $sqlMissing = '
      SELECT v.id, v.titulo, v.descripcion, v.requisitos, v.ciudad, v.etiquetas,
             a.nombre AS area_nombre, n.nombre AS nivel_nombre, m.nombre AS modalidad_nombre
      FROM vacantes v
      LEFT JOIN vacante_embeddings ve ON ve.vacante_id = v.id
      LEFT JOIN areas a ON a.id = v.area_id
      LEFT JOIN niveles n ON n.id = v.nivel_id
      LEFT JOIN modalidades m ON m.id = v.modalidad_id
      WHERE ve.vacante_id IS NULL AND '.implode(' AND ', $conditions).'
      LIMIT '.(int)$limit;

    try {
        $stmt = $pdo->prepare($sqlMissing);
        $stmt->execute($params);
        $missing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return 'No se pudieron consultar vacantes sin embedding: '.$e->getMessage();
    }

    if (!$missing) {
        return null;
    }

    try {
        $insert = $pdo->prepare('
          INSERT INTO vacante_embeddings (vacante_id, embedding, norm, created_at, updated_at)
          VALUES (:vacante_id, :embedding, :norm, NOW(), NOW())
          ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), norm = VALUES(norm), updated_at = VALUES(updated_at)
        ');
    } catch (Throwable $e) {
        return 'No se pudo preparar el insert de embeddings: '.$e->getMessage();
    }

    foreach ($missing as $vac) {
        try {
            $text = dash_ai_vacancy_text($vac);
            $vec = $client->embed($text);
            $norm = dash_ai_norm($vec);
            $insert->execute([
                ':vacante_id' => (int)$vac['id'],
                ':embedding' => json_encode($vec),
                ':norm' => $norm,
            ]);
        } catch (Throwable $e) {
            // Continúa con las demás; no aborta toda la generación.
            error_log('[AI] No se pudo generar embedding para vacante '.$vac['id'].': '.$e->getMessage());
        }
    }

    return null;
}


if (!function_exists('dash_ai_recommendations')) {
  /**
   * @param array<string,mixed> $profile
   * @param array<int,bool> $applied
   * @return array{items:array<int,array<string,mixed>>,error:?string}
   */
  function dash_ai_recommendations(PDO $pdo, array $profile, string $email, array $applied = [], int $limit = 8): array {
    $client = MatchService::getClient();
    if (!$client) {
      return ['items' => [], 'error' => 'Configura la variable OPENAI_API_KEY y el cliente OpenAI.'];
    }

    $items = [];

    try {
      $stmt = $pdo->prepare(
        'SELECT v.id, v.titulo, v.descripcion, v.requisitos, v.etiquetas, v.ciudad,
                v.salario_min, v.salario_max, v.moneda, v.publicada_at, v.created_at,
                e.id AS empresa_id, e.razon_social AS empresa_nombre,
                a.nombre AS area_nombre, n.nombre AS nivel_nombre, m.nombre AS modalidad_nombre
         FROM vacantes v
         LEFT JOIN empresas e ON e.id = v.empresa_id
         LEFT JOIN areas a ON a.id = v.area_id
         LEFT JOIN niveles n ON n.id = v.nivel_id
         LEFT JOIN modalidades m ON m.id = v.modalidad_id
         WHERE v.estado IN ("publicada","activa","publicada ")
         ORDER BY COALESCE(v.publicada_at, v.created_at) DESC
         LIMIT 200'
      );
      $stmt->execute();
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return ['items' => [], 'error' => 'No se pudieron consultar vacantes: '.$e->getMessage()];
    }

    foreach ($rows as $row) {
      $vacData = ms_normalize_vac($row);
      $scorePct = ms_score($pdo, $vacData, $email);
      $docFactor = isset($profile['doc_score']) ? (float)$profile['doc_score'] : 1.0;
      $scorePct = max(0.0, min(100.0, $scorePct * $docFactor));

      $pubInfo = dash_publication_info($row['publicada_at'] ?? $row['created_at']);
      $metaParts = array_filter([
        $row['modalidad_nombre'] ?? null,
        $row['ciudad'] ?? null,
        $pubInfo['meta'],
      ]);

      $tags = MatchService::cleanList(dash_extract_tags($row['etiquetas'] ?? null));
      $chips = array_values(array_filter([
        $row['nivel_nombre'] ?? null,
        $row['modalidad_nombre'] ?? null,
        $row['area_nombre'] ?? null,
        $row['ciudad'] ?? null,
      ]));

      $items[] = [
        'id' => (int)$row['id'],
        'titulo' => $row['titulo'] ?? 'Oferta sin título',
        'empresa' => $row['empresa_nombre'] ?? 'Empresa confidencial',
        'empresa_id' => isset($row['empresa_id']) ? (int)$row['empresa_id'] : null,
        'meta_line' => $metaParts ? implode(' · ', $metaParts) : $pubInfo['meta'],
        'badge' => $pubInfo['badge'],
        'chips' => $chips,
        'descripcion' => dash_truncate_text($row['descripcion'] ?? $row['requisitos'] ?? ''),
        'coincidencias' => $tags ? array_slice($tags, 0, 4) : [],
        'match' => $scorePct,
        'salario' => dash_format_salary(
          isset($row['salario_min']) ? (int)$row['salario_min'] : null,
          isset($row['salario_max']) ? (int)$row['salario_max'] : null,
          (string)($row['moneda'] ?? 'COP')
        ),
        'postulado' => isset($applied[(int)$row['id']]),
      ];
      if (count($items) >= $limit) { break; }
    }

    return ['items' => $items, 'error' => null];
  }
}
if (!function_exists('dash_lower')) {

  function dash_lower(string $value): string {

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

  }

}



if (!function_exists('dash_slugify')) {

  function dash_slugify(?string $value): string {

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



if (!function_exists('dash_infer_experience_years')) {

  function dash_infer_experience_years(?string $level, ?string $text = null): ?int {

    $levelSlug = dash_slugify($level);

    if ($levelSlug !== '') {

      if (strpos($levelSlug, 'practic') !== false || strpos($levelSlug, 'aprendiz') !== false || strpos($levelSlug, 'intern') !== false) {

        return 0;

      }

      if (strpos($levelSlug, 'junior') !== false || strpos($levelSlug, 'asistent') !== false) {

        return 1;

      }

      if (strpos($levelSlug, 'semi') !== false || strpos($levelSlug, 'medio') !== false) {

        return 3;

      }

      if (strpos($levelSlug, 'senior') !== false) {

        return 5;

      }

      if (strpos($levelSlug, 'lider') !== false || strpos($levelSlug, 'director') !== false || strpos($levelSlug, 'gerente') !== false) {

        return 8;

      }

    }

    $content = trim((string)$text);

    if ($content !== '' && preg_match('/(\\d+)\\s*\\+?\\s*(?:años|años)/i', $content, $matches)) {

      return (int)$matches[1];

    }

    return null;

  }

}



$candidateKeywords = [];

foreach ($profileData['skills'] as $skillText) {

  $normalized = strtolower((string)$skillText);

  $normalized = preg_replace('/[^a-z0-9áéíóúüñ]+/u', ' ', $normalized ?? '');

  foreach (preg_split('/\s+/', trim((string)$normalized)) as $token) {

    $token = trim((string)$token);

    if ($token === '' || strlen($token) < 3) { continue; }

    $candidateKeywords[] = $token;

  }

}

if (!$candidateKeywords && !empty($profileData['headline'])) {

  foreach (preg_split('/\s+/', strtolower((string)$profileData['headline'])) as $token) {

    $token = trim((string)$token);

    if ($token === '' || strlen($token) < 3) { continue; }

    $candidateKeywords[] = $token;

  }

}

$candidateKeywords = array_values(array_unique($candidateKeywords));

$docEvidence = [
  'score' => 1.0,
  'flags' => [],
  'cv_verified' => false,
  'edu_total' => 0,
  'edu_verified' => 0,
  'exp_total' => 0,
  'exp_verified' => 0,
];
if ($pdo instanceof PDO && $docAnalyzer) {
  try {
    $docEvidence = $docAnalyzer->candidateEvidence($pdo, $userSession['email'], $profileData);
  } catch (Throwable $e) {
    error_log('[dashboard] evidence: '.$e->getMessage());
  }
}
$profileData['doc_score'] = $docEvidence['score'] ?? 1.0;
$profileData['doc_flags'] = $docEvidence['flags'] ?? [];
$profileData['cv_verified'] = $docEvidence['cv_verified'] ?? $profileData['cv_uploaded'] ?? false;


$modalidadOptions = [];
$contratoOptions = [];
$nivelOptions = [];
$areaOptions = [];
$currencyOptions = [];
if ($pdo instanceof PDO) {
  try {
    $modalidadOptions = $pdo->query('SELECT id, nombre FROM modalidades ORDER BY nombre ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $contratoOptions = $pdo->query('SELECT id, nombre FROM contratos ORDER BY nombre ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $nivelOptions = $pdo->query('SELECT id, nombre FROM niveles ORDER BY nombre ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $areaOptions = $pdo->query('SELECT id, nombre FROM areas ORDER BY nombre ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $modalidadOptions = dash_seed_modalidades($pdo, $modalidadOptions);
    $currencyOptions = $pdo->query('SELECT DISTINCT UPPER(moneda) AS moneda FROM vacantes WHERE moneda IS NOT NULL AND moneda <> "" ORDER BY moneda ASC')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    // Asegura COP y USD visibles siempre, sin duplicar
    $currencyOptions = array_values(array_unique(array_filter(array_merge(['COP'], $currencyOptions, ['USD']))));
  } catch (Throwable $e) {
    error_log('[dashboard] catalogos: '.$e->getMessage());
  }
}

$filterSearch = trim((string)($_GET['buscar'] ?? ''));

$filterModalidad = $_GET['modalidad'] ?? [];
if (!is_array($filterModalidad)) { $filterModalidad = [$filterModalidad]; }
$filterModalidad = array_values(array_filter(array_map(static fn($v) => (int)$v, $filterModalidad), static fn($v) => $v > 0));

$filterContrato = $_GET['contrato'] ?? [];
if (!is_array($filterContrato)) { $filterContrato = [$filterContrato]; }
$filterContrato = array_values(array_filter(array_map(static fn($v) => (int)$v, $filterContrato), static fn($v) => $v > 0));

$filterNivel = $_GET['nivel'] ?? [];
if (!is_array($filterNivel)) { $filterNivel = [$filterNivel]; }
$filterNivel = array_values(array_filter(array_map(static fn($v) => (int)$v, $filterNivel), static fn($v) => $v > 0));

$filterArea = $_GET['area'] ?? [];
if (!is_array($filterArea)) { $filterArea = [$filterArea]; }
$filterArea = array_values(array_filter(array_map(static fn($v) => (int)$v, $filterArea), static fn($v) => $v > 0));

$filterCurrency = strtoupper(trim((string)($_GET['moneda'] ?? '')));

$filterCiudad = trim((string)($_GET['ciudad'] ?? ''));

$filterCiudadSlug = dash_slugify($filterCiudad);



$filterSalarioInput = preg_replace('/[^0-9]/', '', (string)($_GET['salario'] ?? ''));

$filterSalario = $filterSalarioInput !== '' ? (int)$filterSalarioInput : null;

$filterSalarioDisplay = $filterSalario !== null ? number_format($filterSalario, 0, ',', '.') : '';



$searchNeedle = $filterSearch !== '' ? dash_lower($filterSearch) : '';

$filtersApplied = (
  $filterSearch !== ''
  || $filterModalidad
  || $filterContrato
  || $filterNivel
  || $filterArea
  || $filterCurrency !== ''
  || $filterCiudadSlug !== ''
  || $filterSalario !== null
);



$postulaciones = [];
$appliedVacantes = [];
$entrevistaCount = 0;



$vacantes = [];
$vacantesPool = [];

$nuevasVacantes = 0;



if ($pdo instanceof PDO) {

  try {

    $postStmt = $pdo->prepare('SELECT vacante_id, estado, match_score FROM postulaciones WHERE candidato_email = ?');

    $postStmt->execute([$userSession['email']]);

    $postulaciones = $postStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($postulaciones as $post) {

      $vacId = isset($post['vacante_id']) ? (int)$post['vacante_id'] : 0;

      $estadoPost = strtolower((string)($post['estado'] ?? ''));

      // Permitir re-aplicar si la postulación quedó en no_seleccionado/rechazado
      $estadoBloqueaReaplicar = !in_array($estadoPost, ['no_seleccionado', 'rechazado'], true);

      if ($vacId > 0 && $estadoBloqueaReaplicar) {
        $appliedVacantes[$vacId] = true;
        if (isset($post['match_score'])) {
        }
      }
      if ($estadoPost === 'entrevista') {
        $entrevistaCount++;
      }
    }

  } catch (Throwable $postsError) {

    error_log('[dashboard] postulaciones: '.$postsError->getMessage());

  }



  try {
    // Construye filtros SQL para mejorar consistencia cuando se aplican filtros de la UI
    $baseSql = 'SELECT
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
        v.modalidad_id,
        v.tipo_contrato_id,
        v.nivel_id,
        v.area_id,
        e.id AS empresa_id,
        e.razon_social AS empresa_nombre,
        m.nombre AS modalidad_nombre,
        n.nombre AS nivel_nombre,
        c.nombre AS contrato_nombre,
         a.nombre AS area_nombre
       FROM vacantes v
       LEFT JOIN empresas e ON e.id = v.empresa_id
       LEFT JOIN modalidades m ON m.id = v.modalidad_id
       LEFT JOIN niveles n ON n.id = v.nivel_id
       LEFT JOIN contratos c ON c.id = v.tipo_contrato_id
       LEFT JOIN areas a ON a.id = v.area_id
       WHERE v.estado IN ("publicada","activa","publicada ")';

    $whereParts = [];
    $params = [];

    if ($filterModalidad) {
      $place = implode(',', array_fill(0, count($filterModalidad), '?'));
      $whereParts[] = ' AND v.modalidad_id IN ('.$place.')';
      foreach ($filterModalidad as $id) { $params[] = $id; }
    }

    if ($filterContrato) {
      $place = implode(',', array_fill(0, count($filterContrato), '?'));
      $whereParts[] = ' AND v.tipo_contrato_id IN ('.$place.')';
      foreach ($filterContrato as $id) { $params[] = $id; }
    }

    if ($filterNivel) {
      $place = implode(',', array_fill(0, count($filterNivel), '?'));
      $whereParts[] = ' AND v.nivel_id IN ('.$place.')';
      foreach ($filterNivel as $id) { $params[] = $id; }
    }

    if ($filterArea) {
      $place = implode(',', array_fill(0, count($filterArea), '?'));
      $whereParts[] = ' AND v.area_id IN ('.$place.')';
      foreach ($filterArea as $id) { $params[] = $id; }
    }

    if ($filterCurrency !== '') {
      $whereParts[] = ' AND UPPER(v.moneda) = ?';
      $params[] = $filterCurrency;
    }

    if ($filterCiudadSlug !== '') {
      // Búsqueda por ciudad básica, insensible a mayúsculas
      $whereParts[] = ' AND LOWER(v.ciudad) LIKE ?';
      $params[] = '%'.strtolower($filterCiudad).'%' ;
    }

    if ($filterSalario !== null) {
      $whereParts[] = ' AND COALESCE(v.salario_min,0) >= ?';
      $params[] = $filterSalario;
    }

    // Aplica orden y límite (mayor cobertura cuando hay filtros)
    $limit = ($filterModalidad || $filterContrato || $filterNivel || $filterArea || $filterCurrency !== '' || $filterCiudadSlug !== '' || $filterSalario !== null) ? 200 : 20;
    $sql = $baseSql.implode('', $whereParts).' ORDER BY COALESCE(v.publicada_at, v.created_at) DESC LIMIT '.$limit;

    $vacStmt = $pdo->prepare($sql);
    $vacStmt->execute($params);
    if ($vacStmt) {
      while ($row = $vacStmt->fetch(PDO::FETCH_ASSOC)) {
        $tags = dash_ai_clean_skills(dash_extract_tags($row['etiquetas'] ?? null));

        $tagsLower = array_map('strtolower', $tags);

        $pubInfo = dash_publication_info($row['publicada_at'] ?? $row['created_at']);

        if ($pubInfo['is_new']) {

          $nuevasVacantes++;

        }

        $chips = array_values(array_filter([

          $row['nivel_nombre'] ?? null,

          $row['modalidad_nombre'] ?? null,

          $row['contrato_nombre'] ?? null,

        ]));

        $metaParts = array_filter([

          $row['modalidad_nombre'] ?? null,

          $row['ciudad'] ?? null,

          $pubInfo['meta'],

        ]);

        $common = [];

        if ($tags) {

          $intersection = array_intersect($tagsLower, $candidateKeywords);

          if ($intersection) {

            foreach ($intersection as $token) {

              foreach ($tags as $tagOriginal) {

                if (strtolower($tagOriginal) === $token) {

                  $common[] = $tagOriginal;

                  break;

                }

              }

            }

          }

        }

        if (!$common) {

          $common = array_slice($tags, 0, 4);

        }



        $row['tags_lower'] = $tagsLower;

        $matchScore = dash_match_score($row, $candidateKeywords, $profileData);
        $docFactor = isset($profileData['doc_score']) ? (float)$profileData['doc_score'] : 1.0;
        $matchScore = max(0.0, min(100.0, $matchScore * $docFactor));

        $ciudadSlug = dash_slugify($row['ciudad'] ?? '');

        $salarioMinRaw = isset($row['salario_min']) ? (int)$row['salario_min'] : null;

        $salarioMaxRaw = isset($row['salario_max']) ? (int)$row['salario_max'] : null;

        $searchParts = array_filter([

          $row['titulo'] ?? '',

          $row['empresa_nombre'] ?? '',

          implode(' ', $tags),

          $row['ciudad'] ?? '',

          $row['descripcion'] ?? '',

          $row['requisitos'] ?? '',

        ]);

        $searchBlob = $searchParts ? dash_lower(implode(' ', $searchParts)) : '';

        $filterMatchScore = 0;
        if ($filterModalidad && in_array((int)($row['modalidad_id'] ?? 0), $filterModalidad, true)) { $filterMatchScore += 12; }
        if ($filterContrato && in_array((int)($row['tipo_contrato_id'] ?? 0), $filterContrato, true)) { $filterMatchScore += 10; }
        if ($filterNivel && in_array((int)($row['nivel_id'] ?? 0), $filterNivel, true)) { $filterMatchScore += 10; }
        if ($filterArea && in_array((int)($row['area_id'] ?? 0), $filterArea, true)) { $filterMatchScore += 10; }
        if ($filterCurrency !== '' && strtoupper((string)($row['moneda'] ?? '')) === $filterCurrency) { $filterMatchScore += 8; }
        if ($filterSalario !== null && $salarioMinRaw !== null && $salarioMinRaw >= $filterSalario) { $filterMatchScore += 6; }
        if ($searchNeedle !== '' && $searchBlob !== '' && strpos($searchBlob, $searchNeedle) !== false) { $filterMatchScore += 5; }
        $sortScore = (float)$matchScore + (float)$filterMatchScore;

        $vacantes[] = [

          'id' => (int)$row['id'],

          'titulo' => $row['titulo'] ?? 'Oferta sin título',

          'empresa' => $row['empresa_nombre'] ?? 'Empresa confidencial',
          'empresa_id' => isset($row['empresa_id']) ? (int)$row['empresa_id'] : null,

          'meta_line' => $metaParts ? implode(' · ', $metaParts) : $pubInfo['meta'],

          'badge' => $pubInfo['badge'],

          'chips' => $chips,

          'descripcion' => dash_truncate_text($row['descripcion'] ?? $row['requisitos'] ?? ''),

          'coincidencias' => $common,

          'match' => $matchScore,

          'salario' => dash_format_salary($salarioMinRaw, $salarioMaxRaw, (string)($row['moneda'] ?? 'COP')),

          'postulado' => isset($appliedVacantes[(int)$row['id']]),

          'filter_modalidad' => isset($row['modalidad_id']) ? (int)$row['modalidad_id'] : null,

          'filter_contrato' => isset($row['tipo_contrato_id']) ? (int)$row['tipo_contrato_id'] : null,

          'filter_nivel' => isset($row['nivel_id']) ? (int)$row['nivel_id'] : null,

          'filter_area' => isset($row['area_id']) ? (int)$row['area_id'] : null,

          'filter_ciudad' => $ciudadSlug,

          'filter_currency' => strtoupper((string)($row['moneda'] ?? '')),

          'filter_salary' => $salarioMinRaw,

          'filter_search' => $searchBlob,

          'sort_score' => $sortScore,

        ];

      }

    }
    // Guarda dataset estricto para fallback
    $vacantesPool = $vacantes;
    // Si no hubo resultados estrictos y hay filtros de modalidad/contrato, relaja la consulta (sin esos filtros)
    if (!$vacantes && ($filterModalidad || $filterContrato)) {
      $whereRelax = [];
      $paramsRelax = [];
      if ($filterCiudadSlug !== '') { $whereRelax[] = ' AND LOWER(v.ciudad) LIKE ?'; $paramsRelax[] = '%'.strtolower($filterCiudad).'%'; }
      if ($filterSalario !== null) { $whereRelax[] = ' AND COALESCE(v.salario_min,0) >= ?'; $paramsRelax[] = $filterSalario; }
      $sqlRelax = $baseSql.implode('', $whereRelax).' ORDER BY COALESCE(v.publicada_at, v.created_at) DESC LIMIT 200';
      $vacStmt2 = $pdo->prepare($sqlRelax);
      $vacStmt2->execute($paramsRelax);
      $vacantesPool = [];
      while ($row = $vacStmt2->fetch(PDO::FETCH_ASSOC)) {
        $tags = dash_ai_clean_skills(dash_extract_tags($row['etiquetas'] ?? null));
        $tagsLower = array_map('strtolower', $tags);
        $pubInfo = dash_publication_info($row['publicada_at'] ?? $row['created_at']);
        if ($pubInfo['is_new']) { $nuevasVacantes++; }
        $chips = array_values(array_filter([
          $row['nivel_nombre'] ?? null,
          $row['modalidad_nombre'] ?? null,
          $row['contrato_nombre'] ?? null,
        ]));
        $metaParts = array_filter([
          $row['modalidad_nombre'] ?? null,
          $row['ciudad'] ?? null,
          $pubInfo['meta'],
        ]);
        $common = [];
        if ($tags) {
          $intersection = array_intersect($tagsLower, $candidateKeywords);
          if ($intersection) {
            foreach ($intersection as $token) {
              foreach ($tags as $tagOriginal) {
                if (strtolower($tagOriginal) === $token) { $common[] = $tagOriginal; break; }
              }
            }
          }
        }
        if (!$common) { $common = array_slice($tags, 0, 4); }
        $row['tags_lower'] = $tagsLower;
        $matchScore = dash_match_score($row, $candidateKeywords, $profileData);
        $docFactor = isset($profileData['doc_score']) ? (float)$profileData['doc_score'] : 1.0;
        $matchScore = max(0.0, min(100.0, $matchScore * $docFactor));
        $ciudadSlug = dash_slugify($row['ciudad'] ?? '');
        $salarioMinRaw = isset($row['salario_min']) ? (int)$row['salario_min'] : null;
        $salarioMaxRaw = isset($row['salario_max']) ? (int)$row['salario_max'] : null;
        $searchParts = array_filter([
          $row['titulo'] ?? '',
          $row['empresa_nombre'] ?? '',
          implode(' ', $tags),
          $row['ciudad'] ?? '',
          $row['descripcion'] ?? '',
          $row['requisitos'] ?? '',
        ]);
        $searchBlob = $searchParts ? dash_lower(implode(' ', $searchParts)) : '';

        $filterMatchScore = 0;
        if ($filterModalidad && in_array((int)($row['modalidad_id'] ?? 0), $filterModalidad, true)) { $filterMatchScore += 12; }
        if ($filterContrato && in_array((int)($row['tipo_contrato_id'] ?? 0), $filterContrato, true)) { $filterMatchScore += 10; }
        if ($filterNivel && in_array((int)($row['nivel_id'] ?? 0), $filterNivel, true)) { $filterMatchScore += 10; }
        if ($filterArea && in_array((int)($row['area_id'] ?? 0), $filterArea, true)) { $filterMatchScore += 10; }
        if ($filterCurrency !== '' && strtoupper((string)($row['moneda'] ?? '')) === $filterCurrency) { $filterMatchScore += 8; }
        if ($filterSalario !== null && $salarioMinRaw !== null && $salarioMinRaw >= $filterSalario) { $filterMatchScore += 6; }
        if ($searchNeedle !== '' && $searchBlob !== '' && strpos($searchBlob, $searchNeedle) !== false) { $filterMatchScore += 5; }
        $sortScore = (float)$matchScore + (float)$filterMatchScore;
        $vacantesPool[] = [
          'id' => (int)$row['id'],
          'titulo' => $row['titulo'] ?? 'Oferta sin título',
          'empresa' => $row['empresa_nombre'] ?? 'Empresa confidencial',
          'empresa_id' => isset($row['empresa_id']) ? (int)$row['empresa_id'] : null,
          'meta_line' => $metaParts ? implode(' · ', $metaParts) : $pubInfo['meta'],
          'badge' => $pubInfo['badge'],
          'chips' => $chips,
          'descripcion' => dash_truncate_text($row['descripcion'] ?? $row['requisitos'] ?? ''),
          'coincidencias' => $common,
          'match' => $matchScore,
          'salario' => dash_format_salary($salarioMinRaw, $salarioMaxRaw, (string)($row['moneda'] ?? 'COP')),
          'postulado' => isset($appliedVacantes[(int)$row['id']]),
          'filter_modalidad' => isset($row['modalidad_id']) ? (int)$row['modalidad_id'] : null,
          'filter_contrato' => isset($row['tipo_contrato_id']) ? (int)$row['tipo_contrato_id'] : null,
          'filter_nivel' => isset($row['nivel_id']) ? (int)$row['nivel_id'] : null,
          'filter_area' => isset($row['area_id']) ? (int)$row['area_id'] : null,
          'filter_ciudad' => $ciudadSlug,
          'filter_currency' => strtoupper((string)($row['moneda'] ?? '')),
          'filter_salary' => $salarioMinRaw,
          'filter_search' => $searchBlob,
          'sort_score' => $sortScore,
        ];
      }
    }
  } catch (Throwable $vacError) {
    error_log('[dashboard] vacantes: '.$vacError->getMessage());
  }
}


$vacantesBase = $vacantes ?: $vacantesPool;
$vacantesAll = $vacantesBase; // dataset previo a filtros en memoria
$vacantes = $vacantesBase

  ? array_values(array_filter(

      $vacantesBase,

      static function (array $vac) use (

        $filterModalidad,

        $filterContrato,

        $filterNivel,

        $filterArea,

        $filterCurrency,

        $filterCiudadSlug,

        $filterSalario,

        $searchNeedle

      ): bool {

        if ($filterModalidad && !in_array((int)($vac['filter_modalidad'] ?? 0), $filterModalidad, true)) {

          return false;

        }

        if ($filterContrato && !in_array((int)($vac['filter_contrato'] ?? 0), $filterContrato, true)) {

          return false;

        }

        if ($filterNivel && !in_array((int)($vac['filter_nivel'] ?? 0), $filterNivel, true)) {

          return false;

        }

        if ($filterArea && !in_array((int)($vac['filter_area'] ?? 0), $filterArea, true)) {

          return false;

        }

        if ($filterCurrency !== '') {

          $vacCurrency = strtoupper((string)($vac['filter_currency'] ?? ''));

          if ($vacCurrency !== $filterCurrency) {

            return false;

          }

        }

        if ($filterCiudadSlug !== '' && strpos((string)($vac['filter_ciudad'] ?? ''), $filterCiudadSlug) === false) {

          return false;

        }

        if ($filterSalario !== null) {

          $minSalary = $vac['filter_salary'] ?? null;

          if ($minSalary === null || $minSalary < $filterSalario) {

            return false;

          }

        }

        if ($searchNeedle !== '' && strpos((string)($vac['filter_search'] ?? ''), $searchNeedle) === false) {

          return false;

        }

        return true;

      }

    ))

  : [];

if ($vacantes) {
  usort(
    $vacantes,
    static function (array $a, array $b): int {
      $matchCmp = ($b['match'] ?? 0) <=> ($a['match'] ?? 0);
      if ($matchCmp !== 0) { return $matchCmp; }
      $scoreA = $a['sort_score'] ?? 0;
      $scoreB = $b['sort_score'] ?? 0;
      return $scoreB <=> $scoreA;
    }
  );
}



$aiResult = ['items' => [], 'error' => null];
$vacantesFeed = $vacantes;
if (!$filtersApplied && $pdo instanceof PDO) {
  $aiResult = dash_ai_recommendations($pdo, $profileData, $userSession['email'], $appliedVacantes, 8);
  if (!empty($aiResult['items'])) {
    $vacantesFeed = $aiResult['items'];
  }
}
$aiError = $aiResult['error'] ?? null;

// Ordena feed por mayor match primero
if ($vacantesFeed) {
  usort($vacantesFeed, static fn($a, $b) => (($b['match'] ?? 0) <=> ($a['match'] ?? 0)));
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 4;
$totalVac = count($vacantesFeed);
$totalPages = max(1, (int)ceil($totalVac / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;
$vacantesPage = array_slice($vacantesFeed, $offset, $perPage);
$pageQuery = $_GET;
$pageQuery['view'] = 'dashboard';
unset($pageQuery['page']);
$buildPageUrl = function (int $p) use ($pageQuery): string {
  $q = $pageQuery;
  $q['page'] = $p;
  return 'index.php?'.http_build_query($q);
};

$kpiData = [

  'nuevas' => $nuevasVacantes,

  'postulaciones' => count($postulaciones),

  'entrevistas' => $entrevistaCount,

  'guardadas' => 0,

];



$skillHighlights = $profileData['skills'] ? array_slice($profileData['skills'], 0, 3) : [];

$modalidadLabel = $profileData['modalidad'] ?? null;

$levelLabel = $profileData['level'] ?? null;

// Checklist dinamico del perfil
$hasSummary = trim((string)($profileData['summary'] ?? '')) !== '';
$hasSkills = !empty($profileData['skills']);
$hasCity = !empty($profileData['city']);

if ($pdo instanceof PDO) {
  try {
    $cvStmtDash = $pdo->prepare('SELECT id FROM candidato_documentos WHERE LOWER(email) = LOWER(?) AND tipo = "cv" ORDER BY uploaded_at DESC LIMIT 1');
    $cvStmtDash->execute([$userSession['email']]);
    $profileData['cv_uploaded'] = (bool)$cvStmtDash->fetchColumn();
  } catch (Throwable $e) {
    error_log('[dashboard] cv status: '.$e->getMessage());
  }
  try {
    $certStmt1 = $pdo->prepare('SELECT COUNT(*) FROM candidato_experiencia_certificados c JOIN candidato_documentos d ON d.id = c.documento_id WHERE LOWER(d.email) = LOWER(?)');
    $certStmt2 = $pdo->prepare('SELECT COUNT(*) FROM candidato_educacion_certificados c JOIN candidato_documentos d ON d.id = c.documento_id WHERE LOWER(d.email) = LOWER(?)');
    $certTotal = 0;
    if ($certStmt1 && $certStmt2) {
      $certStmt1->execute([$userSession['email']]);
      $certStmt2->execute([$userSession['email']]);
      $certTotal = (int)$certStmt1->fetchColumn() + (int)$certStmt2->fetchColumn();
    }
    $profileData['certifications'] = $certTotal;
  } catch (Throwable $e) {
    error_log('[dashboard] cert status: '.$e->getMessage());
  }
}

$checkItems = [
  $profileData['cv_uploaded'],
  $profileData['email_verified'],
  $profileData['certifications'] >= 2,
  $hasSkills,
  $hasSummary,
  $hasCity,
];
$checkCount = count($checkItems);
$checkDone = array_sum(array_map(static fn($v) => $v ? 1 : 0, $checkItems));
$progressPct = $checkCount > 0 ? (int)round(($checkDone / $checkCount) * 100) : 0;
$progressPct = max(10, min(100, $progressPct));
$certMissing = max(0, 2 - (int)$profileData['certifications']);

?>

<!-- ===== Head / Preferencias ===== -->

  <section class="container section">

    <div class="dash-head">

      <div>

        <h1>Hola, <?=htmlspecialchars($profileData['firstName'] ?? $profileData['fullName'], ENT_QUOTES, 'UTF-8');?></h1>

        <p class="muted">Estas son tus <strong>recomendaciones personalizadas</strong> según tu perfil y preferencias.</p>

      </div>

      <div class="pref-chips">

      <?php if (!empty($profileData['chips'])): ?>

        <?php foreach ($profileData['chips'] as $chip): ?>

          <span class="chip"><?=htmlspecialchars($chip, ENT_QUOTES, 'UTF-8');?></span>

        <?php endforeach; ?>

        <?php else: ?>

          <span class="chip">Completa tu perfil para mejores coincidencias</span>

        <?php endif; ?>

      </div>

    </div>

  </section>



  <!-- ===== KPIs ===== -->

  <section class="container">

    <div class="kpis">

      <div class="kpi card">

        <span class="kpi-label">Nuevas esta semana</span>

        <span class="kpi-value"><?=number_format((int)$kpiData['nuevas']); ?></span>

      </div>

      <div class="kpi card">

        <span class="kpi-label">Postulaciones activas</span>

        <span class="kpi-value"><?=number_format((int)$kpiData['postulaciones']); ?></span>

      </div>

      <div class="kpi card">

        <span class="kpi-label">Entrevistas</span>

        <span class="kpi-value"><?=number_format((int)$kpiData['entrevistas']); ?></span>

      </div>

      <div class="kpi card">

        <span class="kpi-label">Guardadas</span>

        <span class="kpi-value"><?=number_format((int)$kpiData['guardadas']); ?></span>

      </div>

    </div>

  </section>

  <style>
    .filters .field-head{display:flex;justify-content:space-between;align-items:center;gap:.35rem;}
    .filters .hint{font-size:.85rem;color:#6b7280;}
    .filters .pill-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.35rem;margin-top:.25rem;}
    .filters .pill-grid-tight{grid-template-columns:repeat(auto-fit,minmax(100px,1fr));}
    .filters .pill-scroll{max-height:220px;overflow-y:auto;padding-right:.25rem;}
    .filters .pill-check{position:relative;display:flex;align-items:center;}
    .filters .pill-check input{position:absolute;opacity:0;left:0;top:0;}
    .filters .pill-check span{display:block;width:100%;text-align:center;border:1px solid #e6e8ed;border-radius:999px;padding:.4rem .6rem;background:#f9fafb;transition:all .15s ease;font-size:.95rem;line-height:1.25;word-break:break-word;white-space:normal;box-sizing:border-box;}
    .filters .pill-check input:checked + span{background:#e8f5ee;border-color:#2dc26b;color:#0f5132;font-weight:600;box-shadow:0 0 0 1px #2dc26b inset;}
    .filters .pill-check span:hover{border-color:#d1d5db;}

    /* Estilo suave para la tarjeta de filtros */
    .filters .card{
      background:#ffffff;
      border-radius:16px;
      border:1px solid #e6e8ed;
      box-shadow:0 6px 18px rgba(0,0,0,0.04);
    }
    .filters h3{color:#1f2937;}
    .filters .field label{font-weight:700;color:#1f2937;}
    .filters input[type="text"], .filters input[type="search"], .filters select{
      border-radius:12px;
      border:1px solid #e6e8ed;
      background:#ffffff;
      padding-inline:14px;
    }
    .filters .check{
      display:flex; align-items:center; gap:.5rem; padding:.3rem .5rem;
      border-radius:12px;
      transition:background .2s ease,border-color .2s ease;
      border:1px solid transparent;
    }
    .filters .check:hover{background:#f5f8fb;border-color:#d5dfe9;}
    .filters .btn.full{
      border-radius:12px;
      background:#ffffff;
      color:#1f9b1f;
      border:1px solid #2f8f36;
      font-weight:700;
      text-shadow:-0.4px -0.4px 0 #c58c00, 0.4px 0.4px 0 #c58c00;
      box-shadow:0 4px 10px rgba(47,143,54,0.12);
    }
    .btn-important{
      color:#1f9b1f !important;
      font-weight:700;
      text-shadow:-0.4px -0.4px 0 #c58c00, 0.4px 0.4px 0 #c58c00;
    }

    /* Ajuste de botones en cards de vacantes */
    .job .row-cta.row-cta-tight{
      gap:8px;
      flex-wrap:nowrap;
      align-items:center;
    }
    .job .row-cta.row-cta-tight .btn{
      padding:0.35rem 0.9rem;
      min-height:38px;
      font-size:0.92rem;
      white-space:nowrap;
    }
    .job .row-cta.row-cta-tight .chip{
      padding:0.35rem 0.9rem;
      white-space:nowrap;
    }
    .pager{
      display:flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      flex-wrap:wrap;
      margin-top:12px;
    }
    .pager .btn{
      border-radius:12px;
      border:1px solid #2f8f36;
      color:#1f9b1f;
      background:#fff;
      text-shadow:-0.4px -0.4px 0 #c58c00, 0.4px 0.4px 0 #c58c00;
      box-shadow:0 4px 10px rgba(47,143,54,0.12);
    }
    .pager select{
      border-radius:10px;
      padding:6px 10px;
      border:1px solid #e6e8ed;
    }
  </style>



  <!-- ===== Layout principal ===== -->

  <main class="container section layout">

    <!-- ===== Columna izquierda: Filtros ===== -->

    <aside class="filters">

      <form class="card" method="get">

        <input type="hidden" name="view" value="dashboard" />

        <h3>Filtros</h3>

        <div class="field">

          <label for="buscador">Buscar</label>

          <input id="buscador" name="buscar" type="text" placeholder="Cargo, habilidad..." value="<?=htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8'); ?>" />

        </div>



        <div class="field">

          <label>Modalidad</label>

          <?php foreach ($modalidadOptions as $opt): ?>

            <label class="check">

              <input type="checkbox" name="modalidad[]" value="<?= (int)($opt['id'] ?? 0); ?>" <?=in_array((int)($opt['id'] ?? 0), $filterModalidad, true) ? 'checked' : ''; ?> />

              <?=htmlspecialchars($opt['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>

            </label>

          <?php endforeach; ?>

        </div>



        <div class="field">

          <label>Tipo de contrato</label>

          <?php foreach ($contratoOptions as $opt): ?>

            <label class="check">

              <input type="checkbox" name="contrato[]" value="<?= (int)($opt['id'] ?? 0); ?>" <?=in_array((int)($opt['id'] ?? 0), $filterContrato, true) ? 'checked' : ''; ?> />

              <?=htmlspecialchars($opt['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>

            </label>

          <?php endforeach; ?>

        </div>



        <div class="field">

          <div class="field-head">
            <label>Nivel</label>
            <span class="hint">Selecciona uno o varios</span>
          </div>

          <div class="pill-grid">
            <?php foreach ($nivelOptions as $opt): ?>
              <label class="pill-check">
                <input type="checkbox" name="nivel[]" value="<?= (int)($opt['id'] ?? 0); ?>" <?=in_array((int)($opt['id'] ?? 0), $filterNivel, true) ? 'checked' : ''; ?> />
                <span><?=htmlspecialchars($opt['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
              </label>
            <?php endforeach; ?>
          </div>

        </div>



        <div class="field">

          <div class="field-head">
            <label>Area</label>
            <span class="hint">Puedes elegir varias</span>
          </div>

          <div class="pill-scroll">
            <div class="pill-grid">
              <?php foreach ($areaOptions as $opt): ?>
                <label class="pill-check">
                  <input type="checkbox" name="area[]" value="<?= (int)($opt['id'] ?? 0); ?>" <?=in_array((int)($opt['id'] ?? 0), $filterArea, true) ? 'checked' : ''; ?> />
                  <span><?=htmlspecialchars($opt['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

        </div>



        <div class="field">

          <label for="salario">Salario objetivo</label>

          <input id="salario" name="salario" type="text" placeholder="$2.000.000" value="<?=htmlspecialchars($filterSalarioDisplay, ENT_QUOTES, 'UTF-8'); ?>" />

        </div>



        <div class="field">

          <label>Moneda</label>

          <div class="pill-grid pill-grid-tight">
            <label class="pill-check">
              <input type="radio" name="moneda" value="" <?= $filterCurrency === '' ? 'checked' : ''; ?> />
              <span>Cualquiera</span>
            </label>
            <?php foreach ($currencyOptions as $cur): ?>
              <?php $curCode = strtoupper((string)$cur); ?>
              <label class="pill-check">
                <input type="radio" name="moneda" value="<?=htmlspecialchars($curCode, ENT_QUOTES, 'UTF-8'); ?>" <?= $filterCurrency === $curCode ? 'checked' : ''; ?> />
                <span><?=htmlspecialchars($curCode, ENT_QUOTES, 'UTF-8'); ?></span>
              </label>
            <?php endforeach; ?>
          </div>

        </div>



        <div class="field">

          <label for="ciudad">Ciudad</label>

          <input id="ciudad" name="ciudad" type="text" placeholder="Bogotá" value="<?=htmlspecialchars($filterCiudad, ENT_QUOTES, 'UTF-8'); ?>" />

        </div>



        <div class="field">

          <button type="submit" class="btn btn-outline full btn-important">Aplicar filtros</button>

          <?php if ($filtersApplied): ?>

            <a class="link-edit" href="?view=dashboard">Limpiar</a>

          <?php endif; ?>

        </div>

      </form>

      <div class="card tips">

        <h3>Consejo</h3>

        <p class="muted">Mejora tu match agregando 3–5 <strong>habilidades clave</strong> y actualizando tu CV.</p>

    </div>

  </aside>

  <?php $flashSave = $_SESSION['flash_dashboard_save'] ?? null; unset($_SESSION['flash_dashboard_save']); ?>
  <?php if ($flashSave): ?>
    <div class="card" style="margin-bottom:1rem; border-color:#d6f5d6; background:#f6fff6;">
      <strong><?=htmlspecialchars($flashSave, ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>
  <?php endif; ?>



    <!-- ===== Feed central: Recomendaciones IA ===== -->

    <section class="feed">

      <div class="feed-head">

        <h2>Recomendadas para ti (IA)</h2>

        <?php

          $feedSummaryParts = [];

          if ($skillHighlights) {

            $feedSummaryParts[] = 'habilidades (<strong>'.htmlspecialchars(implode(', ', $skillHighlights), ENT_QUOTES, 'UTF-8').'</strong>)';

          }

          if ($modalidadLabel) {

            $feedSummaryParts[] = 'modalidad <strong>'.htmlspecialchars($modalidadLabel, ENT_QUOTES, 'UTF-8').'</strong>';

          }

          if ($levelLabel) {

            $feedSummaryParts[] = 'nivel <strong>'.htmlspecialchars($levelLabel, ENT_QUOTES, 'UTF-8').'</strong>';

          }

        ?>

        <p class="muted">

          Basadas en

          <?php if ($feedSummaryParts): ?>

            <?=implode(', ', $feedSummaryParts); ?>.

          <?php else: ?>

            tu perfil. Completa tus preferencias para mejores coincidencias.

          <?php endif; ?>

        </p>

      </div>

      <?php if (!empty($aiError)): ?>
        <article class="card">
          <h3>Recomendaciones IA no disponibles</h3>
          <p class="muted m-0"><?=htmlspecialchars($aiError, ENT_QUOTES, 'UTF-8'); ?></p>
        </article>
      <?php endif; ?>
      <?php if ($vacantesPage): ?>

        <?php foreach ($vacantesPage as $vacante): ?>

          <article class="job card">

            <div class="job-head">

              <h3><?=htmlspecialchars($vacante['titulo'], ENT_QUOTES, 'UTF-8'); ?></h3>

              <?php if (!empty($vacante['badge'])): ?>

                <span class="badge<?=strtolower($vacante['badge']) === 'nueva' ? ' new' : ''; ?>"><?=htmlspecialchars($vacante['badge'], ENT_QUOTES, 'UTF-8'); ?></span>

              <?php endif; ?>

            </div>

            <div class="meta" style="display:flex; flex-wrap:wrap; gap:.45rem; align-items:center;">
              <span><strong><?=htmlspecialchars($vacante['empresa'], ENT_QUOTES, 'UTF-8'); ?></strong> · <?=htmlspecialchars($vacante['meta_line'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>



            <?php
              $matchVal = isset($vacante['match']) ? (float)$vacante['match'] : 0.0;
              $matchVal = max(0.0, min(100.0, $matchVal));
              $matchText = number_format($matchVal, 1);
            ?>
            <div class="match">
              <div class="match-bar"><span style="width: <?=$matchText; ?>%;"></span></div>
              <span class="match-label">Match <?=$matchText; ?>%</span>
            </div>



            <?php if (!empty($vacante['chips'])): ?>

              <div class="tags">

                <?php foreach ($vacante['chips'] as $chip): ?>

                  <span class="chip"><?=htmlspecialchars($chip, ENT_QUOTES, 'UTF-8'); ?></span>

                <?php endforeach; ?>

              </div>

            <?php endif; ?>



            <p class="desc"><?=htmlspecialchars($vacante['descripcion'], ENT_QUOTES, 'UTF-8'); ?></p>



            <div class="why">

              <span class="why-title">Coincidencias:</span>

              <?php if (!empty($vacante['coincidencias'])): ?>

                <?php foreach ($vacante['coincidencias'] as $item): ?>

                  <span class="why-item"><?=htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></span>

                <?php endforeach; ?>

              <?php else: ?>

                <span class="why-item">Revisa la descripción</span>

              <?php endif; ?>

              <span class="why-title">Salario:</span>

              <span class="why-item"><?=htmlspecialchars($vacante['salario'], ENT_QUOTES, 'UTF-8'); ?></span>

            </div>



            <div class="row-cta row-cta-tight">
              <?php if (!empty($vacante['empresa_id'])): ?>
                <a class="btn btn-ghost" href="index.php?view=PerfilEmpresaVistaCandidato&empresa_id=<?= (int)$vacante['empresa_id']; ?>">Ver Empresa</a>
              <?php endif; ?>

              <a class="btn btn-ghost" href="index.php?view=oferta_detalle&id=<?=$vacante['id']; ?>">Ver detalle</a>

              <a class="btn btn-outline" href="index.php?view=dashboard&save=<?=$vacante['id']; ?>">Guardar</a>

              <?php if (!empty($vacante['postulado'])): ?>

                <span class="chip muted">Ya postulaste</span>

              <?php else: ?>

                <a class="btn btn-brand" href="index.php?view=oferta_detalle&id=<?=$vacante['id']; ?>&apply=1">Postular</a>

              <?php endif; ?>

            </div>

          </article>

        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
          <nav class="pager" role="navigation" aria-label="Paginación">
            <?php if ($page > 1): ?>
              <a class="btn" href="<?=htmlspecialchars($buildPageUrl($page-1), ENT_QUOTES, 'UTF-8'); ?>">Anterior</a>
            <?php endif; ?>
            <form method="get" class="pager-select" onsubmit="return true;" style="display:flex; gap:6px; align-items:center;">
              <?php foreach ($pageQuery as $k => $v): ?>
                <?php if (is_array($v)): ?>
                  <?php foreach ($v as $vv): ?>
                    <input type="hidden" name="<?=htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>[]" value="<?=htmlspecialchars($vv, ENT_QUOTES, 'UTF-8'); ?>" />
                  <?php endforeach; ?>
                <?php else: ?>
                  <input type="hidden" name="<?=htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>" value="<?=htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); ?>" />
                <?php endif; ?>
              <?php endforeach; ?>
              <label class="muted" for="pager-page">Página</label>
              <select id="pager-page" name="page">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                  <option value="<?=$p; ?>" <?=$p === $page ? 'selected' : ''; ?>><?=$p; ?> de <?=$totalPages; ?></option>
                <?php endfor; ?>
              </select>
              <button type="submit" class="btn">Ir</button>
            </form>
            <?php if ($page < $totalPages): ?>
              <a class="btn" href="<?=htmlspecialchars($buildPageUrl($page+1), ENT_QUOTES, 'UTF-8'); ?>">Siguiente</a>
            <?php endif; ?>
          </nav>
        <?php endif; ?>

      <?php else: ?>

        <article class="card">

          <h3><?= $filtersApplied ? 'No encontramos ofertas con esos filtros' : 'No encontramos ofertas publicadas'; ?></h3>

          <p class="muted m-0">

            <?= $filtersApplied

              ? 'Ajusta los filtros o límpialos para ver más oportunidades.'

              : 'Actualiza tus preferencias o vuelve más tarde para ver nuevas oportunidades.'; ?>

          </p>

        </article>

      <?php endif; ?>

    </section>



    <!-- ===== Columna derecha: Resumen de perfil ===== -->

    <aside class="profile">

      <div class="card profile-card" id="perfil">

        <?php

          $avatarStyle = '';

          if (!empty($profileData['photo'])) {

            $src = htmlspecialchars($profileData['photo'], ENT_QUOTES, 'UTF-8');

            $avatarStyle = " style=\"background-image:url('{$src}')\"";

          }

          $headlineParts = array_filter([

            $profileData['headline'],

            $profileData['level'],

          ]);

          $headlineText = $headlineParts ? implode(' · ', $headlineParts) : 'Completa tu perfil profesional';

        ?>

        <div class="profile-head">

          <div class="avatar"<?php echo $avatarStyle; ?>></div>

          <div>

            <h3><?=htmlspecialchars($profileData['fullName'], ENT_QUOTES, 'UTF-8');?></h3>

            <p class="muted"><?=htmlspecialchars($headlineText, ENT_QUOTES, 'UTF-8');?></p>

          </div>

        </div>



        <div class="progress">
          <div class="progress-bar"><span style="width:<?=$progressPct;?>%"></span></div>
          <div class="progress-label">Perfil <?=$progressPct; ?>% completo</div>
        </div>



        <div class="pills">

          <?php if (!empty($profileData['pills'])): ?>

            <?php foreach ($profileData['pills'] as $pill): ?>

              <span class="pill"><?=htmlspecialchars($pill, ENT_QUOTES, 'UTF-8');?></span>

            <?php endforeach; ?>

          <?php else: ?>

            <span class="pill">Completa tu perfil</span>

          <?php endif; ?>

        </div>



        <div class="skills">

          <h4>Habilidades</h4>

          <div class="tags">

            <?php if (!empty($profileData['skills'])): ?>

              <?php foreach ($profileData['skills'] as $skill): ?>

                <span class="chip"><?=htmlspecialchars($skill, ENT_QUOTES, 'UTF-8');?></span>

              <?php endforeach; ?>

            <?php else: ?>

              <span class="chip">Añade habilidades clave</span>

            <?php endif; ?>

          </div>

        </div>



        <ul class="checklist">
          <li class="<?= $profileData['cv_uploaded'] ? 'ok' : 'warn'; ?>">
            <?= $profileData['cv_uploaded'] ? 'CV actualizado' : 'Sube tu CV'; ?>
          </li>
          <li class="<?= $profileData['email_verified'] ? 'ok' : 'warn'; ?>">
            <?= $profileData['email_verified'] ? 'Correo verificado' : 'Verifica tu correo'; ?>
          </li>
          <li class="<?= $profileData['certifications'] >= 2 ? 'ok' : 'warn'; ?>">
            <?= $profileData['certifications'] >= 2
              ? 'Certificaciones ('.$profileData['certifications'].')'
              : 'Agrega '.($certMissing ?: 1).' certificacion'.(($certMissing ?: 1) === 1 ? '' : 'es'); ?>
          </li>
          <li class="<?= $hasSkills ? 'ok' : 'warn'; ?>">
            <?= $hasSkills ? 'Habilidades registradas ('.count($profileData['skills']).')' : 'Anade habilidades clave'; ?>
          </li>
          <li class="<?= $hasSummary ? 'ok' : 'warn'; ?>">
            <?= $hasSummary ? 'Resumen listo' : 'Completa tu resumen'; ?>
          </li>
        </ul>

        <?php if (!empty($profileData['doc_flags'])): ?>
          <div class="muted" style="font-size:0.9rem;">
            <?php foreach ($profileData['doc_flags'] as $flag): ?>
              <div>• <?=htmlspecialchars($flag, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>



        

      </div>



      <div class="card alerts">

        <h3>Alertas de empleo</h3>

        <p class="muted">Recibe un resumen semanal por correo con nuevas coincidencias.</p>

        <a class="btn btn-brand full" href="#activar">Activar alertas</a>

      </div>

    </aside>

  </main>





