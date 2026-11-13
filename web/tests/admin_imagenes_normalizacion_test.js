"use strict";

/**
 * Normalización de la pantalla «Configurar imágenes».
 *
 * Se carga el bloque de normalización de `admin_imagenes_poste.js` en un
 * contexto aislado —sin DOM— y se le dan respuestas incompletas a propósito,
 * incluida la que provocaba
 *
 *     Cannot read properties of undefined (reading 'existe')
 *
 * Lo que se comprueba es la regla de negocio, no sólo que no lance: sin
 * configuración guardada TODAS las fotografías disponibles quedan marcadas, y
 * un slot sin fotografía nunca se marca ni desaparece.
 *
 *   node web/tests/admin_imagenes_normalizacion_test.js
 */

const fs = require("fs");
const vm = require("vm");
const path = require("path");

const source = fs.readFileSync(
  path.join(__dirname, "..", "js", "admin_imagenes_poste.js"),
  "utf8"
);
// Del catálogo de fuentes hasta antes de la primera función que toca el DOM
// al ejecutarse. `fuenteActual()` entra en el corte porque sólo lee el select
// dentro de su cuerpo, y aquí no se llama.
const start = source.indexOf("const FUENTES_CONOCIDAS");
const end = source.indexOf("function hayCambiosPendientes", start);
if (start < 0 || end < 0) throw new Error("No se encontro el bloque de normalizacion.");

const context = { console };
vm.createContext(context);
vm.runInContext(source.slice(start, end), context);
const { normalizarDatos, normalizarSlot, marcadasPorDefecto } = context;

let checks = 0;
const fallos = [];
function check(condicion, mensaje) {
  checks++;
  if (!condicion) fallos.push(mensaje);
}

/** Payload sano de 28 slots con `disponibles` de ellos con fotografía. */
function payload(disponibles, extra = {}) {
  const slots = [];
  for (let i = 0; i < 28; i++) {
    const conFoto = i < disponibles;
    slots.push({
      slot: "slot_" + (i + 1),
      etiqueta: "Slot " + (i + 1),
      panel: "Panel",
      disponible: conFoto,
      imprimir: true,
      archivo: conFoto ? "0" + (i + 1) + "_Foto.jpg" : null,
      motivo: conFoto ? null : "no_registrada"
    });
  }
  return Object.assign({
    poste: { codigo: "L-6028-01", linea: "L-6028", estructura: "01" },
    total_slots: 28,
    fuente_predeterminada: "current",
    configuracion: { existe: false, actualizado_en: null, actualizado_por: null },
    fuentes: [
      { clave: "current", etiqueta: "NAS", slots },
      { clave: "backup", etiqueta: "Backup", slots: slots.map((s) => Object.assign({}, s)) }
    ]
  }, extra);
}

// ── A: el caso de L-6028-01: 26 de 28 y las 26 marcadas ────────────────────
const a = normalizarDatos(payload(26));
const nas = a.fuentes.find((f) => f.clave === "current");
check(nas.slots.length === 28, "A: no se conservaron los 28 slots.");
check(nas.disponibles === 26, "A: no contó 26 disponibles.");
check(nas.no_disponibles === 2, "A: no contó 2 no disponibles.");
check(marcadasPorDefecto(nas).size === 26, "A: una estructura sin configurar no abrió con las 26 marcadas.");
check(a.total_slots === 28, "A: perdió el total del catálogo.");

// ── B: la respuesta SIN `configuracion` ya no rompe nada ───────────────────
// Es exactamente la que producía el error: se leía datos.configuracion.existe
// antes de sembrar la selección, así que además dejaba 0 marcadas.
const sinConfig = payload(26);
delete sinConfig.configuracion;
const b = normalizarDatos(sinConfig);
check(b.configuracion.existe === false, "B: no normalizó `configuracion` ausente.");
check(
  marcadasPorDefecto(b.fuentes[0]).size === 26,
  "B: sin `configuracion` se quedó sin fotografías marcadas."
);

