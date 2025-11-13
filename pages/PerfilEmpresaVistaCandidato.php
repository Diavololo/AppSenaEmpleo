<?php
declare(strict_types=1);

// Solo vía index.php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=PerfilEmpresaVistaCandidato');
  exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['type'] ?? '') !== 'persona') {
  // Vista pensada para candidatos
  header('Location: index.php?view=login');
  exit;
}

require __DIR__.'/db.php';

function pec_e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function pec_split_list(?string $value): array {
  if ($value === null) { return []; }
  $parts = preg_split('/[,;\r\n]+/', (string)$value);
  if (!is_array($parts)) { return []; }
  $parts = array_map(static fn($tag) => trim((string)$tag), $parts);
  $parts = array_filter($parts, static fn($tag) => $tag !== '');
  return array_values(array_unique($parts));
}
function pec_format_money(?int $min, ?int $max, string $currency): string {
  $currency = strtoupper(trim($currency ?: 'COP'));
  if ($min === null && $max === null) { return 'Salario a convenir'; }
  $fmt = static function (?int $v) use ($currency): string { return ($v === null || $v <= 0) ? '' : $currency.' '.number_format($v, 0, ',', '.'); };
  if ($min !== null && $max !== null) { return $fmt($min).' - '.$fmt($max); }
  if ($min !== null) { return 'Desde '.$fmt($min); }
  return 'Hasta '.$fmt($max);
}

$empresaId = isset($_GET['empresa_id']) ? (int)$_GET['empresa_id'] : 0;
$empresa = null;
$empresaFicha = [
  'sector' => null,
  'tamano' => null,
  'ciudad' => null,
  'pais' => null,
  'descripcion' => null,
  'mision' => null,
  'valores' => [],
  'areas' => [],
  'tecnologias' => [],
  'logo_url' => null,
  'portada_url' => null,
  'sitio_web' => null,
  'verificada' => false,
  'anio_fundacion' => null,
  'modalidad' => null,
  // presencia digital
  'linkedin' => null,
  'facebook' => null,
  'instagram' => null,
  'x' => null,
  'youtube' => null,
  'glassdoor' => null,
];
$kpis = ['activas' => 0, 'respuesta' => null, 'proceso' => null];
$vacantesActivas = [];
$error = null;

