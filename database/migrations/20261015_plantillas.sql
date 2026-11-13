-- Plantillas de inspección configurables por proyecto (2026-10-15).
-- Aditiva y reejecutable (MariaDB 10.4+). No toca poste_datos, imagenes_poste
-- ni el RST: los proyectos sin plantilla (plantilla_id NULL) siguen igual.
-- El API también la aplica sola la primera vez (plantillasAsegurarEsquema) y
-- siembra la plantilla base «Electro Puno 60 kV (base)» con sus logos.

CREATE TABLE IF NOT EXISTS `plantillas` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `nombre` VARCHAR(150) NOT NULL,
          `descripcion` VARCHAR(500) DEFAULT NULL,
          `version` INT UNSIGNED NOT NULL DEFAULT 1,
          `config` LONGTEXT NOT NULL,
          `activa` TINYINT(1) NOT NULL DEFAULT 1,
          `creado_por` INT UNSIGNED DEFAULT NULL,
          `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_plantillas_nombre` (`nombre`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plantilla_versiones` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `plantilla_id` INT UNSIGNED NOT NULL,
          `version` INT UNSIGNED NOT NULL,
          `config` LONGTEXT NOT NULL,
          `creado_por` INT UNSIGNED DEFAULT NULL,
          `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_plantilla_version` (`plantilla_id`, `version`),
          CONSTRAINT `fk_plantilla_versiones_plantilla`
            FOREIGN KEY (`plantilla_id`) REFERENCES `plantillas` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poste_respuestas` (
          `poste_id` INT UNSIGNED NOT NULL,
          `plantilla_id` INT UNSIGNED NOT NULL,
          `plantilla_version` INT UNSIGNED NOT NULL,
          `respuestas` LONGTEXT NOT NULL,
          `actualizado_por` INT UNSIGNED DEFAULT NULL,
          `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`poste_id`),
          KEY `idx_poste_respuestas_plantilla` (`plantilla_id`),
          CONSTRAINT `fk_poste_respuestas_poste`
            FOREIGN KEY (`poste_id`) REFERENCES `postes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `imagenes_plantilla` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `poste_id` INT UNSIGNED NOT NULL,
          `foto_clave` VARCHAR(48) NOT NULL,
          `indice` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `ruta_archivo` VARCHAR(255) NOT NULL,
          `fecha_captura` DATETIME NOT NULL,
          `utm_este` DECIMAL(15,5) DEFAULT NULL,
          `utm_norte` DECIMAL(15,5) DEFAULT NULL,
          `zona` VARCHAR(10) DEFAULT NULL,
          `uuid` VARCHAR(64) DEFAULT NULL,
          `checksum` VARCHAR(64) DEFAULT NULL,
          `fecha_subida` DATETIME DEFAULT NULL,
          `activo` TINYINT(1) NOT NULL DEFAULT 1,
          `archivado_en` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_imagenes_plantilla` (`poste_id`, `foto_clave`, `indice`),
          CONSTRAINT `fk_imagenes_plantilla_poste`
            FOREIGN KEY (`poste_id`) REFERENCES `postes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `proyectos` ADD COLUMN IF NOT EXISTS `plantilla_id` INT UNSIGNED DEFAULT NULL;

-- Índice para listar los proyectos de una plantilla.
CREATE INDEX IF NOT EXISTS `idx_proyectos_plantilla` ON `proyectos` (`plantilla_id`);
