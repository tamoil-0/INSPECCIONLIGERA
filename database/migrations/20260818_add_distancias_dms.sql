-- Distancias DMS opcionales del formulario de inspeccion.
-- Ejecutar una sola vez sobre una instalacion existente.

ALTER TABLE `poste_datos`
  ADD COLUMN `distancia_poste_anterior` DECIMAL(12,3) UNSIGNED NULL AFTER `cantidad_pat`,
  ADD COLUMN `distancia_vertical` DECIMAL(12,3) UNSIGNED NULL AFTER `distancia_poste_anterior`,
  ADD COLUMN `distancia_horizontal` DECIMAL(12,3) UNSIGNED NULL AFTER `distancia_vertical`;
