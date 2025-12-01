<?php

if (session_status() === PHP_SESSION_NONE) { session_start(); }



$userSession = $_SESSION['user'] ?? null;

if (!$userSession || ($userSession['type'] ?? '') !== 'persona') {

  header('Location: index.php?view=login');

  exit;

}



require_once __DIR__.'/db.php';
// Cliente OpenAI para embeddings
$openaiClientFile = dirname(__DIR__).'/lib/OpenAIClient.php';
if (is_file($openaiClientFile)) {
  require_once $openaiClientFile;
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

    'SELECT c.nombres, c.apellidos, c.ciudad,

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

    if (!empty($row['foto_ruta'])) {

      $profileData['photo'] = $row['foto_ruta'];

    }



    $skillsStmt = $pdo->prepare('SELECT nombre, anios_experiencia FROM candidato_habilidades WHERE email = ? ORDER BY anios_experiencia DESC, nombre ASC');

    $skillsStmt->execute([$userSession['email']]);

    $skillRecords = $skillsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($skillRecords) {

      $skills = [];

      foreach ($skillRecords as $skill) {

        $name = trim((string)$skill['nombre']);

        if ($name === '') { continue; }

        $years = $skill['anios_experiencia'];

        if ($years !== null && $years !== '') {

          $yearsFloat = (float)$years;

          $yearsLabel = rtrim(rtrim(number_format($yearsFloat, 1, '.', ''), '0'), '.');

          $skills[] = sprintf('%s · %s %s', $name, $yearsLabel, $yearsFloat == 1.0 ? 'año' : 'años');

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

    $text = trim((string)$text);

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

   * @param array<string,mixed> $vacante

   * @param string[] $keywords

   * @param array<string,mixed> $profile

   */

  function dash_match_score(array $vacante, array $keywords, array $profile): int {

    // Valor predeterminado (placeholder) mientras se integra la IA real.

    // Ajusta este número si deseas un default distinto a 0.

    $DEFAULT_MATCH = 0;

    return $DEFAULT_MATCH;

  }

}


if (!function_exists('dash_ai_norm')) {
  function dash_ai_norm(array $vector): float {
    $sum = 0.0;
    foreach ($vector as $v) { $sum += $v * $v; }
    return sqrt($sum);
  }
}


if (!function_exists('dash_ai_cosine')) {
  function dash_ai_cosine(array $a, float $normA, array $b, float $normB): float {
    if ($normA <= 0.0 || $normB <= 0.0) { return 0.0; }
    $len = min(count($a), count($b));
    $dot = 0.0;
    for ($i = 0; $i < $len; $i++) { $dot += $a[$i] * $b[$i]; }
    return $dot / ($normA * $normB);
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


if (!function_exists('dash_ai_recommendations')) {
  /**
   * @param array<string,mixed> $profile
   * @param array<int,bool> $applied
   * @return array{items:array<int,array<string,mixed>>,error:?string}
   */
  function dash_ai_recommendations(PDO $pdo, array $profile, string $email, array $applied = [], int $limit = 8): array {
    if (!class_exists('OpenAIClient')) {
      return ['items' => [], 'error' => 'Falta el cliente OpenAI'];
    }

    $apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
    if (trim((string)$apiKey) === '') {
      return ['items' => [], 'error' => 'Configura la variable de entorno OPENAI_API_KEY.'];
    }

    $base = getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1';
    $model = getenv('OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small';

    try {
      $client = new OpenAIClient($apiKey, $base, $model);
    } catch (Throwable $e) {
      return ['items' => [], 'error' => 'No se pudo inicializar OpenAI: '.$e->getMessage()];
    }

    try {
      $tableExists = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'vacante_embeddings' LIMIT 1");
      if (!$tableExists || !$tableExists->fetchColumn()) {
        return ['items' => [], 'error' => 'La tabla vacante_embeddings no existe. Ejecuta las migraciones para crearla.'];
      }
    } catch (Throwable $e) {
      return ['items' => [], 'error' => 'No se pudo verificar vacante_embeddings: '.$e->getMessage()];
    }

    try {
      $profileText = dash_ai_profile_text($profile, $email);
      $userVec = $client->embed($profileText);
      $userNorm = dash_ai_norm($userVec);
    } catch (Throwable $e) {
      return ['items' => [], 'error' => 'No se pudo calcular el embedding del perfil: '.$e->getMessage()];
    }

    $conditions = ['v.estado IN ("publicada","activa","publicada ")'];
    $params = [];

    if (!empty($profile['area_id'])) { $conditions[] = 'v.area_id = ?'; $params[] = (int)$profile['area_id']; }
    if (!empty($profile['nivel_id'])) { $conditions[] = 'v.nivel_id = ?'; $params[] = (int)$profile['nivel_id']; }
    if (!empty($profile['modalidad_id'])) { $conditions[] = 'v.modalidad_id = ?'; $params[] = (int)$profile['modalidad_id']; }
    if (!empty($profile['city'])) { $conditions[] = '(v.ciudad = ? OR v.ciudad = "Remoto")'; $params[] = $profile['city']; }

    $sql = '
      SELECT v.id, v.titulo, v.descripcion, v.requisitos, v.etiquetas, v.ciudad,
             v.salario_min, v.salario_max, v.moneda, v.publicada_at, v.created_at,
             e.id AS empresa_id, e.razon_social AS empresa_nombre,
             a.nombre AS area_nombre, n.nombre AS nivel_nombre, m.nombre AS modalidad_nombre,
             ve.embedding, ve.norm
      FROM vacantes v
      JOIN vacante_embeddings ve ON ve.vacante_id = v.id
      LEFT JOIN empresas e ON e.id = v.empresa_id
      LEFT JOIN areas a ON a.id = v.area_id
      LEFT JOIN niveles n ON n.id = v.nivel_id
      LEFT JOIN modalidades m ON m.id = v.modalidad_id
      WHERE '.implode(' AND ', $conditions).'
      ORDER BY COALESCE(v.publicada_at, v.created_at) DESC
      LIMIT 200
    ';

    try {
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return ['items' => [], 'error' => 'No se pudieron consultar vacantes: '.$e->getMessage()];
    }

    $items = [];
    foreach ($rows as $row) {
      $embedding = json_decode((string)($row['embedding'] ?? ''), true);
      if (!is_array($embedding)) { continue; }
      $vacNorm = isset($row['norm']) ? (float)$row['norm'] : 0.0;
      $score = dash_ai_cosine($userVec, $userNorm, $embedding, $vacNorm);
      $scorePct = (int)round(max(0, min(1, $score)) * 100);

      $pubInfo = dash_publication_info($row['publicada_at'] ?? $row['created_at']);
      $metaParts = array_filter([
        $row['modalidad_nombre'] ?? null,
        $row['ciudad'] ?? null,
        $pubInfo['meta'],
      ]);

      $tags = dash_extract_tags($row['etiquetas'] ?? null);
      $chips = array_values(array_filter([
        $row['nivel_nombre'] ?? null,
        $row['modalidad_nombre'] ?? null,
        $row['area_nombre'] ?? null,
        $row['ciudad'] ?? null,
      ]));

      $items[] = [
        'id' => (int)$row['id'],
        'titulo' => $row['titulo'] ?? 'Oferta sin t��tulo',
        'empresa' => $row['empresa_nombre'] ?? 'Empresa confidencial',
        'empresa_id' => isset($row['empresa_id']) ? (int)$row['empresa_id'] : null,
        'meta_line' => $metaParts ? implode(' �� ', $metaParts) : $pubInfo['meta'],
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
    }

    if (!$items) {
      return ['items' => [], 'error' => 'No hay embeddings de vacantes. Genera embeddings antes de usar recomendaciones.'];
    }

    usort($items, static fn($a, $b) => $b['match'] <=> $a['match']);
    $items = array_slice($items, 0, max(1, $limit));

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

    if ($content !== '' && preg_match('/(\\d+)\\s*\\+?\\s*a(?:nos|ños)/iu', $content, $matches)) {

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



$modalidadOptions = [

  'remoto' => 'Remoto',

  'hibrido' => 'Híbrido',

  'presencial' => 'Presencial',

];

$contratoOptions = [

  'tiempo-completo' => 'Tiempo completo',

  'medio-tiempo' => 'Medio tiempo',

  'practicas' => 'Prácticas',

  'por-proyecto' => 'Por proyecto',

];

$experienceOptions = [

  '0' => '0 (primera experiencia)',

  '1-2' => '1 a 2 años',

  '3-5' => '3 a 5 años',

  '6-9' => '6 a 9 años',

  '10+' => '10+ años',

];

$experienceBuckets = [

  '0' => [0, 0],

  '1-2' => [1, 2],

  '3-5' => [3, 5],

  '6-9' => [6, 9],

  '10+' => [10, null],

];



$filterSearch = trim((string)($_GET['buscar'] ?? ''));

$filterModalidad = $_GET['modalidad'] ?? [];

if (!is_array($filterModalidad)) { $filterModalidad = [$filterModalidad]; }

$filterModalidad = array_values(array_filter(array_map('dash_slugify', $filterModalidad)));



$filterContrato = $_GET['contrato'] ?? [];

if (!is_array($filterContrato)) { $filterContrato = [$filterContrato]; }

$filterContrato = array_values(array_filter(array_map('dash_slugify', $filterContrato)));



$filterExperiencia = (string)($_GET['experiencia'] ?? '');

if (!array_key_exists($filterExperiencia, $experienceOptions)) {

  $filterExperiencia = '';

}



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

  || $filterExperiencia !== ''

  || $filterCiudadSlug !== ''

  || $filterSalario !== null

);



$postulaciones = [];

$appliedVacantes = [];

$entrevistaCount = 0;



$vacantes = [];

$nuevasVacantes = 0;



if ($pdo instanceof PDO) {

  try {

    $postStmt = $pdo->prepare('SELECT vacante_id, estado FROM postulaciones WHERE candidato_email = ?');

    $postStmt->execute([$userSession['email']]);

    $postulaciones = $postStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($postulaciones as $post) {

      $vacId = isset($post['vacante_id']) ? (int)$post['vacante_id'] : 0;

      if ($vacId > 0) {

        $appliedVacantes[$vacId] = true;

      }

      if (($post['estado'] ?? '') === 'entrevista') {

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

    // Buscar IDs canónicos de modalidad/contrato para filtrar por v.modalidad_id / v.tipo_contrato_id
    $modalidadIds = [];
    if ($filterModalidad) {
      $modNames = [];
      foreach ($filterModalidad as $slug) {
        if ($slug === 'remoto') { $modNames[] = 'remoto'; }
        elseif ($slug === 'hibrido') { $modNames[] = 'híbrido'; $modNames[] = 'hibrido'; }
        elseif ($slug === 'presencial') { $modNames[] = 'presencial'; }
      }
      if ($modNames) {
        $place = implode(',', array_fill(0, count($modNames), '?'));
        $q = $pdo->prepare('SELECT id FROM modalidades WHERE LOWER(TRIM(nombre)) IN ('.$place.')');
        $q->execute($modNames);
        $modalidadIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $q->fetchAll(PDO::FETCH_ASSOC) ?: []), static fn($id) => $id > 0));
      }
      if ($modalidadIds) {
        $place = implode(',', array_fill(0, count($modalidadIds), '?'));
        $whereParts[] = ' AND v.modalidad_id IN ('.$place.')';
        foreach ($modalidadIds as $id) { $params[] = $id; }
      }
    }

    $contratoIds = [];
    if ($filterContrato) {
      $conNames = [];
      foreach ($filterContrato as $slug) {
        if ($slug === 'tiempo-completo') { $conNames[] = 'tiempo completo'; }
        elseif ($slug === 'medio-tiempo') { $conNames[] = 'medio tiempo'; }
        elseif ($slug === 'practicas') { $conNames[] = 'prácticas'; $conNames[] = 'practicas'; }
        elseif ($slug === 'por-proyecto') { $conNames[] = 'por proyecto'; }
      }
      if ($conNames) {
        $place = implode(',', array_fill(0, count($conNames), '?'));
        $q = $pdo->prepare('SELECT id FROM contratos WHERE LOWER(TRIM(nombre)) IN ('.$place.')');
        $q->execute($conNames);
        $contratoIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $q->fetchAll(PDO::FETCH_ASSOC) ?: []), static fn($id) => $id > 0));
      }
      if ($contratoIds) {
        $place = implode(',', array_fill(0, count($contratoIds), '?'));
        $whereParts[] = ' AND v.tipo_contrato_id IN ('.$place.')';
        foreach ($contratoIds as $id) { $params[] = $id; }
      }
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
    $limit = ($filterModalidad || $filterContrato || $filterCiudadSlug !== '' || $filterSalario !== null || $filterExperiencia !== '') ? 200 : 20;
    $sql = $baseSql.implode('', $whereParts).' ORDER BY COALESCE(v.publicada_at, v.created_at) DESC LIMIT '.$limit;

    $vacStmt = $pdo->prepare($sql);
    $vacStmt->execute($params);
    if ($vacStmt) {
      while ($row = $vacStmt->fetch(PDO::FETCH_ASSOC)) {
        $tags = dash_extract_tags($row['etiquetas'] ?? null);

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

        $modalidadSlug = dash_slugify($row['modalidad_nombre'] ?? '');

        $contratoSlug = dash_slugify($row['contrato_nombre'] ?? '');

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

        $experienceYears = dash_infer_experience_years(

          $row['nivel_nombre'] ?? '',

          ($row['descripcion'] ?? '').' '.($row['requisitos'] ?? '')

        );



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

          'filter_modalidad' => $modalidadSlug,

          'filter_contrato' => $contratoSlug,

          'filter_ciudad' => $ciudadSlug,

          'filter_salary' => $salarioMinRaw,

          'filter_experience' => $experienceYears,

          'filter_search' => $searchBlob,

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
        $tags = dash_extract_tags($row['etiquetas'] ?? null);
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
        $modalidadSlug = dash_slugify($row['modalidad_nombre'] ?? '');
        $contratoSlug = dash_slugify($row['contrato_nombre'] ?? '');
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
        $experienceYears = dash_infer_experience_years(
          $row['nivel_nombre'] ?? '',
          ($row['descripcion'] ?? '').' '.($row['requisitos'] ?? '')
        );
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
          'filter_modalidad' => $modalidadSlug,
          'filter_contrato' => $contratoSlug,
          'filter_ciudad' => $ciudadSlug,
          'filter_salary' => $salarioMinRaw,
          'filter_experience' => $experienceYears,
          'filter_search' => $searchBlob,
        ];
      }
    }
  } catch (Throwable $vacError) {
    error_log('[dashboard] vacantes: '.$vacError->getMessage());
  }
}


$vacantesAll = $vacantes; // estricto
$vacantes = $vacantes

  ? array_values(array_filter(

      $vacantes,

      static function (array $vac) use (

        $filterModalidad,

        $filterContrato,

        $filterCiudadSlug,

        $filterSalario,

        $filterExperiencia,

        $experienceBuckets,

        $searchNeedle

      ): bool {

        if ($filterModalidad && !in_array($vac['filter_modalidad'] ?? '', $filterModalidad, true)) {

          return false;

        }

        if ($filterContrato && !in_array($vac['filter_contrato'] ?? '', $filterContrato, true)) {

          return false;

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

        if ($filterExperiencia !== '') {

          if (!isset($experienceBuckets[$filterExperiencia])) {

            return false;

          }

          $years = $vac['filter_experience'] ?? null;

          if ($years === null) {

            return false;

          }

          [$minExp, $maxExp] = $experienceBuckets[$filterExperiencia];

          if ($minExp !== null && $years < $minExp) {

            return false;

          }

          if ($maxExp !== null && $years > $maxExp) {

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



// Fallback relacionado cuando no hay resultados estrictos
if (!$vacantes && (!empty($filterModalidad) || !empty($filterContrato)) && !empty($vacantesPool)) {
  $modalidadFallbackMap = [
    'hibrido' => ['hibrido','remoto','presencial'],
    'remoto' => ['remoto','hibrido'],
    'presencial' => ['presencial','hibrido'],
  ];
  $contratoFallbackMap = [
    'practicas' => ['practicas','medio-tiempo','tiempo-completo'],
    'medio-tiempo' => ['medio-tiempo','tiempo-completo'],
    'tiempo-completo' => ['tiempo-completo','medio-tiempo'],
    'por-proyecto' => ['por-proyecto','medio-tiempo'],
  ];
  $modalidadRelated = [];
  foreach ($filterModalidad as $m) { $modalidadRelated = array_merge($modalidadRelated, ($modalidadFallbackMap[$m] ?? [$m])); }
  $modalidadRelated = array_values(array_unique($modalidadRelated));
  $contratoRelated = [];
  foreach ($filterContrato as $c) { $contratoRelated = array_merge($contratoRelated, ($contratoFallbackMap[$c] ?? [$c])); }
  $contratoRelated = array_values(array_unique($contratoRelated));
  $vacantes = array_values(array_filter(
    $vacantesPool,
    static function (array $vac) use (
      $modalidadRelated,
      $contratoRelated,
      $filterCiudadSlug,
      $filterSalario,
      $filterExperiencia,
      $experienceBuckets,
      $searchNeedle
    ): bool {
      if ($modalidadRelated && !in_array($vac['filter_modalidad'] ?? '', $modalidadRelated, true)) { return false; }
      if ($contratoRelated && !in_array($vac['filter_contrato'] ?? '', $contratoRelated, true)) { return false; }
      if ($filterCiudadSlug !== '' && strpos((string)($vac['filter_ciudad'] ?? ''), $filterCiudadSlug) === false) { return false; }
      if ($filterSalario !== null) {
        $minSalary = $vac['filter_salary'] ?? null;
        if ($minSalary === null || $minSalary < $filterSalario) { return false; }
      }
      if ($filterExperiencia !== '') {
        if (!isset($experienceBuckets[$filterExperiencia])) { return false; }
        $years = $vac['filter_experience'] ?? null;
        if ($years === null) { return false; }
        [$minExp, $maxExp] = $experienceBuckets[$filterExperiencia];
        if ($minExp !== null && $years < $minExp) { return false; }
        if ($maxExp !== null && $years > $maxExp) { return false; }
      }
      if ($searchNeedle !== '' && strpos((string)($vac['filter_search'] ?? ''), $searchNeedle) === false) { return false; }
      return true;
    }
  ));
}

$aiResult = ['items' => [], 'error' => null];
$vacantesFeed = $vacantes;
if ($pdo instanceof PDO) {
  $aiResult = dash_ai_recommendations($pdo, $profileData, $userSession['email'], $appliedVacantes, 8);
  if (!empty($aiResult['items'])) {
    $vacantesFeed = $aiResult['items'];
  }
}
$aiError = $aiResult['error'];

$kpiData = [

  'nuevas' => $nuevasVacantes,

  'postulaciones' => count($postulaciones),

  'entrevistas' => $entrevistaCount,

  'guardadas' => 0,

];



$skillHighlights = $profileData['skills'] ? array_slice($profileData['skills'], 0, 3) : [];

$modalidadLabel = $profileData['modalidad'] ?? null;

$levelLabel = $profileData['level'] ?? null;

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

        <a class="link-edit" href="#perfil">Actualizar preferencias</a>

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

          <?php foreach ($modalidadOptions as $slug => $label): ?>

            <label class="check">

              <input type="checkbox" name="modalidad[]" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>" <?=in_array($slug, $filterModalidad, true) ? 'checked' : ''; ?> />

              <?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>

            </label>

          <?php endforeach; ?>

        </div>



        <div class="field">

          <label>Tipo de contrato</label>

          <?php foreach ($contratoOptions as $slug => $label): ?>

            <label class="check">

              <input type="checkbox" name="contrato[]" value="<?=htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>" <?=in_array($slug, $filterContrato, true) ? 'checked' : ''; ?> />

              <?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>

            </label>

          <?php endforeach; ?>

        </div>



        <div class="field">

          <label for="experiencia">Años de experiencia</label>

          <select id="experiencia" name="experiencia">

            <option value="">Cualquiera</option>

            <?php foreach ($experienceOptions as $value => $label): ?>

              <option value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?=$filterExperiencia === $value ? 'selected' : ''; ?>>

                <?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>

              </option>

            <?php endforeach; ?>

          </select>

        </div>



        <div class="field">

          <label for="salario">Salario objetivo</label>

          <input id="salario" name="salario" type="text" placeholder="$2.000.000" value="<?=htmlspecialchars($filterSalarioDisplay, ENT_QUOTES, 'UTF-8'); ?>" />

        </div>



        <div class="field">

          <label for="ciudad">Ciudad</label>

          <input id="ciudad" name="ciudad" type="text" placeholder="Bogotá" value="<?=htmlspecialchars($filterCiudad, ENT_QUOTES, 'UTF-8'); ?>" />

        </div>



        <div class="field">

          <button type="submit" class="btn btn-outline full">Aplicar filtros</button>

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
      <?php if ($vacantesFeed): ?>

        <?php foreach ($vacantesFeed as $vacante): ?>

          <article class="job card">

            <div class="job-head">

              <h3><?=htmlspecialchars($vacante['titulo'], ENT_QUOTES, 'UTF-8'); ?></h3>

              <?php if (!empty($vacante['badge'])): ?>

                <span class="badge<?=strtolower($vacante['badge']) === 'nueva' ? ' new' : ''; ?>"><?=htmlspecialchars($vacante['badge'], ENT_QUOTES, 'UTF-8'); ?></span>

              <?php endif; ?>

            </div>

            <div class="meta" style="display:flex; flex-wrap:wrap; gap:.45rem; align-items:center;">
              <span><strong><?=htmlspecialchars($vacante['empresa'], ENT_QUOTES, 'UTF-8'); ?></strong> · <?=htmlspecialchars($vacante['meta_line'], ENT_QUOTES, 'UTF-8'); ?></span>
              <?php if (!empty($vacante['empresa_id'])): ?>
                <a class="btn btn-ghost" style="padding:0.2rem 0.9rem; font-size:0.85rem;" href="index.php?view=PerfilEmpresaVistaCandidato&empresa_id=<?= (int)$vacante['empresa_id']; ?>">Ver perfil</a>
              <?php endif; ?>
            </div>



            <div class="match">

              <div class="match-bar"><span style="width: <?=max(0, min(100, (int)$vacante['match'])); ?>%"></span></div>

              <span class="match-label">Match <?=max(0, min(100, (int)$vacante['match'])); ?>%</span>

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



            <div class="row-cta">

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

          <div class="progress-bar"><span style="width:80%"></span></div>

          <div class="progress-label">Perfil 80% completo</div>

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

          <li class="ok">CV actualizado</li>

          <li class="ok">Correo verificado</li>

          <li class="warn">Agrega 2 certificaciones</li>

        </ul>



        

      </div>



      <div class="card alerts">

        <h3>Alertas de empleo</h3>

        <p class="muted">Recibe un resumen semanal por correo con nuevas coincidencias.</p>

        <a class="btn btn-brand full" href="#activar">Activar alertas</a>

      </div>

    </aside>

  </main>





