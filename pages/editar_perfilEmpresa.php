<?php
declare(strict_types=1);

// Solo vía index.php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=editar_perfilEmpresa');
  exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['type'] ?? '') !== 'empresa') {
  header('Location: index.php?view=login');
  exit;
}

require __DIR__.'/db.php';

if (!function_exists('ee_e')) {
  function ee_e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}

// CSRF
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

$empresaId = isset($user['empresa_id']) ? (int)$user['empresa_id'] : null;
$empresa = [
  'nit' => '',
  'razon_social' => '',
  'nombre_comercial' => '',
  'tipo_entidad' => '',
  'anio_fundacion' => null,
  'ciudad' => null,
  'pais' => null,
  'sitio_web' => null,
  'telefono' => null,
  'email_contacto' => $user['email'] ?? null,
  'logo_url' => null,
  'portada_url' => null,
  'linkedin' => null,
  'facebook' => null,
  'instagram' => null,
  'x' => null,
  'youtube' => null,
  'glassdoor' => null,
];
$det = [
  'pais' => null,
  'tipo_entidad' => null,
  'anio_fundacion' => null,
  'modalidad' => null,
  'areas' => null,
  'tecnologias' => null,
  'mision' => null,
  'valores' => null,
];
$contacto = [
  'nombre' => $user['display_name'] ?? ($user['nombre'] ?? ''),
  'telefono' => null,
  'email' => $user['email'] ?? null,
];

