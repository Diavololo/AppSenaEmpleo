<?php
  $currentView = $view ?? ($_GET['view'] ?? null);
  $sessionUser = $_SESSION['user'] ?? null;
  $sessionName = null;
  $sessionPanel = null;

  if (is_array($sessionUser)) {
    $sessionPanel = ($sessionUser['type'] ?? '') === 'empresa'
      ? '?view=mis_ofertas_empresa'
      : '?view=dashboard';
    $sessionName = $sessionUser['display_name']
      ?? $sessionUser['nombre']
      ?? $sessionUser['empresa']
      ?? $sessionUser['email']
      ?? null;
  }
  $isEmpresa = is_array($sessionUser) && (($sessionUser['type'] ?? '') === 'empresa');
?>
<header class="header">
  <?php
    // Vistas de cuenta (candidato).
    $accountViews = ['dashboard','mis_postulaciones','oferta_detalle','editar_perfil','postulacion_confirmada','perfil_publico','PerfilEmpresaVistaCandidato','OfertasEmpresaVistaCandidato'];
    $centerNav = ($currentView === 'recuperar' || $currentView === 'recuperar_confirmacion' || $currentView === 'recuperar_crear');
    $empresaViewingCandidate = ($currentView === 'perfil_publico' && $isEmpresa);
  ?>
  <div class="container nav <?php echo $centerNav ? 'nav--center' : ''; ?>">
    <a href="index.php" class="brand">
      <img src="assets/logoSena.png" alt="Logo SENA" />
      <span>Bolsa de Empleo SENA<?php echo (in_array($currentView, ['candidatos','candidatos_review'], true) || $empresaViewingCandidate) ? ' - Empresas' : ''; ?></span>
    </a>
    <?php if (in_array($currentView, ['candidatos','candidatos_review'], true) || $empresaViewingCandidate): ?>
      <ul class="tabs" role="list">
        <li><a class="link text-brand" href="?view=mis_ofertas_empresa">Mis ofertas</a></li>
        <li><a class="link" href="?view=perfil_empresa">Mi perfil</a></li>
      </ul>
      <div class="actions">
        <a class="btn btn-secondary" href="?view=mis_ofertas_empresa">Volver</a>
        <a class="btn btn-primary" href="?view=candidatos&action=export_csv">Exportar CSV</a>
      </div>
    <?php elseif ($currentView === 'mis_ofertas_empresa' || $currentView === 'perfil_empresa' || $currentView === 'crear_vacante' || $currentView === 'editar_oferta' || $currentView === 'editar_perfilEmpresa' || $currentView === 'oferta_empresa_detalle'): ?>
      <ul class="tabs" role="list">
        <li><a class="link <?php echo ($currentView === 'mis_ofertas_empresa') ? 'is-active' : ''; ?>" href="?view=mis_ofertas_empresa">Mis ofertas</a></li>
        <li><a class="link <?php echo (in_array($currentView, ['perfil_empresa','editar_perfilEmpresa'], true)) ? 'is-active' : ''; ?>" href="?view=perfil_empresa">Mi perfil</a></li>
      </ul>
      <div class="actions">
        <?php if ($sessionName): ?>
        <?php endif; ?>
        <a class="btn btn-secondary" href="index.php?action=logout">Salir</a>
        <?php if ($currentView !== 'crear_vacante'): ?>
          <a class="btn btn-primary" href="index.php?view=crear_vacante">+ Crear vacante</a>
        <?php endif; ?>
      </div>
    <?php elseif (in_array($currentView, $accountViews, true)): ?>
      <ul class="tabs" role="list">
        <li><a class="link <?php echo ($currentView === 'dashboard' || $currentView === 'home' || !$currentView) ? 'is-active' : ''; ?>" href="?view=dashboard">Inicio</a></li>
        <li><a class="link <?php echo ($currentView === 'mis_postulaciones') ? 'is-active' : ''; ?>" href="?view=mis_postulaciones">Mis postulaciones</a></li>
        <li><a class="link <?php echo (in_array($currentView, ['perfil_publico','editar_perfil'], true)) ? 'is-active' : ''; ?>" href="?view=perfil_publico">Mi perfil</a></li>
      </ul>
      <div class="actions">
        <a class="btn btn-primary" href="index.php?action=logout">Cerrar sesion</a>
      </div>
    <?php elseif ($currentView === 'recuperar' || $currentView === 'recuperar_confirmacion' || $currentView === 'recuperar_crear'): ?>
      <!-- Navbar centrado solo con marca (logo + texto) para recuperacion -->
    <?php else: ?>
      <ul role="list">
        <li><a class="link" href="index.php#explorar">Explorar empleos</a></li>
        <li><a class="link" href="index.php#tecnologias">Tecnologias</a></li>
        <li><a class="link" href="index.php#como-funciona">Como funciona</a></li>
      </ul>
      <div class="actions">
        <?php if ($sessionUser): ?>
          <a class="btn btn-primary" href="index.php?action=logout">Cerrar sesion</a>
        <?php else: ?>
          <a class="btn btn-secondary" href="?view=login">Iniciar sesion</a>
          <?php if (!in_array($currentView, ['register','register-empresa'], true)): ?>
            <a class="btn btn-primary" href="?view=register">Crear cuenta</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</header>
