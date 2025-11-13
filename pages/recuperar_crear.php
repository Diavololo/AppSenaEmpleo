<?php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=recuperar_crear');
  exit;
}

require_once __DIR__.'/db.php';
require_once __DIR__.'/../lib/password_reset.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashType = 'error';
$flashMessage = '';
if (is_array($flash)) {
  $flashType = (string)($flash['type'] ?? 'error');
  $flashMessage = trim((string)($flash['message'] ?? ''));
} elseif (is_string($flash)) {
  $flashMessage = trim($flash);
}

$token = trim((string)($_GET['token'] ?? ''));
$masked = 'ana***@ejemplo.com';
$error = null;

if ($token === '') {
  $error = 'El enlace no es válido. Solicita uno nuevo.';
} elseif (!($pdo instanceof PDO)) {
  $error = 'No hay conexión con la base de datos.';
} else {
  $reset = pr_get_reset_by_token($pdo, $token);
  if ($reset) {
    $masked = pr_mask_email((string)$reset['email']);
  } else {
    $error = 'El enlace ya expiró o fue utilizado.';
  }
}
?>

<section class="section reset-shell">
  <div class="container reset-container">
        <div class="reset-card card">
      <div class="reset-head">
        <h1>Crear nueva contraseña</h1>
        <p class="muted">Para la cuenta: <strong><?=htmlspecialchars($masked, ENT_QUOTES, 'UTF-8'); ?></strong></p>
      </div>

      <?php if ($flashMessage !== ''): ?>
        <div class="reset-alert <?=$flashType === 'success' ? 'is-success' : 'is-error'; ?>">
          <span><?=htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="reset-empty">
          <p><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
          <a class="btn btn-primary" href="?view=recuperar">Solicitar nuevo enlace</a>
        </div>
      <?php else: ?>
        <form class="reset-form" method="post" action="index.php?action=password_reset_update">
          <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="token" value="<?=htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>" />

          <div class="reset-field">
            <label for="new_password">Nueva contraseña *</label>
            <input type="password" id="new_password" name="new_password" required autocomplete="new-password" />
            <div class="reset-progress" id="resetStrength">
              <span class="reset-progress-bar"><span></span></span>
              <small id="resetStrengthLabel">Ingresa una contraseña segura.</small>
            </div>
            <p class="reset-hint">Mínimo 8 caracteres, incluye mayúscula, minúscula, número y símbolo.</p>
          </div>

          <div class="reset-field">
            <label for="confirm_password">Confirmar contraseña *</label>
            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" />
          </div>

          <div class="reset-actions">
            <button type="submit" class="btn btn-primary">Guardar nueva contraseña</button>
            <a class="btn btn-secondary" href="?view=login">Cancelar</a>
          </div>

          <p class="muted reset-note">Si no solicitaste este cambio, ignora este mensaje; el enlace expirará automáticamente.</p>
        </form>
      <?php endif; ?>
    </div>

    <footer class="reset-foot">
      <small>© <?=date('Y'); ?> SENA · Bolsa de Empleo</small>
      <div>
        <a href="#">Términos</a>
        <span>·</span>
        <a href="#">Privacidad</a>
      </div>
    </footer>
  </div>
</section>

<script>
(function () {
  const input = document.getElementById('new_password');
  const meter = document.getElementById('resetStrength');
  if (!input || !meter) { return; }
  const bar = meter.querySelector('.reset-progress-bar span');
  const label = document.getElementById('resetStrengthLabel');

  const evaluate = (value) => {
    if (!value) { return { percent: 0, text: 'Ingresa una contraseña segura.' }; }
    let score = 0;
    if (value.length >= 8) { score++; }
    if (/[A-Z]/.test(value)) { score++; }
    if (/[a-z]/.test(value)) { score++; }
    if (/[0-9]/.test(value)) { score++; }
    if (/[^A-Za-z0-9]/.test(value)) { score++; }
    const percent = Math.min(100, (score / 5) * 100);
    const text = score >= 4 ? 'Contraseña fuerte.' : 'Sigue agregando variedad para mayor seguridad.';
    return { percent, text };
  };

  const update = () => {
    const { percent, text } = evaluate(input.value);
    bar.style.width = percent + '%';
    label.textContent = text;
  };

  input.addEventListener('input', update);
  update();
})();
</script>
