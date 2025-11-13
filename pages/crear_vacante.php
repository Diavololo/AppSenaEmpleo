<?php

declare(strict_types=1);



// Restringe el acceso directo; siempre se carga como parcial desde index.php

if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {

  header('Location: ../index.php?view=crear_vacante');

  exit;

}



if (session_status() === PHP_SESSION_NONE) {

  session_start();

}



$sessionUser = $_SESSION['user'] ?? null;

if (!$sessionUser || ($sessionUser['type'] ?? '') !== 'empresa') {

  header('Location: ../index.php?view=login');

  exit;

}



require __DIR__.'/db.php';



if (!function_exists('cv_e')) {

  function cv_e(?string $value): string {

    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');

  }

}



if (!function_exists('cv_fix_catalog_label')) {

  function cv_fix_catalog_label(?string $value): string {

    if ($value === null || $value === '') {
      return $value ?? '';
    }

    $label = (string)$value;

    if (strpbrk($label, 'ÃÂâ') === false) {
      return $label;
    }

    static $map = [
      'Ã ' => 'à',
      'Ã¡' => 'á',
      'Ã¢' => 'â',
      'Ã£' => 'ã',
      'Ã¤' => 'ä',
      'Ã¨' => 'è',
      'Ã©' => 'é',
      'Ãª' => 'ê',
      'Ã«' => 'ë',
      'Ã¬' => 'ì',
      'Ã­' => 'í',
      'Ã®' => 'î',
      'Ã¯' => 'ï',
      'Ã²' => 'ò',
      'Ã³' => 'ó',
      'Ã´' => 'ô',
      'Ãµ' => 'õ',
      'Ã¶' => 'ö',
      'Ã¹' => 'ù',
      'Ãº' => 'ú',
      'Ã»' => 'û',
      'Ã¼' => 'ü',
      'Ã±' => 'ñ',
      'Ã€' => 'À',
      'Ã�' => 'Á',
      'Ã‚' => 'Â',
      'Ãƒ' => 'Ã',
      'Ã„' => 'Ä',
      'Ãˆ' => 'È',
      'Ã‰' => 'É',
      'ÃŠ' => 'Ê',
      'Ã‹' => 'Ë',
      'ÃŒ' => 'Ì',
      'Ã�' => 'Í',
      'ÃŽ' => 'Î',
      'Ã�' => 'Ï',
      'Ã’' => 'Ò',
      'Ã“' => 'Ó',
      'Ã”' => 'Ô',
      'Ã•' => 'Õ',
      'Ã–' => 'Ö',
      'Ã™' => 'Ù',
      'Ãš' => 'Ú',
      'Ã›' => 'Û',
      'Ãœ' => 'Ü',
      'ÃŸ' => 'ß',
      'Ã‘' => 'Ñ',
      'Ã§' => 'ç',
      'Ã‡' => 'Ç',
      'Â ' => ' ',
      'Â¡' => '¡',
      'Â¿' => '¿',
      'Âº' => 'º',
      'Âª' => 'ª',
      'Â·' => '·',
      'Â°' => '°',
      'Â�' => '',
      'Â'  => '',
      'â€“' => '–',
      'â€”' => '—',
      'â€˜' => '‘',
      'â€™' => '’',
      'â€œ' => '“',
      'â€�' => '”',
      'â€¢' => '•',
      'â€¦' => '…',
    ];

    return strtr($label, $map);
  }
}




$empresaId = isset($sessionUser['empresa_id']) ? (int)$sessionUser['empresa_id'] : null;

$empresaNombre = $sessionUser['empresa']

  ?? $sessionUser['display_name']

  ?? ($sessionUser['nombre'] ?? 'Mi empresa');



$fatalError = null;

$dbError = null;



if (!$empresaId) {

  $fatalError = 'No encontramos la empresa asociada a tu cuenta.';

}



if (empty($_SESSION['csrf'])) {

  $_SESSION['csrf'] = bin2hex(random_bytes(16));

}

$csrf = $_SESSION['csrf'];



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



