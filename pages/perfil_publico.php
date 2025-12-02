<?php
declare(strict_types=1);

// Acceso solo via index.php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=perfil_publico');
  exit;
}

if (!function_exists('pp_e')) {
  function pp_e(?string $value): string
  {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
  }
}

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$sessionUser = $_SESSION['user'] ?? null;
$viewerIsEmpresa = is_array($sessionUser) && (($sessionUser['type'] ?? '') === 'empresa');
$targetEmail = null;
if ($viewerIsEmpresa) {
  // Permitir a empresas visualizar perfiles públicos por email
  $targetEmail = isset($_GET['email']) ? trim((string)$_GET['email']) : null;
  // Ajustar el contexto del navbar al de empresas (similar a candidatos)
  $view = 'candidatos_review';
} else {
  $targetEmail = $sessionUser['email'] ?? null;
}

$perfil = [
  'nombre'       => '',
  'titulo'       => '',
  'ubicacion'    => '',
  'bio'          => '',
  'skills'       => [],
  'experiencias' => [],
  'educacion'    => [],
  'contacto'     => [
    'email'    => $targetEmail ?? '',
    'telefono' => '',
    'linkedin' => '#',
  ],
  'foto'         => null,
];

$splitList = static function (?string $value): array {
  if ($value === null) {
    return [];
  }
  $items = preg_split('/[,;\r\n]+/', $value);
  if (!is_array($items)) {
    return [];
  }
  $items = array_map('trim', $items);
  $items = array_filter($items, static fn(string $item) => $item !== '');
  return array_values(array_unique($items));
};

require_once __DIR__.'/db.php';

// Cargar el perfil para candidato propio (persona) o para empresa por email
if (($pdo instanceof PDO) && $targetEmail) {
  try {
    $stmt = $pdo->prepare(
      'SELECT c.nombres, c.apellidos, c.ciudad, c.telefono,
              cd.perfil AS resumen, cd.areas_interes, cd.foto_ruta, cd.pais,
              cp.rol_deseado, cp.habilidades,
              a.nombre AS area_nombre,
              n.nombre AS nivel_nombre,
              m.nombre AS modalidad_nombre,
              d.nombre AS disponibilidad_nombre
       FROM candidatos c
       LEFT JOIN candidato_detalles cd ON cd.email = c.email
       LEFT JOIN candidato_perfil cp ON cp.email = c.email
       LEFT JOIN areas a ON a.id = cp.area_id
       LEFT JOIN niveles n ON n.id = cp.nivel_id
       LEFT JOIN modalidades m ON m.id = cp.modalidad_id
       LEFT JOIN disponibilidades d ON d.id = cp.disponibilidad_id
       WHERE c.email = ?
       LIMIT 1'
    );
    $stmt->execute([$targetEmail]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $fullName = trim(($row['nombres'] ?? '').' '.($row['apellidos'] ?? ''));
      if ($fullName !== '') {
        $perfil['nombre'] = $fullName;
      }

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
      if ($locParts) {
        $perfil['ubicacion'] = implode(', ', $locParts);
      }

      if (!empty($row['resumen'])) {
        $perfil['bio'] = (string)$row['resumen'];
      }

      if (!empty($row['foto_ruta'])) {
        $perfil['foto'] = (string)$row['foto_ruta'];
      }

      $perfil['contacto']['email'] = $targetEmail ?? $perfil['contacto']['email'];
      if (!empty($row['telefono'])) {
        $perfil['contacto']['telefono'] = (string)$row['telefono'];
      }

      $skillsStmt = $pdo->prepare(
        'SELECT nombre, COALESCE(anios_experiencia, anos_experiencia, a?os_experiencia) AS anos_experiencia
           FROM candidato_habilidades
          WHERE email = ?
          ORDER BY anos_experiencia DESC, nombre ASC'
      );
      $skillsStmt->execute([$targetEmail]);
      $skillRecords = $skillsStmt->fetchAll(PDO::FETCH_ASSOC);
      if ($skillRecords) {
        $skills = [];
        foreach ($skillRecords as $skill) {
          $name = trim((string)$skill['nombre']);
          if ($name === '') {
            continue;
          }
          $years = $skill['anos_experiencia'];
          if ($years !== null && $years !== '') {
            $yearsFloat = (float)$years;
            $yearsLabel = rtrim(rtrim(number_format($yearsFloat, 1, '.', ''), '0'), '.');
            $skills[] = $name.' - '.$yearsLabel.' '.($yearsFloat === 1.0 ? 'a?o' : 'a?os');
          } else {
            $skills[] = $name;
          }
        }
        if ($skills) {
          $perfil['skills'] = array_slice($skills, 0, 10);
        }
      } else {
        $skills = $splitList($row['habilidades'] ?? '');
        if (!$skills) {
          $skills = $splitList($row['areas_interes'] ?? '');
        }
        if ($skills) {
          $perfil['skills'] = array_slice($skills, 0, 10);
        }
      }
    }

    $expStmt = $pdo->prepare(
      'SELECT cargo, empresa, periodo, COALESCE(anios_experiencia, anos_experiencia, a?os_experiencia) AS anos_experiencia, descripcion
         FROM candidato_experiencias
        WHERE email = ?
        ORDER BY orden ASC, created_at ASC'
    );
    $expStmt->execute([$targetEmail]);
    $expRecords = $expStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($expRecords) {
      foreach ($expRecords as $exp) {
        $perfil['experiencias'][] = [
          'cargo'  => $exp['cargo'] ?: 'Experiencia',
          'empresa'=> $exp['empresa'] ?: '',
          'periodo'=> $exp['periodo'] ?: '',
          'a?os'  => $exp['anos_experiencia'],
          'desc'   => $exp['descripcion'] ?: '',
        ];
      }
    }

    $eduStmt = $pdo->prepare(
      'SELECT titulo, institucion, periodo, descripcion
         FROM candidato_educacion
        WHERE email = ?
        ORDER BY orden ASC, created_at ASC'
    );
    $eduStmt->execute([$targetEmail]);
    $eduRecords = $eduStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($eduRecords) {
      foreach ($eduRecords as $edu) {
        $perfil['educacion'][] = [
          'titulo'      => $edu['titulo'] ?: 'Estudio',
          'institucion' => $edu['institucion'] ?: '',
          'periodo'     => $edu['periodo'] ?: '',
          'desc'        => $edu['descripcion'] ?: '',
        ];
      }
    }
  } catch (Throwable $profileError) {
    error_log('[perfil_publico] '.$profileError->getMessage());
  }
}

