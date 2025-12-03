<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=editar_perfil');
  exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$sessionUser = $_SESSION['user'] ?? null;
if (!$sessionUser || ($sessionUser['type'] ?? '') !== 'persona') {
  header('Location: index.php?view=login');
  exit;
}

require __DIR__.'/db.php';

if (!function_exists('ep_e')) {
  function ep_e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('ep_format_years')) {
  function ep_format_years(?string $value): string {
    if ($value === null || $value === '') { return ''; }
    $float = (float)$value;
    if ($float <= 0.0) { return ''; }
    $formatted = number_format($float, 1, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
  }
}

if (!function_exists('ep_bucket_experience')) {
  function ep_bucket_experience(?int $years): string {
    if ($years === null) { return ''; }
    if ($years <= 1) { return '0-1'; }
    if ($years <= 2) { return '1-2'; }
    if ($years <= 4) { return '2-4'; }
    return '4+';
  }
}

if (!function_exists('ep_selected')) {
  function ep_selected(string $current, string $value): string {
    return $current === $value ? ' selected' : '';
  }
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$docTypes = ['CC','CE','pasaporte','Otro'];
$experienceRanges = ['0-1','1-2','2-4','4+'];
$studySituations = ['En curso','Graduado','Suspendido'];

$form = [
  'per_email'        => (string)($sessionUser['email'] ?? ''),
  'nombre'           => (string)($sessionUser['nombre'] ?? ''),
  'apellido'         => (string)($sessionUser['apellidos'] ?? ''),
  'telefono'         => '',
  'documento_tipo'   => '',
  'documento_numero' => '',
  'pais'             => '',
  'ciudad'           => (string)($sessionUser['ciudad'] ?? ''),
  'direccion'        => '',
  'perfil'           => '',
  'areas_interes'    => '',
  'rol'              => '',
  'area'             => '',
  'nivel'            => '',
  'modalidad'        => '',
  'contrato'         => '',
  'disponibilidad'   => '',
  'estudios'         => '',
  'institucion'      => '',
  'años'            => '',
  'area_estudio'     => '',
  'situacion'        => '',
  'idiomas'          => '',
  'competencias'     => '',
  'logros'           => '',
];

$skills = [];
$experiencias = [];
$educacion = [];

$fotoActual = null;
$cvActual = null;

$candidateEmail = (string)($sessionUser['email'] ?? '');

if ($candidateEmail !== '' && ($pdo instanceof PDO)) {
  try {
    $stmt = $pdo->prepare('SELECT nombres, apellidos, telefono, ciudad FROM candidatos WHERE email = ? LIMIT 1');
    $stmt->execute([$candidateEmail]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $form['nombre'] = trim((string)$row['nombres']);
      $form['apellido'] = trim((string)$row['apellidos']);
      $form['telefono'] = trim((string)$row['telefono']);
      $form['ciudad'] = trim((string)$row['ciudad']);
    }
  } catch (Throwable $e) {
    error_log('[editar_perfil] candidatos: '.$e->getMessage());
  }

  try {
    $detailsStmt = $pdo->prepare('SELECT documento_tipo, documento_numero, pais, direccion, perfil, areas_interes, foto_ruta, idiomas, competencias, logros FROM candidato_detalles WHERE email = ? LIMIT 1');
    $detailsStmt->execute([$candidateEmail]);
    if ($det = $detailsStmt->fetch(PDO::FETCH_ASSOC)) {
      $form['documento_tipo'] = trim((string)($det['documento_tipo'] ?? ''));
      $form['documento_numero'] = trim((string)($det['documento_numero'] ?? ''));
      $form['pais'] = trim((string)($det['pais'] ?? ''));
      $form['direccion'] = trim((string)($det['direccion'] ?? ''));
      $form['perfil'] = trim((string)($det['perfil'] ?? ''));
      $form['areas_interes'] = trim((string)($det['areas_interes'] ?? ''));
      $form['idiomas'] = trim((string)($det['idiomas'] ?? ''));
      $form['competencias'] = trim((string)($det['competencias'] ?? ''));
      $form['logros'] = trim((string)($det['logros'] ?? ''));
      if (!empty($det['foto_ruta'])) {
        $fotoActual = (string)$det['foto_ruta'];
      }
    }
  } catch (Throwable $e) {
    error_log('[editar_perfil] candidato_detalles: '.$e->getMessage());
  }

  try {
    $perfilStmt = $pdo->prepare(
      'SELECT
          cp.rol_deseado,
          cp.habilidades,
          cp.resumen,
          cp.institucion,
          COALESCE(cp.anios_experiencia, cp.anos_experiencia, cp.a?os_experiencia) AS anos_experiencia,
          cp.visible_empresas,
          a.nombre  AS area_nombre,
          n.nombre  AS nivel_nombre,
          m.nombre  AS modalidad_nombre,
          co.nombre AS contrato_nombre,
          ne.nombre AS estudios_nombre,
          d.nombre  AS disponibilidad_nombre
       FROM candidato_perfil cp
       LEFT JOIN areas a ON a.id = cp.area_id
       LEFT JOIN niveles n ON n.id = cp.nivel_id
       LEFT JOIN modalidades m ON m.id = cp.modalidad_id
       LEFT JOIN contratos co ON co.id = cp.contrato_pref_id
       LEFT JOIN niveles_estudio ne ON ne.id = cp.estudios_id
       LEFT JOIN disponibilidades d ON d.id = cp.disponibilidad_id
       WHERE cp.email = ?
       LIMIT 1'
    );
    $perfilStmt->execute([$candidateEmail]);
    if ($prof = $perfilStmt->fetch(PDO::FETCH_ASSOC)) {
      $form['rol'] = trim((string)($prof['rol_deseado'] ?? ''));
      $form['area'] = trim((string)($prof['area_nombre'] ?? ''));
      $form['nivel'] = trim((string)($prof['nivel_nombre'] ?? ''));
      $form['modalidad'] = trim((string)($prof['modalidad_nombre'] ?? ''));
      $form['contrato'] = trim((string)($prof['contrato_nombre'] ?? ''));
      $form['disponibilidad'] = trim((string)($prof['disponibilidad_nombre'] ?? ''));
      $form['estudios'] = trim((string)($prof['estudios_nombre'] ?? ''));
      $form['institucion'] = trim((string)($prof['institucion'] ?? ''));
      $form['a?os'] = ep_bucket_experience(isset($prof['anos_experiencia']) ? (int)$prof['anos_experiencia'] : null);
      if (empty($form['perfil']) && !empty($prof['resumen'])) {
        $form['perfil'] = trim((string)$prof['resumen']);
      }
      if (empty($form['areas_interes']) && !empty($prof['habilidades'])) {
        $form['areas_interes'] = trim((string)$prof['habilidades']);
      }
    }
  } catch (Throwable $e) {
    error_log('[editar_perfil] candidato_perfil: '.$e->getMessage());
  }

  try {
    $skillStmt = $pdo->prepare('SELECT nombre, COALESCE(anios_experiencia, anos_experiencia, a?os_experiencia) AS anos_experiencia FROM candidato_habilidades WHERE email = ? ORDER BY anos_experiencia DESC, nombre ASC');
    $skillStmt->execute([$candidateEmail]);
    foreach ($skillStmt->fetchAll(PDO::FETCH_ASSOC) as $skill) {
      $skills[] = [
        'nombre' => trim((string)$skill['nombre']),
        'a?os'  => ep_format_years($skill['anos_experiencia'] ?? null),
      ];
    }
  } catch (Throwable $e) {
    error_log('[editar_perfil] candidato_habilidades: '.$e->getMessage());
  }

  try {
    $expCertMap = [];
    $certStmt = $pdo->prepare('SELECT c.experiencia_id, d.nombre_archivo, d.ruta FROM candidato_experiencia_certificados c JOIN candidato_documentos d ON d.id = c.documento_id WHERE d.email = ?');
    $certStmt->execute([$candidateEmail]);
    foreach ($certStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $expCertMap[(int)$row['experiencia_id']] = [
        'nombre' => (string)$row['nombre_archivo'],
        'ruta'   => (string)$row['ruta'],
      ];
    }

    $expStmt = $pdo->prepare('SELECT id, cargo, empresa, periodo, COALESCE(anios_experiencia, anos_experiencia, a?os_experiencia) AS anos_experiencia, descripcion FROM candidato_experiencias WHERE email = ? ORDER BY orden ASC, created_at ASC');
    $expStmt->execute([$candidateEmail]);
    foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $exp) {
      $expId = (int)$exp['id'];
      $experiencias[] = [
        'cargo' => trim((string)$exp['cargo']),
        'empresa' => trim((string)$exp['empresa']),
        'periodo' => trim((string)$exp['periodo']),
        'a?os' => ep_format_years($exp['anos_experiencia'] ?? null),
        'descripcion' => trim((string)$exp['descripcion']),
        'soporte' => $expCertMap[$expId] ?? null,
      ];
    }
  } catch (Throwable $e) {
    error_log('[editar_perfil] candidato_experiencias: '.$e->getMessage());
  }

  try {
    $eduCertMap = [];
    $certEduStmt = $pdo->prepare('SELECT c.educacion_id, d.nombre_archivo, d.ruta FROM candidato_educacion_certificados c JOIN candidato_documentos d ON d.id = c.documento_id WHERE d.email = ?');
    $certEduStmt->execute([$candidateEmail]);
    foreach ($certEduStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $eduCertMap[(int)$row['educacion_id']] = [
        'nombre' => (string)$row['nombre_archivo'],
        'ruta'   => (string)$row['ruta'],
      ];
    }

    $eduStmt = $pdo->prepare('SELECT id, titulo, institucion, periodo, descripcion FROM candidato_educacion WHERE email = ? ORDER BY orden ASC, created_at ASC');
    $eduStmt->execute([$candidateEmail]);
    foreach ($eduStmt->fetchAll(PDO::FETCH_ASSOC) as $edu) {
      $eduId = (int)$edu['id'];
      $educacion[] = [
        'titulo' => trim((string)$edu['titulo']),
        'institucion' => trim((string)$edu['institucion']),
        'periodo' => trim((string)$edu['periodo']),
        'descripcion' => trim((string)$edu['descripcion']),
        'soporte' => $eduCertMap[$eduId] ?? null,
      ];
    }
  } catch (Throwable $e) {
    error_log('[editar_perfil] candidato_educacion: '.$e->getMessage());
  }

  try {
    $docStmt = $pdo->prepare('SELECT nombre_archivo, ruta FROM candidato_documentos WHERE email = ? AND tipo = "cv" ORDER BY uploaded_at DESC LIMIT 1');
    $docStmt->execute([$candidateEmail]);
    if ($cv = $docStmt->fetch(PDO::FETCH_ASSOC)) {
      $cvActual = $cv;
    }
  } catch (Throwable $e) {
    error_log('[editar_perfil] candidato_documentos: '.$e->getMessage());
  }
}

$flash = $_SESSION['flash_edit_profile'] ?? null;
unset($_SESSION['flash_edit_profile']);

if (!$skills) { $skills = [['nombre' => '', 'años' => '']]; }
if (!$experiencias) { $experiencias = [['cargo'=>'','empresa'=>'','periodo'=>'','años'=>'','descripcion'=>'','soporte'=>null]]; }
if (!$educacion) { $educacion = [['titulo'=>'','institucion'=>'','periodo'=>'','descripcion'=>'','soporte'=>null]]; }
?>

<section class="section">
  <div class="container">
    <div class="dash-head">
      <div>
        <h1>Editar perfil</h1>
        <p class="muted">Actualiza tus datos personales, tu experiencia y la información que verán las empresas.</p>
      </div>
<a class="btn btn-secondary" href="index.php?view=perfil_usuario">Ver perfil</a>
    </div>

    <?php if ($flash): ?>
      <div class="card" style="border-color:#d6f5d6;background:#f3fff3;">
        <strong><?=ep_e($flash); ?></strong>
      </div>
    <?php endif; ?>

    <div class="card" style="display:flex; gap:var(--sp-4); flex-wrap:wrap; align-items:center; margin-bottom:var(--sp-4);">
      <div style="width:96px; height:96px; border-radius:50%; background:#e9f0fb; overflow:hidden; display:flex; align-items:center; justify-content:center;">
        <?php if ($fotoActual): ?>
          <img src="<?=ep_e($fotoActual); ?>" alt="Foto de perfil" style="width:100%; height:100%; object-fit:cover;" />
        <?php else: ?>
          <span class="muted">Sin foto</span>
        <?php endif; ?>
      </div>
      <div style="flex:1; min-width:220px;">
        <h2 class="h5 m-0"><?=ep_e(trim($form['nombre'].' '.$form['apellido'])); ?></h2>
        <p class="muted m-0">
          <?=ep_e($form['rol'] ?: 'Agrega tu rol deseado'); ?>
          <?php if ($form['ciudad']): ?> · <?=ep_e($form['ciudad']); ?><?php endif; ?>
        </p>
      </div>
    </div>

    <form class="card form" method="post" action="index.php?action=update_profile" enctype="multipart/form-data" data-validate="instant" novalidate>
      <input type="hidden" name="csrf" value="<?=ep_e($csrf); ?>" />

      <section>
        <h2>Cuenta</h2>
        <div class="g-3">
          <div class="field">
            <label for="per_email">Correo *</label>
            <input id="per_email" name="per_email" type="email" required value="<?=ep_e($form['per_email']); ?>" />
          </div>
          <div class="field">
            <label for="per_password">Nueva contraseña</label>
            <input id="per_password" name="per_password" type="password" minlength="8" placeholder="Déjalo vacío para mantenerla" autocomplete="new-password" />
          </div>
          <div class="field">
            <label for="per_password_confirm">Confirmar contraseña</label>
            <input id="per_password_confirm" name="per_password_confirm" type="password" minlength="8" placeholder="Repite la nueva contraseña" autocomplete="new-password" />
          </div>
        </div>
      </section>

      <section>
        <h2>Datos personales</h2>
        <div class="g-3">
          <div class="field">
            <label for="nombre">Nombre *</label>
            <input id="nombre" name="nombre" type="text" required value="<?=ep_e($form['nombre']); ?>" />
          </div>
          <div class="field">
            <label for="apellido">Apellido *</label>
            <input id="apellido" name="apellido" type="text" required value="<?=ep_e($form['apellido']); ?>" />
          </div>
          <div class="field">
            <label for="telefono">Teléfono *</label>
            <input id="telefono" name="telefono" type="tel" required value="<?=ep_e($form['telefono']); ?>" placeholder="+57 320 000 0000" />
          </div>
          <div class="field">
            <label for="documento_tipo">Tipo de documento *</label>
            <select id="documento_tipo" name="documento_tipo" required>
              <option value="">Selecciona</option>
              <?php foreach ($docTypes as $docType): ?>
                <option value="<?=$docType; ?>"<?=ep_selected($form['documento_tipo'], $docType); ?>><?=$docType; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="documento_numero">Número de documento *</label>
            <input id="documento_numero" name="documento_numero" type="text" required value="<?=ep_e($form['documento_numero']); ?>" />
          </div>
        </div>
      </section>

      <section>
        <h2>Ubicación</h2>
        <div class="g-3">
          <div class="field">
            <label for="pais">País *</label>
            <input id="pais" name="pais" type="text" required value="<?=ep_e($form['pais']); ?>" placeholder="Colombia" />
          </div>
          <div class="field">
            <label for="ciudad">Ciudad *</label>
            <input id="ciudad" name="ciudad" type="text" required value="<?=ep_e($form['ciudad']); ?>" placeholder="Medellín" />
          </div>
          <div class="field">
            <label for="direccion">Dirección (opcional)</label>
            <input id="direccion" name="direccion" type="text" value="<?=ep_e($form['direccion']); ?>" placeholder="Calle 00 # 00-00" />
          </div>
        </div>
      </section>

      <section>
        <h2>Perfil profesional</h2>
        <div class="g-2">
          <div class="field">
            <label for="perfil">Resumen de perfil *</label>
            <textarea id="perfil" name="perfil" rows="4" required placeholder="Tu experiencia, habilidades y objetivos"><?=ep_e($form['perfil']); ?></textarea>
          </div>
          <div class="field">
            <label for="area_input">Áreas de interés</label>
            <div class="chip-input" id="areas-wrapper">
              <div id="areas_chips" class="chips"></div>
              <div style="display:flex; gap:.5rem; margin-top:.35rem;">
                <input id="area_input" type="text" placeholder="Escribe un área y presiona Enter o Añadir" />
                <button type="button" class="btn btn-outline" id="area_add_btn">Añadir</button>
              </div>
              <small class="muted">Agrega una por una; usaremos estas áreas para recomendarte vacantes.</small>
              <input id="areas_interes" name="areas_interes" type="hidden" value="<?=ep_e($form['areas_interes']); ?>" />
            </div>
          </div>
        </div>
        <div class="g-3">
          <div class="field">
            <label for="rol">Rol deseado</label>
            <input id="rol" name="rol" type="text" value="<?=ep_e($form['rol']); ?>" placeholder="Ej: Desarrollador Backend" />
          </div>
          <div class="field">
            <label for="area">Área</label>
            <input id="area" name="area" type="text" value="<?=ep_e($form['area']); ?>" placeholder="Ej: Desarrollo de software" />
          </div>
          <div class="field">
            <label for="nivel">Nivel de experiencia</label>
            <input id="nivel" name="nivel" type="text" value="<?=ep_e($form['nivel']); ?>" placeholder="Ej: Junior, Intermedio, Senior" />
          </div>
          <div class="field">
            <label for="modalidad">Modalidad preferida</label>
            <input id="modalidad" name="modalidad" type="text" value="<?=ep_e($form['modalidad']); ?>" placeholder="Remoto, híbrido, presencial" />
          </div>
          <div class="field">
            <label for="contrato">Tipo de contrato</label>
            <input id="contrato" name="contrato" type="text" value="<?=ep_e($form['contrato']); ?>" placeholder="Tiempo completo, freelance..." />
          </div>
          <div class="field">
            <label for="disponibilidad">Disponibilidad</label>
            <input id="disponibilidad" name="disponibilidad" type="text" value="<?=ep_e($form['disponibilidad']); ?>" placeholder="Inmediata, Próximo mes..." />
          </div>
        </div>
      </section>

      <section>
        <h2>Habilidades y experiencia</h2>
        <p class="muted">Agrega habilidades clave y años de experiencia. Puedes sumar o quitar según necesites.</p>
        <div id="skill-list" data-collection="skill">
          <?php foreach ($skills as $i => $skill): ?>
            <div class="card skill-item" style="padding:var(--sp-2);margin-block:var(--sp-2);" data-index="<?=$i?>">
              <div class="g-3" style="align-items:end;">
                <div class="field">
                  <label for="skill_name_<?=$i?>" data-num-label="Habilidad">Habilidad <?=($i+1)?></label>
                  <input id="skill_name_<?=$i?>" name="skill_name[]" type="text" placeholder="Ej: Comunicación, Liderazgo, SQL" value="<?=ep_e($skill['nombre']); ?>"/>
                </div>
                <div class="field">
                  <label for="skill_years_<?=$i?>">Nivel / experiencia</label>
                  <input id="skill_years_<?=$i?>" name="skill_years[]" type="text" placeholder="Ej: 4 años, Intermedio, Senior" value="<?=ep_e($skill['años']); ?>"/>
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
        <p class="muted">Agrega tus experiencias (laborales, voluntariado, prácticas) y un soporte opcional para cada una.</p>
        <div id="exp-list" data-collection="exp">
          <?php foreach ($experiencias as $i => $exp): ?>
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
                  <input id="exp_role_<?=$i?>" name="exp_role[]" type="text" placeholder="Ej: Auxiliar de Bodega" value="<?=ep_e($exp['cargo']); ?>"/>
                </div>
                <div class="field">
                  <label for="exp_company_<?=$i?>">Empresa / Entidad</label>
                  <input id="exp_company_<?=$i?>" name="exp_company[]" type="text" placeholder="Ej: Logisur" value="<?=ep_e($exp['empresa']); ?>"/>
                </div>
              </div>
              <div class="g-3">
                <div class="field">
                  <label for="exp_period_<?=$i?>">Periodo</label>
                  <input id="exp_period_<?=$i?>" name="exp_period[]" type="text" placeholder="Ej: 2023-2025" value="<?=ep_e($exp['periodo']); ?>"/>
                </div>
                <div class="field">
                  <label for="exp_years_<?=$i?>">Años de experiencia</label>
                  <input id="exp_years_<?=$i?>" name="exp_years[]" type="number" step="0.5" min="0" max="60" placeholder="Ej: 2" value="<?=ep_e($exp['años']); ?>"/>
                </div>
              </div>
              <div class="field">
                <label for="exp_desc_<?=$i?>">Descripción</label>
                <textarea id="exp_desc_<?=$i?>" name="exp_desc[]" rows="3" placeholder="Principales responsabilidades, logros, herramientas"><?=ep_e($exp['descripcion']); ?></textarea>
              </div>
              <div class="field">
                <label for="exp_proof_<?=$i?>">Soporte (PDF/Imagen)</label>
                <?php if (!empty($exp['soporte']['ruta'])): ?>
                  <p class="muted m-0">Actual: <a class="link" href="<?=ep_e($exp['soporte']['ruta']); ?>" target="_blank" rel="noopener"><?=ep_e($exp['soporte']['nombre'] ?? 'Soporte'); ?></a></p>
                <?php endif; ?>
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
          <?php foreach ($educacion as $i => $edu): ?>
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
                  <input id="edu_title_<?=$i?>" name="edu_title[]" type="text" placeholder="Ej: Tecnólogo en Logística" value="<?=ep_e($edu['titulo']); ?>"/>
                </div>
                <div class="field">
                  <label for="edu_institution_<?=$i?>">Institución</label>
                  <input id="edu_institution_<?=$i?>" name="edu_institution[]" type="text" placeholder="Ej: SENA" value="<?=ep_e($edu['institucion']); ?>"/>
                </div>
              </div>
              <div class="g-2">
                <div class="field">
                  <label for="edu_period_<?=$i?>">Periodo</label>
                  <input id="edu_period_<?=$i?>" name="edu_period[]" type="text" placeholder="Ej: 2019-2021" value="<?=ep_e($edu['periodo']); ?>"/>
                </div>
              </div>
              <div class="field">
                <label for="edu_desc_<?=$i?>">Descripción (opcional)</label>
                <textarea id="edu_desc_<?=$i?>" name="edu_desc[]" rows="2" placeholder="Logros, énfasis o actividades destacadas."><?=ep_e($edu['descripcion']); ?></textarea>
              </div>
              <div class="field">
                <label for="edu_proof_<?=$i?>">Soporte (certificado/imagen)</label>
                <?php if (!empty($edu['soporte']['ruta'])): ?>
                  <p class="muted m-0">Actual: <a class="link" href="<?=ep_e($edu['soporte']['ruta']); ?>" target="_blank" rel="noopener"><?=ep_e($edu['soporte']['nombre'] ?? 'Soporte'); ?></a></p>
                <?php endif; ?>
                <input id="edu_proof_<?=$i?>" name="edu_proof[]" type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" />
                <small class="muted">Opcional. Adjunta diploma, certificado o constancia.</small>
              </div>
            </fieldset>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-add" data-add-row="#edu-template" data-target="#edu-list">Agregar estudio</button>
      </section>

      <template id="skill-template">
        <div class="card skill-item" style="padding:var(--sp-2);margin-block:var(--sp-2);" data-index="__INDEX__">
          <div class="g-3" style="align-items:end;">
            <div class="field">
              <label for="skill_name___INDEX__" data-num-label="Habilidad">Habilidad __NUM__</label>
              <input id="skill_name___INDEX__" name="skill_name[]" type="text" placeholder="Ej: Comunicación, Liderazgo, SQL"/>
            </div>
            <div class="field">
                <label for="skill_years___INDEX__">Nivel / experiencia</label>
                <input id="skill_years___INDEX__" name="skill_years[]" type="text" placeholder="Ej: 4 años, Intermedio, Senior"/>
            </div>
            <div class="field" style="display:flex;align-items:center;justify-content:flex-end;">
              <button type="button" class="btn btn-ghost danger" data-remove-row=".skill-item">Eliminar</button>
            </div>
          </div>
        </div>
      </template>

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
              <label for="exp_years___INDEX__">Años de experiencia</label>
              <input id="exp_years___INDEX__" name="exp_years[]" type="number" step="0.5" min="0" max="60" placeholder="Ej: 2"/>
            </div>
          </div>
          <div class="field">
            <label for="exp_desc___INDEX__">Descripción</label>
            <textarea id="exp_desc___INDEX__" name="exp_desc[]" rows="3" placeholder="Principales responsabilidades, logros, herramientas"></textarea>
          </div>
          <div class="field">
            <label for="exp_proof___INDEX__">Soporte (PDF/Imagen)</label>
            <input id="exp_proof___INDEX__" name="exp_proof[]" type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" />
            <small class="muted">Opcional. Se usará para validar la experiencia.</small>
          </div>
        </fieldset>
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

      <section>
        <h2>Otros datos</h2>
        <div class="g-3">
          <div class="field">
            <label for="estudios">Nivel de estudios</label>
            <input id="estudios" name="estudios" type="text" value="<?=ep_e($form['estudios']); ?>" placeholder="Ej: Tecnólogo, Profesional..." />
          </div>
          <div class="field">
            <label for="institucion">Institución</label>
            <input id="institucion" name="institucion" type="text" value="<?=ep_e($form['institucion']); ?>" placeholder="Ej: SENA" />
          </div>
          <div class="field">
            <label for="años">Años de experiencia total</label>
            <select id="años" name="años">
              <option value="">Selecciona</option>
              <?php foreach ($experienceRanges as $range): ?>
                <option value="<?=$range; ?>"<?=ep_selected($form['años'], $range); ?>><?=$range; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="g-3">
          <div class="field">
            <label for="area_estudio">Área de estudio</label>
            <input id="area_estudio" name="area_estudio" type="text" value="<?=ep_e($form['area_estudio']); ?>" placeholder="Ej.: Administración, Sistemas" />
          </div>
          <div class="field">
            <label for="situacion">Situación académica</label>
            <select id="situacion" name="situacion">
              <option value="">Selecciona</option>
              <?php foreach ($studySituations as $s): ?>
                <option value="<?=$s; ?>"<?=ep_selected($form['situacion'], $s); ?>><?=$s; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="idiomas">Idiomas</label>
            <input id="idiomas" name="idiomas" type="text" value="<?=ep_e($form['idiomas']); ?>" placeholder="Ej.: Español (Nativo), Inglés (B2)" />
          </div>
        </div>
        <div class="field">
          <label for="competencias">Competencias blandas</label>
          <textarea id="competencias" name="competencias" rows="3" placeholder="Comunicación, trabajo en equipo, liderazgo"><?=ep_e($form['competencias']); ?></textarea>
        </div>
        <div class="field">
          <label for="logros">Reconocimientos / logros</label>
          <textarea id="logros" name="logros" rows="3" placeholder="Premios, proyectos destacados, metas superadas"><?=ep_e($form['logros']); ?></textarea>
        </div>
      </section>

    <section>
      <h2>Hoja de vida</h2>
      <div class="g-2">
        <div class="dropzone">
          <label for="cv">Actualizar CV (PDF/DOC)</label>
            <?php if ($cvActual): ?>
              <p class="muted m-0">Actual: <a class="link" href="<?=ep_e($cvActual['ruta']); ?>" target="_blank" rel="noopener"><?=ep_e($cvActual['nombre_archivo']); ?></a></p>
            <?php endif; ?>
            <input id="cv" name="cv" type="file" accept=".pdf,.doc,.docx" />
            <small>Máximo 5MB.</small>
          </div>
          <div class="dropzone">
            <label for="foto">Foto (opcional)</label>
            <input id="foto" name="foto" type="file" accept=".png,.jpg,.jpeg" />
            <small>PNG/JPG · Fondo neutro</small>
          </div>
        </div>
      </section>

      <div class="actions">
<a class="btn btn-secondary" href="index.php?view=perfil_usuario">Cancelar</a>
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
      </div>
    </form>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Áreas de interés con chips
  const hidden = document.getElementById('areas_interes');
  const input = document.getElementById('area_input');
  const addBtn = document.getElementById('area_add_btn');
  const chipsBox = document.getElementById('areas_chips');
  const parseList = (text) => {
    return Array.from(new Set(
      (text || '')
        .split(/[,;\n]+/)
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
