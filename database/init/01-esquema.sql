-- ECOING - Inspección Ligera · esquema inicial para el contenedor `ecoing-db`
-- ===========================================================================
--
-- CUÁNDO SE EJECUTA
-- -----------------
-- La imagen oficial de MariaDB ejecuta los archivos de
-- /docker-entrypoint-initdb.d UNA SOLA VEZ: cuando arranca con el directorio de
-- datos vacío. En cuanto la base existe, este archivo se ignora para siempre.
--
-- Es la razón de que NO haya aquí ningún DROP DATABASE, ningún CREATE DATABASE
-- y ningún USE: la base la crea el entrypoint a partir de MARIADB_DATABASE y
-- las sentencias se aplican ya dentro de ella. Un `DROP DATABASE` en esta
-- carpeta sería un arma cargada apuntando a producción.
--
-- QUÉ CREA
-- --------
-- Las 7 tablas del sistema, vacías. Ni usuarios, ni proyectos, ni estructuras,
-- ni imágenes. Es el punto de partida de una instalación nueva.
--
-- Equivale a `database/schema.sql` sin las sentencias de creación de la base.
-- Si se toca uno, hay que tocar el otro.
--
-- IMPORTAR DATOS REALES
-- ---------------------
-- El padrón de estructuras o un dump de producción NO se importan aquí: se
-- importan a mano, una vez, con `scripts/import-dump.sh`. Ver
-- `docs/MIGRACION_XAMPP_A_QNAP.md`.


SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


