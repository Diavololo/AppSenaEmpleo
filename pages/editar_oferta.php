<?php
declare(strict_types=1);

// Vista: Editar oferta (empresa)
// Carga valores actuales desde BD, valida y actualiza, consistente con crear_vacante

if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=editar_oferta');
  exit;
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$sessionUser = $_SESSION['user'] ?? null;
if (!$sessionUser || ($sessionUser['type'] ?? '') !== 'empresa') {
  header('Location: ../index.php?view=login');
  exit;
}

require __DIR__.'/db.php';

// Helpers
if (!function_exists('eo_e')) {
  function eo_e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('eo_fix_label')) {
  function eo_fix_label(?string $value): string {
    if ($value === null || $value === '') { return $value ?? ''; }
    $label = (string)$value;
    static $map = ['Ã¡'=>'á','Ã©'=>'é','Ã­'=>'í','Ã³'=>'ó','Ãº'=>'ú','Ã±'=>'ñ','Ãœ'=>'Ü','Ã‘'=>'Ñ','Â '=> ' ','â€“'=>'–','â€”'=>'—','â€¦'=>'…'];
    return strtr($label, $map);
  }
}

$empresaId = isset($sessionUser['empresa_id']) ? (int)$sessionUser['empresa_id'] : null;
if (!$empresaId) {
  $globalErrors[] = 'No encontramos la empresa asociada a tu cuenta.';
}

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

$vacanteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$form = [
  'titulo' => '',
  'area_id' => '',
  'nivel_id' => '',
  'modalidad_id' => '',
  'tipo_contrato_id' => '',
  'ciudad' => '',
  'salario_min' => '',
  'salario_max' => '',
  'moneda' => 'COP',
  'descripcion' => '',
  'requisitos' => '',
  'etiquetas' => '',
];
$fieldErrors = [];
$globalErrors = [];
$monedas = ['COP' => 'COP', 'USD' => 'USD', 'EUR' => 'EUR'];
$catalogs = ['areas'=>[], 'niveles'=>[], 'modalidades'=>[], 'contratos'=>[]];
$catalogIndex = ['areas'=>[], 'niveles'=>[], 'modalidades'=>[], 'contratos'=>[]];

if (!function_exists('eo_seed_modalidades_if_missing')) {
  /**
   * Inserta modalidades basicas si faltan y devuelve el catalogo actualizado.
   * @param array<int,array<string,mixed>> $catalog
   * @return array<int,array<string,mixed>>
   */
  function eo_seed_modalidades_if_missing(PDO $pdo, array $catalog): array
  {
    $required = ['Presencial', 'Hibrido', 'Remoto'];
    $normalize = static function (?string $value): string {
      $label = eo_fix_label($value);
      $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
      $ascii = $ascii !== false ? $ascii : $label;
      return strtolower(trim((string)$ascii));
    };
    $existing = [];
    foreach ($catalog as $row) { $existing[] = $normalize($row['nombre'] ?? ''); }
    $missing = [];
    foreach ($required as $name) {
      if (!in_array($normalize($name), $existing, true)) { $missing[] = $name; }
    }
    if ($missing) {
      try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO modalidades (nombre) VALUES (?)');
        foreach ($missing as $name) { $stmt->execute([$name]); }
        $refresh = $pdo->query('SELECT id, nombre FROM modalidades ORDER BY nombre');
        if ($refresh) { $catalog = $refresh->fetchAll(PDO::FETCH_ASSOC) ?: $catalog; }
      } catch (Throwable $e) {
        error_log('[editar_oferta] seed modalidades: '.$e->getMessage());
      }
    }
    return $catalog;
  }
}

