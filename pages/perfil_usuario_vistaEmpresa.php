<?php
declare(strict_types=1);

// Acceso solo via index.php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=perfil_usuario_vistaEmpresa');
  exit;
}

if (!function_exists('pp_e')) {
  function pp_e(?string $value): string
  {
    $val = $value ?? '';
    if (function_exists('fix_mojibake')) { $val = fix_mojibake($val); }
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
  }
}

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || (($sessionUser['type'] ?? '') !== 'empresa')) {
  header('Location: index.php?view=login');
  exit;
}



$targetEmail = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
if ($targetEmail === '') {
  header('Location: index.php?view=mis_ofertas_empresa');
  exit;
}

require_once __DIR__.'/db.php';
// Prefijo de esquema: dejamos vacío y confiamos en la DB activa (DB_NAME en .env)
$schemaPrefix = '';

$perfil = [
  'nombre'       => '',
  'titulo'       => '',
  'ubicacion'    => '',
  'bio'          => '',
  'skills'       => [],
  'experiencias' => [],
  'educacion'    => [],
  'contacto'     => [
    'email'    => $targetEmail,
    'telefono' => '',
    'linkedin' => '#',
  ],
  'foto'         => null,
];

$splitList = static function (?string $value): array {
  if ($value === null) { return []; }
  $items = preg_split('/[,;\r\n]+/', $value);
  if (!is_array($items)) { return []; }
  $items = array_map('trim', $items);
  $items = array_filter($items, static fn(string $item) => $item !== '');
  return array_values(array_unique($items));
};

