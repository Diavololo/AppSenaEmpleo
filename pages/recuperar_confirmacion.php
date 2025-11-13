<?php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=recuperar_confirmacion');
  exit;
}
require_once __DIR__.'/../lib/password_reset.php';
$masked = pr_mask_email($_GET['email'] ?? '');
?>
<section class="section container">
  <div class="card" style="max-width: 680px; margin: 0 auto; text-align:center; display:flex; flex-direction:column; gap:1rem; padding:2rem;">
    <div style="inline-size:78px; block-size:78px; border-radius:999px; background:#EAF7EF; border:1px solid #D6EBDD; display:grid; place-items:center; margin:0 auto;">
      <img src="assets/Check.svg" alt="Solicitud enviada" width="40" height="40" loading="lazy" />
    </div>
    <h2>Revisa tu correo</h2>
    <p class="muted">Enviamos un enlace para restablecer tu contraseña a:</p>
    <p style="font-weight:800; color: var(--brand-700);"><?=htmlspecialchars($masked, ENT_QUOTES, 'UTF-8'); ?></p>
    <ul style="text-align:left; margin:0 auto; display:grid; gap:.3rem;">
      <li>El enlace caduca en 60 minutos.</li>
      <li>Si no lo ves, revisa la carpeta Spam.</li>
      <li>Puedes solicitarlo de nuevo si es necesario.</li>
    </ul>
    <div class="actions" style="justify-content:center;">
      <a class="btn btn-primary" href="?view=login">Volver al login</a>
    </div>
  </div>
</section>
