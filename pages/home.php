<?php
// Home pública con datos reales resumidos y sin acciones directas (solo login/registro)
require_once __DIR__.'/db.php';

$landingVacantes = [];
$stats = ['total' => 0, 'remoto' => 0, 'hibrido' => 0, 'presencial' => 0, 'ciudades' => []];

if ($pdo instanceof PDO) {
  try {
    $vStmt = $pdo->query(
      'SELECT v.id, v.titulo, v.descripcion, v.requisitos, v.ciudad,
              v.salario_min, v.salario_max, v.moneda,
              e.razon_social AS empresa, m.nombre AS modalidad
       FROM vacantes v
       LEFT JOIN empresas e ON e.id = v.empresa_id
       LEFT JOIN modalidades m ON m.id = v.modalidad_id
       ORDER BY COALESCE(v.publicada_at, v.created_at) DESC
       LIMIT 6'
    );
    $landingVacantes = $vStmt ? $vStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $stats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM vacantes')->fetchColumn();
    $stats['remoto'] = (int)$pdo->query('SELECT COUNT(*) FROM vacantes v JOIN modalidades m ON m.id = v.modalidad_id WHERE LOWER(m.nombre) LIKE "%remoto%"')->fetchColumn();
    $stats['hibrido'] = (int)$pdo->query('SELECT COUNT(*) FROM vacantes v JOIN modalidades m ON m.id = v.modalidad_id WHERE LOWER(m.nombre) LIKE "%hibr%"')->fetchColumn();
    $stats['presencial'] = (int)$pdo->query('SELECT COUNT(*) FROM vacantes v JOIN modalidades m ON m.id = v.modalidad_id WHERE LOWER(m.nombre) LIKE "%presencial%"')->fetchColumn();
    $cityStmt = $pdo->query('SELECT ciudad, COUNT(*) AS c FROM vacantes WHERE ciudad IS NOT NULL AND ciudad <> "" GROUP BY ciudad ORDER BY c DESC LIMIT 3');
    $stats['ciudades'] = $cityStmt ? $cityStmt->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Throwable $e) {
    error_log('[home] landing data: '.$e->getMessage());
  }
}
?>

<!-- Hero compacto -->
<section class="hero compact">
  <div class="container hero-grid">
    <div class="reveal">
      <h1>Ofertas reales de nuestras empresas aliadas</h1>
      <p class="lead">Vista pública de ejemplo. Inicia sesión o regístrate para postular.</p>

      <div class="hero-cta">
        <a class="btn btn-primary" href="?view=register">Crear cuenta</a>
        <a class="btn btn-secondary" href="?view=login">Ya tengo cuenta</a>
      </div>

      <div class="quick-cats" id="explorar">
        <a class="qcat reveal" href="#listado"><strong>Remoto</strong><span class="muted"><?=$stats['remoto']; ?> ofertas</span></a>
        <a class="qcat reveal" href="#listado"><strong>Híbrido</strong><span class="muted"><?=$stats['hibrido']; ?> ofertas</span></a>
        <a class="qcat reveal" href="#listado"><strong>Presencial</strong><span class="muted"><?=$stats['presencial']; ?> ofertas</span></a>
        <?php if (!empty($stats['ciudades'])): ?>
          <a class="qcat reveal" href="#listado">
            <strong>Top ciudades</strong>
            <span class="muted">
              <?php foreach ($stats['ciudades'] as $idx => $c): ?>
                <?= $idx === 0 ? '' : '· ' ; ?><?=htmlspecialchars($c['ciudad'], ENT_QUOTES, 'UTF-8'); ?>
              <?php endforeach; ?>
            </span>
          </a>
        <?php endif; ?>
        <a class="qcat reveal" href="#listado"><strong>Educación formal</strong><span class="muted">Programas con enfoque TI</span></a>
        <a class="qcat reveal" href="#listado"><strong>Convocatorias</strong><span class="muted">Procesos vigentes</span></a>
      </div>
    </div>
    <div class="mock reveal" aria-hidden="true">
      <div class="mock-frame">
        <img src="https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=1100&q=80" alt="Personas trabajando en equipo" />
      </div>
    </div>
  </div>