$catalogs = [

  'areas' => [],

  'niveles' => [],

  'modalidades' => [],

  'contratos' => [],

];

$catalogIndex = [

  'areas' => [],

  'niveles' => [],

  'modalidades' => [],

  'contratos' => [],

];



$catalogSeeds = [

  'areas' => [

    'Administración',

    'Atención al cliente',

    'Contabilidad',

    'Logística',

    'Tecnología',

  ],

  'niveles' => [

    'Prácticas',

    'Junior',

    'Semi Senior',

    'Senior',

  ],

  'modalidades' => [

    'Presencial',

    'Híbrido',

    'Remoto',

  ],

  'contratos' => [

    'Indefinido',

    'Término fijo',

    'Prácticas',

    'Temporal',

    'Freelance',

  ],

];



if ($pdo instanceof PDO) {

  $catalogTables = [

    'areas' => 'areas',

    'niveles' => 'niveles',

    'modalidades' => 'modalidades',

    'contratos' => 'contratos',

  ];

  foreach ($catalogTables as $key => $table) {

    $catalogError = null;

    try {

      $stmt = $pdo->query('SELECT id, nombre FROM '.$table.' ORDER BY nombre');

      $catalogs[$key] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    } catch (Throwable $catalogError) {

      error_log('[crear_vacante] Catalogo '.$table.': '.$catalogError->getMessage());

      $catalogs[$key] = [];

    }



    if ($catalogError === null && empty($catalogs[$key]) && isset($catalogSeeds[$key])) {

      try {

        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO '.$table.' (nombre) VALUES (:nombre)');
        foreach ($catalogSeeds[$key] as $seedName) {
          $insert->execute([':nombre' => cv_fix_catalog_label($seedName)]);
        }
        $pdo->commit();


        $stmt = $pdo->query('SELECT id, nombre FROM '.$table.' ORDER BY nombre');

        $catalogs[$key] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

      } catch (Throwable $seedError) {

        if ($pdo->inTransaction()) {

          $pdo->rollBack();

        }

        error_log('[crear_vacante] Seed '.$table.': '.$seedError->getMessage());

      }

    }



    if (!empty($catalogs[$key])) {

      $updateStmt = null;

      foreach ($catalogs[$key] as &$row) {

        if (!array_key_exists('nombre', $row)) {

          continue;

        }

        $currentName = (string)$row['nombre'];

        $cleanName = cv_fix_catalog_label($currentName);

        if ($cleanName !== $currentName && $catalogError === null) {
          $rowId = isset($row['id']) ? (int)$row['id'] : 0;
          if ($rowId > 0) {
            try {
              if ($updateStmt === null) {
                $updateStmt = $pdo->prepare('UPDATE '.$table.' SET nombre = :nombre WHERE id = :id LIMIT 1');

              }

              $updateStmt->execute([

                ':nombre' => $cleanName,

                ':id' => $rowId,

              ]);

            } catch (Throwable $fixError) {
              error_log('[crear_vacante] Fix nombre '.$table.': '.$fixError->getMessage());
            }
          }
        }
        $row['nombre'] = $cleanName;
      }
      unset($row);
    }


    foreach ($catalogs[$key] as $row) {

      if (isset($row['id'])) {

        $catalogIndex[$key][(int)$row['id']] = (string)($row['nombre'] ?? '');

      }

    }

  }

} else {

  $dbError = 'No hay conexion con la base de datos.';

}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  foreach (array_keys($form) as $key) {

    if (isset($_POST[$key])) {

      $value = $_POST[$key];

      $form[$key] = is_string($value) ? trim($value) : '';

    }

  }



  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {

    $globalErrors[] = 'Tu sesion expiro. Recarga la pagina e intentalo de nuevo.';

  }



  if (!$fatalError && !$dbError && empty($globalErrors)) {

    $titulo = $form['titulo'];

    if ($titulo === '') {

      $fieldErrors['titulo'] = 'Ingresa un titulo para la vacante.';

    }



    $descripcion = $form['descripcion'];

    if ($descripcion === '') {

      $fieldErrors['descripcion'] = 'Describe la posicion.';

    }



    $requisitos = $form['requisitos'] !== '' ? $form['requisitos'] : null;

    $etiquetas = $form['etiquetas'] !== '' ? $form['etiquetas'] : null;

    $ciudad = $form['ciudad'] !== '' ? $form['ciudad'] : null;



    $areaId = $form['area_id'] !== '' ? (int)$form['area_id'] : null;

    if ($form['area_id'] !== '' && !isset($catalogIndex['areas'][$areaId ?? 0])) {

      $fieldErrors['area_id'] = 'Selecciona un area valida.';

    }



    $nivelId = $form['nivel_id'] !== '' ? (int)$form['nivel_id'] : null;

    if ($form['nivel_id'] !== '' && !isset($catalogIndex['niveles'][$nivelId ?? 0])) {

      $fieldErrors['nivel_id'] = 'Selecciona un nivel valido.';

    }



    $modalidadId = $form['modalidad_id'] !== '' ? (int)$form['modalidad_id'] : null;

    if ($form['modalidad_id'] !== '' && !isset($catalogIndex['modalidades'][$modalidadId ?? 0])) {

      $fieldErrors['modalidad_id'] = 'Selecciona una modalidad valida.';

    }



    $contratoId = $form['tipo_contrato_id'] !== '' ? (int)$form['tipo_contrato_id'] : null;

    if ($form['tipo_contrato_id'] !== '' && !isset($catalogIndex['contratos'][$contratoId ?? 0])) {

      $fieldErrors['tipo_contrato_id'] = 'Selecciona un tipo de contrato valido.';

    }



    $salarioMin = null;

    if ($form['salario_min'] !== '') {

      $min = filter_var($form['salario_min'], FILTER_VALIDATE_INT);

      if ($min === false || $min < 0) {

        $fieldErrors['salario_min'] = 'Salario minimo invalido.';

      } else {

        $salarioMin = $min;

      }

    }



    $salarioMax = null;

    if ($form['salario_max'] !== '') {

      $max = filter_var($form['salario_max'], FILTER_VALIDATE_INT);

      if ($max === false || $max < 0) {

        $fieldErrors['salario_max'] = 'Salario maximo invalido.';

      } else {

        $salarioMax = $max;

      }

    }



    if ($salarioMin !== null && $salarioMax !== null && $salarioMin > $salarioMax) {

      $fieldErrors['salario_max'] = 'El salario maximo debe ser mayor o igual al minimo.';

    }



    $moneda = strtoupper($form['moneda']);

    if (!isset($monedas[$moneda])) {

      $moneda = 'COP';

      $form['moneda'] = 'COP';

    }



    if (!$fieldErrors) {

      try {

        $stmt = $pdo->prepare(

          'INSERT INTO vacantes (

              empresa_id, titulo, area_id, nivel_id, modalidad_id, tipo_contrato_id,

              ciudad, salario_min, salario_max, moneda, descripcion, requisitos,

              etiquetas, estado, publicada_at, created_at, updated_at

            ) VALUES (

              :empresa_id, :titulo, :area_id, :nivel_id, :modalidad_id, :tipo_contrato_id,

              :ciudad, :salario_min, :salario_max, :moneda, :descripcion, :requisitos,

              :etiquetas, :estado, :publicada_at, NOW(), NOW()

            )'

        );



        $stmt->execute([

          ':empresa_id' => $empresaId,

          ':titulo' => $titulo,

          ':area_id' => $areaId,

          ':nivel_id' => $nivelId,

          ':modalidad_id' => $modalidadId,

          ':tipo_contrato_id' => $contratoId,

          ':ciudad' => $ciudad,

          ':salario_min' => $salarioMin,

          ':salario_max' => $salarioMax,

          ':moneda' => $moneda,

          ':descripcion' => $descripcion,

          ':requisitos' => $requisitos,

          ':etiquetas' => $etiquetas,

          ':estado' => 'publicada',

          ':publicada_at' => date('Y-m-d H:i:s'),

        ]);



        $_SESSION['flash_mis_ofertas'] = 'Vacante creada correctamente.';

        header('Location: index.php?view=mis_ofertas_empresa&created=1');

        exit;

      } catch (Throwable $insertError) {

        $globalErrors[] = 'No se pudo guardar la vacante. '.$insertError->getMessage();

      }

    }

  }

}

