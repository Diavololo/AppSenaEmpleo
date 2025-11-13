<?php
declare(strict_types=1);

// Bloquear acceso directo: solo vía index.php?view=perfil_empresa
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=perfil_empresa');
  exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$sessionUser = $_SESSION['user'] ?? null;

if (!$sessionUser || ($sessionUser['type'] ?? '') !== 'empresa') {
  header('Location: ../index.php?view=login');
  exit;
}

require_once __DIR__.'/db.php';

if (!function_exists('pe_e')) {
  function pe_e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
  }
}

$splitList = static function (?string $value): array {
  if ($value === null) { return []; }
  $items = preg_split('/[,;\\r\\n]+/', (string)$value);
  if (!is_array($items)) { return []; }
  $items = array_map('trim', $items);
  $items = array_filter($items, static fn(string $item) => $item !== '');
  return array_values(array_unique($items));
};

$empresa = [
  'razon_social'    => $sessionUser['empresa'] ?? 'Mi empresa',
  'nombre_comercial'=> null,
  'descripcion'     => null,
  'mision'          => null,
  'valores'         => [],
  'areas'           => [],
  'tecnologias'     => [],
  'tamano'          => null,
  'sector'          => null,
  'anio_fundacion'  => null,
  'modalidad'       => null,
  'tipo_entidad'    => null,
  'pais'            => null,
  'ciudad'          => null,
  'sitio_web'       => null,
  'telefono'        => null,
  'email_contacto'  => $_SESSION['empresa_email'] ?? ($sessionUser['email'] ?? null),
  'logo_url'        => null,
  'portada_url'     => null,
  'verificada'      => false,
  'estado'          => null,
  'linkedin'        => null,
  'facebook'        => null,
  'instagram'       => null,
  'x'               => null,
  'youtube'         => null,
  'glassdoor'       => null,
];

$contacto = [
  'nombre'   => $sessionUser['nombre'] ?? '',
  'telefono' => null,
  'email'    => $_SESSION['empresa_email'] ?? ($sessionUser['email'] ?? ''),
];

$vacantesDestacadas = [];
$kpis = [
  'activas'      => 0,
  'respuesta'    => null,
  'proceso_prom' => null,
];

$empresaId = isset($sessionUser['empresa_id']) ? (int)$sessionUser['empresa_id'] : null;
$empresaEmail = $contacto['email'];

