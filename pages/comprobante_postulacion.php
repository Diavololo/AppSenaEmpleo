<?php
// Comprobante de postulación
// Soporta dos modos:
// - Texto plano (descarga o inline): por defecto
// - HTML (vista estilizada): usando ?format=html

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$g = function($k, $d = '') { return isset($_GET[$k]) ? $_GET[$k] : $d; };
$titulo    = $g('titulo', 'Cargo');
$empresa   = $g('empresa', 'Empresa');
$ubicacion = $g('ciudad', $g('ubicacion', 'Ciudad'));
$fecha = date('Y-m-d H:i');
$format = strtolower($g('format', 'txt'));
// Render como documento completo solo si se accede directamente a este archivo
$is_direct = (basename($_SERVER['SCRIPT_NAME']) === 'comprobante_postulacion.php');

if ($format === 'html') {
  header('Content-Type: text/html; charset=utf-8');
  $dl = 'index.php?action=comprobante_postulacion'
    . '&titulo=' . urlencode($titulo)
    . '&empresa=' . urlencode($empresa)
    . '&ciudad=' . urlencode($ubicacion);
  ?>
  <?php if ($is_direct): ?>
  <!DOCTYPE html>
  <html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Comprobante de postulación</title>
    <link rel="stylesheet" href="../style.css" />
  </head>
  <body>
    <?php require __DIR__.'/../templates/header.php'; ?>
  <?php endif; ?>
    <main class="container section">
      <div class="card" style="padding: var(--sp-4); display:flex; flex-direction:column; gap:.9rem;">
        <h1>Comprobante de postulación</h1>
        <p class="muted">Generado el: <?= htmlspecialchars($fecha) ?></p>
        <div style="display:grid; grid-template-columns: 160px 1fr; gap:.6rem;">
          <div class="muted"><strong>Cargo</strong></div>
          <div><?= htmlspecialchars($titulo) ?></div>
          <div class="muted"><strong>Empresa</strong></div>
          <div><?= htmlspecialchars($empresa) ?></div>
          <div class="muted"><strong>Ubicación</strong></div>
          <div><?= htmlspecialchars($ubicacion) ?></div>
        </div>
        <div style="display:flex; gap:.6rem; margin-top:.6rem;">
          <a class="btn btn-brand" href="<?= $dl ?>">Descargar comprobante</a>
          <a class="btn btn-outline" href="../index.php?view=mis_postulaciones">Volver a Mis postulaciones</a>
        </div>
      </div>
    </main>
    <?php if ($is_direct): ?>
    <?php require __DIR__.'/../templates/footer.php'; ?>
    <script src="../js/script.js"></script>
  </body>
  </html>
    <?php endif; ?>
  <?php
  exit;
}

// Texto plano (descarga/inline)
header('Content-Type: text/plain; charset=utf-8');
$lines = [
  'Comprobante de postulación',
  'Fecha: ' . $fecha,
  'Cargo: ' . $titulo,
  'Empresa: ' . $empresa,
  'Ubicación: ' . $ubicacion,
];
$filename = 'comprobante_' . preg_replace('/[^a-z0-9_-]+/i', '_', $titulo) . '_' . date('Ymd_His') . '.txt';
$inline = isset($_GET['inline']) && ($_GET['inline'] === '1' || strtolower($_GET['inline']) === 'true');
header(($inline ? 'Content-Disposition: inline; filename="' : 'Content-Disposition: attachment; filename="') . $filename . '"');
echo implode("\r\n", $lines);
?>