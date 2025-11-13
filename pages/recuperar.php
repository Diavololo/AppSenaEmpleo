<?php
if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=recuperar');
  exit;
}
if (!isset($_SESSION)) { session_start(); }
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$oldEmail = $_SESSION['recover_old_email'] ?? '';
unset($_SESSION['recover_old_email']);
?>
<section class="section container">
  <div class="card" style="max-width: 860px; margin: 0 auto; display:flex; flex-direction:column; gap:1.5rem;">
    <div>
      <h2>Encuentra tu cuenta</h2>
      <p class="muted">Ingresa tu correo para buscar tu cuenta.</p>
    </div>
    <?php if ($flash): ?>
      <?php
        $class = ($flash['type'] ?? 'error') === 'success'
          ? 'background:#EAF7EF;border:1px solid #CFE6CA;color:#255C2C;'
          : 'background:#FDECEA;border:1px solid #F5C2C7;color:#842029;';
      ?>
      <div class="card" style="<?=$class?>">
        <strong><?=htmlspecialchars($flash['message'] ?? (string)$flash, ENT_QUOTES, 'UTF-8');?></strong>
      </div>
    <?php endif; ?>
    <form
      class="card"
      style="padding:1.5rem; display:flex; flex-direction:column; gap:1.25rem; max-width:520px; width:100%; margin:0 auto;"
      action="index.php?action=password_reset_request"
      method="post"
    >
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
      <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
        <label for="recover_email" style="min-width:140px; font-weight:600; color:var(--text);">
          Correo electrónico
        </label>
        <input
          id="recover_email"
          type="email"
          name="email"
          placeholder="tucorreo@ejemplo.com"
          value="<?=htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8'); ?>"
          required
          style="flex:1; min-width:220px; padding:.85rem 1rem; border:1px solid var(--border); border-radius:12px; font-size:1rem;"
        />
      </div>
      <div class="actions" style="display:flex; gap:.8rem;">
        <button class="btn btn-primary" type="submit">Buscar cuenta</button>
        <a class="btn btn-secondary" href="?view=login">Cancelar</a>
      </div>
    </form>
  </div>
</section>
