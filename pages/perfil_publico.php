<?php
declare(strict_types=1);

// Solo accesible via index.php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=perfil_publico');
  exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__.'/db.php';
require_once dirname(__DIR__).'/lib/EncodingHelper.php';

// Escape seguro con limpieza de mojibake
if (!function_exists('pp_e')) {
  function pp_e(?string $value): string {
    $val = $value ?? '';
    if (function_exists('fix_mojibake')) { $val = fix_mojibake($val); }
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
  }
}

$sessionUser = $_SESSION['user'] ?? null;
$viewerIsEmpresa = is_array($sessionUser) && (($sessionUser['type'] ?? '') === 'empresa');
$targetEmail = $viewerIsEmpresa
  ? trim((string)($_GET['email'] ?? ''))
  : (string)($sessionUser['email'] ?? '');

if ($targetEmail === '') {
  header('Location: index.php?view=login');
  exit;
}

$DB_NAME = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'mydb');
$schemaPrefix = $DB_NAME ? ($DB_NAME . '.') : '';

// Datos del perfil
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
  'cv'           => null,
];

$splitList = static function (?string $value): array {
  if ($value === null) { return []; }
  $items = preg_split('/[,;\r\n]+/', $value);
  if (!is_array($items)) { return []; }
  $items = array_map('trim', $items);
  $items = array_filter($items, static fn(string $item) => $item !== '');
  return array_values(array_unique($items));
};

if ($pdo instanceof PDO) {
  try {
    // Perfil básico
    $stmt = $pdo->prepare(
      "SELECT c.nombres, c.apellidos, c.ciudad, c.telefono,
              cd.perfil AS resumen, cd.areas_interes, cd.foto_ruta, cd.pais,
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
      $fullName = trim(($row['nombres'] ?? '') . ' ' . ($row['apellidos'] ?? ''));
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
      if (!empty($row['areas_interes'])) {
        $perfil['skills'] = array_slice($splitList($row['areas_interes']), 0, 10);
      }
    }

    // Habilidades
    $skillsStmt = $pdo->prepare(
      "SELECT nombre, anios_experiencia AS anos_experiencia
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
          $yearsFloat = (float)$years;
          $yearsLabel = rtrim(rtrim(number_format($yearsFloat, 1, '.', ''), '0'), '.');
          $skills[] = $name . ' - ' . $yearsLabel . ' ' . ($yearsFloat === 1.0 ? 'año' : 'años');
        } else {
          $skills[] = $name;
        }
      }
      if ($skills) { $perfil['skills'] = array_slice($skills, 0, 10); }
    }

    // Experiencia
    $expCertMap = [];
    $expCertStmt = $pdo->prepare(
      "SELECT c.experiencia_id, d.nombre_archivo, d.ruta
         FROM {$schemaPrefix}candidato_experiencia_certificados c
         JOIN {$schemaPrefix}candidato_documentos d ON d.id = c.documento_id
        WHERE LOWER(d.email) = LOWER(?)"
    );
    $expCertStmt->execute([$targetEmail]);
    foreach ($expCertStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $expCertMap[(int)$row['experiencia_id']] = [
        'nombre' => (string)$row['nombre_archivo'],
        'ruta'   => (string)$row['ruta'],
      ];
    }

    $expStmt = $pdo->prepare(
      "SELECT id, cargo, empresa, periodo, anios_experiencia AS anos_experiencia, descripcion
         FROM {$schemaPrefix}candidato_experiencias
        WHERE LOWER(email) = LOWER(?)
        ORDER BY orden ASC, created_at ASC"
    );
    $expStmt->execute([$targetEmail]);
    foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $exp) {
      $expId = (int)$exp['id'];
      $perfil['experiencias'][] = [
        'cargo'            => $exp['cargo'] ?: 'Experiencia',
        'empresa'          => $exp['empresa'] ?: '',
        'periodo'          => $exp['periodo'] ?: '',
        'anos_experiencia' => $exp['anos_experiencia'],
        'desc'             => $exp['descripcion'] ?: '',
        'soporte'          => $expCertMap[$expId] ?? null,
      ];
    }

    // Educacion
    $eduCertMap = [];
    $eduCertStmt = $pdo->prepare(
      "SELECT c.educacion_id, d.nombre_archivo, d.ruta
         FROM {$schemaPrefix}candidato_educacion_certificados c
         JOIN {$schemaPrefix}candidato_documentos d ON d.id = c.documento_id
        WHERE LOWER(d.email) = LOWER(?)"
    );
    $eduCertStmt->execute([$targetEmail]);
    foreach ($eduCertStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $eduCertMap[(int)$row['educacion_id']] = [
        'nombre' => (string)$row['nombre_archivo'],
        'ruta'   => (string)$row['ruta'],
      ];
    }

    $eduStmt = $pdo->prepare(
      "SELECT id, titulo, institucion, periodo, descripcion
         FROM {$schemaPrefix}candidato_educacion
        WHERE LOWER(email) = LOWER(?)
        ORDER BY orden ASC, created_at ASC"
    );
    $eduStmt->execute([$targetEmail]);
    foreach ($eduStmt->fetchAll(PDO::FETCH_ASSOC) as $edu) {
      $eduId = (int)$edu['id'];
      $perfil['educacion'][] = [
        'titulo'      => $edu['titulo'] ?: 'Estudio',
        'institucion' => $edu['institucion'] ?: '',
        'periodo'     => $edu['periodo'] ?: '',
        'desc'        => $edu['descripcion'] ?: '',
        'soporte'     => $eduCertMap[$eduId] ?? null,
      ];
    }

    // CV mas reciente
    $cvStmt = $pdo->prepare(
      "SELECT nombre_archivo, ruta, mime
         FROM {$schemaPrefix}candidato_documentos
        WHERE LOWER(email) = LOWER(?) AND tipo = 'cv'
        ORDER BY uploaded_at DESC
        LIMIT 1"
    );
    $cvStmt->execute([$targetEmail]);
    if ($cvRow = $cvStmt->fetch(PDO::FETCH_ASSOC)) {
      $perfil['cv'] = [
        'nombre' => (string)$cvRow['nombre_archivo'],
        'ruta'   => (string)$cvRow['ruta'],
        'mime'   => (string)$cvRow['mime'],
      ];
    }
  } catch (Throwable $profileError) {
    error_log('[perfil_publico] '.$profileError->getMessage());
  }
}

