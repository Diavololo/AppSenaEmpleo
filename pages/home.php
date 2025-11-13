<!-- Hero + buscador -->
<section class="hero">
  <div class="container hero-grid">
    <div class="reveal">
      <h1>Conecta con <span class="text-brand">oportunidades reales</span> de empresas aliadas SENA</h1>
      <p class="lead">Busca por cargo o habilidad, filtra por ciudad o modalidad. Pensado para aprendices y egresados.</p>

      <form class="search" role="search" aria-label="Buscador de empleos">
        <label class="input" aria-label="Cargo o palabra clave">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="#2E8800" stroke-width="2" stroke-linecap="round"/></svg>
          <input type="search" name="q" placeholder="Ej: Auxiliar contable, React, cocina..." />
        </label>
        <label class="input" aria-label="Ciudad o remoto">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s7-4.5 7-10A7 7 0 0 0 5 11c0 5.5 7 10 7 10Z" stroke="#2E8800" stroke-width="2" stroke-linecap="round"/></svg>
          <input type="text" name="l" placeholder="Ciudad o 'Remoto'" />
        </label>
        <button class="btn btn-primary tooltip" type="button" onclick="window.location.href='?view=login';">
          Buscar<span class="tip">Ejemplo interactivo</span>
        </button>
      </form>

      <div class="spacer"></div>
      <div class="quick-cats" id="explorar">
        <a class="qcat reveal" href="#listado"><strong>Tiempo completo</strong><span class="muted">+120 ofertas</span></a>
        <a class="qcat reveal" href="#listado"><strong>Pr&aacute;cticas</strong><span class="muted">+58 ofertas</span></a>
        <a class="qcat reveal" href="#listado"><strong>Remoto</strong><span class="muted">+94 ofertas</span></a>
        <a class="qcat reveal" href="#listado"><strong>Por ciudad</strong><span class="muted">Bogot&aacute; &middot; Cali &middot; Medell&iacute;n</span></a>
      </div>
    </div>
    <div class="mock reveal">
      <img src="https://picsum.photos/seed/hero-sena/1200/900" alt="Personas trabajando en equipo" />
    </div>
  </div>
</section>

<!-- Ofertas destacadas -->
<section class="section container">
  <h2 class="reveal">Ofertas destacadas</h2>
  <p class="muted reveal">Basadas en tus intereses y tendencias del mercado.</p>
  <div class="spacer"></div>

  <div class="carousel reveal" aria-label="Carrusel de ofertas">
    <button class="ctrl prev" aria-label="Anterior">&#8249;</button>
    <div class="track" id="track">
      <article class="card">
        <h3>Auxiliar de bodega</h3>
        <div class="meta"><strong>Logisur</strong> &middot; Yumbo</div>
        <div class="meta"><span class="tag">Tiempo completo</span> <span class="tag">Turnos</span></div>
        <p class="muted">Apoyo en recepci&oacute;n, inventario y despacho. Deseable manejo de Excel.</p>
        <div class="card-cta">
          <a class="btn btn-secondary" href="#detalle">Ver detalle</a>
          <a class="btn btn-primary" href="?view=login">Postular</a>
        </div>
      </article>
      <article class="card">
        <h3>Desarrollador Laravel</h3>
        <div class="meta"><strong>DevAndes</strong> &middot; Remoto</div>
        <div class="meta"><span class="tag">Junior</span> <span class="tag">Remoto</span></div>
        <p class="muted">Construcci&oacute;n de API y m&oacute;dulos en Laravel/MySQL. Git y buenas pr&aacute;cticas.</p>
        <div class="card-cta">
          <a class="btn btn-secondary" href="#detalle">Ver detalle</a>
          <a class="btn btn-primary" href="?view=login">Postular</a>
        </div>
      </article>
      <article class="card">
        <h3>Asistente Contable</h3>
        <div class="meta"><strong>Finactiva</strong> &middot; Bogot&aacute;</div>
        <div class="meta"><span class="tag">Pr&aacute;cticas</span> <span class="tag">H&iacute;brido</span></div>
        <p class="muted">Apoyo en conciliaciones, causaci&oacute;n y gesti&oacute;n de documentos.</p>
        <div class="card-cta">
          <a class="btn btn-secondary" href="#detalle">Ver detalle</a>
          <a class="btn btn-primary" href="?view=login">Postular</a>
        </div>
      </article>
      <article class="card">
        <h3>Auxiliar de Cocina</h3>
        <div class="meta"><strong>FoodLab</strong> &middot; Medell&iacute;n</div>
        <div class="meta"><span class="tag">Tiempo completo</span> <span class="tag">Turnos</span></div>
        <p class="muted">Mise en place, porcionado y apoyo en producci&oacute;n. Buenas pr&aacute;cticas.</p>
        <div class="card-cta">
          <a class="btn btn-secondary" href="#detalle">Ver detalle</a>
          <a class="btn btn-primary" href="?view=login">Postular</a>
        </div>
      </article>
    </div>
    <button class="ctrl next" aria-label="Siguiente">&#8250;</button>
  </div>
