-- Esquema para soportar caché de embeddings y evaluaciones IA de postulaciones.
-- Ejecutar en MySQL 8+ (usa IF NOT EXISTS en columnas).
-- Ajusta los nombres de BD/esquema si es necesario antes de ejecutar.

-- 1) Caché de embeddings de vacantes (por si no existe o está incompleta)
CREATE TABLE IF NOT EXISTS vacante_embeddings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vacante_id BIGINT UNSIGNED NOT NULL,
  embedding LONGTEXT NOT NULL, -- JSON del vector
  norm DOUBLE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_vacante_embeddings_vacante (vacante_id),
  CONSTRAINT fk_vac_emb_vac FOREIGN KEY (vacante_id) REFERENCES vacantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Caché de embeddings de candidatos (paralelo a vacante_embeddings)
CREATE TABLE IF NOT EXISTS candidate_embeddings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  embedding LONGTEXT NOT NULL, -- JSON del vector
  norm DOUBLE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_candidate_embeddings_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Evaluación IA de una postulación (resumen + datos explicativos)
CREATE TABLE IF NOT EXISTS postulacion_eval (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  postulacion_id BIGINT UNSIGNED NOT NULL,
  vacante_id BIGINT UNSIGNED NOT NULL,
  candidato_email VARCHAR(255) NOT NULL,
  resumen TEXT NULL, -- breve resumen IA
  datos TEXT NULL,   -- puntos fuertes/gaps
  modelo VARCHAR(100) NULL, -- modelo usado (p.ej. text-embedding-3-small / gpt-4o-mini)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_eval_postulacion (postulacion_id),
  KEY idx_eval_vac_cand (vacante_id, candidato_email),
  CONSTRAINT fk_eval_postulacion FOREIGN KEY (postulacion_id) REFERENCES postulaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_eval_vacante FOREIGN KEY (vacante_id) REFERENCES vacantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Columnas de caché de match en postulaciones (si aún no existen)
-- Uso de guardas vía information_schema para evitar error de columna duplicada.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'postulaciones' AND COLUMN_NAME = 'match_score'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE postulaciones ADD COLUMN match_score DECIMAL(6,2) NULL AFTER estado',
  'SELECT ''match_score ya existe, sin cambios''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'postulaciones' AND COLUMN_NAME = 'match_updated_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE postulaciones ADD COLUMN match_updated_at DATETIME NULL AFTER match_score',
  'SELECT ''match_updated_at ya existe, sin cambios''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
