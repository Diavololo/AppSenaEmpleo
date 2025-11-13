<?php
declare(strict_types=1);

// Solo vía index.php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=PerfilEmpresaVistaCandidato');
  exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$user = $_SESSION['user'] ?? null;

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
function pec_truncate(?string $text, int $limit = 180): string {
  $text = trim((string)$text);
  if ($text === '' || mb_strlen($text, 'UTF-8') <= $limit) {
    return $text;
  }
  $slice = mb_substr($text, 0, $limit - 1, 'UTF-8');
  return rtrim($slice).'…';
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
    $stmt = $pdo->prepare('SELECT e.id, e.nit, e.razon_social, e.nombre_comercial, e.sitio_web, e.ciudad, e.direccion, e.descripcion, e.telefono, e.email_contacto, e.logo_url, e.portada_url,
                                   e.linkedin_url, e.facebook_url, e.instagram_url,
                                   s.nombre AS sector_nombre, t.nombre AS tamano_nombre, e.estado, e.verificada,
                                   d.mision, d.valores, d.areas_contratacion, d.tecnologias,
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
if ($error) {
?>
  <section class="section">
    <div class="container">
      <div class="card co-alert co-alert--error" style="display:flex; flex-direction:column; gap:.5rem;">
        <strong><?=pec_e($error); ?></strong>
        <a class="btn btn-secondary" href="index.php?view=dashboard">Volver al dashboard</a>
      </div>
    </div>
  </section>
<?php
  return;
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

  $tagline = pec_truncate($empresa['descripcion'] ?? '');
  $heroChips = [];
  if (!empty($empresaFicha['sector'])) { $heroChips[] = 'Industria: '.$empresaFicha['sector']; }
  if (!empty($empresaFicha['tamano'])) { $heroChips[] = 'Tamaño: '.$empresaFicha['tamano']; }
  if (!empty($empresaFicha['anio_fundacion'])) { $heroChips[] = 'Fundada en '.(string)$empresaFicha['anio_fundacion']; }
  if (!empty($empresaFicha['modalidad'])) { $heroChips[] = 'Modalidad: '.$empresaFicha['modalidad']; }
  if (!empty($empresaFicha['ciudad']) || !empty($empresaFicha['pais'])) {
    $heroChips[] = 'Sede: '.trim(($empresaFicha['ciudad'] ?? '').(!empty($empresaFicha['pais']) ? ', '.$empresaFicha['pais'] : ''));
  }

  $badges = [];
  if (!empty($empresa['estado'])) { $badges[] = ['label' => ucfirst((string)$empresa['estado']), 'class' => 'chip', 'style' => '']; }
  if (!empty($empresaFicha['verificada'])) { $badges[] = ['label' => 'Verificada', 'class' => 'chip', 'style' => 'background:#e9f7ef;color:#1f513f;font-weight:600;']; }

  $ctaEmail = trim((string)($empresa['email_contacto'] ?? ''));
  $ctaPhone = trim((string)($empresa['telefono'] ?? ''));
  $ctaSite = trim((string)($empresaFicha['sitio_web'] ?? ''));
  $ctaMailHref = $ctaEmail !== '' ? 'mailto:'.$ctaEmail.'?subject='.rawurlencode('Interés en '.$nombre) : null;

  $kpiCards = [
    ['label' => 'Vacantes activas', 'value' => $kpis['activas']],
    ['label' => 'Tiempo de respuesta', 'value' => $kpis['respuesta'] ?? '—'],
    ['label' => 'Proceso promedio', 'value' => $kpis['proceso'] ?? '—'],
  ];

  $valoresTexto = $empresaFicha['valores'] ? implode(' · ', $empresaFicha['valores']) : null;
  $areasTexto = $empresaFicha['areas'] ? implode(' · ', $empresaFicha['areas']) : null;
  $tecnologias = $empresaFicha['tecnologias'] ?: [];
  $beneficiosLista = [];
  $vacantesDestacadas = $vacantesActivas ? array_slice($vacantesActivas, 0, 2) : [];
  $mostrarTodasLasVacantes = count($vacantesActivas) > count($vacantesDestacadas);

  $empresaFicha['anio_fundacion'] = $empresaFicha['anio_fundacion'] !== null ? (string)$empresaFicha['anio_fundacion'] : null;
?>

<section class="section">
  <div class="container" style="display:flex; flex-direction:column; gap:1.5rem;">
    <div class="card" style="padding:0; overflow:hidden;">
      <div style="height:220px; background:#eef3f7;">
        <img src="<?=pec_e($portada); ?>" alt="Portada de <?=pec_e($nombre); ?>" style="width:100%; height:100%; object-fit:cover; display:block;" />
      </div>
      <div style="padding:1.5rem; display:flex; flex-wrap:wrap; gap:1.5rem; justify-content:space-between; align-items:flex-start;">
        <div style="flex:1 1 320px; display:flex; flex-direction:column; gap:.9rem;">
          <div style="display:flex; gap:1rem; align-items:center;">
            <?php if ($logo): ?>
              <img src="<?=pec_e($logo); ?>" alt="Logo <?=pec_e($nombre); ?>" style="width:80px; height:80px; border-radius:18px; object-fit:cover; background:#fff; box-shadow:0 0 0 1px rgba(0,0,0,0.08);" />
            <?php else: ?>
              <div style="width:80px; height:80px; border-radius:18px; background:#f3f5f7; display:flex; align-items:center; justify-content:center; font-weight:600; color:#96a0aa; text-transform:uppercase;">
                <?=pec_e(mb_substr($nombre, 0, 2, 'UTF-8')); ?>
              </div>
            <?php endif; ?>
            <div style="display:flex; flex-direction:column; gap:.35rem;">
              <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:center;">
                <h1 class="m-0"><?=pec_e($nombre); ?></h1>
                <?php foreach ($badges as $badge): ?>
                  <span class="<?=pec_e($badge['class']); ?>" style="font-size:.85rem;<?=pec_e($badge['style'] ?? ''); ?>"><?=pec_e($badge['label']); ?></span>
                <?php endforeach; ?>
              </div>
              <?php if ($tagline !== ''): ?><p class="muted m-0"><?=pec_e($tagline); ?></p><?php endif; ?>
            </div>
          </div>
          <?php if ($heroChips): ?>
            <div style="display:flex; flex-wrap:wrap; gap:.4rem;">
              <?php foreach ($heroChips as $chip): ?>
                <span class="chip"><?=pec_e($chip); ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center;">
          <?php if ($ctaMailHref): ?>
            <a class="btn btn-primary" href="<?=$ctaMailHref; ?>">Contactar</a>
          <?php endif; ?>
          <?php if ($ctaSite !== ''): ?>
            <a class="btn btn-secondary" href="<?=pec_e($ctaSite); ?>" target="_blank" rel="noopener">Visitar sitio</a>
          <?php endif; ?>
          <a class="btn btn-outline" href="index.php?view=OfertasEmpresaVistaCandidato&empresa_id=<?=pec_e((string)$empresaId); ?>">Ver ofertas</a>
        </div>
      </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:1rem;">
      <?php foreach ($kpiCards as $kpi): ?>
        <div class="card" style="text-align:center; padding:1rem;">
          <span class="muted" style="display:block; font-size:.9rem;"><?=pec_e($kpi['label']); ?></span>
          <span style="font-size:2rem; font-weight:600; color:#1f513f;"><?=pec_e((string)$kpi['value']); ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex; gap:1.5rem; flex-wrap:wrap; align-items:flex-start;">
      <div style="flex:1 1 520px; display:grid; gap:1rem;">
        <div class="card" style="display:grid; gap:.75rem;">
          <h3>Sobre la empresa</h3>
          <p class="muted m-0"><?=pec_e($empresa['descripcion'] ?? 'Esta empresa aún no ha añadido una descripción.'); ?></p>
          <div style="display:flex; flex-wrap:wrap; gap:1rem;">
            <?php if (!empty($empresaFicha['mision'])): ?>
              <div style="flex:1 1 220px;">
                <strong>Propósito</strong>
                <p class="muted m-0"><?=pec_e($empresaFicha['mision']); ?></p>
              </div>
            <?php endif; ?>
            <?php if ($valoresTexto): ?>
              <div style="flex:1 1 220px;">
                <strong>Valores</strong>
                <p class="muted m-0"><?=pec_e($valoresTexto); ?></p>
              </div>
            <?php endif; ?>
            <?php if ($areasTexto): ?>
              <div style="flex:1 1 220px;">
                <strong>Áreas de contratación</strong>
                <p class="muted m-0"><?=pec_e($areasTexto); ?></p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card" style="display:grid; gap:.75rem;">
          <h3>Tecnologías y herramientas</h3>
          <p class="muted m-0">Esta información ayuda a mejorar el match de candidatos.</p>
          <?php if ($tecnologias): ?>
            <div style="display:flex; flex-wrap:wrap; gap:.4rem;">
              <?php foreach ($tecnologias as $tech): ?>
                <span class="chip"><?=pec_e($tech); ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="muted m-0">La empresa aún no ha registrado tecnologías.</p>
          <?php endif; ?>
        </div>

        <div class="card" style="display:grid; gap:.75rem;">
          <h3>Beneficios</h3>
          <?php if ($beneficiosLista): ?>
            <ul style="margin:0; padding-left:1.2rem; display:grid; gap:.25rem;">
              <?php foreach ($beneficiosLista as $beneficio): ?>
                <li><?=pec_e($beneficio); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="muted m-0">La empresa no ha publicado beneficios aún.</p>
          <?php endif; ?>
        </div>

        <div class="card" style="display:grid; gap:.75rem;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem;">
            <h3 class="m-0">Vacantes destacadas</h3>
            <?php if ($mostrarTodasLasVacantes): ?>
              <a class="btn btn-outline" href="index.php?view=OfertasEmpresaVistaCandidato&empresa_id=<?=pec_e((string)$empresaId); ?>">Ver todas las ofertas</a>
            <?php endif; ?>
          </div>
          <?php if ($vacantesDestacadas): ?>
            <div style="display:grid; gap:1rem;">
              <?php foreach ($vacantesDestacadas as $v): ?>
                <article class="card" style="border:1px solid #e3e8ef; padding:1rem; display:grid; gap:.75rem;">
                  <div style="display:flex; justify-content:space-between; gap:.5rem; align-items:flex-start; flex-wrap:wrap;">
                    <div>
                      <h4 class="m-0"><?=pec_e($v['titulo'] ?? 'Oferta laboral'); ?></h4>
                      <?php $lead = array_filter([$v['modalidad'] ?? null, $v['contrato'] ?? null, $v['ciudad'] ?? null]); ?>
                      <?php if ($lead): ?><p class="muted m-0"><?=pec_e(implode(' · ', $lead)); ?></p><?php endif; ?>
                    </div>
                    <?php if (!empty($v['estado'])): ?>
                      <span class="badge"><?=pec_e(ucfirst((string)$v['estado'])); ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($v['descripcion'])): ?>
                    <p class="muted m-0"><?=pec_e($v['descripcion']); ?></p>
                  <?php endif; ?>
                  <div style="display:flex; gap:1.5rem; flex-wrap:wrap;">
                    <div>
                      <span class="muted" style="font-size:.85rem;">Salario</span>
                      <p class="m-0"><strong><?=pec_e(pec_format_money(isset($v['salario_min'])?(int)$v['salario_min']:null, isset($v['salario_max'])?(int)$v['salario_max']:null, (string)($v['moneda'] ?? 'COP'))); ?></strong></p>
                    </div>
                  </div>
                  <div style="display:flex; gap:.6rem; flex-wrap:wrap;">
                    <a class="btn btn-outline" href="index.php?view=oferta_detalle&id=<?=pec_e((string)$v['id']); ?>">Ver detalle</a>
                    <a class="btn btn-brand" href="index.php?view=oferta_detalle&id=<?=pec_e((string)$v['id']); ?>&apply=1">Postular</a>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="muted m-0">Esta empresa aún no tiene vacantes publicadas.</p>
          <?php endif; ?>
        </div>
      </div>

      <aside style="flex:0 0 320px; display:grid; gap:1rem;">
        <div class="card" style="display:grid; gap:.4rem;">
          <h3>Ficha de empresa</h3>
          <ul style="list-style:none; padding:0; margin:0; display:grid; gap:.35rem;">
            <li><strong>Razón social:</strong> <?=pec_e($empresa['razon_social'] ?? $nombre); ?></li>
            <li><strong>NIT:</strong> <?=pec_e($empresa['nit'] ?? '—'); ?></li>
            <li><strong>Industria:</strong> <?=pec_e($empresaFicha['sector'] ?? '—'); ?></li>
            <li><strong>Tamaño:</strong> <?=pec_e($empresaFicha['tamano'] ?? '—'); ?></li>
            <li><strong>Fundación:</strong> <?=pec_e($empresaFicha['anio_fundacion'] ?? '—'); ?></li>
            <li><strong>Modalidad:</strong> <?=pec_e($empresaFicha['modalidad'] ?? '—'); ?></li>
            <li><strong>Ubicación:</strong> <?=pec_e(trim(($empresaFicha['ciudad'] ?? '').(!empty($empresaFicha['pais']) ? ', '.$empresaFicha['pais'] : '')) ?: '—'); ?></li>
            <li><strong>Dirección:</strong> <?=pec_e($empresa['direccion'] ?? '—'); ?></li>
          </ul>
        </div>

        <div class="card" style="display:grid; gap:.5rem;">
          <h3>Contacto</h3>
          <ul style="list-style:none; padding:0; margin:0; display:grid; gap:.35rem;">
            <li><strong>Correo:</strong> <?= $ctaEmail !== '' ? '<a href="mailto:'.pec_e($ctaEmail).'">'.pec_e($ctaEmail).'</a>' : '—'; ?></li>
            <li><strong>Teléfono:</strong> <?=pec_e($ctaPhone !== '' ? $ctaPhone : '—'); ?></li>
            <li><strong>Cuenta verificada:</strong> <?= $empresaFicha['verificada'] ? 'Sí' : 'No'; ?></li>
          </ul>
          <?php if ($ctaMailHref): ?>
            <a class="btn btn-secondary" href="<?=$ctaMailHref; ?>">Enviar mensaje</a>
          <?php endif; ?>
        </div>

        <div class="card" style="display:grid; gap:.5rem;">
          <h3>Redes e enlaces</h3>
          <ul style="list-style:none; padding:0; margin:0; display:grid; gap:.35rem;">
            <?php if ($ctaSite !== ''): ?><li><a class="link" href="<?=pec_e($ctaSite); ?>" target="_blank" rel="noopener">Sitio web</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['linkedin'])): ?><li><a class="link" href="<?=pec_e($empresaFicha['linkedin']); ?>" target="_blank" rel="noopener">LinkedIn</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['facebook'])): ?><li><a class="link" href="<?=pec_e($empresaFicha['facebook']); ?>" target="_blank" rel="noopener">Facebook</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['instagram'])): ?><li><a class="link" href="<?=pec_e($empresaFicha['instagram']); ?>" target="_blank" rel="noopener">Instagram</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['x'])): ?><li><a class="link" href="<?=pec_e($empresaFicha['x']); ?>" target="_blank" rel="noopener">X / Twitter</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['youtube'])): ?><li><a class="link" href="<?=pec_e($empresaFicha['youtube']); ?>" target="_blank" rel="noopener">YouTube</a></li><?php endif; ?>
            <?php if (!empty($empresaFicha['glassdoor'])): ?><li><a class="link" href="<?=pec_e($empresaFicha['glassdoor']); ?>" target="_blank" rel="noopener">Glassdoor</a></li><?php endif; ?>
          </ul>
        </div>
      </aside>
    </div>
  </div>
</section>
