<?php
// Bloquear acceso directo: solo via index.php?view=register-empresa
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=register-empresa');
  exit;
}
// Inicia sesion solo si no esta activa
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__.'/db.php';

if (!function_exists('re_slugify')) {
  function re_slugify(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = trim((string)$value, '-');
    return $value !== '' ? $value : 'empresa';
  }
}

if (!function_exists('re_company_slug')) {
  function re_company_slug(string $nombre, string $nit): string {
    $base = re_slugify($nombre !== '' ? $nombre : $nit);
    $nitSlug = preg_replace('/[^a-z0-9]+/i', '', strtolower($nit));
    if ($nitSlug !== '') {
      $base = $base.'-'.$nitSlug;
    }
    return $base !== '' ? $base : 'empresa';
  }
}

if (!function_exists('re_ensure_directory')) {
  function re_ensure_directory(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
      throw new RuntimeException('No se pudo crear el directorio: '.$path);
    }
  }
}

if (!function_exists('re_process_upload')) {
  /**
   * @param array{error?:int,tmp_name?:string,name?:string,size?:int}|null $file
   * @param bool $required
   * @param array<string,string[]> $allowed
   * @return array{absolute:string,relative:string,original:string,mime:string,size:int}|null
   */
  function re_process_upload(?array $file, bool $required, array $allowed, string $targetDir, string $publicPrefix, string $namePrefix, int $maxBytes): ?array {
    if (!is_array($file)) {
      if ($required) { throw new RuntimeException('Falta archivo requerido.'); }
      return null;
    }
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
      if ($required) { throw new RuntimeException('Falta archivo requerido.'); }
      return null;
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
    re_ensure_directory($targetDir);
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

if (!function_exists('re_remove_accents')) {
  function re_remove_accents(string $value): string {
    $map = [
      "\u{00E0}" => 'a', "\u{00E1}" => 'a', "\u{00E2}" => 'a', "\u{00E3}" => 'a', "\u{00E4}" => 'a',
      "\u{00E8}" => 'e', "\u{00E9}" => 'e', "\u{00EA}" => 'e', "\u{00EB}" => 'e',
      "\u{00EC}" => 'i', "\u{00ED}" => 'i', "\u{00EE}" => 'i', "\u{00EF}" => 'i',
      "\u{00F2}" => 'o', "\u{00F3}" => 'o', "\u{00F4}" => 'o', "\u{00F5}" => 'o', "\u{00F6}" => 'o',
      "\u{00F9}" => 'u', "\u{00FA}" => 'u', "\u{00FB}" => 'u', "\u{00FC}" => 'u',
      "\u{00C0}" => 'A', "\u{00C1}" => 'A', "\u{00C2}" => 'A', "\u{00C3}" => 'A', "\u{00C4}" => 'A',
      "\u{00C8}" => 'E', "\u{00C9}" => 'E', "\u{00CA}" => 'E', "\u{00CB}" => 'E',
      "\u{00CC}" => 'I', "\u{00CD}" => 'I', "\u{00CE}" => 'I', "\u{00CF}" => 'I',
      "\u{00D2}" => 'O', "\u{00D3}" => 'O', "\u{00D4}" => 'O', "\u{00D5}" => 'O', "\u{00D6}" => 'O',
      "\u{00D9}" => 'U', "\u{00DA}" => 'U', "\u{00DB}" => 'U', "\u{00DC}" => 'U',
      "\u{00F1}" => 'n', "\u{00D1}" => 'N',
      "\u{00E7}" => 'c', "\u{00C7}" => 'C',
    ];
    return strtr($value, $map);
  }
}

if (!function_exists('re_normalize_modalidad')) {
  function re_normalize_modalidad(string $value): string {
    $value = trim($value);
    $normalized = strtolower(re_remove_accents($value));
    if ($normalized === 'remoto') { return 'Remoto'; }
    if ($normalized === 'hibrido' || stripos($value, 'hibrido') !== false) { return 'Hibrido'; }
    if ($normalized === 'presencial') { return 'Presencial'; }
    return $value;
  }
}

if (!function_exists('re_lookup_catalog')) {
  function re_lookup_catalog(PDO $pdo, string $table, string $value): ?int {
    $allowed = ['tamanos_empresa','sectores','modalidades'];
    if (!in_array($table, $allowed, true)) {
      throw new RuntimeException('Catalogo no permitido.');
    }
    $sql = sprintf('SELECT id FROM %s WHERE nombre = ?', $table);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$value]);
    $id = $stmt->fetchColumn();
    if ($id !== false) { return (int)$id; }
    $insertSql = sprintf('INSERT INTO %s (nombre) VALUES (?)', $table);
    $ins = $pdo->prepare($insertSql);
    try {
      $ins->execute([$value]);
      return (int)$pdo->lastInsertId();
    } catch (PDOException $ex) {
      if (($ex->errorInfo[1] ?? null) === 1062) {
        $stmt->execute([$value]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
      }
      throw $ex;
    }
  }
}

if (!function_exists('re_validate_optional_url')) {
  function re_validate_optional_url(?string $value, string $label): ?string {
    $value = trim((string)$value);
    if ($value === '') {
      return null;
    }
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
      throw new RuntimeException($label.' invalido.');
    }
    return $value;
  }
}