if ($empresaId > 0 && ($pdo instanceof PDO)) {
  try {
    $stmt = $pdo->prepare('SELECT e.id, e.razon_social, e.nombre_comercial, e.sitio_web, e.ciudad, e.logo_url, e.portada_url,
                                   e.linkedin_url, e.facebook_url, e.instagram_url,
                                   s.nombre AS sector_nombre, t.nombre AS tamano_nombre, e.estado, e.verificada,
                                   d.descripcion, d.mision, d.valores, d.areas_contratacion, d.tecnologias,
                                   d.pais, d.tipo_entidad, d.anio_fundacion, d.modalidad_trabajo,
                                   d.link_x, d.link_youtube, d.link_glassdoor
                              FROM empresas e
                              LEFT JOIN sectores s ON s.id = e.sector_id
                              LEFT JOIN tamanos_empresa t ON t.id = e.tamano_id
                              LEFT JOIN empresa_detalles d ON d.empresa_id = e.id
                             WHERE e.id = ? LIMIT 1');
    $stmt->execute([$empresaId]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {
    $error = 'No fue posible cargar la empresa.';
    error_log('[PerfilEmpresaVistaCandidato] '.$e->getMessage());
  }
  if ($empresa) {
    $empresaFicha['sector'] = $empresa['sector_nombre'] ?? null;
    $empresaFicha['tamano'] = $empresa['tamano_nombre'] ?? null;
    $empresaFicha['ciudad'] = $empresa['ciudad'] ?? null;
    $empresaFicha['pais'] = $empresa['pais'] ?? null;
    $empresaFicha['descripcion'] = $empresa['descripcion'] ?? null;
    $empresaFicha['mision'] = $empresa['mision'] ?? null;
    $empresaFicha['valores'] = pec_split_list($empresa['valores'] ?? null);
    $empresaFicha['areas'] = pec_split_list($empresa['areas_contratacion'] ?? null);
    $empresaFicha['tecnologias'] = pec_split_list($empresa['tecnologias'] ?? null);
    $empresaFicha['logo_url'] = $empresa['logo_url'] ?? null;
    $empresaFicha['portada_url'] = $empresa['portada_url'] ?? null;
    $empresaFicha['sitio_web'] = $empresa['sitio_web'] ?? null;
    $empresaFicha['verificada'] = !empty($empresa['verificada']);
    $empresaFicha['anio_fundacion'] = $empresa['anio_fundacion'] ?? null;
    $empresaFicha['modalidad'] = $empresa['modalidad_trabajo'] ?? null;
    // presencia digital
    $empresaFicha['linkedin'] = $empresa['linkedin_url'] ?? null;
    $empresaFicha['facebook'] = $empresa['facebook_url'] ?? null;
    $empresaFicha['instagram'] = $empresa['instagram_url'] ?? null;
    $empresaFicha['x'] = $empresa['link_x'] ?? null;
    $empresaFicha['youtube'] = $empresa['link_youtube'] ?? null;
    $empresaFicha['glassdoor'] = $empresa['link_glassdoor'] ?? null;
    // KPIs
    try {
      $k = $pdo->prepare('SELECT SUM(estado IN ("publicada","activa")) AS activas FROM vacantes WHERE empresa_id = ?');
      $k->execute([$empresaId]);
      $kpis['activas'] = (int)($k->fetchColumn() ?? 0);
    } catch (Throwable $ke) { error_log('[PEC][kpis] '.$ke->getMessage()); }
    try {
      $vstmt = $pdo->prepare('SELECT v.id, v.titulo, v.ciudad, v.descripcion, v.salario_min, v.salario_max, v.moneda,
                                     m.nombre AS modalidad, c.nombre AS contrato
                                FROM vacantes v
                               LEFT JOIN modalidades m ON m.id = v.modalidad_id
                               LEFT JOIN contratos c ON c.id = v.tipo_contrato_id
                               WHERE v.empresa_id = ? AND v.estado IN ("publicada","activa")
                               ORDER BY COALESCE(v.publicada_at, v.created_at) DESC');
      $vstmt->execute([$empresaId]);
      $vacantesActivas = $vstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $ve) {
      error_log('[PerfilEmpresaVistaCandidato][vacantes] '.$ve->getMessage());
    }
  } else {
    $error = 'No encontramos la empresa.';
  }
} else {
  $error = 'Empresa no especificada.';
}
?>

<?php
  $portada = $empresaFicha['portada_url'] ?: 'assets/Empresa.png';
  $logo = $empresaFicha['logo_url'] ?: null;
  $nombreComercial = trim((string)($empresa['nombre_comercial'] ?? ''));
  if ($nombreComercial === '') {
    $nombreComercial = trim((string)($empresa['razon_social'] ?? ''));
  }
  $nombre = $nombreComercial !== '' ? $nombreComercial : 'Empresa';
?>

<section class="section">
  <div class="container">
    <div class="card" style="padding:0; overflow:hidden;">
      <div style="height:180px; background:#eef3f7;">
        <img src="<?=pec_e($portada); ?>" alt="Portada de <?=pec_e($nombre); ?>" style="width:100%; height:100%; object-fit:cover; display:block;" />
      </div>
      <div style="padding:1rem; display:flex; justify-content:space-between; align-items:flex-start; gap:1rem;">
        <div style="flex:1; display:flex; flex-direction:column; gap:.75rem;">
          <div style="display:flex; align-items:center; gap:.75rem;">
            <?php if ($logo): ?>
              <img src="<?=pec_e($logo); ?>" alt="Logo <?=pec_e($nombre); ?>" style="width:72px; height:72px; border-radius:16px; object-fit:cover; background:#fff; box-shadow:0 0 0 1px rgba(0,0,0,0.08);" />
            <?php endif; ?>
            <div>
              <h2 class="m-0"><?=pec_e($nombre); ?></h2>
              <?php if (!empty($empresa['estado'] ?? null)): ?><p class="muted m-0"><?=pec_e((string)$empresa['estado']); ?></p><?php endif; ?>
            </div>
          </div>
          <p class="muted m-0"><?=pec_e($empresaFicha['descripcion'] ?? ''); ?></p>
          <div style="display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.25rem;">
            <?php if (!empty($empresaFicha['sector'])): ?><span class="chip">Industria: <?=pec_e($empresaFicha['sector']); ?></span><?php endif; ?>
            <?php if (!empty($empresaFicha['tamano'])): ?><span class="chip">Tamaño: <?=pec_e($empresaFicha['tamano']); ?></span><?php endif; ?>
            <?php if (!empty($empresaFicha['anio_fundacion'])): ?><span class="chip">Fundada en <?=pec_e((string)$empresaFicha['anio_fundacion']); ?></span><?php endif; ?>
            <?php if (!empty($empresaFicha['modalidad'])): ?><span class="chip">Modalidad: <?=pec_e($empresaFicha['modalidad']); ?></span><?php endif; ?>
            <?php if (!empty($empresaFicha['ciudad'])): ?><span class="chip muted">Sede: <?=pec_e($empresaFicha['ciudad']); ?><?= !empty($empresaFicha['pais']) ? ', '.pec_e($empresaFicha['pais']) : ''; ?></span><?php endif; ?>
            <?php if ($empresaFicha['verificada']): ?><span class="chip">Cuenta verificada</span><?php endif; ?>
            <?php if (!empty($empresa['estado'] ?? null)): ?><span class="chip muted">Estado: <?=pec_e((string)$empresa['estado']); ?></span><?php endif; ?>
          </div>
        </div>
        <div class="actions" style="gap:.5rem; display:flex; flex-direction:column; align-items:flex-end;">
          <?php if (!empty($empresaFicha['sitio_web'])): ?>
            <a class="btn btn-secondary" href="<?=pec_e($empresaFicha['sitio_web']); ?>" target="_blank" rel="noopener">Visitar sitio</a>
          <?php endif; ?>
          <a class="btn btn-primary" href="index.php?view=OfertasEmpresaVistaCandidato&empresa_id=<?=pec_e((string)$empresaId); ?>">Ver ofertas</a>
        </div>
      </div>
    </div>

    <div class="kpis" style="margin-top:1rem; display:flex; gap: var(--sp-4); flex-wrap:wrap;">
      <div class="card kpi" style="flex:1 1 220px;">
        <span class="kpi-label">Vacantes activas</span>
        <span class="kpi-value"><?=pec_e((string)$kpis['activas']); ?></span>
      </div>
      <div class="card kpi" style="flex:1 1 220px;">
        <span class="kpi-label">Tiempo de respuesta</span>
        <span class="kpi-value"><?=pec_e($kpis['respuesta'] ?? '—'); ?></span>
      </div>
      <div class="card kpi" style="flex:1 1 220px;">
        <span class="kpi-label">Proceso promedio</span>
        <span class="kpi-value"><?=pec_e($kpis['proceso'] ?? '—'); ?></span>
      </div>
    </div>

    <div class="layout" style="margin-top:1rem; display:flex; gap: var(--sp-4); align-items:flex-start; flex-wrap:wrap;">
      <main style="flex:1 1 420px;">
        <div class="card">
          <h3>Sobre la empresa</h3>
          <p class="muted"><?=pec_e($empresaFicha['descripcion'] ?? ''); ?></p>
          <div style="display:flex; gap:.8rem; flex-wrap:wrap;">
            <?php if (!empty($empresaFicha['mision'])): ?>
              <div style="flex:1 1 240px;">
                <strong>Propósito</strong>
                <p class="muted m-0"><?=pec_e($empresaFicha['mision']); ?></p>
              </div>
            <?php endif; ?>
            <?php if ($empresaFicha['valores']): ?>
              <div style="flex:1 1 240px;">
                <strong>Valores</strong>
                <p class="muted m-0"><?=pec_e(implode(' · ', $empresaFicha['valores'])); ?></p>
              </div>
            <?php endif; ?>
            <?php if ($empresaFicha['areas']): ?>
              <div style="flex:1 1 240px;">
                <strong>Áreas</strong>
                <p class="muted m-0"><?=pec_e(implode(' · ', $empresaFicha['areas'])); ?></p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <h3>Tecnologías y herramientas</h3>
          <p class="muted">Información que ayuda al matching de candidatos.</p>
          <?php if ($empresaFicha['tecnologias']): ?>
            <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
              <?php foreach ($empresaFicha['tecnologias'] as $tech): ?>
                <span class="chip"><?=pec_e($tech); ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="muted m-0">No hay tecnologías registradas.</p>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3>Vacantes activas</h3>
          <?php if ($vacantesActivas): ?>
            <?php foreach ($vacantesActivas as $v): ?>
              <article class="card co-card" style="margin-top:.6rem;">
                <div class="co-card-head" style="display:flex; justify-content:space-between; align-items:center;">
                  <div>
                    <h3 class="mb-2"><?=pec_e($v['titulo'] ?? 'Oferta'); ?></h3>
                    <?php $lead = array_filter([$v['modalidad'] ?? null, $v['contrato'] ?? null, $v['ciudad'] ?? null]); ?>
                    <?php if ($lead): ?><p class="muted"><?=pec_e(implode(' · ', $lead)); ?></p><?php endif; ?>
                  </div>
                </div>
                <?php if (!empty($v['descripcion'])): ?>
                  <p class="muted"><?=pec_e($v['descripcion']); ?></p>
                <?php endif; ?>
                <div class="co-meta-row" style="display:flex; gap:1rem;">
                  <div>
                    <span class="co-meta-label">Salario</span>
                    <span class="co-meta-value"><?=pec_e(pec_format_money(isset($v['salario_min'])?(int)$v['salario_min']:null, isset($v['salario_max'])?(int)$v['salario_max']:null, (string)($v['moneda'] ?? 'COP'))); ?></span>
                  </div>
                </div>
                <div class="co-actions" style="display:flex; gap:.6rem;">
                  <a class="btn btn-outline" href="index.php?view=oferta_detalle&id=<?=pec_e((string)$v['id']); ?>">Ver detalle</a>
                  <a class="btn btn-brand" href="index.php?view=oferta_detalle&id=<?=pec_e((string)$v['id']); ?>&apply=1">Postular</a>
                </div>
              </article>
            <?php endforeach; ?>
            <div class="actions" style="margin-top:.6rem;">
              <a class="btn btn-secondary" href="index.php?view=OfertasEmpresaVistaCandidato&empresa_id=<?=pec_e((string)$empresaId); ?>">Ver todas las ofertas</a>
            </div>
          <?php else: ?>
            <p class="muted m-0">Esta empresa no tiene vacantes activas en este momento.</p>
          <?php endif; ?>
        </div>
      </main>

      <aside style="flex:0 0 320px; display:flex; flex-direction:column; gap: var(--sp-4);">
        <div class="card">
          <h3>Ficha de empresa</h3>
          <ul role="list" style="list-style:none; padding:0; margin:0; display:grid; gap:.4rem;">
            <li><strong>Razón social:</strong> <?=pec_e($empresa['razon_social'] ?? ''); ?></li>
            <li><strong>Sitio:</strong> <?= !empty($empresaFicha['sitio_web']) ? '<a href="'.pec_e((string)$empresaFicha['sitio_web']).'" target="_blank" rel="noopener">'.pec_e((string)$empresaFicha['sitio_web']).'</a>' : '—'; ?></li>
            <li><strong>Ubicación:</strong> <?=pec_e($empresaFicha['ciudad'] ?? ''); ?><?= !empty($empresaFicha['pais']) ? ', '.pec_e($empresaFicha['pais']) : ''; ?></li>
            <li><strong>Sector:</strong> <?=pec_e($empresaFicha['sector'] ?? ''); ?></li>
            <li><strong>Tamaño:</strong> <?=pec_e($empresaFicha['tamano'] ?? ''); ?></li>
            <li><strong>Verificada:</strong> <?= $empresaFicha['verificada'] ? 'Sí' : 'No'; ?></li>
          </ul>
        </div>
        <div class="card">
          <h3>Presencia digital</h3>
          <ul role="list" style="list-style:none; padding:0; margin:0; display:grid; gap:.4rem;">
            <?php if (!empty($empresaFicha['sitio_web'])): ?><li><a class="link" target="_blank" rel="noopener" href="<?=pec_e($empresaFicha['sitio_web']); ?>">Sitio web</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['linkedin'])): ?><li><a class="link" target="_blank" rel="noopener" href="<?=pec_e($empresaFicha['linkedin']); ?>">LinkedIn</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['facebook'])): ?><li><a class="link" target="_blank" rel="noopener" href="<?=pec_e($empresaFicha['facebook']); ?>">Facebook</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['instagram'])): ?><li><a class="link" target="_blank" rel="noopener" href="<?=pec_e($empresaFicha['instagram']); ?>">Instagram</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['x'])): ?><li><a class="link" target="_blank" rel="noopener" href="<?=pec_e($empresaFicha['x']); ?>">X / Twitter</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['youtube'])): ?><li><a class="link" target="_blank" rel="noopener" href="<?=pec_e($empresaFicha['youtube']); ?>">YouTube</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['glassdoor'])): ?><li><a class="link" target="_blank" rel="noopener" href="<?=pec_e($empresaFicha['glassdoor']); ?>">Glassdoor</a></li><?php endif; ?>
          </ul>
        </div>
      </aside>
    </div>
  </div>
</section>