?>



<section class="section">

  <div class="container">

    <div class="dash-head">

      <div>

        <h2>Crear vacante</h2>

        <p class="muted">Publica una nueva oferta y conectate con el talento de la plataforma.</p>

      </div>

    </div>



    <?php if ($fatalError): ?>

      <div class="card" style="border-color:#f5c7c7;background:#fff5f5;">

        <strong><?=cv_e($fatalError); ?></strong>

      </div>

    <?php elseif ($dbError): ?>

      <div class="card" style="border-color:#f5c7c7;background:#fff5f5;">

        <strong><?=cv_e($dbError); ?></strong>

      </div>

    <?php else: ?>

      <?php if ($globalErrors || $fieldErrors): ?>

        <div class="card" style="border-color:#f5c7c7;background:#fff5f5;">

          <ul style="margin:0; padding-left:1.1rem;">

            <?php foreach ($globalErrors as $err): ?>

              <li><?=cv_e($err); ?></li>

            <?php endforeach; ?>

            <?php foreach ($fieldErrors as $err): ?>

              <li><?=cv_e($err); ?></li>

            <?php endforeach; ?>

          </ul>

        </div>

      <?php endif; ?>



      <form class="card form" method="post" autocomplete="off" novalidate>

        <input type="hidden" name="_csrf" value="<?=cv_e($csrf); ?>"/>

        <div class="field">

          <label for="titulo">Titulo *</label>

          <input id="titulo" name="titulo" type="text" value="<?=cv_e($form['titulo']); ?>" placeholder="Ej: Desarrollador Backend" required />

          <?php if (isset($fieldErrors['titulo'])): ?>

            <small style="color:#c0392b;"><?=cv_e($fieldErrors['titulo']); ?></small>

          <?php endif; ?>

        </div>



        <div class="form-grid">

          <div class="field">

            <label for="area_id">Area</label>

            <select id="area_id" name="area_id">

              <option value="">Selecciona un area</option>

              <?php foreach ($catalogs['areas'] as $item): ?>
                <?php $id = (int)($item['id'] ?? 0); ?>
                <option value="<?=$id; ?>" <?=($form['area_id'] !== '' && (int)$form['area_id'] === $id) ? 'selected' : ''; ?>>
                  <?=cv_e(cv_fix_catalog_label($item['nombre'] ?? '')); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($fieldErrors['area_id'])): ?>

              <small style="color:#c0392b;"><?=cv_e($fieldErrors['area_id']); ?></small>

            <?php endif; ?>

          </div>



          <div class="field">

            <label for="nivel_id">Nivel</label>

            <select id="nivel_id" name="nivel_id">

              <option value="">Selecciona un nivel</option>

              <?php foreach ($catalogs['niveles'] as $item): ?>
                <?php $id = (int)($item['id'] ?? 0); ?>
                <option value="<?=$id; ?>" <?=($form['nivel_id'] !== '' && (int)$form['nivel_id'] === $id) ? 'selected' : ''; ?>>
                  <?=cv_e(cv_fix_catalog_label($item['nombre'] ?? '')); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($fieldErrors['nivel_id'])): ?>

              <small style="color:#c0392b;"><?=cv_e($fieldErrors['nivel_id']); ?></small>

            <?php endif; ?>

          </div>



          <div class="field">

            <label for="modalidad_id">Modalidad</label>

            <select id="modalidad_id" name="modalidad_id">

              <option value="">Selecciona una modalidad</option>

              <?php foreach ($catalogs['modalidades'] as $item): ?>
                <?php $id = (int)($item['id'] ?? 0); ?>
                <option value="<?=$id; ?>" <?=($form['modalidad_id'] !== '' && (int)$form['modalidad_id'] === $id) ? 'selected' : ''; ?>>
                  <?=cv_e(cv_fix_catalog_label($item['nombre'] ?? '')); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($fieldErrors['modalidad_id'])): ?>

              <small style="color:#c0392b;"><?=cv_e($fieldErrors['modalidad_id']); ?></small>

            <?php endif; ?>

          </div>



          <div class="field">

            <label for="tipo_contrato_id">Tipo de contrato</label>

            <select id="tipo_contrato_id" name="tipo_contrato_id">

              <option value="">Selecciona un tipo</option>

              <?php foreach ($catalogs['contratos'] as $item): ?>
                <?php $id = (int)($item['id'] ?? 0); ?>
                <option value="<?=$id; ?>" <?=($form['tipo_contrato_id'] !== '' && (int)$form['tipo_contrato_id'] === $id) ? 'selected' : ''; ?>>
                  <?=cv_e(cv_fix_catalog_label($item['nombre'] ?? '')); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($fieldErrors['tipo_contrato_id'])): ?>

              <small style="color:#c0392b;"><?=cv_e($fieldErrors['tipo_contrato_id']); ?></small>

            <?php endif; ?>

          </div>

        </div>



        <div class="form-grid">

          <div class="field">

            <label for="ciudad">Ciudad</label>

            <input id="ciudad" name="ciudad" type="text" value="<?=cv_e($form['ciudad']); ?>" placeholder="Ej: Bogota" />

          </div>

          <div class="field">

            <label for="salario_min">Salario minimo</label>

            <input id="salario_min" name="salario_min" type="number" min="0" step="10000" value="<?=cv_e($form['salario_min']); ?>" />

            <?php if (isset($fieldErrors['salario_min'])): ?>

              <small style="color:#c0392b;"><?=cv_e($fieldErrors['salario_min']); ?></small>

            <?php endif; ?>

          </div>

          <div class="field">

            <label for="salario_max">Salario maximo</label>

            <input id="salario_max" name="salario_max" type="number" min="0" step="10000" value="<?=cv_e($form['salario_max']); ?>" />

            <?php if (isset($fieldErrors['salario_max'])): ?>

              <small style="color:#c0392b;"><?=cv_e($fieldErrors['salario_max']); ?></small>

            <?php endif; ?>

          </div>

          <div class="field">

            <label for="moneda">Moneda</label>

            <select id="moneda" name="moneda">

              <?php foreach ($monedas as $code => $label): ?>

                <option value="<?=$code; ?>" <?=($form['moneda'] === $code) ? 'selected' : ''; ?>><?=$label; ?></option>

              <?php endforeach; ?>

            </select>

          </div>

        </div>



        <div class="field">

          <label for="descripcion">Descripcion *</label>

          <textarea id="descripcion" name="descripcion" rows="6" placeholder="Cuenta las responsabilidades principales y el reto del cargo." required><?=cv_e($form['descripcion']); ?></textarea>

          <?php if (isset($fieldErrors['descripcion'])): ?>

            <small style="color:#c0392b;"><?=cv_e($fieldErrors['descripcion']); ?></small>

          <?php endif; ?>

        </div>



        <div class="field">

          <label for="requisitos">Requisitos</label>

          <textarea id="requisitos" name="requisitos" rows="5" placeholder="Experiencia, herramientas, idiomas..."><?=cv_e($form['requisitos']); ?></textarea>

        </div>



        <div class="field">

          <label for="etiquetas">Etiquetas</label>

          <input id="etiquetas" name="etiquetas" type="text" value="<?=cv_e($form['etiquetas']); ?>" placeholder="Ej: Laravel, Scrum, Ingles" />

        </div>



        <div style="display:flex; justify-content:flex-end; gap:1rem; flex-wrap:wrap;">

          <button class="btn btn-primary" type="submit">Guardar vacante</button>

        </div>

      </form>

    <?php endif; ?>

  </div>

</section>

