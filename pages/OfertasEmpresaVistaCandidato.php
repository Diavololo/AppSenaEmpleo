<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_NAME']) !== 'index.php') {
  header('Location: ../index.php?view=OfertasEmpresaVistaCandidato');
  exit;
}
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['type'] ?? '') !== 'persona') {
  header('Location: index.php?view=login');
  exit;
}
require __DIR__.'/db.php';
function oevc_e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function oevc_format_money(?int $min, ?int $max, string $currency): string {
  $currency = strtoupper(trim($currency ?: 'COP'));
  if ($min === null && $max === null) { return 'Salario a convenir'; }
  $fmt = static function (?int $v) use ($currency): string { return ($v === null || $v <= 0) ? '' : $currency.' '.number_format($v, 0, ',', '.'); };
  if ($min !== null && $max !== null) { return $fmt($min).' - '.$fmt($max); }
  if ($min !== null) { return 'Desde '.$fmt($min); }
  return 'Hasta '.$fmt($max);
}

$empresaId = isset($_GET['empresa_id']) ? (int)$_GET['empresa_id'] : 0;
$empresaNombre = null;
$vacantes = [];
$error = null;
if ($empresaId > 0 && ($pdo instanceof PDO)) {
  try {
    $nstmt = $pdo->prepare('SELECT razon_social FROM empresas WHERE id = ? LIMIT 1');
    $nstmt->execute([$empresaId]);
    $empresaNombre = $nstmt->fetchColumn() ?: null;
    $stmt = $pdo->prepare('SELECT v.id, v.titulo, v.ciudad, v.descripcion, v.salario_min, v.salario_max, v.moneda,
                                   m.nombre AS modalidad, c.nombre AS contrato, v.etiquetas
                             FROM vacantes v
                             LEFT JOIN modalidades m ON m.id = v.modalidad_id
                             LEFT JOIN contratos c ON c.id = v.tipo_contrato_id
                             WHERE v.empresa_id = ? AND v.estado IN ("publicada","activa")
                             ORDER BY COALESCE(v.publicada_at, v.created_at) DESC');
    $stmt->execute([$empresaId]);
    $vacantes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $error = 'No fue posible cargar las ofertas.';
    error_log('[OfertasEmpresaVistaCandidato] '.$e->getMessage());
  }
} else {
  $error = 'Empresa no especificada.';
}
?>

<section class="container section">
  <div class="card" style="padding: var(--sp-4);">
    <div class="co-head" style="display:flex; justify-content:space-between; align-items:center;">
      <div>
        <h1>Ofertas de <?= oevc_e($empresaNombre ?? 'Empresa'); ?></h1>
        <p class="muted">Solo se muestran ofertas activas.</p>
      </div>
      <a class="btn btn-secondary" href="index.php?view=PerfilEmpresaVistaCandidato&empresa_id=<?= oevc_e((string)$empresaId); ?>">Volver al perfil</a>
    </div>
    <?php if ($error): ?>
      <div class="card co-alert co-alert--error"><strong><?= oevc_e($error); ?></strong></div>
    <?php endif; ?>

    <?php if ($vacantes): ?>
      <div class="co-main">
        <?php foreach ($vacantes as $v): ?>
          <article class="card co-card" style="margin-top:.8rem;">
            <div class="co-card-head" style="display:flex; justify-content:space-between; align-items:center;">
              <div>
                <h3><?= oevc_e($v['titulo'] ?? 'Oferta'); ?></h3>
                <?php $lead = array_filter([$v['modalidad'] ?? null, $v['contrato'] ?? null, $v['ciudad'] ?? null]); ?>
                <?php if ($lead): ?><p class="muted"><?= oevc_e(implode(' · ', $lead)); ?></p><?php endif; ?>
              </div>
            </div>
            <?php if (!empty($v['descripcion'])): ?>
              <p class="muted"><?= oevc_e($v['descripcion']); ?></p>
            <?php endif; ?>
            <div class="co-meta-row" style="display:flex; gap:1rem;">
              <div>
                <span class="co-meta-label">Salario</span>
                <span class="co-meta-value"><?= oevc_e(oevc_format_money(isset($v['salario_min'])?(int)$v['salario_min']:null, isset($v['salario_max'])?(int)$v['salario_max']:null, (string)($v['moneda'] ?? 'COP'))); ?></span>
              </div>
              <?php if (!empty($v['etiquetas'])): ?>
                <div>
                  <span class="co-meta-label">Chips</span>
                  <span class="co-meta-value"><?= oevc_e($v['etiquetas']); ?></span>
                </div>
              <?php endif; ?>
            </div>
            <div class="co-actions" style="display:flex; gap:.6rem;">
              <a class="btn btn-outline" href="index.php?view=oferta_detalle&id=<?= oevc_e((string)$v['id']); ?>">Ver detalle</a>
              <a class="btn btn-brand" href="index.php?view=oferta_detalle&id=<?= oevc_e((string)$v['id']); ?>&apply=1">Postular</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="card co-empty">
        <h3>No hay ofertas activas en este momento</h3>
        <p class="muted">Vuelve más tarde o revisa otras empresas.</p>
      </div>
    <?php endif; ?>
  </div>
</section>