// Normalizamos arrays
$perfil['skills'] = array_values($perfil['skills']);
$perfil['experiencias'] = array_values($perfil['experiencias']);
$perfil['educacion'] = array_values($perfil['educacion']);

?>

<section class="section container" style="margin-block: var(--sp-4);">
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
          <li><a class="link" href="<?=pp_e($perfil['contacto']['linkedin']); ?>" target="_blank" rel="noopener">LinkedIn</a></li>
        </ul>
      </div>
      <div class="row-cta" style="justify-content:flex-start; margin-top:auto;">
        <?php if (!$viewerIsEmpresa): ?>
          <a class="btn btn-secondary" href="index.php?view=editar_perfil">Editar</a>
        <?php endif; ?>
        <?php if (!empty($perfil['cv']['ruta'])): ?>
          <a class="btn btn-outline" href="<?=pp_e($perfil['cv']['ruta']); ?>" target="_blank" rel="noopener">Ver hoja de vida</a>
        <?php endif; ?>
        <button class="btn btn-primary" type="button">Contactar</button>
      </div>
    </aside>

    <section style="display:grid; gap:var(--sp-4);">
      <div class="card">
        <h2 class="h5">Sobre mi</h2>
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
                  <?php if (!empty($exp['empresa'])): ?> - <?=pp_e($exp['empresa']); ?><?php endif; ?>
                </h3>
                <?php $labels = array_filter([
                  $exp['periodo'] ?? '',
                  isset($exp['anos_experiencia']) && $exp['anos_experiencia'] !== '' ? ($exp['anos_experiencia'].' años') : null
                ]); ?>
                <?php if ($labels): ?><p class="muted m-0"><?=pp_e(implode(' - ', $labels)); ?></p><?php endif; ?>
                <?php if (!empty($exp['desc'])): ?><p class="m-0"><?=pp_e($exp['desc']); ?></p><?php endif; ?>
                <?php if (!empty($exp['soporte']['ruta'])): ?>
                  <p class="m-0"><a class="link" href="<?=pp_e($exp['soporte']['ruta']); ?>" target="_blank" rel="noopener">Ver soporte</a></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted m-0">Aun no registras experiencia.</p>
        <?php endif; ?>
      </div>

      <div class="card" style="display:grid; gap:var(--sp-3);">
        <h2 class="h5 m-0">Educacion</h2>
        <?php if ($perfil['educacion']): ?>
          <div style="display:grid; gap:var(--sp-3);">
            <?php foreach ($perfil['educacion'] as $edu): ?>
              <div class="card" style="padding:var(--sp-3);">
                <strong><?=pp_e($edu['titulo']); ?></strong>
                <?php $eduLabels = array_filter([$edu['institucion'] ?? '', $edu['periodo'] ?? '']); ?>
                <?php if ($eduLabels): ?><p class="m-0 muted"><?=pp_e(implode(' - ', $eduLabels)); ?></p><?php endif; ?>
                <?php if (!empty($edu['desc'])): ?><p class="m-0"><?=pp_e($edu['desc']); ?></p><?php endif; ?>
                <?php if (!empty($edu['soporte']['ruta'])): ?>
                  <p class="m-0"><a class="link" href="<?=pp_e($edu['soporte']['ruta']); ?>" target="_blank" rel="noopener">Ver soporte</a></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted m-0">Aun no registras estudios.</p>
        <?php endif; ?>
      </div>
    </section>
  </div>
</section>

<?php // Fin perfil_publico ?>
