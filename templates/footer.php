<?php $currentView = $view ?? ($_GET['view'] ?? null); ?>
<?php
  $appViews = [
    'dashboard','mis_postulaciones','perfil_publico','editar_perfil','oferta_detalle','Ofertaendetalle','oferta_empresa_detalle','postulacion_confirmada',
    'mis_ofertas_empresa','perfil_empresa','crear_vacante','editar_oferta','candidatos','candidatos_review'
  ];
?>

<?php if (in_array($currentView, $appViews, true)): ?>
  <footer class="footer" id="footer">
    <div class="container legal-row">
      <span>&copy; 2025 SENA - Bolsa de Empleo</span>
      <span><a href="#legal">Terminos</a> - <a href="#privacidad">Privacidad</a></span>
    </div>
  </footer>
<?php else: ?>
  <footer class="footer" id="footer">
    <div class="container grid">
      <div>
        <div class="brand mb-2">
          <img src="assets/logoSena.png" alt="Logo SENA" />
          <span>Bolsa de Empleo SENA</span>
        </div>
        <p class="muted">Plataforma para aprendices y egresados, en alianza con empresas.</p>
        <label class="sr-only" for="newsletter-email">Ingresa tu correo para recibir novedades</label>
        <div class="news">
          <input id="newsletter-email" type="email" placeholder="Tu correo" aria-describedby="newsletter-hint" />
          <button class="btn btn-primary" type="button">Suscribirme</button>
        </div>
        <p class="news-hint" id="newsletter-hint">Te enviaremos actualizaciones relevantes; puedes darte de baja en cualquier momento.</p>
      </div>
      <div>
        <h4>Explorar</h4>
        <ul role="list">
          <li><a href="index.php#explorar">Por categoria</a></li>
          <li><a href="index.php#listado">Todas las ofertas</a></li>
          <li><a href="index.php#como-funciona">Como funciona</a></li>
        </ul>
      </div>
      <div>
        <h4>Para empresas</h4>
        <ul role="list">
          <li><a href="#publicar">Publicar una oferta</a></li>
          <li><a href="#convenios">Convenios SENA</a></li>
          <li><a href="#contacto">Contacto</a></li>
        </ul>
      </div>
      <div>
        <h4>Recursos</h4>
        <ul role="list">
          <li><a href="#tips">Consejos de empleo</a></li>
          <li><a href="#cv">Plantillas de CV</a></li>
          <li><a href="#ayuda">Centro de ayuda</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-legal">
      <div class="container legal-row">
        <span>&copy; 2025 SENA - Hecho con dedicacion para aprendices</span>
        <span><a href="#legal">Terminos</a> - <a href="#privacidad">Privacidad</a></span>
      </div>
    </div>
  </footer>
<?php endif; ?>
