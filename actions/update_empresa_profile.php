<?php
declare(strict_types=1);

// Acceso solo a través de index.php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=perfil_empresa');
  exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// CSRF
if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf'])) {
  http_response_code(400);
  echo 'Solicitud inválida (CSRF).';
  exit;
}

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['type'] ?? '') !== 'empresa') {
  header('Location: index.php?view=login');
  exit;
}

require __DIR__.'/../pages/db.php';
if (!($pdo instanceof PDO)) {
  $_SESSION['flash_empresa'] = 'No hay conexión con la base de datos.';
  header('Location: index.php?view=perfil_empresa');
  exit;
}

$empresaId = (int)($user['empresa_id'] ?? 0);
$empresaEmail = (string)($user['email'] ?? '');
if ($empresaId <= 0) {
  $_SESSION['flash_empresa'] = 'No encontramos la empresa asociada a tu cuenta.';
  header('Location: index.php?view=perfil_empresa');
  exit;
}

// Helper: normaliza vacíos a NULL
$val = static function (?string $s): ?string {
  $s = trim((string)$s);
  return $s === '' ? null : $s;
};

// Datos básicos
$nombreComercial = $val($_POST['nombre_comercial'] ?? null);
$descripcion     = $val($_POST['descripcion'] ?? null);
$telefono        = $val($_POST['telefono'] ?? null);
$emailContacto   = $val($_POST['email_contacto'] ?? null);
$ciudad          = $val($_POST['ciudad'] ?? null);
$sitioWeb        = $val($_POST['sitio_web'] ?? null);
$linkedin        = $val($_POST['linkedin'] ?? null);
$facebook        = $val($_POST['facebook'] ?? null);
$instagram       = $val($_POST['instagram'] ?? null);
// Subida de archivos (logo/portada) - PNG/JPG
// Helpers de subida
$rootPath = dirname(__DIR__);
$uploadsRoot = $rootPath.DIRECTORY_SEPARATOR.'uploads';
$ensureDir = static function (string $path): void {
  if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
    throw new RuntimeException('No se pudo crear directorio: '.$path);
  }
};
$slugify = static function (string $value): string {
  $value = strtolower(trim($value));
  $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
  return trim((string)$value, '-') ?: 'empresa';
};
$companySlug = static function (string $nombre, string $nit) use ($slugify): string {
  $base = $slugify($nombre !== '' ? $nombre : $nit);
  $nitSlug = preg_replace('/[^a-z0-9]+/i', '', strtolower($nit));
  return $nitSlug !== '' ? ($base.'-'.$nitSlug) : ($base !== '' ? $base : 'empresa');
};

// Busca datos para crear el slug
$slug = 'empresa';
try {
  $slugStmt = $pdo->prepare('SELECT razon_social, nombre_comercial, nit FROM empresas WHERE id = ? LIMIT 1');
  $slugStmt->execute([$empresaId]);
  if ($row = $slugStmt->fetch(PDO::FETCH_ASSOC)) {
    $slug = $companySlug((string)($row['nombre_comercial'] ?? $row['razon_social'] ?? 'empresa'), (string)($row['nit'] ?? ''));
  }
} catch (Throwable $e) {
  error_log('[update_empresa_profile][slug] '.$e->getMessage());
}

$empresaBase = $uploadsRoot.DIRECTORY_SEPARATOR.'empresas';
$ensureDir($empresaBase);
$empresaDir = $empresaBase.DIRECTORY_SEPARATOR.$slug;
$ensureDir($empresaDir);
$logoDir = $empresaDir.DIRECTORY_SEPARATOR.'logo';
$portadaDir = $empresaDir.DIRECTORY_SEPARATOR.'portada';
$ensureDir($logoDir);
$ensureDir($portadaDir);
$publicBase = '/uploads/empresas/'.$slug;