$rootPath = dirname(__DIR__);
$uploadsRoot = $rootPath.DIRECTORY_SEPARATOR.'uploads';
if (!is_dir($uploadsRoot)) {
  re_ensure_directory($uploadsRoot);
}

if ($pdo instanceof PDO) {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS empresa_detalles (
      empresa_id BIGINT UNSIGNED NOT NULL,
      pais VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
      tipo_entidad VARCHAR(80) COLLATE utf8mb4_unicode_ci NOT NULL,
      anio_fundacion SMALLINT UNSIGNED DEFAULT NULL,
      modalidad_trabajo VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL,
      modalidad_id TINYINT UNSIGNED DEFAULT NULL,
      areas_contratacion TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      tecnologias TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      mision TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      valores TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      link_x VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      link_youtube VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      link_glassdoor VARCHAR(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
      acepta_datos_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (empresa_id),
      KEY idx_det_modalidad (modalidad_id),
      CONSTRAINT fk_empresa_det FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT fk_empresa_det_modalidad FOREIGN KEY (modalidad_id) REFERENCES modalidades (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}

// CSRF
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

$okMsg = $errMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
    $errMsg = 'CSRF invalido. Recarga la pagina.';
  } else {
    $cleanupFiles = [];
    try {
      if (!($pdo instanceof PDO)) {
        throw new RuntimeException('No hay conexion a la base de datos. Configura config/database.php o las variables de entorno DB_* y vuelve a intentarlo.');
      }

      $required = ['emp_email','emp_password','emp_password_confirm','contacto_nombre','contacto_tel','razon_social','nombre_comercial','nit','tipo_entidad','pais','ciudad','tamano','industria','modalidad_trabajo','descripcion'];
      foreach ($required as $key) {
        if (trim((string)($_POST[$key] ?? '')) === '') {
          throw new RuntimeException('Campo requerido: '.$key);
        }
      }
      if (!isset($_POST['acepta_datos'])) {
        throw new RuntimeException('Debes aceptar el tratamiento de datos.');
      }

      $email = strtolower(trim((string)($_POST['emp_email'] ?? '')));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Correo electronico invalido.');
      }
      $password = (string)($_POST['emp_password'] ?? '');
      if ($password !== (string)($_POST['emp_password_confirm'] ?? '')) {
        throw new RuntimeException('Las contrasenas no coinciden.');
      }
      if (strlen($password) < 8) {
        throw new RuntimeException('La contrasena debe tener al menos 8 caracteres.');
      }

      $contactoNombre = trim((string)($_POST['contacto_nombre'] ?? ''));
      if (strlen($contactoNombre) > 140) { $contactoNombre = substr($contactoNombre, 0, 140); }
      $contactoTelRaw = trim((string)($_POST['contacto_tel'] ?? ''));
      $contactoTel = preg_replace('/\D+/', '', $contactoTelRaw);
      if ($contactoTel === '' || strlen($contactoTel) < 7 || strlen($contactoTel) > 15) {
        throw new RuntimeException('Telefono de contacto invalido. Usa solo numeros (7 a 15).');
      }
      $_POST['contacto_tel'] = $contactoTel;

      $razonSocial = trim((string)($_POST['razon_social'] ?? ''));
      $nombreComercial = trim((string)($_POST['nombre_comercial'] ?? ''));

      $nit = strtoupper(trim((string)($_POST['nit'] ?? '')));
      if (!preg_match('/^[0-9]{5,16}(?:-[0-9]{1,2})?$/', $nit)) {
        throw new RuntimeException('NIT/ID fiscal invalido. Usa solo numeros y guion opcional.');
      }
      $_POST['nit'] = $nit;

      $tipoEntidad = trim((string)($_POST['tipo_entidad'] ?? ''));
      $tipoEntidad = html_entity_decode($tipoEntidad, ENT_QUOTES, 'UTF-8');
      $tiposEntidad = ['S.A.S.','S.A.','Fundación','Cooperativa','Otra'];
      if (!in_array($tipoEntidad, $tiposEntidad, true)) {
        throw new RuntimeException('Tipo de entidad invalido.');
      }
      $anioFundacionStr = trim((string)($_POST['anio_fundacion'] ?? ''));
      $anioFundacion = null;
      if ($anioFundacionStr !== '') {
        if (!ctype_digit($anioFundacionStr)) {
          throw new RuntimeException('Ano de fundacion invalido.');
        }
        $anioFundacion = (int)$anioFundacionStr;
        if ($anioFundacion < 1900 || $anioFundacion > 2099) {
          throw new RuntimeException('Ano de fundacion fuera de rango.');
        }
      }
      $sitioWeb = re_validate_optional_url($_POST['sitio_web'] ?? null, 'Sitio web');
      $pais = trim((string)($_POST['pais'] ?? ''));
      if (strlen($pais) > 80) { $pais = substr($pais, 0, 80); }
      $ciudad = trim((string)($_POST['ciudad'] ?? ''));
      if (strlen($ciudad) > 80) { $ciudad = substr($ciudad, 0, 80); }
      $direccion = trim((string)($_POST['direccion'] ?? ''));
      if (strlen($direccion) > 180) { $direccion = substr($direccion, 0, 180); }

      $tamano = trim((string)($_POST['tamano'] ?? ''));
      $tamanosPermitidos = ['1-10','11-50','51-200','201-500','500+'];
      if (!in_array($tamano, $tamanosPermitidos, true)) {
        throw new RuntimeException('Tamano de empresa invalido.');
      }

      $industria = trim((string)($_POST['industria'] ?? ''));
      if (strlen($industria) > 180) { $industria = substr($industria, 0, 180); }

      $modalidadTrabajo = trim((string)($_POST['modalidad_trabajo'] ?? ''));
      $modalidadTrabajo = html_entity_decode($modalidadTrabajo, ENT_QUOTES, 'UTF-8');
      $modalidadNombre = re_normalize_modalidad($modalidadTrabajo);
      $modalidadesPermitidas = ['Remoto','Hibrido','Presencial'];
      if (!in_array($modalidadNombre, $modalidadesPermitidas, true)) {
        throw new RuntimeException('Modalidad de trabajo invalida.');
      }
      $areasContratacion = trim((string)($_POST['areas_contratacion'] ?? ''));
      if (strlen($areasContratacion) > 240) { $areasContratacion = substr($areasContratacion, 0, 240); }
      $tecnologias = trim((string)($_POST['tecnologias'] ?? ''));
      if (strlen($tecnologias) > 240) { $tecnologias = substr($tecnologias, 0, 240); }
      $descripcion = trim((string)($_POST['descripcion'] ?? ''));
      if (strlen($descripcion) > 1200) { $descripcion = substr($descripcion, 0, 1200); }
      $mision = trim((string)($_POST['mision'] ?? ''));
      if (strlen($mision) > 240) { $mision = substr($mision, 0, 240); }
      $valores = trim((string)($_POST['valores'] ?? ''));
      if (strlen($valores) > 240) { $valores = substr($valores, 0, 240); }
      $linkLinkedin = re_validate_optional_url($_POST['link_linkedin'] ?? null, 'LinkedIn');
      $linkFacebook = re_validate_optional_url($_POST['link_facebook'] ?? null, 'Facebook');
      $linkInstagram = re_validate_optional_url($_POST['link_instagram'] ?? null, 'Instagram');
      $linkX = re_validate_optional_url($_POST['link_x'] ?? null, 'X');
      $linkYoutube = re_validate_optional_url($_POST['link_youtube'] ?? null, 'YouTube');
      $linkGlassdoor = re_validate_optional_url($_POST['link_glassdoor'] ?? null, 'Glassdoor');

      $stmt = $pdo->prepare('SELECT 1 FROM empresas WHERE nit = ?');
      $stmt->execute([$nit]);
      if ($stmt->fetchColumn()) {
        throw new RuntimeException('Ya existe una empresa registrada con ese NIT.');
      }

      $stmt = $pdo->prepare('SELECT 1 FROM empresa_cuentas WHERE email = ?');
      $stmt->execute([$email]);
      if ($stmt->fetchColumn()) {
        throw new RuntimeException('Ya existe una cuenta de empresa con este correo.');
      }

      $stmt = $pdo->prepare('SELECT 1 FROM candidatos WHERE email = ?');
      $stmt->execute([$email]);
      if ($stmt->fetchColumn()) {
        throw new RuntimeException('El correo ingresado pertenece a un candidato. Usa un correo corporativo distinto.');
      }

      $slug = re_company_slug($nombreComercial !== '' ? $nombreComercial : $razonSocial, $nit);
      $empresaBase = $uploadsRoot.DIRECTORY_SEPARATOR.'empresas';
      re_ensure_directory($empresaBase);
      $empresaDir = $empresaBase.DIRECTORY_SEPARATOR.$slug;
      re_ensure_directory($empresaDir);
      $logoDir = $empresaDir.DIRECTORY_SEPARATOR.'logo';
      $portadaDir = $empresaDir.DIRECTORY_SEPARATOR.'portada';
      $publicBase = '/uploads/empresas/'.$slug;

      $logoInfo = re_process_upload(
        $_FILES['logo'] ?? null,
        true,
        [
          'png' => ['image/png'],
          'svg' => ['image/svg+xml'],
        ],
        $logoDir,
        $publicBase.'/logo',
        'logo',
        3 * 1024 * 1024
      );
      $cleanupFiles[] = $logoInfo['absolute'];

      $portadaInfo = re_process_upload(
        $_FILES['portada'] ?? null,
        false,
        [
          'png' => ['image/png'],
          'jpg' => ['image/jpeg'],
          'jpeg' => ['image/jpeg'],
        ],
        $portadaDir,
        $publicBase.'/portada',
        'portada',
        5 * 1024 * 1024
      );
      if ($portadaInfo) {
        $cleanupFiles[] = $portadaInfo['absolute'];
      }

      $pdo->beginTransaction();
      try {
        $tamanoValor = str_replace(['"','"'], '-', $tamano);
        $tamanoValor = str_replace(' ', '', $tamanoValor);
        $tamanoId = $tamanoValor !== '' ? re_lookup_catalog($pdo, 'tamanos_empresa', $tamanoValor) : null;

        $sectorValor = $industria !== '' ? preg_replace('/\s+/', ' ', $industria) : '';
        if ($sectorValor !== '') {
          $sectorValor = ucwords(strtolower($sectorValor));
        }
        $sectorId = $sectorValor !== '' ? re_lookup_catalog($pdo, 'sectores', $sectorValor) : null;

        $modalidadId = $modalidadNombre !== '' ? re_lookup_catalog($pdo, 'modalidades', $modalidadNombre) : null;

        $insEmp = $pdo->prepare('INSERT INTO empresas (nit, razon_social, nombre_comercial, sector_id, tamano_id, sitio_web, telefono, email_contacto, ciudad, direccion, descripcion, logo_url, portada_url, linkedin_url, facebook_url, instagram_url, verificada, estado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insEmp->execute([
          $nit,
          $razonSocial,
          $nombreComercial !== '' ? $nombreComercial : null,
          $sectorId,
          $tamanoId,
          $sitioWeb,
          $contactoTel !== '' ? $contactoTel : null,
          $email,
          $ciudad !== '' ? $ciudad : null,
          $direccion !== '' ? $direccion : null,
          $descripcion,
          $logoInfo['relative'],
          $portadaInfo['relative'] ?? null,
          $linkLinkedin,
          $linkFacebook,
          $linkInstagram,
          0,
          'pendiente',
        ]);
        $empresaId = (int)$pdo->lastInsertId();

        $docStmt = $pdo->prepare('INSERT INTO empresa_documentos (empresa_id, tipo, nombre_archivo, ruta, mime, tamano) VALUES (?,?,?,?,?,?)');
        $docStmt->execute([
          $empresaId,
          'logo',
          $logoInfo['original'],
          $logoInfo['relative'],
          $logoInfo['mime'],
          $logoInfo['size'],
        ]);
        if ($portadaInfo) {
          $docStmt->execute([
            $empresaId,
            'portada',
            $portadaInfo['original'],
            $portadaInfo['relative'],
            $portadaInfo['mime'],
            $portadaInfo['size'],
          ]);
        }

        $insCuenta = $pdo->prepare('INSERT INTO empresa_cuentas (email, empresa_id, nombre_contacto, telefono, password_hash, rol, estado) VALUES (?,?,?,?,?,?,?)');
        $insCuenta->execute([
          $email,
          $empresaId,
          $contactoNombre,
          $contactoTel !== '' ? $contactoTel : null,
          password_hash($password, PASSWORD_DEFAULT),
          'owner',
          'activo',
        ]);

        $detStmt = $pdo->prepare('INSERT INTO empresa_detalles (empresa_id, pais, tipo_entidad, anio_fundacion, modalidad_trabajo, modalidad_id, areas_contratacion, tecnologias, mision, valores, link_x, link_youtube, link_glassdoor, acepta_datos_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $detStmt->execute([
          $empresaId,
          $pais,
          $tipoEntidad,
          $anioFundacion,
          $modalidadNombre,
          $modalidadId,
          $areasContratacion !== '' ? $areasContratacion : null,
          $tecnologias !== '' ? $tecnologias : null,
          $mision !== '' ? $mision : null,
          $valores !== '' ? $valores : null,
          $linkX,
          $linkYoutube,
          $linkGlassdoor,
          (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $pdo->commit();

        $cleanupFiles = [];

        $sedeParts = [];
        if ($ciudad !== '') { $sedeParts[] = $ciudad; }
        if ($sectorValor !== '') { $sedeParts[] = $sectorValor; }

        $_SESSION['user'] = [
          'type' => 'empresa',
          'email' => $email,
          'nombre' => $contactoNombre,
          'empresa_id' => $empresaId,
          'empresa' => $razonSocial,
          'display_name' => $contactoNombre !== '' ? $contactoNombre : $razonSocial,
          'rol' => 'owner',
        ];
        $_SESSION['empresa_email'] = $email;
        $_SESSION['empresa_nombre'] = $razonSocial;
        $_SESSION['empresa_sede'] = $sedeParts ? implode(' - ', $sedeParts) : $razonSocial;

        header('Location: index.php?view=perfil_empresa');
        exit;
      } catch (Throwable $tx) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foreach ($cleanupFiles as $path) {
          if (is_file($path)) { @unlink($path); }
        }
        throw $tx;
      }

    } catch (Throwable $e) {
      foreach ($cleanupFiles as $path) {
        if (is_file($path)) { @unlink($path); }
      }
      $errMsg = $e->getMessage();
    }
  }
}
?>

