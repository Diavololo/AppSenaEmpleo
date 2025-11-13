<?php
declare(strict_types=1);

if (!function_exists('pe_ensure_log_table')) {
  function pe_ensure_log_table(PDO $pdo): void
  {
    static $ensured = false;
    if ($ensured) {
      return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS postulacion_eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  postulacion_id BIGINT UNSIGNED DEFAULT NULL,
  vacante_id BIGINT UNSIGNED NOT NULL,
  candidato_email VARCHAR(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  estado VARCHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  actor VARCHAR(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sistema',
  nota VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_postulacion_evento (vacante_id, candidato_email),
  KEY idx_postulacion_evento_estado (estado),
  CONSTRAINT fk_postulacion_evento_postulacion
    FOREIGN KEY (postulacion_id)
    REFERENCES postulaciones (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    try {
      $pdo->exec($sql);
    } catch (Throwable $e) {
      // Si la tabla no puede crearse no bloqueamos el flujo principal.
      error_log('[postulacion_eventos] unable to ensure table: '.$e->getMessage());
    }

    $ensured = true;
  }
}

if (!function_exists('pe_log_event')) {
  /**
   * @param array{actor?:string, nota?:string} $options
   */
  function pe_log_event(PDO $pdo, int $postulacionId, int $vacanteId, string $email, string $estado, array $options = []): void
  {
    pe_ensure_log_table($pdo);

    $actor = $options['actor'] ?? 'sistema';
    $nota = $options['nota'] ?? null;

    try {
      $stmt = $pdo->prepare(
        'INSERT INTO postulacion_eventos (postulacion_id, vacante_id, candidato_email, estado, actor, nota)
         VALUES (?, ?, ?, ?, ?, ?)'
      );
      $stmt->execute([
        $postulacionId ?: null,
        $vacanteId,
        strtolower(trim($email)),
        strtolower(trim($estado)),
        strtolower(trim($actor)),
        $nota,
      ]);
    } catch (Throwable $e) {
      error_log('[postulacion_eventos] log failed: '.$e->getMessage());
    }
  }
}

if (!function_exists('pe_fetch_events')) {
  /**
   * @return array<int,array{estado:string,actor:string,nota:?string,created_at:string}>
   */
  function pe_fetch_events(PDO $pdo, int $vacanteId, string $email, int $limit = 10): array
  {
    pe_ensure_log_table($pdo);

    try {
      $stmt = $pdo->prepare(
        'SELECT estado, actor, nota, created_at
         FROM postulacion_eventos
         WHERE vacante_id = ? AND candidato_email = ?
         ORDER BY created_at DESC
         LIMIT ?'
      );
      $stmt->bindValue(1, $vacanteId, PDO::PARAM_INT);
      $stmt->bindValue(2, strtolower(trim($email)), PDO::PARAM_STR);
      $stmt->bindValue(3, $limit, PDO::PARAM_INT);
      $stmt->execute();
      return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      error_log('[postulacion_eventos] fetch failed: '.$e->getMessage());
      return [];
    }
  }
}
