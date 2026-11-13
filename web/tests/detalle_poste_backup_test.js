"use strict";

/**
 * Estado y tarjetas del listado de estructuras según la fuente.
 *
 * Se carga el bloque de estado de `detalle_poste.html` en un contexto aislado
 * —con un DOM mínimo simulado— y se comprueba que:
 *
 *   NAS    : las reglas de siempre, sin tocar (banderas de la BD);
 *   Backup : el estado sale de `estructuras[].estado` del endpoint, y una
 *            estructura sin entrada en el respaldo queda PENDIENTE, nunca
 *            heredando el estado del NAS.
 *
 * El payload de ejemplo es el que devuelve de verdad
 * `proyectos/avance_respaldo.php`.
 *
 *   node web/tests/detalle_poste_backup_test.js
 */

const fs = require("fs");
const vm = require("vm");
const path = require("path");

const html = fs.readFileSync(path.join(__dirname, "..", "detalle_poste.html"), "utf8");
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const start = script.indexOf("function fuenteSeleccionada");
const end = script.indexOf("function renderPostes", start);
if (start < 0 || end < 0) throw new Error("No se encontro el bloque de estado.");

/** DOM mínimo: sólo el select de fuente y los nodos de las tarjetas. */
const nodes = new Map();
function nodo(id) {
  if (!nodes.has(id)) nodes.set(id, { id, textContent: "", value: "" });
  return nodes.get(id);
}
const context = {
  document: { getElementById: nodo },
  postes: [],
  respaldoPorPoste: new Map(),
  console
};
vm.createContext(context);
vm.runInContext(script.slice(start, end), context);
const { estadoPoste, actualizarMetricas, respaldoDe } = context;

let checks = 0;
const fallos = [];
function check(condicion, mensaje) {
  checks++;
  if (!condicion) fallos.push(mensaje);
}

function fuente(valor) {
  nodo("fuenteFotosLista").value = valor;
}

/** Fila del padrón tal como la devuelve `postes/buscar_por_linea.php`. */
function poste(id, extra = {}) {
  return Object.assign({
    id,
    codigo: "L-6021-" + String(id).padStart(2, "0"),
    estructura: String(id).padStart(2, "0"),
    ubicaciones: "Tramo",
    utm_x: null, utm_y: null, zona: null,
    fecha_inspeccion: null,
    sincronizado: 0, formulario_subido: 0,
    imagenes_subidas: 0, fotos_capturadas: 0, fotos_sincronizadas: 0
  }, extra);
}

/** Entrada de `estructuras[]` del endpoint de respaldo. */
function respaldo(posteId, estado, extra = {}) {
  return Object.assign({
    poste_id: posteId,
    estado,
    backup_presente: estado !== "pendiente",
    inspeccionada: estado !== "pendiente",
    sincronizada: estado === "sincronizada",
    formulario: { existe: true, valido: estado === "sincronizada", estado: "FORMULARIO_OK" },
    fotos: { cantidad: 26, archivos: 26, sin_mapear: 0, confirmadas: estado === "sincronizada" },
    coordenadas: { utm_x: null, utm_y: null, zona: null }
  }, extra);
}

// ── Escenario: 5 estructuras en el padrón ──────────────────────────────────
//   1 sincronizada en NAS      -> en Backup no tiene nada  (debe ser pendiente)
//   2 en proceso en NAS        -> en Backup está completa
//   3 pendiente en NAS         -> en Backup sólo tiene fotos
//   4 pendiente en NAS         -> en Backup sólo tiene formulario
//   5 pendiente en NAS         -> en Backup no existe
context.postes = [
  poste(1, { sincronizado: 1, formulario_subido: 1, imagenes_subidas: 1, fotos_capturadas: 26 }),
  poste(2, { formulario_subido: 1, fotos_capturadas: 10 }),
  poste(3),
  poste(4),
  poste(5)
];
context.respaldoPorPoste = new Map([
  [2, respaldo(2, "sincronizada")],
  [3, respaldo(3, "en_proceso", {
    formulario: { existe: false, valido: false, estado: "FORMULARIO_AUSENTE" },
    fotos: { cantidad: 12, archivos: 12, sin_mapear: 0, confirmadas: false }
  })],
  [4, respaldo(4, "en_proceso", {
    fotos: { cantidad: 0, archivos: 0, sin_mapear: 0, confirmadas: false }
  })]
]);

