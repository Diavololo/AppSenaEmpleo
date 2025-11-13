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
  'anios'            => '',
  'area_estudio'     => '',
  'situacion'        => '',
  'idiomas'          => '',
  'competencias'     => '',
  'logros'           => '',
];

$skills = array_fill(0, 3, ['nombre' => '', 'anios' => '']);
$experiencias = array_fill(0, 2, [
  'cargo' => '',
  'empresa' => '',
  'periodo' => '',
  'anios' => '',
  'descripcion' => '',
]);
$educacion = array_fill(0, 2, [
  'titulo' => '',
  'institucion' => '',
  'periodo' => '',
  'descripcion' => '',
]);

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
          cp.anios_experiencia,
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
      $form['anios'] = ep_bucket_experience(isset($prof['anios_experiencia']) ? (int)$prof['anios_experiencia'] : null);
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
    $skillStmt = $pdo->prepare('SELECT nombre, anios_experiencia FROM candidato_habilidades WHERE email = ? ORDER BY anios_experiencia DESC, nombre ASC LIMIT 3');
    $skillStmt->execute([$candidateEmail]);
    $idx = 0;
    while ($skill = $skillStmt->fetch(PDO::FETCH_ASSOC)) {
      if (!isset($skills[$idx])) { break; }
      $skills[$idx]['nombre'] = trim((string)$skill['nombre']);
      $skills[$idx]['anios'] = ep_format_years($skill['anios_experiencia'] ?? null);
      $idx++;
    }
  } catch (Throwable $e) {
    error_log('[editar_perfil] candidato_habilidades: '.$e->getMessage());
  }

  try {
    $expStmt = $pdo->prepare('SELECT cargo, empresa, periodo, anios_experiencia, descripcion FROM candidato_experiencias WHERE email = ? ORDER BY orden ASC, created_at ASC LIMIT 2');
    $expStmt->execute([$candidateEmail]);
    $idx = 0;
    while ($exp = $expStmt->fetch(PDO::FETCH_ASSOC)) {
      if (!isset($experiencias[$idx])) { break; }
      $experiencias[$idx]['cargo'] = trim((string)$exp['cargo']);
      $experiencias[$idx]['empresa'] = trim((string)$exp['empresa']);
      $experiencias[$idx]['periodo'] = trim((string)$exp['periodo']);
      $experiencias[$idx]['anios'] = ep_format_years($exp['anios_experiencia'] ?? null);
      $experiencias[$idx]['descripcion'] = trim((string)$exp['descripcion']);
      $idx++;
    }
  } catch (Throwable $e) {
    error_log('[editar_perfil] candidato_experiencias: '.$e->getMessage());
  }

  try {
    $eduStmt = $pdo->prepare('SELECT titulo, institucion, periodo, descripcion FROM candidato_educacion WHERE email = ? ORDER BY orden ASC, created_at ASC LIMIT 2');
    $eduStmt->execute([$candidateEmail]);
    $idx = 0;
    while ($edu = $eduStmt->fetch(PDO::FETCH_ASSOC)) {
      if (!isset($educacion[$idx])) { break; }
      $educacion[$idx]['titulo'] = trim((string)$edu['titulo']);
      $educacion[$idx]['institucion'] = trim((string)$edu['institucion']);
      $educacion[$idx]['periodo'] = trim((string)$edu['periodo']);
      $educacion[$idx]['descripcion'] = trim((string)$edu['descripcion']);
      $idx++;
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

    <form class="card form" method="post" action="index.php?action=update_profile" enctype="multipart/form-data" novalidate>
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
            <label for="areas_interes">Áreas de interés</label>
            <input id="areas_interes" name="areas_interes" type="text" value="<?=ep_e($form['areas_interes']); ?>" placeholder="Ej: Desarrollo, Administración, Atención al cliente" />
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
        <p class="muted">Actualiza hasta tres habilidades clave con sus años de experiencia.</p>
        <?php for ($i = 0; $i < 3; $i++): ?>
          <div class="g-3">
            <div class="field">
              <label for="skill_name_<?=$i; ?>">Habilidad <?=($i + 1); ?></label>
              <input id="skill_name_<?=$i; ?>" name="skill_name[]" type="text" value="<?=ep_e($skills[$i]['nombre']); ?>" placeholder="Ej: Laravel" />
            </div>
            <div class="field">
              <label for="skill_years_<?=$i; ?>">Años de experiencia</label>
              <input id="skill_years_<?=$i; ?>" name="skill_years[]" type="number" step="0.5" min="0" value="<?=ep_e($skills[$i]['anios']); ?>" placeholder="Ej: 2" />
            </div>
          </div>
        <?php endfor; ?>
      </section>

      <section>
        <h2>Experiencia profesional</h2>
        <p class="muted">Puedes registrar dos experiencias destacadas. Deja en blanco las que no apliquen.</p>
        <?php for ($i = 0; $i < 2; $i++): ?>
          <fieldset class="card" style="padding:var(--sp-3); margin-block:var(--sp-2);">
            <legend>Experiencia <?=($i + 1); ?></legend>
            <div class="g-2">
              <div class="field">
                <label for="exp_role_<?=$i; ?>">Cargo</label>
                <input id="exp_role_<?=$i; ?>" name="exp_role[]" type="text" value="<?=ep_e($experiencias[$i]['cargo']); ?>" placeholder="Ej: Auxiliar de Bodega" />
              </div>
              <div class="field">
                <label for="exp_company_<?=$i; ?>">Empresa / Entidad</label>
                <input id="exp_company_<?=$i; ?>" name="exp_company[]" type="text" value="<?=ep_e($experiencias[$i]['empresa']); ?>" placeholder="Ej: Logisur" />
              </div>
            </div>
            <div class="g-3">
              <div class="field">
                <label for="exp_period_<?=$i; ?>">Periodo</label>
                <input id="exp_period_<?=$i; ?>" name="exp_period[]" type="text" value="<?=ep_e($experiencias[$i]['periodo']); ?>" placeholder="Ej: 2023-2025" />
              </div>
              <div class="field">
                <label for="exp_years_<?=$i; ?>">Años de experiencia</label>
                <input id="exp_years_<?=$i; ?>" name="exp_years[]" type="number" step="0.5" min="0" value="<?=ep_e($experiencias[$i]['anios']); ?>" placeholder="Ej: 2" />
              </div>
            </div>
            <div class="field">
              <label for="exp_desc_<?=$i; ?>">Descripción</label>
              <textarea id="exp_desc_<?=$i; ?>" name="exp_desc[]" rows="3" placeholder="Principales responsabilidades, logros, herramientas"><?=ep_e($experiencias[$i]['descripcion']); ?></textarea>
            </div>
          </fieldset>
        <?php endfor; ?>
      </section>

      <section>
        <h2>Formación académica</h2>
        <?php for ($i = 0; $i < 2; $i++): ?>
          <fieldset class="card" style="padding:var(--sp-3); margin-block:var(--sp-2);">
            <legend>Estudio <?=($i + 1); ?></legend>
            <div class="g-2">
              <div class="field">
                <label for="edu_title_<?=$i; ?>">Programa / Título</label>
                <input id="edu_title_<?=$i; ?>" name="edu_title[]" type="text" value="<?=ep_e($educacion[$i]['titulo']); ?>" placeholder="Ej: Tecnólogo en Logística" />
              </div>
              <div class="field">
                <label for="edu_institution_<?=$i; ?>">Institución</label>
                <input id="edu_institution_<?=$i; ?>" name="edu_institution[]" type="text" value="<?=ep_e($educacion[$i]['institucion']); ?>" placeholder="Ej: SENA" />
              </div>
            </div>
            <div class="g-2">
              <div class="field">
                <label for="edu_period_<?=$i; ?>">Periodo</label>
                <input id="edu_period_<?=$i; ?>" name="edu_period[]" type="text" value="<?=ep_e($educacion[$i]['periodo']); ?>" placeholder="Ej: 2019-2021" />
              </div>
            </div>
            <div class="field">
              <label for="edu_desc_<?=$i; ?>">Descripción (opcional)</label>
              <textarea id="edu_desc_<?=$i; ?>" name="edu_desc[]" rows="2" placeholder="Logros, énfasis o actividades destacadas"><?=ep_e($educacion[$i]['descripcion']); ?></textarea>
            </div>
          </fieldset>
        <?php endfor; ?>
      </section>

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
            <label for="anios">Años de experiencia total</label>
            <select id="anios" name="anios">
              <option value="">Selecciona</option>
              <?php foreach ($experienceRanges as $range): ?>
                <option value="<?=$range; ?>"<?=ep_selected($form['anios'], $range); ?>><?=$range; ?></option>
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
