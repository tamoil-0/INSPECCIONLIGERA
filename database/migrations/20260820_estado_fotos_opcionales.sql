-- Estado por tipo de foto capturada.
-- Seguro para datos existentes: no elimina ni reemplaza filas.

ALTER TABLE `postes`
  ADD COLUMN IF NOT EXISTS `inspeccion_finalizada` TINYINT(1) NOT NULL DEFAULT 0
  AFTER `formulario_subido`;

-- Compatibilidad historica: actualiza-datos.php solo marcaba formulario_subido
-- cuando el movil enviaba una inspeccion ya finalizada.
UPDATE `postes`
SET `inspeccion_finalizada` = 1
WHERE `formulario_subido` = 1
  AND `inspeccion_finalizada` = 0;

CREATE TABLE IF NOT EXISTS `imagenes_poste_estado` (
  `poste_id` INT UNSIGNED NOT NULL,
  `nombre_foto` VARCHAR(64) NOT NULL,
  `sincronizada` TINYINT(1) NOT NULL DEFAULT 0,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`poste_id`, `nombre_foto`),
  KEY `idx_imagenes_poste_estado_sync` (`poste_id`, `sincronizada`),
  CONSTRAINT `fk_imagenes_poste_estado_poste`
    FOREIGN KEY (`poste_id`) REFERENCES `postes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada imagen historica representa una foto capturada. INSERT IGNORE conserva
-- cualquier estado pendiente ya registrado si la migracion se ejecuta de nuevo.
INSERT IGNORE INTO `imagenes_poste_estado` (`poste_id`, `nombre_foto`, `sincronizada`)
SELECT `poste_id`, `nombre_foto`, `sincronizada`
FROM `imagenes_poste`;

-- Recalcula solo las dos banderas derivadas, usando capturadas = sincronizadas.
-- La cantidad esperada (28) no participa en la decision.
UPDATE `postes` p
LEFT JOIN (
  SELECT
    `poste_id`,
    COUNT(*) AS `capturadas`,
    SUM(CASE WHEN `sincronizada` = 1 THEN 1 ELSE 0 END) AS `sincronizadas`
  FROM `imagenes_poste_estado`
  GROUP BY `poste_id`
) f ON f.`poste_id` = p.`id`
SET
  p.`imagenes_subidas` = IF(
    COALESCE(f.`capturadas`, 0) > 0
    AND COALESCE(f.`capturadas`, 0) = COALESCE(f.`sincronizadas`, 0),
    1,
    0
  ),
  p.`sincronizado` = IF(
    p.`inspeccion_finalizada` = 1
    AND COALESCE(f.`capturadas`, 0) > 0
    AND COALESCE(f.`capturadas`, 0) = COALESCE(f.`sincronizadas`, 0),
    1,
    0
  );
