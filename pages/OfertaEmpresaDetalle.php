<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require __DIR__.'/db.php';
// Utilidad de escape segura (si no existe)
if (!function_exists('e')) {
  function e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}

// Render como documento completo solo si se accede directamente al archivo
$is_direct = (basename($_SERVER['SCRIPT_NAME']) === 'OfertaEmpresaDetalle.php');

// Datos desde GET (con valores demo si faltan)
$get = function($k, $d='') { return isset($_GET[$k]) ? $_GET[$k] : $d; };
$id           = (int)$get('id', 0);
$empresaNombre = $get('empresa', 'DevAndes');
$titulo       = $get('titulo', 'Auxiliar de Bodega');
$ciudad       = $get('ciudad', 'Bogotá');
$modalidad    = $get('modalidad', 'Presencial');
$jornada      = $get('jornada', 'Tiempo completo');
$nivel        = $get('nivel', 'Junior');
$tipoContrato = $get('tipo_contrato', 'Término fijo');
$salMin       = $get('salario_min', '1300000');
$salMax       = $get('salario_max', '1800000');
$periodicidad = $get('periodicidad', 'Mensual');
$resumen      = $get('resumen', 'Recepción, inventario y despacho de mercancías.');
$responsabilidades = array_filter(array_map('trim', preg_split('/\r?\n/', $get('responsabilidades', "Recepción y verificación de mercancías.\nUbicación y acomodo en estanterías."))));
$requisitos        = array_filter(array_map('trim', preg_split('/\r?\n/', $get('requisitos', "Bachiller culminado.\nManejo básico de Excel."))));
$beneficios        = array_filter(array_map('trim', preg_split('/\r?\n/', $get('beneficios', "Prestaciones de ley.\nAuxilio de transporte."))));
$tecnologiasRaw    = $get('tecnologias', 'Inventarios,Excel');
$tecnologias       = array_filter(array_map('trim', explode(',', $tecnologiasRaw)));

$salarioStr = ($salMin || $salMax)
  ? ('$'.number_format((int)$salMin,0,',','.') . ' - $'.number_format((int)$salMax,0,',','.') . ' ' . $periodicidad)
  : '$ A convenir';

// Construye query de edición
$qs = http_build_query(array_filter([
  'id' => $id ?: null,
  'titulo' => $titulo,
  'ciudad' => $ciudad,
  'modalidad' => $modalidad,
  'jornada' => $jornada,
  'nivel' => $nivel,
  'tipo_contrato' => $tipoContrato,
  'salario_min' => $salMin,
  'salario_max' => $salMax,
  'periodicidad' => $periodicidad,
  'resumen' => $resumen,
  'responsabilidades' => implode("\n", $responsabilidades),
  'requisitos' => implode("\n", $requisitos),
  'beneficios' => implode("\n", $beneficios),
  'tecnologias' => implode(',', $tecnologias),
]));
?>
<?php if ($is_direct): ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Detalle de oferta — Empresa</title>
  <link rel="stylesheet" href="../style.css"/>
  <style>
    .page .layout{ display:grid; grid-template-columns: 1fr; gap: 24px; }
    .page .chips{ display:flex; gap:8px; flex-wrap:wrap; }
    .list{ margin:0; padding-left:18px; }
    /* Utilidades mínimas usadas en esta vista */
    .stack{ display:grid; gap:.8rem; }
    .stack--row{ display:flex; gap:16px; }
    .justify-between{ justify-content:space-between; align-items:center; }
    .p-24{ padding: 24px; }
    .space-y-16 > * + *{ margin-top: 16px; }
    .divider{ height:1px; background: var(--border); margin: 8px 0; }
    .h5{ font-size:1.15rem; }
    .h6{ font-size:1rem; }
    .m-0{ margin:0; }
    .text-strong{ font-weight:700; }
  </style>
  </head>
<body>
<?php require __DIR__.'/../templates/header.php'; ?>
<?php endif; ?>

<main class="container page">
  <div class="page__head">
    <h1>Detalle de la oferta</h1>
    <p class="muted">Vista de usuario: detalle de oferta.</p>
  </div>

  <section class="card p-24 space-y-16">
    <div class="stack stack--row justify-between">
      <div>
        <h2 class="h5 m-0"><?= e($titulo) ?></h2>
        <p class="muted m-0"><strong class="text-strong"><?= e($empresaNombre) ?></strong> · <?= e($ciudad) ?></p>
      </div>
      <div>
        <a class="btn btn-secondary" href="../index.php?view=mis_ofertas_empresa">Volver a mis ofertas</a>
        <a class="btn btn-primary" href="../index.php?view=editar_oferta&<?= $qs ?>">Editar oferta</a>
      </div>
    </div>

    <div class="chips">
      <?php foreach (array_filter([$modalidad,$jornada,$nivel]) as $chip): ?>
        <span class="chip"><?= e($chip) ?></span>
      <?php endforeach; ?>
    </div>

    <div class="stack">
      <p class="h5 m-0"><?= e($salarioStr) ?></p>
      <p class="m-0"><?= e($tipoContrato) ?><?= $jornada ? ' · '.e($jornada) : '' ?></p>
    </div>

    <?php if ($resumen): ?>
      <div class="divider"></div>
      <h3 class="h6">Resumen</h3>
      <p><?= e($resumen) ?></p>
    <?php endif; ?>

    <?php if ($responsabilidades): ?>
      <div class="divider"></div>
      <h3 class="h6">Responsabilidades</h3>
      <ul class="list">
        <?php foreach ($responsabilidades as $li): ?><li><?= e($li) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($requisitos): ?>
      <div class="divider"></div>
      <h3 class="h6">Requisitos</h3>
      <ul class="list">
        <?php foreach ($requisitos as $li): ?><li><?= e($li) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($beneficios): ?>
      <div class="divider"></div>
      <h3 class="h6">Beneficios</h3>
      <ul class="list">
        <?php foreach ($beneficios as $li): ?><li><?= e($li) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($tecnologias): ?>
      <div class="divider"></div>
      <h3 class="h6">Tecnologías / etiquetas</h3>
      <div class="chips">
        <?php foreach ($tecnologias as $t): ?><span class="chip"><?= e($t) ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>

<?php if ($is_direct): ?>
<?php require __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
<?php endif; ?>