CREATE TABLE `usuarios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre_completo` VARCHAR(100) NOT NULL,
  `nombre_usuario` VARCHAR(50) NOT NULL,
  `correo_electronico` VARCHAR(150) NOT NULL,
  `contrasena_hash` VARCHAR(255) NOT NULL,
  `rol` ENUM('administrador','supervisor','tecnico','invitado') NOT NULL DEFAULT 'tecnico',
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_login` TIMESTAMP NULL DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `dispositivo_id` VARCHAR(255) DEFAULT NULL,
  `ultima_sincronizacion` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_nombre` (`nombre_usuario`),
  UNIQUE KEY `uq_usuarios_correo` (`correo_electronico`),
  KEY `idx_usuarios_rol_activo` (`rol`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_intentos` (
  `clave` CHAR(64) NOT NULL,
  `intentos` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `bloqueado_hasta` DATETIME DEFAULT NULL,
  `actualizado` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`clave`),
  KEY `idx_login_intentos_actualizado` (`actualizado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `proyectos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre_proyecto` VARCHAR(150) NOT NULL,
  `contratista` VARCHAR(100) NOT NULL,
  `ubicacion` VARCHAR(255) NOT NULL,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `estado` ENUM('activo','completado','cancelado') NOT NULL DEFAULT 'activo',
  `creado_por` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_proyectos_estado_fecha` (`estado`, `fecha_creacion`),
  KEY `idx_proyectos_creado_por` (`creado_por`),
  CONSTRAINT `fk_proyectos_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `postes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(50) NOT NULL,
  `linea` VARCHAR(100) NOT NULL,
  `estructura` VARCHAR(100) NOT NULL,
  `proyecto_id` INT UNSIGNED NOT NULL,
  `utm_x` DECIMAL(15,5) DEFAULT NULL,
  `utm_y` DECIMAL(15,5) DEFAULT NULL,
  `zona` VARCHAR(10) DEFAULT '19S',
  `fecha_inspeccion` TIMESTAMP NULL DEFAULT NULL,
  `fecha_subida` TIMESTAMP NULL DEFAULT NULL,
  `sincronizado` TINYINT(1) NOT NULL DEFAULT 0,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `formulario_subido` TINYINT(1) NOT NULL DEFAULT 0,
  `inspeccion_finalizada` TINYINT(1) NOT NULL DEFAULT 0,
  `imagenes_subidas` TINYINT(1) NOT NULL DEFAULT 0,
  `ubicaciones` VARCHAR(255) DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `desactivado_en` DATETIME DEFAULT NULL,
  `ultima_limpieza_admin` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_postes_proyecto_codigo` (`proyecto_id`, `codigo`),
  KEY `idx_postes_proyecto_linea_codigo` (`proyecto_id`, `linea`, `codigo`),
  KEY `idx_postes_busqueda` (`linea`, `estructura`, `proyecto_id`),
  KEY `idx_postes_sincronizacion` (`proyecto_id`, `sincronizado`, `fecha_subida`),
  KEY `idx_postes_admin_estado` (`proyecto_id`, `linea`, `activo`, `id`),
  CONSTRAINT `fk_postes_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lineas` (
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
  CONSTRAINT `fk_lineas_proyecto` FOREIGN KEY (`proyecto_id`) REFERENCES `proyectos` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `poste_datos` (
  `poste_id` INT UNSIGNED NOT NULL,
  `obstaculos_faja` SET('invasiones_nuevas','construcciones_nuevas','proceso_construccion','cercos_vallas','arboles','arbustos','arboles_fuera_faja','otros','n_a') DEFAULT NULL,
  `estado_cuencas` ENUM('seguimiento','critico','n_a') DEFAULT NULL,
  `marcado_arboles` ENUM('si','no') DEFAULT NULL,
  `criticidad_tala` ENUM('bajo','seguimiento','critico','n_a') DEFAULT NULL,
  `criticidad_contacto` ENUM('bajo','seguimiento','critico','n_a') DEFAULT NULL,
  `notificacion_propietario` ENUM('persona_natural','persona_juridica','otro') DEFAULT NULL,
  `tipo_torre` ENUM('alineamiento','angulo','fin_linea') DEFAULT NULL,
  `ubicacion` ENUM('rural_con_vegetacion','urbana','industrial','rural_sin_vegetacion','zona_sujeta_huaycos','desertico') DEFAULT NULL,
  `acceso_torre` ENUM('a_pie','en_vehiculo') DEFAULT NULL,
  `estado_acceso` ENUM('bueno','mal_estado') DEFAULT NULL,
  `estado_placas_torre` ENUM('bueno','malo','no_existe') DEFAULT NULL,
  `estado_placas_linea` ENUM('bueno','malo','no_existe') DEFAULT NULL,
  `estado_placas_fases` ENUM('bueno','malo','no_existe') DEFAULT NULL,
  `peligro_cerco` ENUM('bueno','malo','no_existe') DEFAULT NULL,
  `peligro_torre` ENUM('bueno','malo','no_existe') DEFAULT NULL,
  `puesta_tierra` ENUM('bueno','malo','no_existe') DEFAULT NULL,
  `retenida` ENUM('buen_estado','cambiar_preforme','retemplar','n_a') DEFAULT NULL,
  `estado_base` ENUM('buen_estado','mal_estado') DEFAULT NULL,
  `limpiar_base` ENUM('si','no') DEFAULT NULL,
  `crucetas_mensuales` ENUM('buen_estado','mal_estado','falta_ajustar','n_a') DEFAULT NULL,
  `perfiles_angulares` ENUM('buen_estado','mal_estado','falta','n_a') DEFAULT NULL,
  `malla_antiescalamiento` ENUM('buen_estado','mal_estado','falta','n_a') DEFAULT NULL,
  `oxidos_base` ENUM('si','no','n_a') DEFAULT NULL,
  `cadena_aisladores` ENUM('en_suspension','en_anclaje','en_cuello_muerto') DEFAULT NULL,
  `tipo_aislador` ENUM('vidrio','porcelana','polimero') DEFAULT NULL,
  `conductor_bajada_pat` ENUM('buen_estado','conductor_en_mal_estado','grapas_en_mal_estado','listones_en_mal_estado','n_a') DEFAULT NULL,
  `conductor_guarda` ENUM('hebras_rotas','encanastillado','empalme_deformado','objetos_extranos','n_a') DEFAULT NULL,
  `comentarios` TEXT DEFAULT NULL,
  `distancia_acceso` DOUBLE UNSIGNED DEFAULT NULL,
  `cantidad_pat` INT UNSIGNED NOT NULL DEFAULT 0,
  `distancia_poste_anterior` DECIMAL(12,3) UNSIGNED DEFAULT NULL,
  `distancia_vertical` DECIMAL(12,3) UNSIGNED DEFAULT NULL,
  `distancia_horizontal` DECIMAL(12,3) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`poste_id`),
  CONSTRAINT `fk_poste_datos_poste` FOREIGN KEY (`poste_id`) REFERENCES `postes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `poste_secciones_rst` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poste_id` INT UNSIGNED NOT NULL,
  `seccion` ENUM('conductores_fase','conductores_cuellos','conductores_guarda','estado_aisladores') NOT NULL,
  `atributo` ENUM('hebras_rotas','encanastillado','empalme_deformado','objetos_extranos','buen_estado','rotos_suspension','rotos_anclaje_adelante','rotos_anclaje_atras','mal_estado') NOT NULL,
  `fase` ENUM('R','S','T') NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `archivado_en` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rst_poste_seccion_fase` (`poste_id`, `seccion`, `fase`),
  KEY `idx_rst_poste_seccion` (`poste_id`, `seccion`),
  CONSTRAINT `fk_rst_poste` FOREIGN KEY (`poste_id`) REFERENCES `postes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `imagenes_poste` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poste_id` INT UNSIGNED NOT NULL,
  `nombre_foto` ENUM(
    'foto_panoramica','placa','torre_parte_inferior','torre_parte_superior','base_torre',
    'mensulas','crucetas','perfiles_angulares','atiescalamiento','otros',
    'aisladores_fase_r_atras','aisladores_fase_s_atras','aisladores_fase_t_atras',
    'aisladores_fase_r_adelante','aisladores_fase_s_adelante','aisladores_fase_t_adelante',
    'ferreteria_fase_r','ferreteria_fase_s','ferreteria_fase_t','cable_guarda',
    'ferreteria_de_cable_de_guarda','conductor','ferreteria_de_conductor',
    'puesta_tierra','puesta_tierra_2','retenida','faja_servidumbre','ubicacion_acceso'
  ) NOT NULL,
  `ruta_archivo` VARCHAR(255) NOT NULL,
  `fecha_captura` DATETIME NOT NULL,
  `utm_este` DECIMAL(15,5) DEFAULT NULL,
  `utm_norte` DECIMAL(15,5) DEFAULT NULL,
  `zona` VARCHAR(10) DEFAULT NULL,
  `sincronizada` TINYINT(1) NOT NULL DEFAULT 0,
  `fecha_inspeccion` DATETIME DEFAULT NULL,
  `fecha_subida` DATETIME DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `archivado_en` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_imagenes_poste_tipo` (`poste_id`, `nombre_foto`),
  KEY `idx_imagenes_sincronizada` (`sincronizada`, `fecha_subida`),
  CONSTRAINT `fk_imagenes_poste` FOREIGN KEY (`poste_id`) REFERENCES `postes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `imagenes_poste_estado` (
  `poste_id` INT UNSIGNED NOT NULL,
  `nombre_foto` VARCHAR(64) NOT NULL,
  `sincronizada` TINYINT(1) NOT NULL DEFAULT 0,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `archivado_en` DATETIME DEFAULT NULL,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`poste_id`, `nombre_foto`),
  KEY `idx_imagenes_poste_estado_sync` (`poste_id`, `sincronizada`),
  CONSTRAINT `fk_imagenes_poste_estado_poste`
    FOREIGN KEY (`poste_id`) REFERENCES `postes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_auditoria` (
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
  CONSTRAINT `fk_admin_auditoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

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

-- Copia en la base de los logos y firmas de las plantillas (PNG).
-- El API también la crea sola si falta. Solo AGREGA una tabla.
CREATE TABLE IF NOT EXISTS `plantilla_archivos` (
  `plantilla_id` INT NOT NULL,
  `archivo` VARCHAR(64) NOT NULL,
  `contenido` MEDIUMBLOB NOT NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`plantilla_id`, `archivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