$processUpload = static function (?array $file, string $targetDir, string $publicPrefix, string $prefix, int $maxBytes = (5*1024*1024)) use ($ensureDir): ?string {
  if (!is_array($file)) { return null; }
  $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($err === UPLOAD_ERR_NO_FILE) { return null; }
  if ($err !== UPLOAD_ERR_OK) { throw new RuntimeException('Error de subida (código '.$err.')'); }
  $tmp = (string)($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) { throw new RuntimeException('Archivo subido inválido.'); }
  $size = (int)($file['size'] ?? 0);
  if ($size <= 0 || $size > $maxBytes) { throw new RuntimeException('Archivo excede el tamaño permitido (máx. '.round($maxBytes/(1024*1024),1).' MB).'); }
  $original = basename((string)($file['name'] ?? 'imagen'));
  $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
  if (!in_array($ext, ['png','jpg','jpeg'], true)) { throw new RuntimeException('Solo se permiten PNG/JPG.'); }
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = $finfo ? (finfo_file($finfo, $tmp) ?: '') : '';
  if ($finfo) { finfo_close($finfo); }
  if (!in_array($mime, ['image/png','image/jpeg'], true)) { throw new RuntimeException('Tipo de imagen no permitido: '.$mime); }
  $ensureDir($targetDir);
  $safePrefix = trim(preg_replace('/[^a-z0-9]+/i', '-', $prefix) ?? '', '-');
  if ($safePrefix === '') { $safePrefix = 'img'; }
  $filename = sprintf('%s-%s.%s', $safePrefix, bin2hex(random_bytes(6)), $ext);
  $dest = $targetDir.DIRECTORY_SEPARATOR.$filename;
  if (!move_uploaded_file($tmp, $dest)) { throw new RuntimeException('No se pudo guardar la imagen.'); }
  $relative = rtrim($publicPrefix, '/').'/'.$filename;
  return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
};

$logoUrl         = null;
$portadaUrl      = null;
try {
  $logoUrl = $processUpload($_FILES['logo'] ?? null, $logoDir, $publicBase.'/logo', 'logo');
} catch (Throwable $e) { error_log('[update_empresa_profile][logo] '.$e->getMessage()); }
try {
  $portadaUrl = $processUpload($_FILES['portada'] ?? null, $portadaDir, $publicBase.'/portada', 'portada');
} catch (Throwable $e) { error_log('[update_empresa_profile][portada] '.$e->getMessage()); }

// Detalles
$pais            = $val($_POST['pais'] ?? null);
$tipoEntidad     = $val($_POST['tipo_entidad'] ?? null);
$anioFundacion   = $val($_POST['anio_fundacion'] ?? null);
$modalidad       = $val($_POST['modalidad'] ?? null);
$areasTxt        = $val($_POST['areas'] ?? null);
$tecnologiasTxt  = $val($_POST['tecnologias'] ?? null);
$mision          = $val($_POST['mision'] ?? null);
$valoresTxt      = $val($_POST['valores'] ?? null);
$xUrl            = $val($_POST['x'] ?? null);
$youtubeUrl      = $val($_POST['youtube'] ?? null);
$glassdoorUrl    = $val($_POST['glassdoor'] ?? null);

// Contacto cuenta
$contactoNombre  = $val($_POST['contacto_nombre'] ?? null);
$contactoTelefono= $val($_POST['contacto_telefono'] ?? null);

