-- Copia en la base de los logos y firmas de las plantillas (PNG).
-- El API también la crea sola si falta. Solo AGREGA una tabla.
CREATE TABLE IF NOT EXISTS `plantilla_archivos` (
  `plantilla_id` INT NOT NULL,
  `archivo` VARCHAR(64) NOT NULL,
  `contenido` MEDIUMBLOB NOT NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`plantilla_id`, `archivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