</section>

<!-- Ofertas destacadas -->
<section class="section container" id="listado">
  <div class="head-inline">
    <div>
      <h2 class="reveal">Ofertas destacadas</h2>
      <p class="muted reveal">Información real, acciones bloqueadas hasta iniciar sesión.</p>
    </div>
    <a class="btn btn-primary" href="?view=register">Crear cuenta</a>
  </div>
  <div class="grid compact">
    <?php if ($landingVacantes): ?>
      <?php foreach ($landingVacantes as $vac): ?>
        <?php
          $salMin = isset($vac['salario_min']) ? (int)$vac['salario_min'] : null;
          $salMax = isset($vac['salario_max']) ? (int)$vac['salario_max'] : null;
          $mon = strtoupper((string)($vac['moneda'] ?? 'COP'));
          $salary = ($salMin || $salMax)
            ? trim(($salMin ? $mon.' '.number_format($salMin,0,',','.') : '').($salMax ? ' - '.$mon.' '.number_format($salMax,0,',','.') : ''))
            : 'Salario a convenir';
          $desc = $vac['descripcion'] ?: ($vac['requisitos'] ?? '');
          $desc = $desc ? mb_substr(strip_tags((string)$desc), 0, 140).'…' : 'Descripción no disponible.';
        ?>
        <article class="card compact-card reveal">
          <header>
            <h3><?=htmlspecialchars($vac['titulo'] ?? 'Oferta sin título', ENT_QUOTES, 'UTF-8'); ?></h3>
            <span class="pill muted"><?=htmlspecialchars($vac['modalidad'] ?? 'Modalidad', ENT_QUOTES, 'UTF-8'); ?></span>
          </header>
          <p class="muted"><strong><?=htmlspecialchars($vac['empresa'] ?? 'Empresa', ENT_QUOTES, 'UTF-8'); ?></strong> · <?=htmlspecialchars($vac['ciudad'] ?: 'Remoto', ENT_QUOTES, 'UTF-8'); ?></p>
          <p class="muted small"><?=htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></p>
          <div class="meta-row">
            <span class="tag"><?=htmlspecialchars($salary, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="card-cta">
            <a class="btn btn-secondary" href="?view=login">Ver detalle</a>
            <a class="btn btn-primary" href="?view=login">Postular</a>
          </div>
        </article>
      <?php endforeach; ?>
    <?php else: ?>
      <article class="card compact-card">
        <h3>Estamos cargando ofertas</h3>
        <p class="muted">Inicia sesión para ver todas las vacantes disponibles.</p>
        <div class="card-cta">
          <a class="btn btn-primary" href="?view=login">Iniciar sesión</a>
        </div>
      </article>
    <?php endif; ?>
  </div>
</section>

<!-- Cómo funciona (compacto) -->
<section class="section container compact-steps" id="como-funciona">
  <h2 class="reveal">¿Cómo funciona?</h2>
  <div class="steps">
    <div class="step reveal"><strong>1. Crea tu perfil</strong><p class="muted">Sube tu hoja de vida y elige tus intereses.</p></div>
    <div class="step reveal"><strong>2. Explora y guarda</strong><p class="muted">Descubre ofertas y márcalas como favoritas.</p></div>
    <div class="step reveal"><strong>3. Postula en un clic</strong><p class="muted">Activa tu cuenta para aplicar.</p></div>
  </div>
</section>

<!-- Empresas aliadas -->
<section class="section tech" id="aliados">
  <div class="container">
    <h2 class="reveal">Empresas aliadas</h2>
    <p class="muted tc reveal">Organizaciones que confían y colaboran con nuestro programa.</p>
  </div>
  <div class="tech-rail">
    <div class="tech-track">
      <figure class="tech-item reveal">
        <div class="tech-logo placeholder"></div>
        <figcaption class="muted tc">Nombre de empresa</figcaption>
      </figure>
      <figure class="tech-item reveal">
        <div class="tech-logo placeholder"></div>
        <figcaption class="muted tc">Nombre de empresa</figcaption>
      </figure>
      <figure class="tech-item reveal">
        <div class="tech-logo placeholder"></div>
        <figcaption class="muted tc">Nombre de empresa</figcaption>
      </figure>
      <figure class="tech-item reveal">
        <div class="tech-logo placeholder"></div>
        <figcaption class="muted tc">Nombre de empresa</figcaption>
      </figure>
      <figure class="tech-item reveal">
        <div class="tech-logo placeholder"></div>
        <figcaption class="muted tc">Nombre de empresa</figcaption>
      </figure>
    </div>
  </div>
</section>

<!-- CTA compacto -->
<section class="section container cta-compact" id="registro">
  <div class="cta reveal">
    <div>
      <h3>Accede a todas las ofertas</h3>
      <p>Regístrate o inicia sesión para postular, guardar y recibir alertas.</p>
      <div class="cta-actions">
        <a class="btn btn-secondary" href="?view=login">Ya tengo cuenta</a>
        <a class="btn btn-primary" href="?view=register">Crear cuenta</a>
      </div>
    </div>
    <div class="cta-img">
      <img src="https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=900&q=80" alt="Ilustración de registro" />
    </div>
  </div>
</section>

<style>
  .hero.compact{padding:48px 0;}
  .hero-grid{display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); align-items:center; gap:18px;}
  .hero-cta{display:flex; gap:10px; margin:12px 0;}
  .mock{display:flex; justify-content:center; align-items:center; width:100%; aspect-ratio: unset; border:none; box-shadow:none; padding:0;}
  .mock-frame{
    background:#f7fff3;
    border:1px solid #e6f6e2;
    border-radius:20px;
    padding:0;
    box-shadow:0 12px 30px rgba(0,128,0,0.08);
    overflow:hidden;
    width:100%;
    aspect-ratio: 16 / 9;
    min-height: 420px;
  }
  .mock-frame img{width:100%; height:100%; object-fit:cover; display:block;}
  .search.compact{flex-direction:row; gap:8px;}
  .search.compact .input{flex:1;}
  .grid.compact{display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:14px;}
  .compact-card{padding:16px; display:flex; flex-direction:column; gap:8px; min-height:240px;}
  .compact-card header{display:flex; justify-content:space-between; align-items:flex-start; gap:8px;}
  .compact-card .meta-row{margin:4px 0;}
  .compact-card .small{font-size:.92rem;}
  .compact-card .card-cta{margin-top:auto; display:flex; gap:10px; align-items:center; justify-content:flex-start;}
  .head-inline{display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;}
  .cta-compact .cta{padding:18px; border-radius:14px;}
  .cta-compact .cta-img{width:100%; height:100%; max-width:none; aspect-ratio:16/9; border-radius: var(--r-lg); overflow:hidden; border:1px solid rgba(255,255,255,.25);}
  .cta-compact .cta-img img{width:100%; height:100%; max-width:none; object-fit:cover; display:block; border-radius:0;}
  .compact-steps .steps{display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px;}
  .tech-rail{overflow:hidden; padding:12px 0;}
  .tech-track{display:flex; gap:16px; justify-content:center; flex-wrap:wrap;}
  .tech-logo{width:86px; height:64px; border-radius:12px; background:#f8f9fb; border:1px solid #e6e8ed; display:flex; align-items:center; justify-content:center;}
  .tech-logo.placeholder{background:#ffffff;}
  .tech-logo img{max-width:72px; height:auto;}
  .quick-cats{display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-top:12px;}
  .quick-cats .qcat{display:flex; flex-direction:column; gap:2px; padding:12px 14px; border:1px solid #e6e8ed; border-radius:12px; background:#fff; text-decoration:none;}
  .quick-cats .qcat strong{color:#111827;}
  .quick-cats .qcat .muted{color:#4b5563;}
</style>