// Solo aplicar snapshot/edición cuando el propio candidato (no empresa)
if (!$viewerIsEmpresa) {
  $lastPayload = $_SESSION['last_update_profile'] ?? null;
  if (!$lastPayload && isset($_SESSION['last_profile_snapshot'])) {
    $lastPayload = [
    'account' => ['email' => $_SESSION['last_profile_snapshot']['email'] ?? $perfil['contacto']['email']],
      'personal' => [
        'telefono' => $_SESSION['last_profile_snapshot']['telefono'] ?? '',
      ],
      'perfil' => [
        'resumen' => $_SESSION['last_profile_snapshot']['resumen'] ?? '',
        'areas_interes' => $_SESSION['last_profile_snapshot']['areas_interes'] ?? '',
        'rol' => $_SESSION['last_profile_snapshot']['titulo'] ?? '',
      ],
      'experiencias' => $_SESSION['last_profile_snapshot']['experiencias'] ?? [],
      'educacion' => $_SESSION['last_profile_snapshot']['educacion'] ?? [],
    ];
  }
  if (is_array($lastPayload)) {
    $payloadEmail = strtolower((string)($lastPayload['account']['email'] ?? $lastPayload['account']['email'] ?? $sessionUser['email'] ?? ''));
    $currentEmail = strtolower((string)($sessionUser['email'] ?? ''));
    if ($currentEmail !== '' && ($payloadEmail === $currentEmail || $payloadEmail === strtolower($perfil['contacto']['email']))) {
      $perfil['contacto']['email'] = (string)($lastPayload['account']['email'] ?? $perfil['contacto']['email']);
      if (!empty($lastPayload['personal']['telefono'])) {
        $perfil['contacto']['telefono'] = (string)$lastPayload['personal']['telefono'];
      }
      if (!empty($lastPayload['perfil']['resumen'])) {
        $perfil['bio'] = (string)$lastPayload['perfil']['resumen'];
      }
      if (!empty($lastPayload['perfil']['rol'])) {
        $perfil['titulo'] = (string)$lastPayload['perfil']['rol'];
      }
      if (!empty($lastPayload['perfil']['areas_interes'])) {
        $perfil['skills'] = $splitList((string)$lastPayload['perfil']['areas_interes']);
      }
        $experiencias = [];
        $expPayload = $lastPayload['experiencias'] ?? [];
        if (isset($expPayload['cargos'])) {
          $expCount = max(
            count($expPayload['cargos'] ?? []),
            count($expPayload['empresas'] ?? []),
            count($expPayload['periodos'] ?? []),
            count($expPayload['años'] ?? []),
            count($expPayload['descripciones'] ?? [])
          );
          for ($i = 0; $i < $expCount; $i++) {
            $cargo  = trim((string)($expPayload['cargos'][$i] ?? ''));
            $empresa= trim((string)($expPayload['empresas'][$i] ?? ''));
            $periodo= trim((string)($expPayload['periodos'][$i] ?? ''));
            $años  = trim((string)($expPayload['años'][$i] ?? ''));
            $desc   = trim((string)($expPayload['descripciones'][$i] ?? ''));
            if ($cargo === '' && $empresa === '' && $periodo === '' && $desc === '' && $años === '') {
              continue;
            }
            $experiencias[] = [
              'cargo'   => $cargo !== '' ? $cargo : 'Experiencia',
              'empresa' => $empresa,
              'periodo' => $periodo,
              'años'   => $años,
              'desc'    => $desc,
            ];
          }
        } elseif (is_array($expPayload) && isset($expPayload[0])) {
          foreach ($expPayload as $exp) {
            $cargo = trim((string)($exp['cargo'] ?? ''));
            $empresa = trim((string)($exp['empresa'] ?? ''));
            $periodo = trim((string)($exp['periodo'] ?? ''));
            $años = trim((string)($exp['años'] ?? ''));
            $desc = trim((string)($exp['desc'] ?? ''));
            if ($cargo === '' && $empresa === '' && $periodo === '' && $años === '' && $desc === '') {
              continue;
            }
            $experiencias[] = [
              'cargo'   => $cargo !== '' ? $cargo : 'Experiencia',
              'empresa' => $empresa,
              'periodo' => $periodo,
              'años'   => $años,
              'desc'    => $desc,
            ];
          }
        }
        if ($experiencias) {
          $perfil['experiencias'] = $experiencias;
        }

        $educacionPayload = $lastPayload['educacion'] ?? [];
        $educacion = [];
        if (isset($educacionPayload['titulos'])) {
          $eduCount = max(
            count($educacionPayload['titulos'] ?? []),
            count($educacionPayload['instituciones'] ?? []),
            count($educacionPayload['periodos'] ?? []),
            count($educacionPayload['descripciones'] ?? [])
          );
          for ($i = 0; $i < $eduCount; $i++) {
            $titulo = trim((string)($educacionPayload['titulos'][$i] ?? ''));
            $inst   = trim((string)($educacionPayload['instituciones'][$i] ?? ''));
            $period = trim((string)($educacionPayload['periodos'][$i] ?? ''));
            $desc   = trim((string)($educacionPayload['descripciones'][$i] ?? ''));
            if ($titulo === '' && $inst === '' && $period === '' && $desc === '') {
              continue;
            }
            $educacion[] = [
              'titulo'      => $titulo !== '' ? $titulo : 'Estudio',
              'institucion' => $inst,
              'periodo'     => $period,
              'desc'        => $desc,
            ];
          }
        } elseif (is_array($educacionPayload) && isset($educacionPayload[0])) {
          foreach ($educacionPayload as $edu) {
            $titulo = trim((string)($edu['titulo'] ?? ''));
            $inst   = trim((string)($edu['institucion'] ?? ''));
            $period = trim((string)($edu['periodo'] ?? ''));
            $desc   = trim((string)($edu['desc'] ?? ''));
            if ($titulo === '' && $inst === '' && $period === '' && $desc === '') {
              continue;
            }
            $educacion[] = [
              'titulo'      => $titulo !== '' ? $titulo : 'Estudio',
              'institucion' => $inst,
              'periodo'     => $period,
              'desc'        => $desc,
            ];
          }
        }
        if ($educacion) {
          $perfil['educacion'] = $educacion;
        }
      }
}

// Cierre del bloque de snapshot para candidatos (no empresa)
}

