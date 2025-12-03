<?php
session_start();
$view = $_GET['view'] ?? null;
// Normaliza alias para acceder a Ofertaendetalle
if ($view === 'Ofertaendetalle' || $view === 'ofertaendetalle') {
  $view = 'oferta_detalle';
}
// Acepta alias con mayúsculas para postulación confirmada
if ($view === 'PostulacionConfirmada') {
  $view = 'postulacion_confirmada';
}
$action = $_GET['action'] ?? null;
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__.'/actions/login.php'; exit; }
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__.'/actions/register.php'; exit; }
if ($action === 'logout') { require __DIR__.'/actions/logout.php'; exit; }
// Actualización de perfil (POST)
if ($action === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__.'/actions/update_profile.php'; exit; }
if ($action === 'password_reset_request' && $_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__.'/actions/password_reset_request.php'; exit; }
if ($action === 'password_reset_update' && $_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__.'/actions/password_reset_update.php'; exit; }
if ($action === 'vacante_estado' && $_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__.'/actions/vacante_estado.php'; exit; }
if ($action === 'update_postulacion' && $_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__.'/actions/update_postulacion.php'; exit; }
if ($action === 'reprogramar_entrevista' && $_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__.'/actions/reprogramar_entrevista.php'; exit; }
if ($action === 'update_empresa_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__.'/actions/update_empresa_profile.php'; exit; }
// Comprobante de postulación (descarga o vista HTML)
if ($action === 'comprobante_postulacion') { require __DIR__.'/pages/comprobante_postulacion.php'; exit; }
if (!isset($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Bolsa de Empleo SENA</title>
  <link rel="icon" type="image/png" href="assets/logoSena.png" />
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <?php require __DIR__.'/templates/header.php'; ?>

  <?php
    if ($view === 'login') {
      require __DIR__.'/pages/login.php';
    } elseif ($view === 'register') {
      require __DIR__.'/pages/register.php';
    } elseif ($view === 'register-empresa') {
      require __DIR__.'/pages/register-empresa.php';
    } elseif ($view === 'register-persona') {
      require __DIR__.'/pages/register-persona.php';
    } elseif ($view === 'perfil_publico' || $view === 'PerfilPublico') {
      // Acepta alias con mayúsculas y carga el archivo correcto en minúsculas
      require __DIR__.'/pages/perfil_publico.php';
    } elseif ($view === 'editar_perfil') {
      require __DIR__.'/pages/editar_perfil.php';
    } elseif ($view === 'oferta_detalle') {
      require __DIR__.'/pages/Ofertaendetalle.php';
    } elseif ($view === 'oferta_empresa_detalle') {
      require __DIR__.'/pages/OfertaEmpresaDetalle.php';
    } elseif ($view === 'mis_postulaciones') {
      require __DIR__.'/pages/mis_postulaciones.php';
    } elseif ($view === 'recuperar') {
      require __DIR__.'/pages/recuperar.php';
    } elseif ($view === 'recuperar_confirmacion') {
      require __DIR__.'/pages/recuperar_confirmacion.php';
    } elseif ($view === 'recuperar_crear') {
      require __DIR__.'/pages/recuperar_crear.php';
    } elseif ($view === 'mis_ofertas_empresa') {
      require __DIR__.'/pages/mis_ofertas_empresa.php';
  } elseif ($view === 'perfil_empresa') {
    require __DIR__.'/pages/perfil_empresa.php';
  } elseif ($view === 'crear_vacante') {
    require __DIR__.'/pages/crear_vacante.php';
  } elseif ($view === 'editar_oferta') {
    require __DIR__.'/pages/editar_oferta.php';
  } elseif ($view === 'editar_perfilEmpresa') {
    require __DIR__.'/pages/editar_perfilEmpresa.php';
  } elseif ($view === 'PerfilEmpresaVistaCandidato') {
    require __DIR__.'/pages/PerfilEmpresaVistaCandidato.php';
  } elseif ($view === 'OfertasEmpresaVistaCandidato') {
    require __DIR__.'/pages/OfertasEmpresaVistaCandidato.php';
  } elseif ($view === 'postulacion_confirmada') {
    require __DIR__.'/pages/PostulacionConfirmada.php';
    } elseif ($view === 'candidatos') {
      require __DIR__.'/pages/candidatos.php';
    } elseif ($view === 'candidatos_review') {
      require __DIR__.'/pages/candidatos_review.php';
    } elseif ($view === 'dashboard') {
      require __DIR__.'/pages/dashboard.php';
    } else {
      require __DIR__.'/pages/home.php';
    }
  ?>

  <?php if ($view !== 'candidatos_review') { require __DIR__.'/templates/footer.php'; } ?>
  <script src="js/script.js"></script>
</body>
</html>