$pdo->beginTransaction();
try {
  // Actualiza tabla empresas
  $updEmp = $pdo->prepare(
    'UPDATE empresas
        SET nombre_comercial = COALESCE(:nombre_comercial, nombre_comercial),
            descripcion      = COALESCE(:descripcion, descripcion),
            telefono         = COALESCE(:telefono, telefono),
            email_contacto   = COALESCE(:email_contacto, email_contacto),
            ciudad           = COALESCE(:ciudad, ciudad),
            sitio_web        = COALESCE(:sitio_web, sitio_web),
            logo_url         = COALESCE(:logo_url, logo_url),
            portada_url      = COALESCE(:portada_url, portada_url),
            linkedin_url     = COALESCE(:linkedin, linkedin_url),
            facebook_url     = COALESCE(:facebook, facebook_url),
            instagram_url    = COALESCE(:instagram, instagram_url),
            updated_at       = NOW()
      WHERE id = :empresa_id'
  );
  $updEmp->execute([
    ':nombre_comercial' => $nombreComercial,
    ':descripcion'      => $descripcion,
    ':telefono'         => $telefono,
    ':email_contacto'   => $emailContacto,
    ':ciudad'           => $ciudad,
    ':sitio_web'        => $sitioWeb,
    ':logo_url'         => $logoUrl,
    ':portada_url'      => $portadaUrl,
    ':linkedin'         => $linkedin,
    ':facebook'         => $facebook,
    ':instagram'        => $instagram,
    ':empresa_id'       => $empresaId,
  ]);

  // Asegura existencia de empresa_detalles
  $pdo->exec('INSERT IGNORE INTO empresa_detalles (empresa_id) VALUES ('.(int)$empresaId.')');
  $updDet = $pdo->prepare(
    'UPDATE empresa_detalles
        SET pais              = COALESCE(:pais, pais),
            tipo_entidad      = COALESCE(:tipo_entidad, tipo_entidad),
            anio_fundacion    = COALESCE(:anio_fundacion, anio_fundacion),
            modalidad_trabajo = COALESCE(:modalidad, modalidad_trabajo),
            areas_contratacion= COALESCE(:areas, areas_contratacion),
            tecnologias       = COALESCE(:tecnologias, tecnologias),
            mision            = COALESCE(:mision, mision),
            valores           = COALESCE(:valores, valores),
            link_x            = COALESCE(:x, link_x),
            link_youtube      = COALESCE(:youtube, link_youtube),
            link_glassdoor    = COALESCE(:glassdoor, link_glassdoor)
      WHERE empresa_id = :empresa_id'
  );
  $updDet->execute([
    ':pais'           => $pais,
    ':tipo_entidad'   => $tipoEntidad,
    ':anio_fundacion' => $anioFundacion,
    ':modalidad'      => $modalidad,
    ':areas'          => $areasTxt,
    ':tecnologias'    => $tecnologiasTxt,
    ':mision'         => $mision,
    ':valores'        => $valoresTxt,
    ':x'              => $xUrl,
    ':youtube'        => $youtubeUrl,
    ':glassdoor'      => $glassdoorUrl,
    ':empresa_id'     => $empresaId,
  ]);

  // Actualiza contacto de la cuenta
  if ($contactoNombre !== null || $contactoTelefono !== null) {
    $updAcc = $pdo->prepare(
      'UPDATE empresa_cuentas SET nombre_contacto = COALESCE(:nombre, nombre_contacto), telefono = COALESCE(:tel, telefono)
        WHERE empresa_id = :empresa_id AND email = :email LIMIT 1'
    );
    $updAcc->execute([
      ':nombre'     => $contactoNombre,
      ':tel'        => $contactoTelefono,
      ':empresa_id' => $empresaId,
      ':email'      => $empresaEmail,
    ]);
  }

  $pdo->commit();
  $_SESSION['flash_empresa'] = 'Perfil de empresa actualizado correctamente.';
} catch (Throwable $e) {
  $pdo->rollBack();
  error_log('[update_empresa_profile] '.$e->getMessage());
  $_SESSION['flash_empresa'] = 'Ocurrió un error al actualizar el perfil de la empresa.';
}

header('Location: index.php?view=perfil_empresa');
exit;
  // Guarda documentos en histórico si se subieron
  if ($logoUrl || $portadaUrl) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS empresa_documentos (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      empresa_id BIGINT UNSIGNED NOT NULL,
      tipo VARCHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
      nombre_archivo VARCHAR(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      ruta VARCHAR(512) COLLATE utf8mb4_unicode_ci NOT NULL,
      mime VARCHAR(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      tamano INT UNSIGNED DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id), KEY idx_doc_empresa (empresa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $doc = $pdo->prepare('INSERT INTO empresa_documentos (empresa_id, tipo, nombre_archivo, ruta, mime, tamano) VALUES (?,?,?,?,?,?)');
    if ($logoUrl) { $doc->execute([$empresaId, 'logo', null, $logoUrl, null, null]); }
    if ($portadaUrl) { $doc->execute([$empresaId, 'portada', null, $portadaUrl, null, null]); }
  }