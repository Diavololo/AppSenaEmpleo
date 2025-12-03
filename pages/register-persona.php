<?php
// Bloquear acceso directo: solo vía index.php?view=register-persona
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=register-persona');
  exit;
}
// Inicia sesión solo si no está activa
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__.'/db.php';

if (!function_exists('rp_slug_from_email')) {
  function rp_slug_from_email(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = trim((string)$value, '-');
    return $value !== '' ? $value : 'candidato';
  }
}

if (!function_exists('rp_ensure_directory')) {
  function rp_ensure_directory(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
      throw new RuntimeException('No se pudo crear el directorio: '.$path);
    }
  }
}

if (!function_exists('rp_store_upload')) {
  /**
   * @param array{error?:int,tmp_name?:string,name?:string,size?:int} $file
   * @param array<string,string[]> $allowed
   * @return array{absolute:string,relative:string,original:string,mime:string,size:int}
   */
  function rp_store_upload(array $file, array $allowed, string $targetDir, string $publicPrefix, string $namePrefix, int $maxBytes): array {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
      throw new RuntimeException('No se recibió archivo.');
    }
    if ($error !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Error al subir el archivo (código '.$error.').');
    }
    $tmp = $file['tmp_name'] ?? null;
    if (!$tmp || !is_uploaded_file($tmp)) {
      throw new RuntimeException('Carga de archivo inválida.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
      throw new RuntimeException('El archivo excede el tamaño permitido ('.round($maxBytes / (1024*1024), 1).' MB).');
    }
    $original = basename($file['name'] ?? 'archivo');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
      throw new RuntimeException('Extensión no permitida: .'.$ext);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (finfo_file($finfo, $tmp) ?: '') : '';
    if ($finfo) { finfo_close($finfo); }
    if ($mime === '' || !in_array($mime, $allowed[$ext], true)) {
      throw new RuntimeException('Tipo de archivo no permitido ('.$mime.').');
    }
    rp_ensure_directory($targetDir);
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

if (!function_exists('rp_collect_files')) {
  /**
   * Normaliza la estructura de $_FILES para campos multiple ([])
   * @return array<int,array{error?:int,tmp_name?:string,name?:string,size?:int,type?:string}>
   */
  function rp_collect_files(string $field): array {
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

if (!function_exists('rp_parse_years')) {
  function rp_parse_years($value): ?float {
    $value = trim((string)$value);
    if ($value === '') { return null; }
    if (is_numeric($value)) {
      return (float)$value;
    }
    // Extrae primer número (entero o decimal) si existe
    if (preg_match('/([0-9]+(?:[\\.,][0-9]+)?)/', $value, $m)) {
      $num = str_replace(',', '.', $m[1]);
      return is_numeric($num) ? (float)$num : null;
    }
    return null;
  }
}

$rootPath = dirname(__DIR__);
$uploadsRoot = $rootPath.DIRECTORY_SEPARATOR.'uploads';
if (!is_dir($uploadsRoot)) {
  rp_ensure_directory($uploadsRoot);
}

if ($pdo instanceof PDO) {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS candidato_detalles (
      email VARCHAR(254) COLLATE utf8mb4_unicode_ci NOT NULL,
      documento_tipo VARCHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
      documento_numero VARCHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
      pais VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
      direccion VARCHAR(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      perfil TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
      areas_interes TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      cv_documento_id BIGINT UNSIGNED NOT NULL,
      foto_nombre VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      foto_ruta VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      foto_mime VARCHAR(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      foto_tamano INT UNSIGNED DEFAULT NULL,
      acepta_datos_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (email),
      KEY idx_detalles_cv (cv_documento_id),
      CONSTRAINT fk_detalles_candidato FOREIGN KEY (email) REFERENCES candidatos (email) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT fk_detalles_cv FOREIGN KEY (cv_documento_id) REFERENCES candidato_documentos (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
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
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS candidato_experiencia_certificados (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      experiencia_id BIGINT UNSIGNED NOT NULL,
      documento_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_cec_exp (experiencia_id),
      KEY idx_cec_doc (documento_id),
      CONSTRAINT fk_cec_exp FOREIGN KEY (experiencia_id) REFERENCES candidato_experiencias (id) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT fk_cec_doc FOREIGN KEY (documento_id) REFERENCES candidato_documentos (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS candidato_educacion_certificados (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      educacion_id BIGINT UNSIGNED NOT NULL,
      documento_id BIGINT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_ced_edu (educacion_id),
      KEY idx_ced_doc (documento_id),
      CONSTRAINT fk_ced_edu FOREIGN KEY (educacion_id) REFERENCES candidato_educacion (id) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT fk_ced_doc FOREIGN KEY (documento_id) REFERENCES candidato_documentos (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}

// CSRF
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

$okMsg = $errMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
    $errMsg = 'CSRF inválido. Recarga la página.';
  } else {
    $cleanupFiles = [];
    try {
      if (!($pdo instanceof PDO)) {
        throw new RuntimeException('No hay conexión a la base de datos. Configura config/database.php o las variables de entorno DB_* y vuelve a intentarlo.');
      }

      $required = ['per_email','per_password','per_password_confirm','nombre','apellido','telefono','documento_tipo','documento_numero','pais','ciudad','perfil'];
      foreach ($required as $key) {
        if (trim((string)($_POST[$key] ?? '')) === '') {
          throw new RuntimeException('Campo requerido: '.$key);
        }
      }
      if (!isset($_POST['acepta_datos'])) {
        throw new RuntimeException('Debes aceptar el tratamiento de datos.');
      }

      $email = strtolower(trim((string)($_POST['per_email'] ?? '')));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Correo electrónico inválido.');
      }
      $password = (string)($_POST['per_password'] ?? '');
      if ($password !== (string)($_POST['per_password_confirm'] ?? '')) {
        throw new RuntimeException('Las contraseñas no coinciden.');
      }
      if (strlen($password) < 8) {
        throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
      }

      $stmt = $pdo->prepare('SELECT 1 FROM candidatos WHERE email = ?');
      $stmt->execute([$email]);
      if ($stmt->fetchColumn()) {
        throw new RuntimeException('Ya existe una cuenta con este correo.');
      }

      $stmt = $pdo->prepare('SELECT 1 FROM empresa_cuentas WHERE email = ?');
      $stmt->execute([$email]);
      if ($stmt->fetchColumn()) {
        throw new RuntimeException('Este correo ya esta vinculado a una cuenta de empresa. Usa otro correo personal.');
      }

      $nombre = trim((string)($_POST['nombre'] ?? ''));
      $apellido = trim((string)($_POST['apellido'] ?? ''));
      $telefonoRaw = trim((string)($_POST['telefono'] ?? ''));
      $telefono = preg_replace('/\D+/', '', $telefonoRaw);
      if ($telefono === '' || strlen($telefono) < 7 || strlen($telefono) > 15) {
        throw new RuntimeException('Telefono invalido. Usa solo numeros (7 a 15).');
      }
      $_POST['telefono'] = $telefono;

      $docTipo = trim((string)($_POST['documento_tipo'] ?? ''));
      $docTiposPermitidos = ['CC','CE','pasaporte','Otro'];
      if (!in_array($docTipo, $docTiposPermitidos, true)) {
        throw new RuntimeException('Tipo de documento invalido.');
      }

      $docNumero = strtoupper(trim((string)($_POST['documento_numero'] ?? '')));
      if (!preg_match('/^[A-Z0-9]{5,20}$/', $docNumero)) {
        throw new RuntimeException('Numero de documento invalido. Usa 5 a 20 caracteres alfanumericos.');
      }
      $_POST['documento_numero'] = $docNumero;

      $pais = trim((string)($_POST['pais'] ?? ''));
      $ciudad = trim((string)($_POST['ciudad'] ?? ''));
      $direccion = trim((string)($_POST['direccion'] ?? ''));
      $perfil = trim((string)($_POST['perfil'] ?? ''));
      // Normaliza áreas de interés separadas por coma/Enter; evita entradas corruptas
      $areasInteresList = [];
      $areasRaw = $_POST['areas_interes'] ?? '';
      if (is_string($areasRaw)) {
        $areasInteresList = array_filter(array_map(static fn($v) => trim((string)$v), preg_split('/[,;\r\n]+/', $areasRaw)));
        $areasInteresList = array_values(array_unique($areasInteresList));
      }
      $areasInteres = implode(', ', $areasInteresList);
      if (strlen($nombre) > 80) { $nombre = substr($nombre, 0, 80); }
      if (strlen($apellido) > 80) { $apellido = substr($apellido, 0, 80); }
      if (strlen($pais) > 80) { $pais = substr($pais, 0, 80); }
      if (strlen($ciudad) > 80) { $ciudad = substr($ciudad, 0, 80); }
      if (strlen($direccion) > 160) { $direccion = substr($direccion, 0, 160); }
      if (strlen($areasInteres) > 160) { $areasInteres = substr($areasInteres, 0, 160); }
      if (strlen($perfil) > 1200) { $perfil = substr($perfil, 0, 1200); }

      $skillsData = [];
      $skillNamesRaw = $_POST['skill_name'] ?? [];
      $skillYearsRaw = $_POST['skill_years'] ?? [];
      if (is_array($skillNamesRaw) && is_array($skillYearsRaw)) {
        foreach ($skillNamesRaw as $idx => $skillNameRaw) {
          $skillName = trim((string)$skillNameRaw);
          $skillYears = isset($skillYearsRaw[$idx]) ? trim((string)$skillYearsRaw[$idx]) : '';
          if ($skillName === '') {
            continue;
          }
          $skillYears = rp_parse_years($skillYears);
          $skillsData[] = [
            'nombre' => $skillName,
            'anios'  => $skillYears,
          ];
        }
      }

      $experienciasData = [];
      $expRoles = $_POST['exp_role'] ?? [];
      $expEmpresas = $_POST['exp_company'] ?? [];
      $expPeriodos = $_POST['exp_period'] ?? [];
      $expAnos = $_POST['exp_years'] ?? [];
      $expDescs = $_POST['exp_desc'] ?? [];
      $expArchivos = rp_collect_files('exp_proof');
      if (is_array($expRoles)) {
        foreach ($expRoles as $idx => $roleRaw) {
          $role = trim((string)$roleRaw);
          $empresa = is_array($expEmpresas) && array_key_exists($idx, $expEmpresas) ? trim((string)$expEmpresas[$idx]) : '';
          $periodo = is_array($expPeriodos) && array_key_exists($idx, $expPeriodos) ? trim((string)$expPeriodos[$idx]) : '';
          $anios = is_array($expAnos) && array_key_exists($idx, $expAnos) ? trim((string)$expAnos[$idx]) : '';
          $descripcion = is_array($expDescs) && array_key_exists($idx, $expDescs) ? trim((string)$expDescs[$idx]) : '';
          if ($role === '' && $empresa === '' && $periodo === '' && $descripcion === '') {
            continue;
          }
          if ($role === '') {
            $role = 'Experiencia';
          }
          $experienciasData[] = [
            'cargo' => $role,
            'empresa' => $empresa !== '' ? $empresa : null,
            'periodo' => $periodo !== '' ? $periodo : null,
            'anios' => rp_parse_years($anios),
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'archivo' => $expArchivos[$idx] ?? null,
          ];
        }
      }

      $educacionData = [];
      $eduTitulos = $_POST['edu_title'] ?? [];
      $eduInstituciones = $_POST['edu_institution'] ?? [];
      $eduPeriodos = $_POST['edu_period'] ?? [];
      $eduDescs = $_POST['edu_desc'] ?? [];
      $eduArchivos = rp_collect_files('edu_proof');
      if (is_array($eduTitulos)) {
        foreach ($eduTitulos as $idx => $tituloRaw) {
          $titulo = trim((string)$tituloRaw);
          $institucion = is_array($eduInstituciones) && array_key_exists($idx, $eduInstituciones) ? trim((string)$eduInstituciones[$idx]) : '';
          $periodo = is_array($eduPeriodos) && array_key_exists($idx, $eduPeriodos) ? trim((string)$eduPeriodos[$idx]) : '';
          $descripcion = is_array($eduDescs) && array_key_exists($idx, $eduDescs) ? trim((string)$eduDescs[$idx]) : '';
          if ($titulo === '' && $institucion === '' && $periodo === '' && $descripcion === '') {
            continue;
          }
          if ($titulo === '') {
            $titulo = 'Estudio';
          }
          $educacionData[] = [
            'titulo' => $titulo,
            'institucion' => $institucion !== '' ? $institucion : null,
            'periodo' => $periodo !== '' ? $periodo : null,
            'descripcion' => $descripcion !== '' ? $descripcion : null,
            'archivo' => $eduArchivos[$idx] ?? null,
          ];
        }
      }

      $slug = rp_slug_from_email($email);
      $personaBase = $uploadsRoot.DIRECTORY_SEPARATOR.'candidatos';
      rp_ensure_directory($personaBase);
      $personaDir = $personaBase.DIRECTORY_SEPARATOR.$slug;
      rp_ensure_directory($personaDir);

      $cvDir = $personaDir.DIRECTORY_SEPARATOR.'cv';
      $fotoDir = $personaDir.DIRECTORY_SEPARATOR.'foto';
      $expCertDir = $personaDir.DIRECTORY_SEPARATOR.'experiencias';
      $eduCertDir = $personaDir.DIRECTORY_SEPARATOR.'educacion';
      $publicBase = '/uploads/candidatos/'.$slug;
      $publicExpBase = $publicBase.'/experiencias';
      $publicEduBase = $publicBase.'/educacion';
      $certAllowed = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/msword; charset=binary', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
      ];

      $cvInfo = rp_store_upload(
        $_FILES['cv'] ?? [],
        [
          'pdf' => ['application/pdf'],
          'doc' => ['application/msword', 'application/msword; charset=binary', 'application/vnd.ms-office', 'application/octet-stream'],
          'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ],
        $cvDir,
        $publicBase.'/cv',
        'cv',
        5 * 1024 * 1024
      );
      $cleanupFiles[] = $cvInfo['absolute'];

      $fotoInfo = null;
      $fotoFile = $_FILES['foto'] ?? null;
      if (is_array($fotoFile) && ($fotoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $fotoInfo = rp_store_upload(
          $fotoFile,
          [
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
          ],
          $fotoDir,
          $publicBase.'/foto',
          'foto',
          3 * 1024 * 1024
        );
        $cleanupFiles[] = $fotoInfo['absolute'];
      }

      $pdo->beginTransaction();
      try {
        $insert = $pdo->prepare('INSERT INTO candidatos (email, nombres, apellidos, telefono, password_hash, ciudad) VALUES (?,?,?,?,?,?)');
        $insert->execute([
          $email,
          $nombre,
          $apellido,
          $telefono,
          password_hash($password, PASSWORD_DEFAULT),
          $ciudad,
        ]);

        $docStmt = $pdo->prepare('INSERT INTO candidato_documentos (email, tipo, nombre_archivo, ruta, mime, tamano) VALUES (?,?,?,?,?,?)');
        $docStmt->execute([
          $email,
          'cv',
          $cvInfo['original'],
          $cvInfo['relative'],
          $cvInfo['mime'],
          $cvInfo['size'],
        ]);
        $cvId = (int)$pdo->lastInsertId();

        $detStmt = $pdo->prepare('INSERT INTO candidato_detalles (email, documento_tipo, documento_numero, pais, direccion, perfil, areas_interes, cv_documento_id, foto_nombre, foto_ruta, foto_mime, foto_tamano, acepta_datos_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $detStmt->execute([
          $email,
          $docTipo,
          $docNumero,
          $pais,
          $direccion !== '' ? $direccion : null,
          $perfil,
          $areasInteres !== '' ? $areasInteres : null,
          $cvId,
          $fotoInfo['original'] ?? null,
          $fotoInfo['relative'] ?? null,
          $fotoInfo['mime'] ?? null,
          $fotoInfo['size'] ?? null,
          (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $expCertStmt = $pdo->prepare('INSERT INTO candidato_experiencia_certificados (experiencia_id, documento_id) VALUES (?,?)');
        $eduCertStmt = $pdo->prepare('INSERT INTO candidato_educacion_certificados (educacion_id, documento_id) VALUES (?,?)');

        if ($skillsData) {
          $skillStmt = $pdo->prepare('INSERT INTO candidato_habilidades (email, nombre, anios_experiencia) VALUES (?,?,?)');
          foreach ($skillsData as $entry) {
            $skillStmt->execute([
              $email,
              $entry['nombre'],
              $entry['anios'],
            ]);
          }
        }

        if ($experienciasData) {
          $expStmt = $pdo->prepare('INSERT INTO candidato_experiencias (email, cargo, empresa, periodo, anios_experiencia, descripcion, orden) VALUES (?,?,?,?,?,?,?)');
          foreach (array_values($experienciasData) as $order => $exp) {
            $expStmt->execute([
              $email,
              $exp['cargo'],
              $exp['empresa'],
              $exp['periodo'],
              $exp['anios'],
              $exp['descripcion'],
              $order,
            ]);
            $expId = (int)$pdo->lastInsertId();
            $archivo = $exp['archivo'] ?? null;
            if (is_array($archivo) && (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
              $expDoc = rp_store_upload(
                $archivo,
                $certAllowed,
                $expCertDir,
                $publicExpBase,
                'exp-'.$order,
                5 * 1024 * 1024
              );
              $cleanupFiles[] = $expDoc['absolute'];
              $docStmt->execute([
                $email,
                'certificado',
                $expDoc['original'],
                $expDoc['relative'],
                $expDoc['mime'],
                $expDoc['size'],
              ]);
              $expDocId = (int)$pdo->lastInsertId();
              $expCertStmt->execute([$expId, $expDocId]);
            }
          }
        }

        if ($educacionData) {
          $eduStmt = $pdo->prepare('INSERT INTO candidato_educacion (email, titulo, institucion, periodo, descripcion, orden) VALUES (?,?,?,?,?,?)');
          foreach (array_values($educacionData) as $order => $edu) {
            $eduStmt->execute([
              $email,
              $edu['titulo'],
              $edu['institucion'],
              $edu['periodo'],
              $edu['descripcion'],
              $order,
            ]);
            $eduId = (int)$pdo->lastInsertId();
            $archivo = $edu['archivo'] ?? null;
            if (is_array($archivo) && (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
              $eduDoc = rp_store_upload(
                $archivo,
                $certAllowed,
                $eduCertDir,
                $publicEduBase,
                'edu-'.$order,
                5 * 1024 * 1024
              );
              $cleanupFiles[] = $eduDoc['absolute'];
              $docStmt->execute([
                $email,
                'certificado',
                $eduDoc['original'],
                $eduDoc['relative'],
                $eduDoc['mime'],
                $eduDoc['size'],
              ]);
              $eduDocId = (int)$pdo->lastInsertId();
              $eduCertStmt->execute([$eduId, $eduDocId]);
            }
          }
        }

        $pdo->commit();
      } catch (Throwable $tx) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foreach ($cleanupFiles as $path) {
          if (is_file($path)) { @unlink($path); }
        }
        throw $tx;
      }

      $cleanupFiles = [];
      $okMsg = '¡Registro de usuario creado!';
      $_POST = [];
      $skillNamesRaw = $skillYearsRaw = [];
      $experienciasData = [];
      $educacionData = [];
    } catch (Throwable $e) {
      foreach ($cleanupFiles as $path) {
        if (is_file($path)) { @unlink($path); }
      }
      $errMsg = $e->getMessage();
    }
  }
}

$skillFormNames = array_values(is_array($skillNamesRaw ?? null) ? $skillNamesRaw : []);
$skillFormYears = array_values(is_array($skillYearsRaw ?? null) ? $skillYearsRaw : []);
if (!$skillFormNames) { $skillFormNames = ['']; }
if (count($skillFormYears) < count($skillFormNames)) { $skillFormYears = array_pad($skillFormYears, count($skillFormNames), ''); }
$expFormRoles = array_values(is_array($expRoles ?? null) ? $expRoles : []);
$expFormEmpresas = array_values(is_array($expEmpresas ?? null) ? $expEmpresas : []);
$expFormPeriodos = array_values(is_array($expPeriodos ?? null) ? $expPeriodos : []);
$expFormAnos = array_values(is_array($expAnos ?? null) ? $expAnos : []);
$expFormDescs = array_values(is_array($expDescs ?? null) ? $expDescs : []);
if (!$expFormRoles) { $expFormRoles = ['']; }
if (count($expFormEmpresas) < count($expFormRoles)) { $expFormEmpresas = array_pad($expFormEmpresas, count($expFormRoles), ''); }
if (count($expFormPeriodos) < count($expFormRoles)) { $expFormPeriodos = array_pad($expFormPeriodos, count($expFormRoles), ''); }
if (count($expFormAnos) < count($expFormRoles)) { $expFormAnos = array_pad($expFormAnos, count($expFormRoles), ''); }
if (count($expFormDescs) < count($expFormRoles)) { $expFormDescs = array_pad($expFormDescs, count($expFormRoles), ''); }

$eduFormTitulos = array_values(is_array($eduTitulos ?? null) ? $eduTitulos : []);
$eduFormInstituciones = array_values(is_array($eduInstituciones ?? null) ? $eduInstituciones : []);
$eduFormPeriodos = array_values(is_array($eduPeriodos ?? null) ? $eduPeriodos : []);
$eduFormDescs = array_values(is_array($eduDescs ?? null) ? $eduDescs : []);
if (!$eduFormTitulos) { $eduFormTitulos = ['']; }
if (count($eduFormInstituciones) < count($eduFormTitulos)) { $eduFormInstituciones = array_pad($eduFormInstituciones, count($eduFormTitulos), ''); }
if (count($eduFormPeriodos) < count($eduFormTitulos)) { $eduFormPeriodos = array_pad($eduFormPeriodos, count($eduFormTitulos), ''); }
if (count($eduFormDescs) < count($eduFormTitulos)) { $eduFormDescs = array_pad($eduFormDescs, count($eduFormTitulos), ''); }
?>

<!-- Registro de Persona (parcial) -->
<section class="section container">
  <div class="portal-head">
    <h1>Crea tu cuenta — Persona</h1>
    <p class="muted">Regístrate para postularte a oportunidades del SENA. Completa tu cuenta, datos básicos y preferencias profesionales; después podrás cargar tu hoja de vida y postularte.</p>
  </div>

  <?php if ($okMsg): ?>
    <div class="card" style="border-color:#d6f5d6;background:#f3fff3"><strong><?=htmlspecialchars($okMsg)?></strong></div>
  <?php elseif ($errMsg): ?>
    <div class="card" style="border-color:#ffd5d5;background:#fff6f6"><strong>Error:</strong> <?=htmlspecialchars($errMsg)?></div>
  <?php endif; ?>

  <form class="card form" method="post" enctype="multipart/form-data" data-validate="instant" novalidate>
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>" />

    <section>
      <h2>Cuenta</h2>
      <div class="g-3">
        <div class="field"><label for="per_email">Correo *</label><input id="per_email" name="per_email" type="email" required placeholder="tu@email.com" value="<?=htmlspecialchars($_POST['per_email'] ?? '')?>"/></div>
        <div class="field"><label for="per_password">Contrase&ntilde;a *</label><input id="per_password" name="per_password" type="password" minlength="8" autocomplete="new-password" required placeholder="M&iacute;nimo 8 caracteres" /></div>
        <div class="field"><label for="per_password_confirm">Confirmar contrase&ntilde;a *</label><input id="per_password_confirm" name="per_password_confirm" type="password" minlength="8" autocomplete="new-password" required placeholder="Repite tu contrase&ntilde;a" data-match="#per_password" data-match-message="Las contrase&ntilde;as no coinciden." /></div>
      </div>
    </section>

    <section>
      <h2>Datos personales</h2>
      <div class="g-3">
        <div class="field"><label for="nombre">Nombre *</label><input id="nombre" name="nombre" type="text" maxlength="80" required placeholder="Nombre" value="<?=htmlspecialchars($_POST['nombre'] ?? '')?>"/></div>
        <div class="field"><label for="apellido">Apellido *</label><input id="apellido" name="apellido" type="text" maxlength="80" required placeholder="Apellido" value="<?=htmlspecialchars($_POST['apellido'] ?? '')?>"/></div>
        <div class="field"><label for="telefono">Telefono *</label><input id="telefono" name="telefono" type="tel" inputmode="numeric" pattern="\d{7,15}" maxlength="15" data-normalize="digits" data-pattern-message="Usa solo numeros (7 a 15)." required placeholder="+57 320 000 0000" value="<?=htmlspecialchars($_POST['telefono'] ?? '')?>"/></div>
        <div class="field"><label for="documento_tipo">Tipo de documento *</label><select id="documento_tipo" name="documento_tipo" required><option value="">Selecciona</option><?php foreach (["CC","CE","pasaporte","Otro"] as $op): ?><option <?=$op===($_POST['documento_tipo'] ?? '')?'selected':''?>><?=$op?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="documento_numero">N&uacute;mero de documento *</label><input id="documento_numero" name="documento_numero" type="text" inputmode="text" pattern="[A-Za-z0-9]{5,20}" maxlength="20" data-pattern-message="Ingresa entre 5 y 20 caracteres alfanumericos, sin espacios." required placeholder="123456789" value="<?=htmlspecialchars($_POST['documento_numero'] ?? '')?>"/></div>
      </div>
    </section>

    <section>
      <h2>Ubicacion</h2>
      <div class="g-3">
        <div class="field"><label for="pais">Pa&iacute;s *</label><input id="pais" name="pais" type="text" maxlength="80" required placeholder="Colombia" value="<?=htmlspecialchars($_POST['pais'] ?? '')?>"/></div>
        <div class="field"><label for="ciudad">Ciudad *</label><input id="ciudad" name="ciudad" type="text" maxlength="80" required placeholder="Medell&iacute;n" value="<?=htmlspecialchars($_POST['ciudad'] ?? '')?>"/></div>
        <div class="field"><label for="direccion">Direcci&oacute;n (opcional)</label><input id="direccion" name="direccion" type="text" maxlength="160" placeholder="Calle 00 # 00-00" value="<?=htmlspecialchars($_POST['direccion'] ?? '')?>"/></div>
      </div>
    </section>

    <section>
      <h2>Perfil profesional</h2>
      <div class="g-2">
        <div class="field"><label for="perfil">Resumen de perfil *</label><textarea id="perfil" name="perfil" rows="4" maxlength="1200" required placeholder="Tu experiencia, habilidades y objetivos…"><?=htmlspecialchars($_POST['perfil'] ?? '')?></textarea></div>
        <div class="field">
          <label for="area_input">&Aacute;reas de inter&eacute;s</label>
          <div class="chip-input" id="areas-wrapper">
            <div id="areas_chips" class="chips"></div>
            <div style="display:flex; gap:.5rem; margin-top:.35rem;">
              <input id="area_input" type="text" maxlength="80" placeholder="Escribe un &aacute;rea y presiona Enter o A&ntilde;adir" />
              <button type="button" class="btn btn-outline" id="area_add_btn">A&ntilde;adir</button>
            </div>
            <small class="muted">Agrega una por una; se guardan como etiquetas (ej. Desarrollo, Dise&ntilde;o instruccional, Calidad).</small>
            <input id="areas_interes" name="areas_interes" type="hidden" value="<?=htmlspecialchars($_POST['areas_interes'] ?? '')?>" />
          </div>
        </div>
      </div>
    </section>

    <section>
      <h2>Habilidades y experiencia</h2>
      <p class="muted">Añade habilidades clave y su nivel/experiencia. Puedes agregar o quitar según necesites.</p>
      <div id="skill-list" data-collection="skill">
        <?php foreach ($skillFormNames as $i => $val): ?>
          <div class="card skill-item" style="padding:var(--sp-2);margin-block:var(--sp-2);" data-index="<?=$i?>">
            <div class="g-3" style="align-items:end;">
              <div class="field">
                <label for="skill_name_<?=$i?>" data-num-label="Habilidad">Habilidad <?=($i+1)?></label>
                <input id="skill_name_<?=$i?>" name="skill_name[]" type="text" placeholder="Ej: Comunicación, Liderazgo, SQL" value="<?=htmlspecialchars($skillFormNames[$i] ?? '')?>"/>
              </div>
              <div class="field">
                <label for="skill_years_<?=$i?>">Nivel / experiencia</label>
                <input id="skill_years_<?=$i?>" name="skill_years[]" type="text" maxlength="40" placeholder="Ej: 4 años, Intermedio, Senior" value="<?=htmlspecialchars($skillFormYears[$i] ?? '')?>"/>
              </div>
              <div class="field" style="display:flex;align-items:center;justify-content:flex-end;">
                <?php if ($i > 0): ?><button type="button" class="btn btn-ghost danger" data-remove-row=".skill-item">Eliminar</button><?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-add" data-add-row="#skill-template" data-target="#skill-list">Agregar habilidad</button>
    </section>

    <section>
      <h2>Experiencia profesional</h2>
      <p class="muted">Información opcional. Puedes completarla más adelante desde tu perfil. Añade tantas experiencias como necesites, incluyendo voluntariados o prácticas, y opcionalmente sube un soporte (PDF/imagen).</p>
      <div id="exp-list" data-collection="exp">
        <?php foreach ($expFormRoles as $i => $val): ?>
          <fieldset class="card exp-item" style="padding:var(--sp-2);margin-block:var(--sp-2);" data-index="<?=$i?>">
            <legend data-num-label="Experiencia">Experiencia <?=($i+1)?></legend>
            <?php if ($i > 0): ?>
              <div class="field" style="display:flex;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost danger" data-remove-row=".exp-item">Eliminar experiencia</button>
              </div>
            <?php endif; ?>
            <div class="g-2">
              <div class="field">
                <label for="exp_role_<?=$i?>">Cargo / Rol</label>
                <input id="exp_role_<?=$i?>" name="exp_role[]" type="text" placeholder="Ej: Auxiliar de Bodega" value="<?=htmlspecialchars($expFormRoles[$i] ?? '')?>"/>
              </div>
              <div class="field">
                <label for="exp_company_<?=$i?>">Empresa / Entidad</label>
                <input id="exp_company_<?=$i?>" name="exp_company[]" type="text" placeholder="Ej: Logisur" value="<?=htmlspecialchars($expFormEmpresas[$i] ?? '')?>"/>
              </div>
            </div>
            <div class="g-3">
              <div class="field">
                <label for="exp_period_<?=$i?>">Periodo</label>
                <input id="exp_period_<?=$i?>" name="exp_period[]" type="text" placeholder="Ej: 2023-2025" value="<?=htmlspecialchars($expFormPeriodos[$i] ?? '')?>"/>
              </div>
              <div class="field">
                <label for="exp_years_<?=$i?>">Nivel / experiencia</label>
                <input id="exp_years_<?=$i?>" name="exp_years[]" type="text" maxlength="40" placeholder="Ej: 2 años, Junior, Liderando" value="<?=htmlspecialchars($expFormAnos[$i] ?? '')?>"/>
              </div>
            </div>
            <div class="field">
              <label for="exp_desc_<?=$i?>">Descripción</label>
              <textarea id="exp_desc_<?=$i?>" name="exp_desc[]" rows="3" placeholder="Principales responsabilidades, logros, herramientas…"><?=htmlspecialchars($expFormDescs[$i] ?? '')?></textarea>
            </div>
            <div class="field">
              <label for="exp_proof_<?=$i?>">Soporte (PDF/Imagen)</label>
              <input id="exp_proof_<?=$i?>" name="exp_proof[]" type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" />
              <small class="muted">Opcional. Se usará para validar la experiencia.</small>
            </div>
          </fieldset>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-add" data-add-row="#exp-template" data-target="#exp-list">Agregar experiencia</button>
    </section>

    <section>
      <h2>Formación académica</h2>
      <div id="edu-list" data-collection="edu">
        <?php foreach ($eduFormTitulos as $i => $val): ?>
          <fieldset class="card edu-item" style="padding:var(--sp-2);margin-block:var(--sp-2);" data-index="<?=$i?>">
            <legend data-num-label="Estudio">Estudio <?=($i+1)?></legend>
            <?php if ($i > 0): ?>
              <div class="field" style="display:flex;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost danger" data-remove-row=".edu-item">Eliminar estudio</button>
              </div>
            <?php endif; ?>
            <div class="g-2">
              <div class="field">
                <label for="edu_title_<?=$i?>">Programa / Título</label>
                <input id="edu_title_<?=$i?>" name="edu_title[]" type="text" placeholder="Ej: Tecnólogo en Logística" value="<?=htmlspecialchars($eduFormTitulos[$i] ?? '')?>"/>
              </div>
              <div class="field">
                <label for="edu_institution_<?=$i?>">Institución</label>
                <input id="edu_institution_<?=$i?>" name="edu_institution[]" type="text" placeholder="Ej: SENA" value="<?=htmlspecialchars($eduFormInstituciones[$i] ?? '')?>"/>
              </div>
            </div>
            <div class="g-2">
              <div class="field">
                <label for="edu_period_<?=$i?>">Periodo</label>
                <input id="edu_period_<?=$i?>" name="edu_period[]" type="text" placeholder="Ej: 2019-2021" value="<?=htmlspecialchars($eduFormPeriodos[$i] ?? '')?>"/>
              </div>
            </div>
            <div class="field">
              <label for="edu_desc_<?=$i?>">Descripción (opcional)</label>
              <textarea id="edu_desc_<?=$i?>" name="edu_desc[]" rows="2" placeholder="Logros, énfasis o actividades destacadas."><?=htmlspecialchars($eduFormDescs[$i] ?? '')?></textarea>
            </div>
            <div class="field">
              <label for="edu_proof_<?=$i?>">Soporte (certificado/imagen)</label>
              <input id="edu_proof_<?=$i?>" name="edu_proof[]" type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" />
              <small class="muted">Opcional. Adjunta diploma, certificado o constancia.</small>
            </div>
          </fieldset>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-add" data-add-row="#edu-template" data-target="#edu-list">Agregar estudio</button>
    </section>

    <section>
      <h2>Hoja de vida</h2>
      <div class="g-2">
        <div class="dropzone"><label for="cv">Subir CV (PDF/DOC) *</label><input id="cv" name="cv" type="file" accept=".pdf,.doc,.docx" required /><small>Hasta 5MB.</small></div>
        <div class="dropzone"><label for="foto">Foto (opcional)</label><input id="foto" name="foto" type="file" accept=".png,.jpg,.jpeg" /><small>PNG/JPG</small></div>
      </div>
    </section>

    <section>
      <label class="check"><input type="checkbox" name="acepta_datos" required /> <span>Acepto politicas de tratamiento de datos y <a href="#">terminos</a>. *</span></label>
    </section>

    <template id="exp-template">
      <fieldset class="card exp-item" style="padding:var(--sp-2);margin-block:var(--sp-2);" data-index="__INDEX__">
        <legend data-num-label="Experiencia">Experiencia __NUM__</legend>
        <div class="field" style="display:flex;justify-content:flex-end;">
          <button type="button" class="btn btn-ghost danger" data-remove-row=".exp-item">Eliminar experiencia</button>
        </div>
        <div class="g-2">
          <div class="field">
            <label for="exp_role___INDEX__">Cargo / Rol</label>
            <input id="exp_role___INDEX__" name="exp_role[]" type="text" placeholder="Ej: Auxiliar de Bodega"/>
          </div>
          <div class="field">
            <label for="exp_company___INDEX__">Empresa / Entidad</label>
            <input id="exp_company___INDEX__" name="exp_company[]" type="text" placeholder="Ej: Logisur"/>
          </div>
        </div>
        <div class="g-3">
          <div class="field">
            <label for="exp_period___INDEX__">Periodo</label>
            <input id="exp_period___INDEX__" name="exp_period[]" type="text" placeholder="Ej: 2023-2025"/>
          </div>
          <div class="field">
            <label for="exp_years___INDEX__">Nivel / experiencia</label>
            <input id="exp_years___INDEX__" name="exp_years[]" type="text" maxlength="40" placeholder="Ej: 2 años, Junior, Liderando"/>
          </div>
        </div>
        <div class="field">
          <label for="exp_desc___INDEX__">Descripción</label>
          <textarea id="exp_desc___INDEX__" name="exp_desc[]" rows="3" placeholder="Principales responsabilidades, logros, herramientas…"></textarea>
        </div>
        <div class="field">
          <label for="exp_proof___INDEX__">Soporte (PDF/Imagen)</label>
          <input id="exp_proof___INDEX__" name="exp_proof[]" type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" />
          <small class="muted">Opcional. Se usará para validar la experiencia.</small>
        </div>
      </fieldset>
    </template>

    <template id="skill-template">
      <div class="card skill-item" style="padding:var(--sp-2);margin-block:var(--sp-2);" data-index="__INDEX__">
        <div class="g-3" style="align-items:end;">
          <div class="field">
            <label for="skill_name___INDEX__" data-num-label="Habilidad">Habilidad __NUM__</label>
            <input id="skill_name___INDEX__" name="skill_name[]" type="text" placeholder="Ej: Comunicación, Liderazgo, SQL"/>
          </div>
          <div class="field">
            <label for="skill_years___INDEX__">Nivel / experiencia</label>
            <input id="skill_years___INDEX__" name="skill_years[]" type="text" maxlength="40" placeholder="Ej: 4 años, Intermedio, Senior"/>
          </div>
          <div class="field" style="display:flex;align-items:center;justify-content:flex-end;">
            <button type="button" class="btn btn-ghost danger" data-remove-row=".skill-item">Eliminar</button>
          </div>
        </div>
      </div>
    </template>

    <template id="edu-template">
      <fieldset class="card edu-item" style="padding:var(--sp-2);margin-block:var(--sp-2);" data-index="__INDEX__">
        <legend data-num-label="Estudio">Estudio __NUM__</legend>
        <div class="field" style="display:flex;justify-content:flex-end;">
          <button type="button" class="btn btn-ghost danger" data-remove-row=".edu-item">Eliminar estudio</button>
        </div>
        <div class="g-2">
          <div class="field">
            <label for="edu_title___INDEX__">Programa / Título</label>
            <input id="edu_title___INDEX__" name="edu_title[]" type="text" placeholder="Ej: Tecnólogo en Logística"/>
          </div>
          <div class="field">
            <label for="edu_institution___INDEX__">Institución</label>
            <input id="edu_institution___INDEX__" name="edu_institution[]" type="text" placeholder="Ej: SENA"/>
          </div>
        </div>
        <div class="g-2">
          <div class="field">
            <label for="edu_period___INDEX__">Periodo</label>
            <input id="edu_period___INDEX__" name="edu_period[]" type="text" placeholder="Ej: 2019-2021"/>
          </div>
        </div>
        <div class="field">
          <label for="edu_desc___INDEX__">Descripción (opcional)</label>
          <textarea id="edu_desc___INDEX__" name="edu_desc[]" rows="2" placeholder="Logros, énfasis o actividades destacadas."></textarea>
        </div>
        <div class="field">
          <label for="edu_proof___INDEX__">Soporte (certificado/imagen)</label>
          <input id="edu_proof___INDEX__" name="edu_proof[]" type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" />
          <small class="muted">Opcional. Adjunta diploma, certificado o constancia.</small>
        </div>
      </fieldset>
    </template>

    <div class="actions">
      <button type="button" class="btn btn-secondary" onclick="history.back()">Cancelar</button>
      <button type="submit" class="btn btn-primary">Crear cuenta</button>
    </div>
  </form>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Chips para áreas de interés
  const hidden = document.getElementById('areas_interes');
  const input = document.getElementById('area_input');
  const addBtn = document.getElementById('area_add_btn');
  const chipsBox = document.getElementById('areas_chips');
  const parseList = (text) => {
    return Array.from(new Set(
      (text || '')
        .split(/[,;\r\n]+/)
        .map(t => t.trim())
        .filter(Boolean)
    ));
  };
  let tags = parseList(hidden ? hidden.value : '');

  function render() {
    if (!chipsBox) { return; }
    chipsBox.innerHTML = '';
    tags.forEach((tag, idx) => {
      const chip = document.createElement('span');
      chip.className = 'chip';
      chip.textContent = tag;
      chip.style.margin = '.15rem';
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = '×';
      remove.style.marginLeft = '.35rem';
      remove.style.border = 'none';
      remove.style.background = 'transparent';
      remove.style.cursor = 'pointer';
      remove.onclick = () => {
        tags.splice(idx, 1);
        sync();
      };
      chip.appendChild(remove);
      chipsBox.appendChild(chip);
    });
  }

  function sync() {
    if (hidden) { hidden.value = tags.join(', '); }
    render();
  }

  function addTag() {
    const val = (input?.value || '').trim();
    if (!val) return;
    if (!tags.includes(val)) { tags.push(val); }
    if (input) { input.value = ''; }
    sync();
  }

  if (input) {
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        addTag();
      }
    });
  }
  if (addBtn) { addBtn.addEventListener('click', addTag); }
  sync();
});
</script>