// ── 1: NAS conserva exactamente sus reglas ─────────────────────────────────
fuente("current");
check(estadoPoste(context.postes[0]) === "completo", "NAS: sincronizado=1 no dio completo.");
check(estadoPoste(context.postes[1]) === "proceso", "NAS: con formulario no dio proceso.");
check(estadoPoste(context.postes[2]) === "pendiente", "NAS: sin datos no dio pendiente.");
// `fecha_inspeccion` sola basta para «en proceso» en NAS, como antes.
check(
  estadoPoste(poste(9, { fecha_inspeccion: "2026-09-01 08:00:00" })) === "proceso",
  "NAS: una fecha de inspección dejó de contar como proceso."
);
actualizarMetricas();
check(nodo("metricaTotal").textContent === 5, "NAS: el total no es el padrón.");
check(nodo("metricaCompletas").textContent === 1, "NAS: completas != 1.");
check(nodo("metricaProceso").textContent === 1, "NAS: en proceso != 1.");
check(nodo("metricaPendientes").textContent === 3, "NAS: pendientes != 3.");

// ── 2: Backup usa estructuras[].estado ─────────────────────────────────────
fuente("backup");
check(estadoPoste(context.postes[1]) === "completo", "Backup: sincronizada no dio completo.");
check(estadoPoste(context.postes[2]) === "proceso", "Backup: sólo fotos no dio en proceso.");
check(estadoPoste(context.postes[3]) === "proceso", "Backup: sólo formulario no dio en proceso.");
// El poste 1 está sincronizado en NAS pero NO tiene respaldo: pendiente.
check(
  estadoPoste(context.postes[0]) === "pendiente",
  "Backup: heredó el estado del NAS para una estructura sin respaldo."
);
check(estadoPoste(context.postes[4]) === "pendiente", "Backup: sin entrada no dio pendiente.");

// ── 3: las tarjetas cuentan sobre el estado Backup ─────────────────────────
actualizarMetricas();
check(nodo("metricaTotal").textContent === 5, "Backup: el total dejó de ser el padrón.");
check(nodo("metricaCompletas").textContent === 1, "Backup: completas != 1.");
check(nodo("metricaProceso").textContent === 2, "Backup: en proceso != 2.");
check(nodo("metricaPendientes").textContent === 2, "Backup: pendientes != 2.");
// respaldadas = completas + en proceso; el resto, pendientes.
check(
  Number(nodo("metricaCompletas").textContent) + Number(nodo("metricaProceso").textContent)
    + Number(nodo("metricaPendientes").textContent) === context.postes.length,
  "Backup: las tarjetas no suman el padrón."
);

// ── 4: el filtro opera sobre el estado de la fuente activa ─────────────────
const filtrar = (valor) => context.postes.filter((p) => estadoPoste(p) === valor).map((p) => p.id);
fuente("backup");
check(String(filtrar("completo")) === "2", "Filtro Backup: completos incorrectos.");
check(String(filtrar("proceso")) === "3,4", "Filtro Backup: en proceso incorrectos.");
check(String(filtrar("pendiente")) === "1,5", "Filtro Backup: pendientes incorrectos.");
fuente("current");
check(String(filtrar("completo")) === "1", "Filtro NAS: completos incorrectos.");
check(String(filtrar("proceso")) === "2", "Filtro NAS: en proceso incorrectos.");
check(String(filtrar("pendiente")) === "3,4,5", "Filtro NAS: pendientes incorrectos.");

// ── 5: los datos por fila salen del respaldo, no del NAS ───────────────────
fuente("backup");
const r3 = respaldoDe(context.postes[2]);
check(r3.fotos.archivos === 12, "Fila Backup: el conteo físico de fotos no es el del respaldo.");
check(r3.formulario.valido === false, "Fila Backup: el formulario no refleja el estado del respaldo.");
check(r3.formulario.estado === "FORMULARIO_AUSENTE", "Fila Backup: se pierde el motivo del formulario.");
check(respaldoDe(context.postes[0]) === null, "Fila Backup: inventó respaldo para una estructura sin él.");
// El padrón sigue mandando en lo que no es estado: código y estructura.
check(context.postes[2].codigo === "L-6021-03", "Fila: el respaldo alteró el código del padrón.");

// ── 6: sin respaldo cargado, Backup no muestra datos NAS ───────────────────
context.respaldoPorPoste = new Map();
fuente("backup");
check(
  context.postes.every((p) => estadoPoste(p) === "pendiente"),
  "Backup sin datos: alguna estructura tomó el estado del NAS."
);
actualizarMetricas();
check(nodo("metricaCompletas").textContent === 0, "Backup sin datos: mostró completas del NAS.");

if (fallos.length) {
  console.error("FALLOS (" + checks + " comprobaciones):\n - " + fallos.join("\n - "));
  process.exit(1);
}
console.log("OK: " + checks + " comprobaciones del listado de estructuras por fuente");
