<?php
declare(strict_types=1);

session_start();

// CSRF
if (
  !isset($_POST['csrf'], $_SESSION['csrf']) ||
  !hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf'])
) {
  http_response_code(400);
  echo 'Solicitud invalida (CSRF).';
  exit;
}

$sessionUser = $_SESSION['user'] ?? null;
if (!$sessionUser || ($sessionUser['type'] ?? '') !== 'persona') {
  header('Location: index.php?view=login');
  exit;
}

require __DIR__.'/../pages/db.php';

if (!function_exists('up_slug_from_email')) {
  function up_slug_from_email(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = trim((string)$value, '-');
    return $value !== '' ? $value : 'candidato';
  }
}

if (!function_exists('up_ensure_directory')) {
  function up_ensure_directory(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
      throw new RuntimeException('No se pudo crear el directorio: '.$path);
    }
  }
}

if (!function_exists('up_collect_files')) {
  /**
   * @return array<int,array{error?:int,tmp_name?:string,name?:string,size?:int,type?:string}>
   */
  function up_collect_files(string $field): array {
    $src = $_FILES[$field] ?? null;
    if (!is_array($src) || !isset($src['name']) || !is_array($src['name'])) {
      return [];
    }
    $files = [];
    foreach (array_keys($src['name']) as $idx) {
      $files[$idx] = [
        'name' => $src['name'][$idx] ?? null,
        'type' => $src['type'][$idx] ?? null,
        'tmp_name' => $src['tmp_name'][$idx] ?? null,
        'error' => $src['error'][$idx] ?? null,
        'size' => $src['size'][$idx] ?? null,
      ];
    }
    return $files;
  }
}

if (!function_exists('up_store_upload')) {
  /**
   * @param array{error?:int,tmp_name?:string,name?:string,size?:int} $file
   * @param array<string,string[]> $allowed
   * @return array{absolute:string,relative:string,original:string,mime:string,size:int}
   */
  function up_store_upload(array $file, array $allowed, string $targetDir, string $publicPrefix, string $namePrefix, int $maxBytes): array {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
      throw new RuntimeException('No se recibio archivo.');
    }
    if ($error !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Error al subir archivo (codigo '.$error.').');
    }
    $tmp = $file['tmp_name'] ?? null;
    if (!$tmp || !is_uploaded_file($tmp)) {
      throw new RuntimeException('Carga de archivo invalida.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
      throw new RuntimeException('El archivo excede el tamano permitido ('.round($maxBytes / (1024*1024), 1).' MB).');
    }
    $original = basename($file['name'] ?? 'archivo');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
      throw new RuntimeException('Extension no permitida: .'.$ext);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (finfo_file($finfo, $tmp) ?: '') : '';
    if ($finfo) { finfo_close($finfo); }
    if ($mime === '' || !in_array($mime, $allowed[$ext], true)) {
      throw new RuntimeException('Tipo de archivo no permitido ('.$mime.').');
    }
    up_ensure_directory($targetDir);
    $safePrefix = trim(preg_replace('/[^a-z0-9]+/i', '-', $namePrefix) ?? '', '-');
    if ($safePrefix === '') { $safePrefix = 'archivo'; }
    $filename = sprintf('%s-%s.%s', $safePrefix, bin2hex(random_bytes(6)), $ext);
    $destination = $targetDir.DIRECTORY_SEPARATOR.$filename;
    if (!move_uploaded_file($tmp, $destination)) {
      throw new RuntimeException('No se pudo guardar el archivo.');
    }
    $relative = rtrim($publicPrefix, '/').'/'.$filename;
    return [
      'absolute' => $destination,
      'relative' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
      'original' => $original,
      'mime'     => $mime,
      'size'     => $size,
    ];
  }
}

if (!($pdo instanceof PDO)) {
  $_SESSION['flash_profile'] = 'No fue posible conectarse a la base de datos.';
  header('Location: index.php?view=perfil_publico');
  exit;
}