if (($pdo instanceof PDO)) {
  try {
    $stmt = $pdo->prepare(
      "SELECT c.nombres, c.apellidos, c.ciudad, c.telefono,
              cd.perfil AS resumen, cd.areas_interes, cd.foto_ruta, cd.pais, cd.linkedin,
              cp.rol_deseado, cp.habilidades,
              a.nombre AS area_nombre,
              n.nombre AS nivel_nombre,
              m.nombre AS modalidad_nombre,
              d.nombre AS disponibilidad_nombre
       FROM {$schemaPrefix}candidatos c
       LEFT JOIN {$schemaPrefix}candidato_detalles cd ON cd.email = c.email
       LEFT JOIN {$schemaPrefix}candidato_perfil cp ON cp.email = c.email
       LEFT JOIN {$schemaPrefix}areas a ON a.id = cp.area_id
       LEFT JOIN {$schemaPrefix}niveles n ON n.id = cp.nivel_id
       LEFT JOIN {$schemaPrefix}modalidades m ON m.id = cp.modalidad_id
       LEFT JOIN {$schemaPrefix}disponibilidades d ON d.id = cp.disponibilidad_id
       WHERE LOWER(c.email) = LOWER(?)
       LIMIT 1"
    );
    $stmt->execute([$targetEmail]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $fullName = trim(($row['nombres'] ?? '').' '.($row['apellidos'] ?? ''));
      if ($fullName !== '') { $perfil['nombre'] = $fullName; }

      $headlineParts = array_filter([
        $row['rol_deseado'] ?? null,
        $row['nivel_nombre'] ?? null,
      ]);
      if ($headlineParts) {
        $perfil['titulo'] = implode(' - ', $headlineParts);
      } elseif (!empty($row['area_nombre'])) {
        $perfil['titulo'] = (string)$row['area_nombre'];
      }

      $locParts = array_filter([
        $row['ciudad'] ?? null,
        $row['pais'] ?? null,
      ]);
      if ($locParts) { $perfil['ubicacion'] = implode(', ', $locParts); }

      if (!empty($row['resumen'])) { $perfil['bio'] = (string)$row['resumen']; }
      if (!empty($row['foto_ruta'])) { $perfil['foto'] = (string)$row['foto_ruta']; }

      if (!empty($row['telefono'])) { $perfil['contacto']['telefono'] = (string)$row['telefono']; }

      $skillsStmt = $pdo->prepare(
        "SELECT nombre, COALESCE(anios_experiencia, anos_experiencia) AS anos_experiencia
         FROM {$schemaPrefix}candidato_habilidades
         WHERE LOWER(email) = LOWER(?)
         ORDER BY anos_experiencia DESC, nombre ASC"
      );
      $skillsStmt->execute([$targetEmail]);
      $skillRecords = $skillsStmt->fetchAll(PDO::FETCH_ASSOC);
      if ($skillRecords) {
        $skills = [];
        foreach ($skillRecords as $skill) {
          $name = trim((string)$skill['nombre']); if ($name === '') { continue; }
          $years = $skill['anos_experiencia'];
          if ($years !== null && $years !== '') {
            $yearsFloat = (float)$years; $yearsLabel = rtrim(rtrim(number_format($yearsFloat, 1, '.', ''), '0'), '.');
            $skills[] = $name.' - '.$yearsLabel.' '.($yearsFloat === 1.0 ? 'año' : 'años');
          } else { $skills[] = $name; }
        }
        if ($skills) { $perfil['skills'] = array_slice($skills, 0, 10); }
      } else {
        $skills = $splitList($row['habilidades'] ?? '');
        if (!$skills) { $skills = $splitList($row['areas_interes'] ?? ''); }
        if ($skills) { $perfil['skills'] = array_slice($skills, 0, 10); }
      }
    }

    $expStmt = $pdo->prepare(
      "SELECT cargo, empresa, periodo, COALESCE(anios_experiencia, anos_experiencia) AS anos_experiencia, descripcion
       FROM {$schemaPrefix}candidato_experiencias
       WHERE LOWER(email) = LOWER(?)
       ORDER BY orden ASC, created_at ASC"
    );
    $expStmt->execute([$targetEmail]);
    foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $exp) {
      $perfil['experiencias'][] = [
        'cargo'  => $exp['cargo'] ?: 'Experiencia',
        'empresa'=> $exp['empresa'] ?: '',
        'periodo'=> $exp['periodo'] ?: '',
        'anos_experiencia' => $exp['anos_experiencia'],
        'desc'   => $exp['descripcion'] ?: '',
      ];
    }

    $eduStmt = $pdo->prepare(
      "SELECT titulo, institucion, periodo, descripcion
         FROM {$schemaPrefix}candidato_educacion
        WHERE LOWER(email) = LOWER(?)
        ORDER BY orden ASC, created_at ASC"
    );
    $eduStmt->execute([$targetEmail]);
    foreach ($eduStmt->fetchAll(PDO::FETCH_ASSOC) as $edu) {
      $perfil['educacion'][] = [
        'titulo'      => $edu['titulo'] ?: 'Estudio',
        'institucion' => $edu['institucion'] ?: '',
        'periodo'     => $edu['periodo'] ?: '',
        'desc'        => $edu['descripcion'] ?: '',
      ];
    }
  } catch (Throwable $profileError) {
    error_log('[perfil_usuario_vistaEmpresa] '.$profileError->getMessage());
  }
}

// No filtramos en la vista de empresa; mostramos todo lo disponible.
$perfil['experiencias'] = array_values($perfil['experiencias']);
$perfil['educacion'] = array_values($perfil['educacion']);

$flashProfileError = $_SESSION['flash_profile_error'] ?? null;
$flashProfile = $_SESSION['flash_profile'] ?? null;
unset($_SESSION['flash_profile']);
$displayedError = false;
?>

