<?php
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$flashMessage = null;
$flashType = 'info';
if (is_array($flash)) {
  $flashMessage = (string)($flash['message'] ?? '');
  $flashType = (string)($flash['type'] ?? 'info');
} elseif (is_string($flash)) {
  $map = [
    'csrf_error' => 'Por seguridad, vuelve a intentar iniciar sesión.',
    'missing_fields' => 'Ingresa tu correo y contraseña.',
  ];
  $flashMessage = $map[$flash] ?? $flash;
  $flashType = 'error';
}

$loginOld = $_SESSION['login_old'] ?? null;
$personaEmail = '';
$empresaEmail = '';
if (is_array($loginOld)) {
  if (($loginOld['type'] ?? '') === 'persona') {
    $personaEmail = (string)($loginOld['email'] ?? '');
  } elseif (($loginOld['type'] ?? '') === 'empresa') {
    $empresaEmail = (string)($loginOld['email'] ?? '');
  }
}
unset($_SESSION['login_old']);

$flashStyle = '';
if ($flashMessage !== null) {
  $flashStyle = $flashType === 'success'
    ? 'border-color:#d6f5d6;background:#f3fff3'
    : ($flashType === 'info'
      ? 'border-color:#cce1ff;background:#f0f6ff'
      : 'border-color:#ffd5d5;background:#fff6f6');
}
?>
<section class="section container portal portal-login">
  <div class="portal-head">
    <h1 class="tc">¿Cómo deseas iniciar sesión?</h1>
    <p class="muted tc">Accede a tu cuenta como <a href="?view=register" class="text-brand">candidato</a> o como <a href="?view=register" class="text-brand">empresa</a>.</p>
  </div>

  <?php if ($flashMessage):
    $flashAria = ($flashType === 'error') ? 'role="alert" aria-live="assertive"' : 'role="status" aria-live="polite"';
  ?>
    <div class="card" style="<?=$flashStyle?>" <?=$flashAria?>><strong><?=htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8')?></strong></div>
  <?php endif; ?>

  <section class="split">
    <!-- TARJETA: Personas -->
    <div class="tile" aria-label="Iniciar sesión como persona">
      <div class="tile-media">
        <img src="https://images.unsplash.com/photo-1517423440428-a5a00ad493e8?q=80&w=1600&auto=format&fit=crop" alt="Persona iniciando sesión" />
        <div class="overlay"></div>
      </div>
      <div class="tile-body">
        <h2>Personas</h2>
        <p>Encuentra empleo con aliados del SENA.</p>
        <p class="form-hint" id="persona-login-hint">Usa el correo con el que registraste tu hoja de vida en la plataforma.</p>
        <form class="form-panel" action="?action=login" method="post">
          <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="user_type" value="persona" />

          <div class="field mb-2">
            <label for="email-persona">Correo electrónico</label>
            <input id="email-persona" name="email" type="email" placeholder="tucorreo@ejemplo.com" value="<?=htmlspecialchars($personaEmail, ENT_QUOTES, 'UTF-8')?>" aria-describedby="persona-login-hint" required />
          </div>

          <div class="field mb-2">
            <label for="password-persona">Contraseña</label>
            <input id="password-persona" name="password" type="password" placeholder="********" required />
          </div>

          <div class="row mb-2">
            <label style="display:flex; align-items:center; gap:.4rem;"><input type="checkbox" name="remember" /> Recordarme</label>
            <a href="?view=recuperar">¿Olvidaste tu contraseña?</a>
          </div>

          <button type="submit" class="btn btn-primary btn-block">Iniciar sesión</button>
        </form>
        <p class="hint">¿No tienes cuenta? <a href="?view=register" class="text-brand">Crear cuenta</a></p>
      </div>
    </div>

    <!-- TARJETA: Empresas -->
    <div class="tile tile-muted" aria-label="Iniciar sesión como empresa">
      <div class="tile-media">
        <img src="https://images.unsplash.com/photo-1553877522-43269d4ea984?q=80&w=1600&auto=format&fit=crop" alt="Empresa iniciando sesión" />
        <div class="overlay"></div>
      </div>
      <div class="tile-body">
        <h2>Empresas</h2>
        <p>Publica vacantes y gestiona postulaciones.</p>
        <p class="form-hint" id="empresa-login-hint">Usa el correo corporativo autorizado para tu empresa.</p>
        <form class="form-panel" action="?action=login" method="post">
          <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="user_type" value="empresa" />

          <div class="field mb-2">
            <label for="email-empresa">Correo corporativo</label>
            <input id="email-empresa" name="email" type="email" placeholder="talento@empresa.com" value="<?=htmlspecialchars($empresaEmail, ENT_QUOTES, 'UTF-8')?>" aria-describedby="empresa-login-hint" required />
          </div>

          <div class="field mb-2">
            <label for="password-empresa">Contraseña</label>
            <input id="password-empresa" name="password" type="password" placeholder="********" required />
          </div>

          <div class="row mb-2">
            <label style="display:flex; align-items:center; gap:.4rem;"><input type="checkbox" name="remember" /> Recordarme</label>
            <a href="?view=recuperar">¿Olvidaste tu contraseña?</a>
          </div>

          <button type="submit" class="btn btn-primary btn-block">Iniciar sesión</button>
        </form>
        <p class="hint">¿Aún no eres aliado? <a href="?view=register" class="text-brand">Crear cuenta empresa</a></p>
      </div>
    </div>
  </section>
</section>