$pdo->exec("
  CREATE TABLE IF NOT EXISTS candidato_experiencias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) COLLATE utf8mb4_unicode_ci NOT NULL,
    cargo VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL,
    empresa VARCHAR(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    periodo VARCHAR(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    anios_experiencia DECIMAL(4,1) DEFAULT NULL,
    descripcion TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_exp_email (email),
    CONSTRAINT fk_exp_candidato FOREIGN KEY (email) REFERENCES candidatos (email) ON DELETE CASCADE ON UPDATE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS candidato_educacion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) COLLATE utf8mb4_unicode_ci NOT NULL,
    titulo VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL,
    institucion VARCHAR(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    periodo VARCHAR(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    descripcion TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_edu_email (email),
    CONSTRAINT fk_edu_candidato FOREIGN KEY (email) REFERENCES candidatos (email) ON DELETE CASCADE ON UPDATE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS candidato_habilidades (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) COLLATE utf8mb4_unicode_ci NOT NULL,
    nombre VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL,
    anios_experiencia DECIMAL(4,1) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_skill_email (email),
    CONSTRAINT fk_skill_candidato FOREIGN KEY (email) REFERENCES candidatos (email) ON DELETE CASCADE ON UPDATE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$oldEmail = (string)($sessionUser['email'] ?? '');
$newEmail = trim((string)($_POST['per_email'] ?? $oldEmail));
if ($newEmail === '') { $newEmail = $oldEmail; }

$nombre    = trim((string)($_POST['nombre'] ?? $sessionUser['nombre'] ?? ''));
$apellido  = trim((string)($_POST['apellido'] ?? $sessionUser['apellidos'] ?? ''));
$telefono  = trim((string)($_POST['telefono'] ?? ''));
$ciudad    = trim((string)($_POST['ciudad'] ?? $sessionUser['ciudad'] ?? ''));
$docTipo   = trim((string)($_POST['documento_tipo'] ?? ''));
$docNumero = trim((string)($_POST['documento_numero'] ?? ''));
$pais      = trim((string)($_POST['pais'] ?? ''));
$direccion = trim((string)($_POST['direccion'] ?? ''));
$resumen   = trim((string)($_POST['perfil'] ?? ''));
$areasInteres = trim((string)($_POST['areas_interes'] ?? ''));
$idiomas   = trim((string)($_POST['idiomas'] ?? ''));
$competenciasTxt = trim((string)($_POST['competencias'] ?? ''));
$logrosTxt = trim((string)($_POST['logros'] ?? ''));

$skillsNames = $_POST['skill_name'] ?? [];
$skillsYears = $_POST['skill_years'] ?? [];

$expRoles  = $_POST['exp_role'] ?? [];
$expEmp    = $_POST['exp_company'] ?? [];
$expPeriod = $_POST['exp_period'] ?? [];
$expYears  = $_POST['exp_years'] ?? [];
$expDesc   = $_POST['exp_desc'] ?? [];
$expFiles  = up_collect_files('exp_proof');

$eduTitulos = $_POST['edu_title'] ?? [];
$eduInst    = $_POST['edu_institution'] ?? [];
$eduPeriod  = $_POST['edu_period'] ?? [];
$eduDesc    = $_POST['edu_desc'] ?? [];
$eduFiles   = up_collect_files('edu_proof');

$snapshot = [
  'email'         => $newEmail,
  'nombre'        => $nombre,
  'apellido'      => $apellido,
  'telefono'      => $telefono,
  'ciudad'        => $ciudad,
  'resumen'       => $resumen,
  'areas_interes' => $areasInteres,
  'titulo'        => trim((string)($_POST['rol'] ?? '')),
  'experiencias'  => [],
  'educacion'     => [],
];

$existingDetailsStmt = $pdo->prepare('SELECT documento_tipo, documento_numero, pais, direccion, perfil, areas_interes FROM candidato_detalles WHERE email = ? LIMIT 1');
$existingDetailsStmt->execute([$sessionUser['email'] ?? $oldEmail]);
$existingDetails = $existingDetailsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Directorios y configuracion de archivos
$rootPath = dirname(__DIR__);
$uploadsRoot = $rootPath.DIRECTORY_SEPARATOR.'uploads';
up_ensure_directory($uploadsRoot);
$slug = up_slug_from_email($newEmail);
$personaBase = $uploadsRoot.DIRECTORY_SEPARATOR.'candidatos';
up_ensure_directory($personaBase);
$personaDir = $personaBase.DIRECTORY_SEPARATOR.$slug;
up_ensure_directory($personaDir);
$cvDir = $personaDir.DIRECTORY_SEPARATOR.'cv';
$fotoDir = $personaDir.DIRECTORY_SEPARATOR.'foto';
$expCertDir = $personaDir.DIRECTORY_SEPARATOR.'experiencias';
$eduCertDir = $personaDir.DIRECTORY_SEPARATOR.'educacion';
$publicBase = '/uploads/candidatos/'.$slug;
$publicExpBase = $publicBase.'/experiencias';
$publicEduBase = $publicBase.'/educacion';
$cleanupFiles = [];
$certAllowed = [
  'pdf' => ['application/pdf'],
  'doc' => ['application/msword', 'application/msword; charset=binary', 'application/vnd.ms-office', 'application/octet-stream'],
  'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
  'png' => ['image/png'],
  'jpg' => ['image/jpeg'],
  'jpeg' => ['image/jpeg'],
];
$cvAllowed = [
  'pdf' => ['application/pdf'],
  'doc' => ['application/msword', 'application/msword; charset=binary', 'application/vnd.ms-office', 'application/octet-stream'],
  'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
];
$imgAllowed = [
  'png' => ['image/png'],
  'jpg' => ['image/jpeg'],
  'jpeg' => ['image/jpeg'],
];

$pdo->beginTransaction();

try {
  // Actualiza datos basicos del candidato
  $updateCandidate = $pdo->prepare(
    'UPDATE candidatos
        SET email = :new_email,
            nombres = :nombres,
            apellidos = :apellidos,
            telefono = :telefono,
            ciudad = :ciudad,
            updated_at = NOW()
      WHERE email = :old_email'
  );
  $updateCandidate->execute([
    ':new_email' => $newEmail,
    ':nombres'   => $nombre,
    ':apellidos' => $apellido,
    ':telefono'  => $telefono,
    ':ciudad'    => $ciudad,
    ':old_email' => $oldEmail,
  ]);

  if ($newEmail !== $oldEmail) {
    foreach ([
      'candidato_detalles',
      'candidato_perfil',
      'candidato_experiencias',
      'candidato_educacion',
      'candidato_habilidades',
      'candidato_documentos'
    ] as $table) {
      $stmt = $pdo->prepare("UPDATE {$table} SET email = :new WHERE email = :old");
      $stmt->execute([':new' => $newEmail, ':old' => $oldEmail]);
    }
  }

  // Actualiza detalles principales
  $detUpdate = $pdo->prepare(
    'UPDATE candidato_detalles
        SET documento_tipo = :doc_tipo,
            documento_numero = :doc_numero,
            pais = :pais,
            direccion = :direccion,
            perfil = :perfil,
            areas_interes = :areas_interes
      WHERE email = :email'
  );
  $docTipoValue   = $docTipo !== '' ? $docTipo : ($existingDetails['documento_tipo'] ?? null);
  $docNumeroValue = $docNumero !== '' ? $docNumero : ($existingDetails['documento_numero'] ?? null);
  $paisValue      = $pais !== '' ? $pais : ($existingDetails['pais'] ?? null);
  $direccionValue = $direccion !== '' ? $direccion : ($existingDetails['direccion'] ?? null);
  $perfilValue    = $resumen !== '' ? $resumen : ($existingDetails['perfil'] ?? 'Actualiza tu resumen profesional.');
  $areasValue     = $areasInteres !== '' ? $areasInteres : ($existingDetails['areas_interes'] ?? null);

  if ($docTipoValue === null || $docTipoValue === '') {
    throw new RuntimeException('Selecciona un tipo de documento.');
  }
  if ($docNumeroValue === null || $docNumeroValue === '') {
    throw new RuntimeException('Ingresa el numero de documento.');
  }
  if ($paisValue === null || $paisValue === '') {
    throw new RuntimeException('Ingresa el pais de residencia.');
  }

  $detUpdate->execute([
    ':doc_tipo'      => $docTipoValue,
    ':doc_numero'    => $docNumeroValue,
    ':pais'          => $paisValue,
    ':direccion'     => $direccionValue,
    ':perfil'        => $perfilValue,
    ':areas_interes' => $areasValue,
    ':email'         => $newEmail,
  ]);

  // Manejo de CV y foto opcionales
  $cvDocId = null;
  if (isset($_FILES['cv']) && ($_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $cvInfo = up_store_upload($_FILES['cv'], $cvAllowed, $cvDir, $publicBase.'/cv', 'cv', 5 * 1024 * 1024);
    $cleanupFiles[] = $cvInfo['absolute'];
    $docStmt = $pdo->prepare('INSERT INTO candidato_documentos (email, tipo, nombre_archivo, ruta, mime, tamano) VALUES (?,?,?,?,?,?)');
    $docStmt->execute([
      $newEmail,
      'cv',
      $cvInfo['original'],
      $cvInfo['relative'],
      $cvInfo['mime'],
      $cvInfo['size'],
    ]);
    $cvDocId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE candidato_detalles SET cv_documento_id = ? WHERE email = ?')->execute([$cvDocId, $newEmail]);
  }

  if (isset($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $fotoInfo = up_store_upload($_FILES['foto'], $imgAllowed, $fotoDir, $publicBase.'/foto', 'foto', 3 * 1024 * 1024);
    $cleanupFiles[] = $fotoInfo['absolute'];
    $pdo->prepare('UPDATE candidato_detalles SET foto_nombre = ?, foto_ruta = ?, foto_mime = ?, foto_tamano = ? WHERE email = ?')->execute([
      $fotoInfo['original'],
      $fotoInfo['relative'],
      $fotoInfo['mime'],
      $fotoInfo['size'],
      $newEmail,
    ]);
  }

  // Actualiza resumen y habilidades libres en candidato_perfil si existe
  $perfilUpdate = $pdo->prepare(
    'UPDATE candidato_perfil
        SET resumen = COALESCE(:resumen, resumen),
            habilidades = COALESCE(:habilidades, habilidades)
      WHERE email = :email'
  );
  $perfilUpdate->execute([
    ':resumen'     => $resumen !== '' ? $resumen : null,
    ':habilidades' => $areasInteres !== '' ? $areasInteres : null,
    ':email'       => $newEmail,
  ]);

  // Limpia certificados previos
  $pdo->prepare('DELETE FROM candidato_experiencia_certificados WHERE experiencia_id IN (SELECT id FROM candidato_experiencias WHERE email = ?)')->execute([$newEmail]);
  $pdo->prepare('DELETE FROM candidato_educacion_certificados WHERE educacion_id IN (SELECT id FROM candidato_educacion WHERE email = ?)')->execute([$newEmail]);
  $pdo->prepare('DELETE FROM candidato_documentos WHERE email = ? AND tipo = "certificado"')->execute([$newEmail]);

  // Actualiza habilidades
  $pdo->prepare('DELETE FROM candidato_habilidades WHERE email = ?')->execute([$newEmail]);
  $skillInsert = $pdo->prepare(
    'INSERT INTO candidato_habilidades (email, nombre, anios_experiencia)
     VALUES (:email, :nombre, :anios)'
  );
  $skillCount = max(count($skillsNames), count($skillsYears));
  for ($i = 0; $i < $skillCount; $i++) {
    $name = trim((string)($skillsNames[$i] ?? ''));
    $yearsRaw = trim((string)($skillsYears[$i] ?? ''));
    if ($name === '' && $yearsRaw === '') { continue; }
    $years = $yearsRaw !== '' ? (float)$yearsRaw : null;
    $skillInsert->execute([
      ':email'  => $newEmail,
      ':nombre' => $name,
      ':anios'  => $years,
    ]);
  }

  // Actualiza experiencias
  $pdo->prepare('DELETE FROM candidato_experiencias WHERE email = ?')->execute([$newEmail]);
  $expInsert = $pdo->prepare(
    'INSERT INTO candidato_experiencias (email, cargo, empresa, periodo, anios_experiencia, descripcion, orden)
     VALUES (:email, :cargo, :empresa, :periodo, :anios, :descripcion, :orden)'
  );
  $expCertInsert = $pdo->prepare('INSERT INTO candidato_experiencia_certificados (experiencia_id, documento_id) VALUES (?,?)');
  $docInsert = $pdo->prepare('INSERT INTO candidato_documentos (email, tipo, nombre_archivo, ruta, mime, tamano) VALUES (?,?,?,?,?,?)');
  $expCount = max(count($expRoles), count($expEmp), count($expPeriod), count($expYears), count($expDesc));
  $order = 1;
  for ($i = 0; $i < $expCount; $i++) {
    $cargo  = trim((string)($expRoles[$i] ?? ''));
    $empresa= trim((string)($expEmp[$i] ?? ''));
    $periodo= trim((string)($expPeriod[$i] ?? ''));
    $anios  = trim((string)($expYears[$i] ?? ''));
    $desc   = trim((string)($expDesc[$i] ?? ''));
    if ($cargo === '' && $empresa === '' && $periodo === '' && $desc === '' && $anios === '') {
      continue;
    }
    $expInsert->execute([
      ':email'       => $newEmail,
      ':cargo'       => $cargo !== '' ? $cargo : 'Experiencia',
      ':empresa'     => $empresa !== '' ? $empresa : null,
      ':periodo'     => $periodo !== '' ? $periodo : null,
      ':anios'       => $anios !== '' ? (float)$anios : null,
      ':descripcion' => $desc !== '' ? $desc : null,
      ':orden'       => $order++,
    ]);
    $expId = (int)$pdo->lastInsertId();
    $file = $expFiles[$i] ?? null;
    if (is_array($file) && (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
      $up = up_store_upload($file, $certAllowed, $expCertDir, $publicExpBase, 'exp-'.$i, 5 * 1024 * 1024);
      $cleanupFiles[] = $up['absolute'];
      $docInsert->execute([$newEmail, 'certificado', $up['original'], $up['relative'], $up['mime'], $up['size']]);
      $expCertInsert->execute([$expId, (int)$pdo->lastInsertId()]);
    }
  }

  // Actualiza educacion
  $pdo->prepare('DELETE FROM candidato_educacion WHERE email = ?')->execute([$newEmail]);
  $eduInsert = $pdo->prepare(
    'INSERT INTO candidato_educacion (email, titulo, institucion, periodo, descripcion, orden)
     VALUES (:email, :titulo, :institucion, :periodo, :descripcion, :orden)'
  );
  $eduCertInsert = $pdo->prepare('INSERT INTO candidato_educacion_certificados (educacion_id, documento_id) VALUES (?,?)');
  $eduCount = max(count($eduTitulos), count($eduInst), count($eduPeriod), count($eduDesc));
  $order = 1;
  for ($i = 0; $i < $eduCount; $i++) {
    $titulo = trim((string)($eduTitulos[$i] ?? ''));
    $inst   = trim((string)($eduInst[$i] ?? ''));
    $period = trim((string)($eduPeriod[$i] ?? ''));
    $desc   = trim((string)($eduDesc[$i] ?? ''));
    if ($titulo === '' && $inst === '' && $period === '' && $desc === '') {
      continue;
    }
    $eduInsert->execute([
      ':email'       => $newEmail,
      ':titulo'      => $titulo !== '' ? $titulo : 'Estudio',
      ':institucion' => $inst !== '' ? $inst : null,
      ':periodo'     => $period !== '' ? $period : null,
      ':descripcion' => $desc !== '' ? $desc : null,
      ':orden'       => $order++,
    ]);
    $eduId = (int)$pdo->lastInsertId();
    $file = $eduFiles[$i] ?? null;
    if (is_array($file) && (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
      $up = up_store_upload($file, $certAllowed, $eduCertDir, $publicEduBase, 'edu-'.$i, 5 * 1024 * 1024);
      $cleanupFiles[] = $up['absolute'];
      $docInsert->execute([$newEmail, 'certificado', $up['original'], $up['relative'], $up['mime'], $up['size']]);
      $eduCertInsert->execute([$eduId, (int)$pdo->lastInsertId()]);
    }
  }

  $pdo->commit();

  $expCount = max(count($expRoles), count($expEmp), count($expPeriod), count($expYears), count($expDesc));
  for ($i = 0; $i < $expCount; $i++) {
    $snapshot['experiencias'][] = [
      'cargo'   => trim((string)($expRoles[$i] ?? '')),
      'empresa' => trim((string)($expEmp[$i] ?? '')),
      'periodo' => trim((string)($expPeriod[$i] ?? '')),
      'anios'   => trim((string)($expYears[$i] ?? '')),
      'desc'    => trim((string)($expDesc[$i] ?? '')),
    ];
  }
  $eduCount = max(count($eduTitulos), count($eduInst), count($eduPeriod), count($eduDesc));
  for ($i = 0; $i < $eduCount; $i++) {
    $snapshot['educacion'][] = [
      'titulo'      => trim((string)($eduTitulos[$i] ?? '')),
      'institucion' => trim((string)($eduInst[$i] ?? '')),
      'periodo'     => trim((string)($eduPeriod[$i] ?? '')),
      'desc'        => trim((string)($eduDesc[$i] ?? '')),
    ];
  }

  // Actualiza la sesion
  $_SESSION['user']['email']    = $newEmail;
  $_SESSION['user']['nombre']   = $nombre;
  $_SESSION['user']['apellidos']= $apellido;
  $_SESSION['user']['ciudad']   = $ciudad;
  $_SESSION['last_profile_snapshot'] = $snapshot;
  $_SESSION['flash_profile'] = 'Tu perfil se actualizo correctamente.';
  unset($_SESSION['flash_profile_error']);
} catch (Throwable $e) {
  $pdo->rollBack();
  error_log('[update_profile] '.$e->getMessage());
  $_SESSION['flash_profile_error'] = $e->getMessage();
  $_SESSION['last_profile_snapshot'] = $snapshot;
  unset($_SESSION['flash_profile']);
}

header('Location: index.php?view=perfil_publico');
exit;