</section>

<!-- C&oacute;mo funciona -->
<section class="section container" id="como-funciona">
  <h2 class="reveal">&iquest;C&oacute;mo funciona?</h2>
  <div class="steps">
    <div class="step reveal"><strong>1. Crea tu perfil</strong><p class="muted">Sube tu hoja de vida y elige tus intereses.</p></div>
    <div class="step reveal"><strong>2. Explora y filtra</strong><p class="muted">Usa el buscador y filtros para encontrar ofertas.</p></div>
    <div class="step reveal"><strong>3. Postula en un clic</strong><p class="muted">Haz seguimiento desde tu panel.</p></div>
  </div>
</section>

<!-- Tecnolog&iacute;as -->
<section class="section tech" id="tecnologias">
  <div class="container">
    <h2>Tecnolog&iacute;as con las que trabajamos</h2>
    <p class="muted tc">Frontend, Backend, Datos y DevOps. Reemplaza los logos cuando gustes.</p>
  </div>
  <div class="tech-rail">
    <div class="tech-track">
      <figure class="tech-item">
        <div class="tech-logo"><img src="assets/HTML.svg" alt="HTML" /></div>
        <figcaption class="muted tc">HTML</figcaption>
      </figure>
      <figure class="tech-item">
        <div class="tech-logo"><img src="assets/CSS.svg" alt="CSS" /></div>
        <figcaption class="muted tc">CSS</figcaption>
      </figure>
      <figure class="tech-item">
        <div class="tech-logo"><img src="assets/bootstrap.svg" alt="Bootstrap" /></div>
        <figcaption class="muted tc">Bootstrap</figcaption>
      </figure>
      <figure class="tech-item">
        <div class="tech-logo"><img src="assets/php.svg" alt="PHP" /></div>
        <figcaption class="muted tc">PHP</figcaption>
      </figure>
      <figure class="tech-item">
        <div class="tech-logo"><img src="assets/Logo-laravel.svg fill.svg" alt="Laravel" /></div>
        <figcaption class="muted tc">Laravel</figcaption>
      </figure>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="section container">
  <div class="cta reveal" id="registro">
    <div>
      <h3>&iquest;Listo para empezar? Crea tu perfil en minutos</h3>
      <p>Conecta con empresas que conf&iacute;an en el SENA. Guarda ofertas, recibe alertas y postula en un clic.</p>
      <div class="cta-actions">
        <a class="btn btn-secondary" href="?view=login">Ya tengo cuenta</a>
        <a class="btn btn-primary" href="?view=register">Crear cuenta</a>
      </div>
    </div>
    <div class="cta-img">
      <img src="https://picsum.photos/seed/cta-sena/900/700" alt="Ilustraci&oacute;n de registro" />
    </div>
  </div>
</section>