// Catálogos
if ($pdo instanceof PDO) {
  $tables = ['areas'=>'areas','niveles'=>'niveles','modalidades'=>'modalidades','contratos'=>'contratos'];
  foreach ($tables as $key => $table) {
    try {
      $stmt = $pdo->query('SELECT id, nombre FROM '.$table.' ORDER BY nombre');
      $catalogs[$key] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
      error_log('[editar_oferta] Catalogo '.$table.': '.$e->getMessage());
      $catalogs[$key] = [];
    }
    if ($key === 'modalidades') {
      $catalogs[$key] = eo_seed_modalidades_if_missing($pdo, $catalogs[$key]);
    }
    foreach ($catalogs[$key] as $row) {
      if (isset($row['id'])) { $catalogIndex[$key][(int)$row['id']] = eo_fix_label($row['nombre'] ?? ''); }
    }
  }
} else {
  $globalErrors[] = 'No hay conexión con la base de datos.';
}

// Carga vacante existente
if ($pdo instanceof PDO && $vacanteId > 0 && !$globalErrors) {
  try {
    $stmt = $pdo->prepare('SELECT * FROM vacantes WHERE id = ? AND empresa_id = ? LIMIT 1');
    $stmt->execute([$vacanteId, $empresaId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $form['titulo'] = (string)($row['titulo'] ?? '');
      $form['area_id'] = isset($row['area_id']) ? (string)(int)$row['area_id'] : '';
      $form['nivel_id'] = isset($row['nivel_id']) ? (string)(int)$row['nivel_id'] : '';
      $form['modalidad_id'] = isset($row['modalidad_id']) ? (string)(int)$row['modalidad_id'] : '';
      $form['tipo_contrato_id'] = isset($row['tipo_contrato_id']) ? (string)(int)$row['tipo_contrato_id'] : '';
      $form['ciudad'] = (string)($row['ciudad'] ?? '');
      $form['salario_min'] = isset($row['salario_min']) ? (string)(int)$row['salario_min'] : '';
      $form['salario_max'] = isset($row['salario_max']) ? (string)(int)$row['salario_max'] : '';
      $form['moneda'] = strtoupper((string)($row['moneda'] ?? 'COP'));
      $form['descripcion'] = (string)($row['descripcion'] ?? '');
      $form['requisitos'] = (string)($row['requisitos'] ?? '');
      $form['etiquetas'] = (string)($row['etiquetas'] ?? '');
    } else {
      $globalErrors[] = 'No encontramos la vacante indicada.';
    }
  } catch (Throwable $e) {
    $globalErrors[] = 'Error al cargar la vacante.';
    error_log('[editar_oferta] load: '.$e->getMessage());
  }
}

// Procesa actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$globalErrors) {
  foreach (array_keys($form) as $key) {
    if (isset($_POST[$key])) { $val = $_POST[$key]; $form[$key] = is_string($val) ? trim($val) : ''; }
  }
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
    $globalErrors[] = 'Tu sesión expiró. Recarga e inténtalo de nuevo.';
  }
  $titulo = $form['titulo'];
  if ($titulo === '') { $fieldErrors['titulo'] = 'Ingresa un título para la vacante.'; }
  $descripcion = $form['descripcion'];
  if ($descripcion === '') { $fieldErrors['descripcion'] = 'Describe la posición.'; }
  $requisitos = $form['requisitos'] !== '' ? $form['requisitos'] : null;
  $etiquetas = $form['etiquetas'] !== '' ? $form['etiquetas'] : null;
  $ciudad = $form['ciudad'] !== '' ? $form['ciudad'] : null;
  $areaId = $form['area_id'] !== '' ? (int)$form['area_id'] : null;
  if ($form['area_id'] !== '' && !isset($catalogIndex['areas'][$areaId ?? 0])) { $fieldErrors['area_id'] = 'Selecciona un área válida.'; }
  $nivelId = $form['nivel_id'] !== '' ? (int)$form['nivel_id'] : null;
  if ($form['nivel_id'] !== '' && !isset($catalogIndex['niveles'][$nivelId ?? 0])) { $fieldErrors['nivel_id'] = 'Selecciona un nivel válido.'; }
  $modalidadId = $form['modalidad_id'] !== '' ? (int)$form['modalidad_id'] : null;
  if ($form['modalidad_id'] !== '' && !isset($catalogIndex['modalidades'][$modalidadId ?? 0])) { $fieldErrors['modalidad_id'] = 'Selecciona una modalidad válida.'; }
  $contratoId = $form['tipo_contrato_id'] !== '' ? (int)$form['tipo_contrato_id'] : null;
  if ($form['tipo_contrato_id'] !== '' && !isset($catalogIndex['contratos'][$contratoId ?? 0])) { $fieldErrors['tipo_contrato_id'] = 'Selecciona un tipo de contrato válido.'; }
  $salarioMin = null;
  if ($form['salario_min'] !== '') { $min = filter_var($form['salario_min'], FILTER_VALIDATE_INT); if ($min === false || $min < 0) { $fieldErrors['salario_min'] = 'Salario mínimo inválido.'; } else { $salarioMin = $min; } }
  $salarioMax = null;
  if ($form['salario_max'] !== '') { $max = filter_var($form['salario_max'], FILTER_VALIDATE_INT); if ($max === false || $max < 0) { $fieldErrors['salario_max'] = 'Salario máximo inválido.'; } else { $salarioMax = $max; } }
  if ($salarioMin !== null && $salarioMax !== null && $salarioMin > $salarioMax) { $fieldErrors['salario_max'] = 'El salario máximo debe ser mayor o igual al mínimo.'; }
  $moneda = strtoupper($form['moneda']); if (!isset($monedas[$moneda])) { $moneda = 'COP'; $form['moneda'] = 'COP'; }

  if (!$fieldErrors) {
    try {
      $upd = $pdo->prepare(
        'UPDATE vacantes SET
          titulo = :titulo,
          area_id = :area_id,
          nivel_id = :nivel_id,
          modalidad_id = :modalidad_id,
          tipo_contrato_id = :contrato_id,
          ciudad = :ciudad,
          salario_min = :salario_min,
          salario_max = :salario_max,
          moneda = :moneda,
          descripcion = :descripcion,
          requisitos = :requisitos,
          etiquetas = :etiquetas,
          updated_at = NOW()
        WHERE id = :id AND empresa_id = :empresa_id'
      );
      $upd->execute([
        ':titulo' => $titulo,
        ':area_id' => $areaId,
        ':nivel_id' => $nivelId,
        ':modalidad_id' => $modalidadId,
        ':contrato_id' => $contratoId,
        ':ciudad' => $ciudad,
        ':salario_min' => $salarioMin,
        ':salario_max' => $salarioMax,
        ':moneda' => $moneda,
        ':descripcion' => $descripcion,
        ':requisitos' => $requisitos,
        ':etiquetas' => $etiquetas,
        ':id' => $vacanteId,
        ':empresa_id' => $empresaId,
      ]);
      $_SESSION['flash_mis_ofertas'] = 'Vacante actualizada correctamente.';
      header('Location: index.php?view=mis_ofertas_empresa');
      exit;
    } catch (Throwable $e) {
      $globalErrors[] = 'No se pudo actualizar la vacante.';
      error_log('[editar_oferta] update: '.$e->getMessage());
    }
  }
}