if ($empresaId && ($pdo instanceof PDO)) {
  try {
    $stmt = $pdo->prepare(
      'SELECT e.nit, e.razon_social, e.nombre_comercial, e.telefono, e.email_contacto, e.ciudad, e.descripcion, e.logo_url, e.portada_url, e.linkedin_url, e.facebook_url, e.instagram_url, e.sitio_web,
              d.pais, d.tipo_entidad, d.anio_fundacion, d.modalidad_trabajo, d.areas_contratacion, d.tecnologias, d.mision, d.valores, d.link_x, d.link_youtube, d.link_glassdoor
         FROM empresas e
         LEFT JOIN empresa_detalles d ON d.empresa_id = e.id
        WHERE e.id = ?
        LIMIT 1'
    );
    $stmt->execute([$empresaId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $empresa['nit'] = (string)($row['nit'] ?? '');
      $empresa['razon_social'] = (string)($row['razon_social'] ?? '');
      $empresa['nombre_comercial'] = (string)($row['nombre_comercial'] ?? '');
      $empresa['telefono'] = (string)($row['telefono'] ?? '');
      $empresa['email_contacto'] = (string)($row['email_contacto'] ?? ($empresa['email_contacto'] ?? ''));
      $empresa['ciudad'] = (string)($row['ciudad'] ?? '');
      $empresa['sitio_web'] = (string)($row['sitio_web'] ?? '');
      $empresa['descripcion'] = (string)($row['descripcion'] ?? '');
      $empresa['logo_url'] = (string)($row['logo_url'] ?? '');
      $empresa['portada_url'] = (string)($row['portada_url'] ?? '');
      $empresa['linkedin'] = (string)($row['linkedin_url'] ?? '');
      $empresa['facebook'] = (string)($row['facebook_url'] ?? '');
      $empresa['instagram'] = (string)($row['instagram_url'] ?? '');

      $det['pais'] = (string)($row['pais'] ?? '');
      $det['tipo_entidad'] = (string)($row['tipo_entidad'] ?? '');
      $det['anio_fundacion'] = $row['anio_fundacion'] ?? null;
      $det['modalidad'] = (string)($row['modalidad_trabajo'] ?? '');
      $det['areas'] = (string)($row['areas_contratacion'] ?? '');
      $det['tecnologias'] = (string)($row['tecnologias'] ?? '');
      $det['mision'] = (string)($row['mision'] ?? '');
      $det['valores'] = (string)($row['valores'] ?? '');
      $empresa['x'] = (string)($row['link_x'] ?? '');
      $empresa['youtube'] = (string)($row['link_youtube'] ?? '');
      $empresa['glassdoor'] = (string)($row['link_glassdoor'] ?? '');
    }

    $cstmt = $pdo->prepare('SELECT nombre_contacto, telefono FROM empresa_cuentas WHERE empresa_id = ? AND email = ? LIMIT 1');
    $cstmt->execute([$empresaId, $contacto['email']]);
    if ($crow = $cstmt->fetch(PDO::FETCH_ASSOC)) {
      $contacto['nombre'] = (string)($crow['nombre_contacto'] ?? $contacto['nombre']);
      $contacto['telefono'] = (string)($crow['telefono'] ?? '');
    }
  } catch (Throwable $e) { error_log('[editar_perfilEmpresa] '.$e->getMessage()); }
}

?>

<section class="section container">
  <div class="portal-head">
    <h1>Editar perfil - Empresa</h1>
    <p class="muted">Actualiza información pública y de contacto. Los datos legales clave no son editables.</p>
  </div>

  <form class="card form" method="post" action="index.php?action=update_empresa_profile" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf" value="<?= ee_e($csrf); ?>" />

    <section>
      <h2>Cuenta del reclutador</h2>
      <div class="g-3">
        <div class="field"><label>Correo corporativo</label><input type="email" value="<?= ee_e((string)($contacto['email'] ?? '')); ?>" readonly /></div>
        <div class="field"><label>Nombre del contacto</label><input name="contacto_nombre" type="text" placeholder="Nombre Apellido" value="<?= ee_e((string)($contacto['nombre'] ?? '')); ?>" /></div>
        <div class="field"><label>Teléfono</label><input name="contacto_telefono" type="tel" placeholder="+57 320 000 0000" value="<?= ee_e((string)($contacto['telefono'] ?? '')); ?>" /></div>
      </div>
    </section>

    <section>
      <h2>Información legal</h2>
      <div class="g-3">
        <div class="field"><label>Razón social</label><input type="text" value="<?= ee_e((string)$empresa['razon_social']); ?>" readonly /></div>
        <div class="field"><label>Nombre comercial</label><input name="nombre_comercial" type="text" placeholder="DevAndes" value="<?= ee_e((string)$empresa['nombre_comercial']); ?>" /></div>
        <div class="field"><label>NIT / ID fiscal</label><input type="text" value="<?= ee_e((string)$empresa['nit']); ?>" readonly /></div>
        <div class="field"><label>Tipo de entidad</label><input type="text" value="<?= ee_e((string)$det['tipo_entidad']); ?>" readonly /></div>
        <div class="field"><label>Año de fundación</label><input name="anio_fundacion" type="number" min="1900" max="2099" placeholder="2005" value="<?= ee_e((string)($det['anio_fundacion'] ?? '')); ?>" /></div>
        <div class="field"><label>Sitio web</label><input name="sitio_web" type="url" placeholder="https://empresa.com" value="<?= ee_e((string)$empresa['sitio_web']); ?>" /></div>
      </div>
    </section>

    <section>
      <h2>Ubicación y modalidad</h2>
      <div class="g-3">
        <div class="field"><label>País</label><input name="pais" type="text" placeholder="Colombia" value="<?= ee_e((string)($det['pais'] ?? '')); ?>" /></div>
        <div class="field"><label>Ciudad</label><input name="ciudad" type="text" placeholder="Bogotá" value="<?= ee_e((string)$empresa['ciudad']); ?>" /></div>
        <div class="field"><label>Modalidad de trabajo</label>
          <select name="modalidad">
            <?php $mod = strtolower((string)$det['modalidad']); ?>
            <option value="">Selecciona</option>
            <option value="Remoto" <?= $mod==='remoto'?'selected':''; ?>>Remoto</option>
            <option value="Hibrido" <?= ($mod==='hibrido'||$mod==='híbrido')?'selected':''; ?>>Híbrido</option>
            <option value="Presencial" <?= $mod==='presencial'?'selected':''; ?>>Presencial</option>
          </select>
        </div>
      </div>
    </section>

    <section>
      <h2>Enfoque de contratación</h2>
      <div class="g-2">
        <div class="field"><label>Áreas de contratación frecuentes</label><input name="areas" type="text" placeholder="Desarrollo, Atención al cliente..." value="<?= ee_e((string)$det['areas']); ?>" /><small class="muted">Sepáralas por comas.</small></div>
        <div class="field"><label>Tecnologías / herramientas clave</label><input name="tecnologias" type="text" placeholder="Laravel, MySQL, Excel, SAP" value="<?= ee_e((string)$det['tecnologias']); ?>" /></div>
      </div>
    </section>

    <section>
      <h2>Descripción y cultura</h2>
      <div class="g-2">
        <div class="field"><label>Descripción de la empresa</label><textarea name="descripcion" rows="4" placeholder="Quiénes somos..."><?= ee_e((string)($empresa['descripcion'] ?? '')); ?></textarea></div>
        <div class="field"><label>Propósito / Misión</label><input name="mision" type="text" placeholder="Nuestro propósito es..." value="<?= ee_e((string)$det['mision']); ?>" /></div>
        <div class="field"><label>Valores</label><input name="valores" type="text" placeholder="Integridad, Innovación..." value="<?= ee_e((string)$det['valores']); ?>" /></div>
      </div>
    </section>

    <section>
      <h2>Marca</h2>
      <div class="g-2">
        <div class="dropzone">
          <label for="logo">Logo (PNG/JPG)</label>
          <input id="logo" name="logo" type="file" accept=".png,.jpg,.jpeg" />
          <small>Se recomienda fondo transparente. Máx. 5 MB.</small>
        </div>
        <div class="dropzone">
          <label for="portada">Imagen de portada (PNG/JPG)</label>
          <input id="portada" name="portada" type="file" accept=".png,.jpg,.jpeg" />
          <small>Proporción 3:1 aprox. Máx. 5 MB.</small>
        </div>
      </div>
    </section>

    <section>
      <h2>Redes y enlaces</h2>
      <div class="g-3">
        <div class="field"><label>LinkedIn</label><input name="linkedin" type="url" placeholder="https://www.linkedin.com/company/..." value="<?= ee_e((string)$empresa['linkedin']); ?>" /></div>
        <div class="field"><label>Facebook</label><input name="facebook" type="url" placeholder="https://facebook.com/..." value="<?= ee_e((string)$empresa['facebook']); ?>" /></div>
        <div class="field"><label>Instagram</label><input name="instagram" type="url" placeholder="https://instagram.com/..." value="<?= ee_e((string)$empresa['instagram']); ?>" /></div>
        <div class="field"><label>X (Twitter)</label><input name="x" type="url" placeholder="https://x.com/..." value="<?= ee_e((string)$empresa['x']); ?>" /></div>
        <div class="field"><label>YouTube</label><input name="youtube" type="url" placeholder="https://youtube.com/..." value="<?= ee_e((string)$empresa['youtube']); ?>" /></div>
        <div class="field"><label>Glassdoor</label><input name="glassdoor" type="url" placeholder="https://glassdoor.com/..." value="<?= ee_e((string)$empresa['glassdoor']); ?>" /></div>
      </div>
    </section>

    <div class="actions">
      <a class="btn btn-secondary" href="?view=perfil_empresa">Cancelar</a>
      <button type="submit" class="btn btn-primary">Guardar cambios</button>
    </div>
  </form>
</section>