<section class="section container" style="margin-block: var(--sp-4);">
  <?php if ($flashProfileError): ?>
    <div class="card" style="border-color:#ffdddd; background:#fff6f6; margin-bottom:var(--sp-4);">
      <strong>Error:</strong> <?=pp_e($flashProfileError); ?>
    </div>
    <?php $displayedError = true; ?>
  <?php endif; ?>

  <?php if ($flashProfile && !$displayedError): ?>
    <div class="card" style="border-color:#d6f5d6; background:#f3fff3; margin-bottom:var(--sp-4);">
      <strong><?=pp_e($flashProfile); ?></strong>
    </div>
  <?php endif; ?>
  <?php unset($_SESSION['flash_profile_error']); ?>

  <div class="grid" style="grid-template-columns: 1fr 2fr; gap: var(--sp-4);">
    <aside class="card" style="display:flex; flex-direction:column; gap:var(--sp-3);">
      <?php $avatarStyle = !empty($perfil['foto']) ? "background-image:url('".pp_e($perfil['foto'])."'); background-size:cover; background-position:center;" : ''; ?>
      <div class="avatar" aria-hidden="true" style="inline-size:96px; block-size:96px; <?=$avatarStyle?>"></div>
      <div>
        <h1 class="h4 m-0"><?=pp_e($perfil['nombre']); ?></h1>
        <p class="m-0 muted"><?=pp_e($perfil['titulo']); ?></p>
        <p class="m-0 muted"><?=pp_e($perfil['ubicacion']); ?></p>
      </div>
      <div>
        <h2 class="h6 m-0">Competencias</h2>
        <div class="tags">
          <?php foreach ($perfil['skills'] as $skill): ?>
            <span class="chip"><?=pp_e($skill); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <h2 class="h6 m-0">Contacto</h2>
        <ul role="list" style="list-style:none; padding:0; display:grid; gap:.4rem;">
          <li><a class="link" href="mailto:<?=pp_e($perfil['contacto']['email']); ?>"><?=pp_e($perfil['contacto']['email']); ?></a></li>
          <li><a class="link" href="tel:<?=pp_e($perfil['contacto']['telefono']); ?>"><?=pp_e($perfil['contacto']['telefono']); ?></a></li>
          <?php if (!empty($perfil['contacto']['linkedin'])): ?>
            <li><a class="link" href="<?=pp_e($perfil['contacto']['linkedin']); ?>" target="_blank" rel="noopener">LinkedIn</a></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="row-cta" style="justify-content:flex-start; margin-top:auto;">
        <!-- Vista de empresa: sin botón Editar -->
        <button class="btn btn-outline" type="button">Descargar PDF</button>
        <button class="btn btn-primary" type="button">Contactar</button>
      </div>
    </aside>

    <section style="display:grid; gap:var(--sp-4);">
      <div class="card">
        <h2 class="h5">Sobre el candidato</h2>
        <p><?=pp_e($perfil['bio']); ?></p>
      </div>

      <div class="card" style="display:grid; gap:var(--sp-3);">
        <h2 class="h5 m-0">Experiencia</h2>
        <?php if ($perfil['experiencias']): ?>
          <div style="display:grid; gap:var(--sp-3);">
            <?php foreach ($perfil['experiencias'] as $exp): ?>
              <div class="card" style="padding:var(--sp-3);">
                <h3 class="h6 m-0">
                  <?=pp_e($exp['cargo']); ?>
                  <?php if (!empty($exp['empresa'])): ?> - <?=pp_e($exp['empresa']); ?> <?php endif; ?>
                </h3>
                <?php $labels = array_filter([ $exp['periodo'] ?? '', isset($exp['anos_experiencia']) && $exp['anos_experiencia'] !== '' ? ($exp['anos_experiencia'].' años') : null, ]); ?>
                <?php if ($labels): ?><p class="muted m-0"><?=pp_e(implode(' - ', $labels)); ?></p><?php endif; ?>
                <?php if (!empty($exp['desc'])): ?><p class="m-0"><?=pp_e($exp['desc']); ?></p><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted m-0">Sin experiencias registradas.</p>
        <?php endif; ?>
      </div>

      <div class="card" style="display:grid; gap:var(--sp-3);">
        <h2 class="h5 m-0">Educacion</h2>
        <?php if ($perfil['educacion']): ?>
          <div style="display:grid; gap:var(--sp-3);">
            <?php foreach ($perfil['educacion'] as $edu): ?>
              <div class="card" style="padding:var(--sp-3);">
                <strong><?=pp_e($edu['titulo']); ?></strong>
                <?php $eduLabels = array_filter([ $edu['institucion'] ?? '', $edu['periodo'] ?? '', ]); ?>
                <?php if ($eduLabels): ?><p class="m-0 muted"><?=pp_e(implode(' - ', $eduLabels)); ?></p><?php endif; ?>
                <?php if (!empty($edu['desc'])): ?><p class="m-0"><?=pp_e($edu['desc']); ?></p><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted m-0">Sin estudios registrados.</p>
        <?php endif; ?>
      </div>
    </section>
  </div>
</section>

<?php // Fin de perfil_usuario_vistaEmpresa ?>