if ($empresaId && ($pdo instanceof PDO)) {
  try {
    $stmt = $pdo->prepare(
      'SELECT e.razon_social,
              e.nombre_comercial,
              e.descripcion,
              e.telefono,
              e.email_contacto,
              e.ciudad,
              e.direccion,
              e.logo_url,
              e.portada_url,
              e.linkedin_url,
              e.facebook_url,
              e.instagram_url,
              e.sitio_web,
              e.verificada,
              e.estado,
              s.nombre AS sector_nombre,
              t.nombre AS tamano_nombre,
              d.pais,
              d.tipo_entidad,
              d.anio_fundacion,
              d.modalidad_trabajo,
              d.areas_contratacion,
              d.tecnologias,
              d.mision,
              d.valores,
              d.link_x,
              d.link_youtube,
              d.link_glassdoor
       FROM empresas e
       LEFT JOIN sectores s ON s.id = e.sector_id
       LEFT JOIN tamanos_empresa t ON t.id = e.tamano_id
       LEFT JOIN empresa_detalles d ON d.empresa_id = e.id
       WHERE e.id = ?
       LIMIT 1'
    );
    $stmt->execute([$empresaId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $empresa['razon_social']     = $row['razon_social'] ?: $empresa['razon_social'];
      $empresa['nombre_comercial'] = $row['nombre_comercial'] ?: null;
      $empresa['descripcion']      = $row['descripcion'] ?: null;
      $empresa['telefono']         = $row['telefono'] ?: null;
      $empresa['email_contacto']   = $row['email_contacto'] ?: $empresa['email_contacto'];
      $empresa['ciudad']           = $row['ciudad'] ?: null;
      $empresa['logo_url']         = $row['logo_url'] ?: null;
      $empresa['portada_url']      = $row['portada_url'] ?: null;
      $empresa['linkedin']         = $row['linkedin_url'] ?: null;
      $empresa['facebook']         = $row['facebook_url'] ?: null;
      $empresa['instagram']        = $row['instagram_url'] ?: null;
      $empresa['sitio_web']        = $row['sitio_web'] ?: null;
      $empresa['verificada']       = (bool)$row['verificada'];
      $empresa['estado']           = $row['estado'] ?: null;
      $empresa['sector']           = $row['sector_nombre'] ?: null;
      $empresa['tamano']           = $row['tamano_nombre'] ?: null;
      $empresa['pais']             = $row['pais'] ?: null;
      $empresa['tipo_entidad']     = $row['tipo_entidad'] ?: null;
      $empresa['anio_fundacion']   = $row['anio_fundacion'] ?: null;
      $empresa['modalidad']        = $row['modalidad_trabajo'] ?: null;
      $empresa['areas']            = $splitList($row['areas_contratacion'] ?? null);
      $empresa['tecnologias']      = $splitList($row['tecnologias'] ?? null);
      $empresa['mision']           = $row['mision'] ?: null;
      $empresa['valores']          = $splitList($row['valores'] ?? null);
      $empresa['x']                = $row['link_x'] ?: null;
      $empresa['youtube']          = $row['link_youtube'] ?: null;
      $empresa['glassdoor']        = $row['link_glassdoor'] ?: null;

    }

    $contactStmt = $pdo->prepare(
      'SELECT nombre_contacto, telefono
         FROM empresa_cuentas
        WHERE empresa_id = ? AND email = ?
        LIMIT 1'
    );
    $contactStmt->execute([$empresaId, $empresaEmail]);
    if ($contactRow = $contactStmt->fetch(PDO::FETCH_ASSOC)) {
      if (!empty($contactRow['nombre_contacto'])) {
        $contacto['nombre'] = $contactRow['nombre_contacto'];
      }
      if (!empty($contactRow['telefono'])) {
        $contacto['telefono'] = $contactRow['telefono'];
      } elseif ($empresa['telefono']) {
        $contacto['telefono'] = $empresa['telefono'];
      }
    } elseif ($empresa['telefono']) {
      $contacto['telefono'] = $empresa['telefono'];
    }

    if ($empresaId) {
      $kpiStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN estado IN ("publicada","activa") THEN 1 ELSE 0 END) AS activas,
            COUNT(*) AS total
           FROM vacantes
          WHERE empresa_id = ?'
      );
      $kpiStmt->execute([$empresaId]);
      if ($kpiRow = $kpiStmt->fetch(PDO::FETCH_ASSOC)) {
        $kpis['activas'] = (int)($kpiRow['activas'] ?? 0);
      }

      $vacStmt = $pdo->prepare(
        'SELECT
            v.titulo,
            m.nombre AS modalidad,
            c.nombre AS tipo_contrato,
            v.ciudad,
            v.descripcion AS resumen,
            v.estado,
            v.created_at
         FROM vacantes v
         LEFT JOIN modalidades m ON m.id = v.modalidad_id
         LEFT JOIN contratos c ON c.id = v.tipo_contrato_id
        WHERE v.empresa_id = ?
        ORDER BY v.created_at DESC
        LIMIT 3'
      );
      $vacStmt->execute([$empresaId]);
      $vacantesDestacadas = $vacStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $profileError) {
    error_log('[perfil_empresa] '.$profileError->getMessage());
  }
}

$sedeParts = array_filter([
  $empresa['ciudad'] ?? null,
  $empresa['pais'] ?? null,
]);
$sedeLabel = $sedeParts ? implode(', ', $sedeParts) : null;

$portadaUrl = $empresa['portada_url'] ?: 'assets/Empresa.png';
$logoUrl = $empresa['logo_url'] ?: null;
$nombrePrincipal = $empresa['nombre_comercial'] ?: $empresa['razon_social'];
$descripcion = $empresa['descripcion'] ?: 'Aún no has agregado la descripción de tu empresa.';

?>