// ── C: slots corruptos, ausentes o sin objeto de imagen ────────────────────
const roto = payload(3);
roto.fuentes[0].slots[0] = null;
roto.fuentes[0].slots[1] = undefined;
roto.fuentes[0].slots[2] = {};
roto.fuentes[0].slots[3] = { slot: "placa", imagen: null };
roto.fuentes[0].slots[4] = { slot: "mensulas", imagen: { existe: true } };
const c = normalizarDatos(roto);
const cNas = c.fuentes.find((f) => f.clave === "current");
check(cNas.slots.length === 28, "C: un slot corrupto hizo desaparecer filas.");
check(cNas.slots.every((s) => typeof s.existe === "boolean"), "C: quedó algún slot sin `existe`.");
check(cNas.slots.every((s) => typeof s.disponible === "boolean"), "C: quedó algún slot sin `disponible`.");
check(cNas.slots.every((s) => typeof s.slot === "string" && s.slot !== ""), "C: quedó un slot sin clave.");
check(cNas.slots.every((s) => typeof s.etiqueta === "string" && s.etiqueta !== ""), "C: quedó un slot sin etiqueta.");
// Sin nombre de archivo no hay fotografía, aunque el objeto diga `existe: true`.
check(
  cNas.slots.find((s) => s.slot === "mensulas").existe === false,
  "C: dio por disponible un slot sin archivo."
);
check(
  !marcadasPorDefecto(cNas).has("mensulas"),
  "C: marcó para imprimir un slot sin fotografía."
);

// ── D: `imprimir` ausente significa que se imprime ─────────────────────────
const sinImprimir = normalizarSlot({ slot: "placa", disponible: true, archivo: "02_Placa.jpg" }, 0);
check(sinImprimir.imprimir === true, "D: la ausencia de `imprimir` se tomó como «no imprimir».");
const excluido = normalizarSlot({ slot: "placa", disponible: true, archivo: "02_Placa.jpg", imprimir: false }, 0);
check(excluido.imprimir === false, "D: no respetó una exclusión guardada.");

// ── E: configuración guardada -> se respetan las casillas ──────────────────
const conConfig = payload(26, {
  configuracion: { existe: true, actualizado_en: "2026-09-02 11:10:12", actualizado_por: "admin01" }
});
conConfig.fuentes[0].slots[0].imprimir = false;
conConfig.fuentes[0].slots[5].imprimir = false;
const e = normalizarDatos(conConfig);
const eNas = e.fuentes.find((f) => f.clave === "current");
check(e.configuracion.existe === true, "E: perdió que hay configuración guardada.");
check(marcadasPorDefecto(eNas).size === 24, "E: no respetó las 2 fotografías excluidas.");
check(!marcadasPorDefecto(eNas).has("slot_1"), "E: marcó una fotografía excluida.");

// ── F: NAS y Backup son independientes ─────────────────────────────────────
const porFuente = payload(26);
porFuente.fuentes[1].slots.forEach((s, i) => { if (i < 4) s.imprimir = false; });
const f = normalizarDatos(porFuente);
check(
  marcadasPorDefecto(f.fuentes.find((x) => x.clave === "current")).size === 26,
  "F: la configuración de Backup afectó a NAS."
);
check(
  marcadasPorDefecto(f.fuentes.find((x) => x.clave === "backup")).size === 22,
  "F: no aplicó la configuración propia de Backup."
);

// ── G: respuestas degeneradas no revientan la pantalla ─────────────────────
for (const bruto of [undefined, null, {}, { fuentes: null }, { fuentes: [] }, { fuentes: [null] }, "texto", 7]) {
  const g = normalizarDatos(bruto);
  check(Array.isArray(g.fuentes) && g.fuentes.length === 2, "G: no dejó las dos fuentes con " + JSON.stringify(bruto));
  check(g.configuracion.existe === false, "G: inventó configuración con " + JSON.stringify(bruto));
  check(typeof g.fuente_predeterminada === "string" && g.fuente_predeterminada !== "",
    "G: se quedó sin fuente por defecto con " + JSON.stringify(bruto));
  check(marcadasPorDefecto(g.fuentes[0]).size === 0, "G: marcó fotografías que no existen.");
}

// ── H: `auto` no se ofrece en esta pantalla ────────────────────────────────
const conAuto = payload(26);
conAuto.fuentes.unshift({ clave: "auto", etiqueta: "Resolución estándar", slots: [] });
const h = normalizarDatos(conAuto);
check(
  h.fuentes.every((x) => x.clave !== "auto") && h.fuentes.length === 2,
  "H: se ofreció la resolución estándar como configurable."
);
check(h.fuente_predeterminada === "current", "H: la fuente por defecto no quedó en NAS.");

if (fallos.length) {
  console.error("FALLOS (" + checks + " comprobaciones):\n - " + fallos.join("\n - "));
  process.exit(1);
}
console.log("OK: " + checks + " comprobaciones de la normalizacion de Configurar imagenes");
