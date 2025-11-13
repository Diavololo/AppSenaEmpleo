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

$eduTitulos = $_POST['edu_title'] ?? [];
$eduInst    = $_POST['edu_institution'] ?? [];
$eduPeriod  = $_POST['edu_period'] ?? [];
$eduDesc    = $_POST['edu_desc'] ?? [];

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
  }

  // Actualiza educacion
  $pdo->prepare('DELETE FROM candidato_educacion WHERE email = ?')->execute([$newEmail]);
  $eduInsert = $pdo->prepare(
    'INSERT INTO candidato_educacion (email, titulo, institucion, periodo, descripcion, orden)
     VALUES (:email, :titulo, :institucion, :periodo, :descripcion, :orden)'
  );
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