?>

<section class="section">
  <div class="container">
    <div class="dash-head">
      <div>
        <h2>Editar oferta</h2>
        <p class="muted">Actualiza la información. Los cambios impactan la publicación y el matching.</p>
      </div>
    </div>

    <?php if (!empty($globalErrors) || !empty($fieldErrors)): ?>
      <div class="card" style="border-color:#f5c7c7;background:#fff5f5;">
        <ul style="margin:0; padding-left:1.1rem;">
          <?php foreach ($globalErrors as $err): ?><li><?=eo_e($err); ?></li><?php endforeach; ?>
          <?php foreach ($fieldErrors as $err): ?><li><?=eo_e($err); ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form class="card form" method="post" autocomplete="off" novalidate>
      <input type="hidden" name="_csrf" value="<?=eo_e($csrf); ?>" />

      <div class="field">
        <label for="titulo">Título *</label>
        <input id="titulo" name="titulo" type="text" value="<?=eo_e($form['titulo']); ?>" placeholder="Ej: Desarrollador Backend" required />
      </div>

      <div class="form-grid">
        <div class="field">
          <label for="area_id">Área</label>
          <select id="area_id" name="area_id">
            <option value="">Selecciona un área</option>
            <?php foreach ($catalogs['areas'] as $item): ?>
              <?php $id = (int)($item['id'] ?? 0); ?>
              <option value="<?=$id; ?>" <?=($form['area_id'] !== '' && (int)$form['area_id'] === $id) ? 'selected' : ''; ?>>
                <?=eo_e(eo_fix_label($item['nombre'] ?? '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="nivel_id">Nivel</label>
          <select id="nivel_id" name="nivel_id">
            <option value="">Selecciona un nivel</option>
            <?php foreach ($catalogs['niveles'] as $item): ?>
              <?php $id = (int)($item['id'] ?? 0); ?>
              <option value="<?=$id; ?>" <?=($form['nivel_id'] !== '' && (int)$form['nivel_id'] === $id) ? 'selected' : ''; ?>>
                <?=eo_e(eo_fix_label($item['nombre'] ?? '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="field">
          <label for="modalidad_id">Modalidad</label>
          <select id="modalidad_id" name="modalidad_id">
            <option value="">Selecciona una modalidad</option>
            <?php foreach ($catalogs['modalidades'] as $item): ?>
              <?php $id = (int)($item['id'] ?? 0); ?>
              <option value="<?=$id; ?>" <?=($form['modalidad_id'] !== '' && (int)$form['modalidad_id'] === $id) ? 'selected' : ''; ?>>
                <?=eo_e(eo_fix_label($item['nombre'] ?? '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="tipo_contrato_id">Tipo de contrato</label>
          <select id="tipo_contrato_id" name="tipo_contrato_id">
            <option value="">Selecciona un tipo</option>
            <?php foreach ($catalogs['contratos'] as $item): ?>
              <?php $id = (int)($item['id'] ?? 0); ?>
              <option value="<?=$id; ?>" <?=($form['tipo_contrato_id'] !== '' && (int)$form['tipo_contrato_id'] === $id) ? 'selected' : ''; ?>>
                <?=eo_e(eo_fix_label($item['nombre'] ?? '')); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="field">
          <label for="ciudad">Ciudad</label>
          <input id="ciudad" name="ciudad" type="text" value="<?=eo_e($form['ciudad']); ?>" placeholder="Ej: Bogotá" />
        </div>
        <div class="field">
          <label for="salario_min">Salario mínimo</label>
          <input id="salario_min" name="salario_min" type="number" value="<?=eo_e($form['salario_min']); ?>" />
        </div>
        <div class="field">
          <label for="salario_max">Salario máximo</label>
          <input id="salario_max" name="salario_max" type="number" value="<?=eo_e($form['salario_max']); ?>" />
        </div>
      </div>

      <div class="form-grid">
        <div class="field">
          <label for="moneda">Moneda</label>
          <select id="moneda" name="moneda">
            <?php foreach ($monedas as $code => $label): ?>
              <option value="<?=$code; ?>" <?=($form['moneda'] === $code) ? 'selected' : ''; ?>><?=$label; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"></div>
        <div class="field"></div>
      </div>

      <div class="field">
        <label for="descripcion">Descripción *</label>
        <textarea id="descripcion" name="descripcion" rows="6" placeholder="Cuenta las responsabilidades y retos." required><?=eo_e($form['descripcion']); ?></textarea>
      </div>

      <div class="field">
        <label for="requisitos">Requisitos</label>
        <textarea id="requisitos" name="requisitos" rows="5" placeholder="Experiencia, herramientas, idiomas..."><?=eo_e($form['requisitos']); ?></textarea>
      </div>

      <div class="field">
        <label for="etiquetas">Etiquetas</label>
        <input id="etiquetas" name="etiquetas" type="text" value="<?=eo_e($form['etiquetas']); ?>" placeholder="Ej: Laravel, Scrum, Inglés" />
      </div>

      <div style="display:flex; justify-content:flex-end; gap:1rem; flex-wrap:wrap;">
        <a class="btn btn-outline" href="index.php?view=mis_ofertas_empresa">Cancelar</a>
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
      </div>
    </form>
  </div>
</section>