<!-- Registro de Empresa (parcial) -->
<section class="section container">
  <div class="portal-head">
    <h1>Crea tu cuenta - Empresa</h1>
    <p class="muted">&Uacute;nete al programa SENA para conectar con talento. Completa la cuenta del reclutador y el perfil de tu empresa; despu&eacute;s podr&aacute;s publicar vacantes y gestionar postulaciones.</p>
  </div>

  <?php if ($okMsg): ?>
    <div class="card" style="border-color:#d6f5d6;background:#f3fff3"><strong><?=htmlspecialchars($okMsg)?></strong></div>
  <?php elseif ($errMsg): ?>
    <div class="card" style="border-color:#ffd5d5;background:#fff6f6"><strong>Error:</strong> <?=htmlspecialchars($errMsg)?></div>
  <?php endif; ?>

  <form class="card form" method="post" enctype="multipart/form-data" data-validate="instant" novalidate>
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>" />

    <section>
      <h2>Cuenta del reclutador</h2>
      <div class="g-3">
        <div class="field"><label for="emp_email">Correo corporativo *</label><input id="emp_email" name="emp_email" type="email" required placeholder="talento@empresa.com" value="<?=htmlspecialchars($_POST['emp_email'] ?? '')?>"/></div>
        <div class="field"><label for="emp_password">Contrase&ntilde;a *</label><input id="emp_password" name="emp_password" type="password" minlength="8" autocomplete="new-password" required placeholder="M&iacute;nimo 8 caracteres" /></div>
        <div class="field"><label for="emp_password_confirm">Confirmar contrase&ntilde;a *</label><input id="emp_password_confirm" name="emp_password_confirm" type="password" minlength="8" autocomplete="new-password" required placeholder="Repite tu contrase&ntilde;a" data-match="#emp_password" data-match-message="Las contrase&ntilde;as no coinciden." /></div>
        <div class="field"><label for="contacto_nombre">Nombre del contacto *</label><input id="contacto_nombre" name="contacto_nombre" type="text" maxlength="140" required placeholder="Nombre Apellido" value="<?=htmlspecialchars($_POST['contacto_nombre'] ?? '')?>"/></div>
        <div class="field"><label for="contacto_tel">Tel&eacute;fono *</label><input id="contacto_tel" name="contacto_tel" type="tel" inputmode="numeric" pattern="\d{7,15}" maxlength="15" data-normalize="digits" data-pattern-message="Usa solo numeros (7 a 15)." required placeholder="+57 320 000 0000" value="<?=htmlspecialchars($_POST['contacto_tel'] ?? '')?>"/></div>
      </div>
    </section>

    <section>
      <h2>Informaci&oacute;n legal</h2>
      <div class="g-3">
        <div class="field"><label for="razon_social">Raz&oacute;n social *</label><input id="razon_social" name="razon_social" type="text" maxlength="160" required placeholder="DevAndes S.A.S." value="<?=htmlspecialchars($_POST['razon_social'] ?? '')?>"/></div>
        <div class="field"><label for="nombre_comercial">Nombre comercial *</label><input id="nombre_comercial" name="nombre_comercial" type="text" maxlength="140" required placeholder="DevAndes" value="<?=htmlspecialchars($_POST['nombre_comercial'] ?? '')?>"/></div>
        <div class="field"><label for="nit">NIT / ID fiscal *</label><input id="nit" name="nit" type="text" inputmode="numeric" pattern="\d{5,16}(-\d{1,2})?" maxlength="18" data-normalize="nit" data-pattern-message="Solo numeros y guion final opcional." required placeholder="900123456-7" value="<?=htmlspecialchars($_POST['nit'] ?? '')?>"/></div>
        <div class="field"><label for="tipo_entidad">Tipo de entidad *</label><select id="tipo_entidad" name="tipo_entidad" required><option value="">Selecciona</option><?php foreach (["S.A.S.","S.A.","Fundaci&oacute;n","Cooperativa","Otra"] as $op): ?><option <?=$op===($_POST['tipo_entidad'] ?? '')?'selected':''?>><?=$op?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="anio_fundacion">A&ntilde;o de fundaci&oacute;n</label><input id="anio_fundacion" name="anio_fundacion" type="number" min="1900" max="2099" placeholder="2005" value="<?=htmlspecialchars($_POST['anio_fundacion'] ?? '')?>"/></div>
        <div class="field"><label for="sitio_web">Sitio web</label><input id="sitio_web" name="sitio_web" type="url" placeholder="https://empresa.com" value="<?=htmlspecialchars($_POST['sitio_web'] ?? '')?>"/></div>
      </div>
    </section>

    <section>
      <h2>Ubicaci&oacute;n, tama&ntilde;o y sector</h2>
      <div class="g-3">
        <div class="field"><label for="pais">Pa&iacute;s *</label><input id="pais" name="pais" type="text" maxlength="80" required placeholder="Colombia" value="<?=htmlspecialchars($_POST['pais'] ?? '')?>"/></div>
        <div class="field"><label for="ciudad">Ciudad *</label><input id="ciudad" name="ciudad" type="text" maxlength="80" required placeholder="Bogot&aacute;" value="<?=htmlspecialchars($_POST['ciudad'] ?? '')?>"/></div>
        <div class="field"><label for="direccion">Direcci&oacute;n (opcional)</label><input id="direccion" name="direccion" type="text" maxlength="180" placeholder="Calle 00 # 00-00" value="<?=htmlspecialchars($_POST['direccion'] ?? '')?>"/></div>
        <div class="field"><label for="tamano">Tama&ntilde;o *</label><select id="tamano" name="tamano" required><option value="">Selecciona</option><?php foreach (["1-10","11-50","51-200","201-500","500+"] as $op): ?><option <?=$op===($_POST['tamano'] ?? '')?'selected':''?>><?=$op?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="industria">Industria *</label><input id="industria" name="industria" type="text" maxlength="180" required placeholder="Tecnolog&iacute;a / Log&iacute;stica / Salud..." value="<?=htmlspecialchars($_POST['industria'] ?? '')?>"/></div>
        <div class="field"><label for="modalidad_trabajo">Modalidad de trabajo *</label><select id="modalidad_trabajo" name="modalidad_trabajo" required><option value="">Selecciona</option><?php foreach (["Remoto","H&iacute;brido","Presencial"] as $op): ?><option <?=$op===($_POST['modalidad_trabajo'] ?? '')?'selected':''?>><?=$op?></option><?php endforeach; ?></select></div>
      </div>
    </section>

    <section>
      <h2>Enfoque de contrataci&oacute;n</h2>
      <div class="g-2">
        <div class="field"><label for="areas_contratacion">&Aacute;reas de contrataci&oacute;n frecuentes</label><input id="areas_contratacion" name="areas_contratacion" type="text" maxlength="240" placeholder="Ej: Desarrollo, Atenci&oacute;n al cliente, Operaciones" value="<?=htmlspecialchars($_POST['areas_contratacion'] ?? '')?>"/><small class="muted">Sep&aacute;ralas por comas (ayuda al matching).</small></div>
        <div class="field"><label for="tecnologias">Tecnolog&iacute;as / herramientas clave</label><input id="tecnologias" name="tecnologias" type="text" maxlength="240" placeholder="Laravel, MySQL, Excel, SAP" value="<?=htmlspecialchars($_POST['tecnologias'] ?? '')?>"/></div>
      </div>
    </section>

    <section>
      <h2>Descripci&oacute;n y cultura</h2>
      <div class="g-2">
        <div class="field"><label for="descripcion">Descripci&oacute;n de la empresa *</label><textarea id="descripcion" name="descripcion" rows="4" maxlength="800" required placeholder="Qui&eacute;nes somos, qu&eacute; hacemos y nuestro impacto..."><?=htmlspecialchars($_POST['descripcion'] ?? '')?></textarea></div>
        <div class="field"><label for="mision">Prop&oacute;sito / Misi&oacute;n (opcional)</label><input id="mision" name="mision" type="text" maxlength="240" placeholder="Nuestro prop&oacute;sito es..." value="<?=htmlspecialchars($_POST['mision'] ?? '')?>"/></div>
        <div class="field"><label for="valores">Valores (opcional)</label><input id="valores" name="valores" type="text" maxlength="240" placeholder="Ej: Integridad, Innovaci&oacute;n, Trabajo en equipo" value="<?=htmlspecialchars($_POST['valores'] ?? '')?>"/></div>
      </div>
    </section>

    <section>
      <h2>Marca</h2>
      <div class="g-2">
        <div class="dropzone"><label for="logo">Logo (PNG/SVG) *</label><input id="logo" name="logo" type="file" accept=".png,.svg" required /><small>Fondo transparente si es posible. Tama&ntilde;o recomendado 512x512.</small></div>
        <div class="dropzone"><label for="portada">Imagen de portada</label><input id="portada" name="portada" type="file" accept=".png,.jpg,.jpeg" /><small>Proporci&oacute;n 3:1 aprox. PNG/JPG</small></div>
      </div>
    </section>

    <section>
      <h2>Redes y enlaces</h2>
      <div class="g-3">
        <div class="field"><label for="link_linkedin">LinkedIn</label><input id="link_linkedin" name="link_linkedin" type="url" placeholder="https://www.linkedin.com/company/..." value="<?=htmlspecialchars($_POST['link_linkedin'] ?? '')?>"/></div>
        <div class="field"><label for="link_facebook">Facebook</label><input id="link_facebook" name="link_facebook" type="url" placeholder="https://facebook.com/..." value="<?=htmlspecialchars($_POST['link_facebook'] ?? '')?>"/></div>
        <div class="field"><label for="link_instagram">Instagram</label><input id="link_instagram" name="link_instagram" type="url" placeholder="https://instagram.com/..." value="<?=htmlspecialchars($_POST['link_instagram'] ?? '')?>"/></div>
        <div class="field"><label for="link_x">X (Twitter)</label><input id="link_x" name="link_x" type="url" placeholder="https://x.com/..." value="<?=htmlspecialchars($_POST['link_x'] ?? '')?>"/></div>
        <div class="field"><label for="link_youtube">YouTube</label><input id="link_youtube" name="link_youtube" type="url" placeholder="https://youtube.com/..." value="<?=htmlspecialchars($_POST['link_youtube'] ?? '')?>"/></div>
        <div class="field"><label for="link_glassdoor">Glassdoor</label><input id="link_glassdoor" name="link_glassdoor" type="url" placeholder="https://glassdoor.com/..." value="<?=htmlspecialchars($_POST['link_glassdoor'] ?? '')?>"/></div>
      </div>
    </section>

    <section>
      <label class="check"><input type="checkbox" name="acepta_datos" required /> <span>La empresa acepta las pol&iacute;ticas de tratamiento de datos y los <a href="#">t&eacute;rminos y condiciones</a>. *</span></label>
    </section>

    <div class="actions">
      <button type="button" class="btn btn-secondary" onclick="history.back()">Cancelar</button>
      <button type="submit" class="btn btn-primary">Crear cuenta empresa</button>
    </div>
  </form>
</section>