<section class="section">
  <div class="container">
    <div class="card" style="padding:0; overflow:hidden;">
      <div style="height:180px; background:#eef3f7;">
        <img src="<?=pe_e($portadaUrl); ?>" alt="Portada de <?=pe_e($nombrePrincipal); ?>" style="width:100%; height:100%; object-fit:cover; display:block;" />
      </div>
      <div style="padding:1rem; display:flex; justify-content:space-between; align-items:flex-start; gap:1rem;">
        <div style="flex:1; display:flex; flex-direction:column; gap:.75rem;">
          <div style="display:flex; align-items:center; gap:.75rem;">
            <?php if ($logoUrl): ?>
              <img src="<?=pe_e($logoUrl); ?>" alt="Logo <?=pe_e($nombrePrincipal); ?>" style="width:72px; height:72px; border-radius:16px; object-fit:cover; background:#fff; box-shadow:0 0 0 1px rgba(0,0,0,0.08);" />
            <?php endif; ?>
            <div>
              <h2 class="m-0"><?=pe_e($nombrePrincipal); ?></h2>
              <?php if (!empty($empresa['tipo_entidad'])): ?>
                <p class="muted m-0"><?=pe_e($empresa['tipo_entidad']); ?></p>
              <?php endif; ?>
            </div>
          </div>
          <p class="muted m-0"><?=pe_e($descripcion); ?></p>
          <div style="display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.25rem;">
            <?php if (!empty($empresa['sector'])): ?>
              <span class="chip">Industria: <?=pe_e($empresa['sector']); ?></span>
            <?php endif; ?>
            <?php if (!empty($empresa['tamano'])): ?>
              <span class="chip">Tamaño: <?=pe_e($empresa['tamano']); ?></span>
            <?php endif; ?>
            <?php if (!empty($empresa['anio_fundacion'])): ?>
              <span class="chip">Fundada en <?=pe_e((string)$empresa['anio_fundacion']); ?></span>
            <?php endif; ?>
            <?php if (!empty($empresa['modalidad'])): ?>
              <span class="chip">Modalidad: <?=pe_e($empresa['modalidad']); ?></span>
            <?php endif; ?>
            <?php if (!empty($empresa['estado'])): ?>
              <span class="chip muted">Estado: <?=pe_e($empresa['estado']); ?></span>
            <?php endif; ?>
            <?php if ($empresa['verificada']): ?>
              <span class="chip">Cuenta verificada</span>
            <?php endif; ?>
          </div>
          <?php if ($sedeLabel): ?>
            <div style="display:flex; gap:.4rem; flex-wrap:wrap;">
              <span class="chip muted">Sede: <?=pe_e($sedeLabel); ?></span>
            </div>
          <?php endif; ?>
        </div>
        <div class="actions" style="gap:.5rem; display:flex; flex-direction:column; align-items:flex-end;">
          <a class="btn btn-secondary" href="?view=editar_perfilEmpresa">Editar</a>
          </div>
      </div>
    </div>

    <div class="kpis" style="margin-top:1rem; display:flex; gap: var(--sp-4); flex-wrap:wrap;">
      <div class="card kpi" style="flex:1 1 220px;">
        <span class="kpi-label">Vacantes activas</span>
        <span class="kpi-value"><?=pe_e((string)$kpis['activas']); ?></span>
      </div>
      <div class="card kpi" style="flex:1 1 220px;">
        <span class="kpi-label">Tiempo de respuesta</span>
        <span class="kpi-value"><?=pe_e($kpis['respuesta'] ?? 'Sin datos'); ?></span>
      </div>
      <div class="card kpi" style="flex:1 1 220px;">
        <span class="kpi-label">Proceso promedio</span>
        <span class="kpi-value"><?=pe_e($kpis['proceso_prom'] ?? 'Sin datos'); ?></span>
      </div>
    </div>

    <div class="layout" style="margin-top:1rem; display:flex; gap: var(--sp-4); align-items:flex-start; flex-wrap:wrap;">
      <main style="flex:1 1 420px;">
        <div class="card">
          <h3>Sobre la empresa</h3>
          <p class="muted"><?=pe_e($empresa['descripcion'] ?? $descripcion); ?></p>
          <div style="display:flex; gap:.8rem; flex-wrap:wrap;">
            <?php if (!empty($empresa['mision'])): ?>
              <div style="flex:1 1 240px;">
                <strong>Propósito</strong>
                <p class="muted m-0"><?=pe_e($empresa['mision']); ?></p>
              </div>
            <?php endif; ?>
            <?php if ($empresa['valores']): ?>
              <div style="flex:1 1 240px;">
                <strong>Valores</strong>
                <p class="muted m-0"><?=pe_e(implode(' · ', $empresa['valores'])); ?></p>
              </div>
            <?php endif; ?>
            <?php if ($empresa['areas']): ?>
              <div style="flex:1 1 240px;">
                <strong>Áreas</strong>
                <p class="muted m-0"><?=pe_e(implode(' · ', $empresa['areas'])); ?></p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <h3>Tecnologías y herramientas</h3>
          <p class="muted">Esta información proviene del perfil de tu empresa y ayuda al matching de candidatos.</p>
          <?php if ($empresa['tecnologias']): ?>
            <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
              <?php foreach ($empresa['tecnologias'] as $tech): ?>
                <span class="chip"><?=pe_e($tech); ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="muted m-0">Aún no has cargado tecnologías.</p>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3>Beneficios</h3>
          <p class="muted m-0">Comparte los beneficios de trabajar en tu empresa para atraer mejor talento.</p>
        </div>

        <div class="card">
          <h3>Vacantes destacadas</h3>
          <?php if ($vacantesDestacadas): ?>
            <?php foreach ($vacantesDestacadas as $vacante): ?>
              <div class="card" style="margin-top:.6rem;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <h3 class="mb-2"><?=pe_e($vacante['titulo']); ?></h3>
                    <?php
                      $vacLead = array_filter([
                        $vacante['modalidad'] ?? null,
                        $vacante['tipo_contrato'] ?? null,
                        $vacante['ciudad'] ?? null,
                      ]);
                    ?>
                    <?php if ($vacLead): ?>
                      <p class="muted"><?=pe_e(implode(' · ', $vacLead)); ?></p>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($vacante['estado'])): ?>
                    <span class="chip"><?=pe_e($vacante['estado']); ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($vacante['resumen'])): ?>
                  <p class="muted"><?=pe_e($vacante['resumen']); ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="muted m-0">No tienes vacantes publicadas todavía.</p>
          <?php endif; ?>
        </div>
      </main>

      <aside style="flex:0 0 320px; display:flex; flex-direction:column; gap: var(--sp-4);">
        <div class="card">
          <h3>Contacto principal</h3>
          <ul role="list" style="list-style:none; padding:0; margin:0; display:grid; gap:.4rem;">
            <?php if (!empty($contacto['nombre'])): ?>
              <li><strong><?=pe_e($contacto['nombre']); ?></strong></li>
            <?php endif; ?>
            <?php if (!empty($contacto['email'])): ?>
              <li><a class="link" href="mailto:<?=pe_e($contacto['email']); ?>"><?=pe_e($contacto['email']); ?></a></li>
            <?php endif; ?>
            <?php if (!empty($contacto['telefono'])): ?>
              <li><a class="link" href="tel:<?=pe_e($contacto['telefono']); ?>"><?=pe_e($contacto['telefono']); ?></a></li>
            <?php endif; ?>
          </ul>
        </div>

        <div class="card">
          <h3>Presencia digital</h3>
          <ul role="list" style="list-style:none; padding:0; margin:0; display:grid; gap:.4rem;">
            <?php if (!empty($empresa['sitio_web'])): ?>
              <li><a class="link" target="_blank" rel="noopener" href="<?=pe_e($empresa['sitio_web']); ?>">Sitio web</a></li>
            <?php endif; ?>
            <?php if (!empty($empresa['linkedin'])): ?>
              <li><a class="link" target="_blank" rel="noopener" href="<?=pe_e($empresa['linkedin']); ?>">LinkedIn</a></li>
            <?php endif; ?>
            <?php if (!empty($empresa['facebook'])): ?>
              <li><a class="link" target="_blank" rel="noopener" href="<?=pe_e($empresa['facebook']); ?>">Facebook</a></li>
            <?php endif; ?>
            <?php if (!empty($empresa['instagram'])): ?>
              <li><a class="link" target="_blank" rel="noopener" href="<?=pe_e($empresa['instagram']); ?>">Instagram</a></li>
            <?php endif; ?>
            <?php if (!empty($empresa['x'])): ?>
              <li><a class="link" target="_blank" rel="noopener" href="<?=pe_e($empresa['x']); ?>">X / Twitter</a></li>
            <?php endif; ?>
            <?php if (!empty($empresa['youtube'])): ?>
              <li><a class="link" target="_blank" rel="noopener" href="<?=pe_e($empresa['youtube']); ?>">YouTube</a></li>
            <?php endif; ?>
            <?php if (!empty($empresa['glassdoor'])): ?>
              <li><a class="link" target="_blank" rel="noopener" href="<?=pe_e($empresa['glassdoor']); ?>">Glassdoor</a></li>
            <?php endif; ?>
          </ul>
          <?php if (
            empty($empresa['sitio_web']) &&
            empty($empresa['linkedin']) &&
            empty($empresa['facebook']) &&
            empty($empresa['instagram']) &&
            empty($empresa['x']) &&
            empty($empresa['youtube']) &&
            empty($empresa['glassdoor'])
          ): ?>
            <p class="muted m-0">Aún no has agregado enlaces.</p>
          <?php endif; ?>
        </div>
      </aside>
    </div>
  </div>
</section>
