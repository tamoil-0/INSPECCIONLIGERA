-- Panel administrativo seguro: cambios aditivos, sin borrar datos maestros.
-- Compatible con MariaDB 10.4+ y reejecutable.

CREATE TABLE IF NOT EXISTS `lineas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `ubicacion` VARCHAR(255) DEFAULT NULL,
  `activa` TINYINT(1) NOT NULL DEFAULT 1,
  `creada_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizada_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `desactivada_en` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lineas_proyecto_nombre` (`proyecto_id`, `nombre`),
  KEY `idx_lineas_proyecto_activa` (`proyecto_id`, `activa`, `nombre`),
  CONSTRAINT `fk_lineas_proyecto`
    FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `lineas` (`proyecto_id`, `nombre`, `ubicacion`)
SELECT `proyecto_id`, `linea`, MAX(`ubicaciones`)
FROM `postes`
WHERE TRIM(`linea`) <> ''
GROUP BY `proyecto_id`, `linea`;

ALTER TABLE `postes`
  ADD COLUMN IF NOT EXISTS `activo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `ubicaciones`,
  ADD COLUMN IF NOT EXISTS `desactivado_en` DATETIME DEFAULT NULL AFTER `activo`,
  ADD COLUMN IF NOT EXISTS `ultima_limpieza_admin` DATETIME DEFAULT NULL AFTER `desactivado_en`;

ALTER TABLE `poste_secciones_rst`
  ADD COLUMN IF NOT EXISTS `activo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `fase`,
  ADD COLUMN IF NOT EXISTS `archivado_en` DATETIME DEFAULT NULL AFTER `activo`;

ALTER TABLE `imagenes_poste`
  ADD COLUMN IF NOT EXISTS `activo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `fecha_subida`,
  ADD COLUMN IF NOT EXISTS `archivado_en` DATETIME DEFAULT NULL AFTER `activo`;

ALTER TABLE `imagenes_poste_estado`
  ADD COLUMN IF NOT EXISTS `activo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sincronizada`,
  ADD COLUMN IF NOT EXISTS `archivado_en` DATETIME DEFAULT NULL AFTER `activo`;

CREATE INDEX IF NOT EXISTS `idx_postes_admin_estado`
  ON `postes` (`proyecto_id`, `linea`, `activo`, `id`);

CREATE TABLE IF NOT EXISTS `admin_auditoria` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` INT UNSIGNED DEFAULT NULL,
  `usuario_nombre` VARCHAR(100) NOT NULL,
  `accion` VARCHAR(64) NOT NULL,
  `entidad_tipo` VARCHAR(32) NOT NULL,
  `proyecto_id` INT UNSIGNED DEFAULT NULL,
  `linea` VARCHAR(100) DEFAULT NULL,
  `poste_id` INT UNSIGNED DEFAULT NULL,
  `antes_json` LONGTEXT DEFAULT NULL,
  `despues_json` LONGTEXT DEFAULT NULL,
  `resultado` ENUM('ok','error') NOT NULL,
  `detalle` VARCHAR(500) DEFAULT NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_auditoria_fecha` (`creado_en`),
  KEY `idx_admin_auditoria_entidad` (`entidad_tipo`, `proyecto_id`, `linea`, `poste_id`),
  KEY `idx_admin_auditoria_usuario` (`usuario_id`, `creado_en`),
  CONSTRAINT `fk_admin_auditoria_usuario`
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