if (!$viewerIsEmpresa) { unset($_SESSION['last_update_profile']); }

$perfil['experiencias'] = array_values(array_filter(
  $perfil['experiencias'],
  static function (array $exp): bool {
    $values = array_map('trim', [
      $exp['cargo'] ?? '',
      $exp['empresa'] ?? '',
      $exp['periodo'] ?? '',
      isset($exp['años']) ? (string)$exp['años'] : '',
      $exp['desc'] ?? '',
    ]);
    return implode('', $values) !== '';
  }
));

$perfil['educacion'] = array_values(array_filter(
  $perfil['educacion'],
  static function (array $edu): bool {
    $values = array_map('trim', [
      $edu['titulo'] ?? '',
      $edu['institucion'] ?? '',
      $edu['periodo'] ?? '',
      $edu['desc'] ?? '',
    ]);
    return implode('', $values) !== '';
  }
));

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
      <?php
        $avatarStyle = '';
        if (!empty($perfil['foto'])) {
          $avatarStyle = "background-image:url('".pp_e($perfil['foto'])."'); background-size:cover; background-position:center;";
        }
      ?>
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
        <button class="btn btn-outline" type="button">Descargar PDF</button>
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
                  <?php if (!empty($exp['empresa'])): ?>
                    - <?=pp_e($exp['empresa']); ?>
                  <?php endif; ?>
                </h3>
                <?php
                  $labels = array_filter([
                    $exp['periodo'] ?? '',
                    isset($exp['años']) && $exp['años'] !== '' ? ($exp['años'].' años') : null,
                  ]);
                ?>
                <?php if ($labels): ?>
                  <p class="muted m-0"><?=pp_e(implode(' - ', $labels)); ?></p>
                <?php endif; ?>
                <?php if (!empty($exp['desc'])): ?>
                  <p class="m-0"><?=pp_e($exp['desc']); ?></p>
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
                <?php
                  $eduLabels = array_filter([
                    $edu['institucion'] ?? '',
                    $edu['periodo'] ?? '',
                  ]);
                ?>
                <?php if ($eduLabels): ?>
                  <p class="m-0 muted"><?=pp_e(implode(' - ', $eduLabels)); ?></p>
                <?php endif; ?>
                <?php if (!empty($edu['desc'])): ?>
                  <p class="m-0"><?=pp_e($edu['desc']); ?></p>
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
