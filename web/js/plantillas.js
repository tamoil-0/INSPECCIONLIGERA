"use strict";

/*
 * Editor de plantillas de inspección.
 *
 * Una plantilla describe, como datos, lo que en el proyecto original está
 * escrito en el código: el formulario de campo, las fotografías y el PDF. El
 * contrato completo está en docs/PLANTILLAS_DINAMICAS.md; el servidor vuelve a
 * validar todo lo que se guarda aquí (plantillaNormalizar).
 *
 * El editor trabaja sobre una copia local (`S.config`) y solo la envía al
 * pulsar «Guardar cambios». Así el administrador puede probar el PDF con
 * «Ver PDF de prueba» antes de que ningún proyecto vea el cambio.
 */

verificarSesion();
(() => {
  const usuario = obtenerUsuario();
  if (!usuario || usuario.rol !== "administrador") window.location.replace("proyectos.html");
})();

// ---------------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------------

const $ = (id) => document.getElementById(id);

/** Crea un elemento: h("button", {class: "x", onclick}, "texto", hijo…) */
function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === "class") el.className = v;
    else if (k === "value") el.value = v;
    else if (k === "style" && typeof v === "object") {
      for (const [prop, val] of Object.entries(v)) {
        if (prop.startsWith("--")) el.style.setProperty(prop, val);
        else el.style[prop] = val;
      }
    }
    else if (k.startsWith("on") && typeof v === "function") el.addEventListener(k.slice(2), v);
    else if (k === "dataset") Object.assign(el.dataset, v);
    else if (k in el && typeof v !== "string") el[k] = v;
    else el.setAttribute(k, v === true ? "" : v);
  }
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return el;
}

// Iconos de línea (SVG) en lugar de emojis: se ven igual en todos los equipos.
const ICONOS = {
  borrar: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
  copiar: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
  arriba: '<path d="M12 19V5M6 11l6-6 6 6"/>',
  abajo: '<path d="M12 5v14M18 13l-6 6-6-6"/>',
  cerrar: '<path d="M6 6l12 12M18 6L6 18"/>',
  camara: '<path d="M4 8.5A2.5 2.5 0 0 1 6.5 6H8l1.6-2h4.8L16 6h1.5A2.5 2.5 0 0 1 20 8.5v8a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5z"/><circle cx="12" cy="12.5" r="3.3"/>',
  panoramica: '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 16.5l5-5 4 4 3-3 5 5"/><circle cx="16" cy="9.5" r="1.4"/>',
  magia: '<path d="M4 20L15 9M13 7l4 4"/><path d="M18 3v4M16 5h4M9 3v2M8 4h2M20 13v2M19 14h2"/>',
  mas: '<path d="M12 5v14M5 12h14"/>'
};

function ico(nombre, clase) {
  const span = document.createElement("span");
  span.className = "tpl-ico" + (clase ? " " + clase : "");
  span.setAttribute("aria-hidden", "true");
  span.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' + ICONOS[nombre] + "</svg>";
  return span;
}

function slug(texto, max = 48) {
  let s = String(texto || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
  if (!/^[a-z]/.test(s)) s = "c_" + s;
  return s.slice(0, max).replace(/_+$/, "") || "campo";
}

function claveUnica(base, usadas) {
  let clave = slug(base);
  let n = 2;
  while (usadas.has(clave)) clave = slug(base).slice(0, 44) + "_" + n++;
  return clave;
}

const clonar = (v) => JSON.parse(JSON.stringify(v));

function toast(texto, tono) {
  const t = h("div", { class: "admin-toast admin-toast-" + (tono || "success"), role: tono === "error" ? "alert" : "status" },
    h("span", { class: "admin-toast-icon", "aria-hidden": "true" }, tono === "error" ? "!" : "✓"),
    h("span", {}, texto));
  $("toastRegion").append(t);
  setTimeout(() => { t.classList.add("is-leaving"); setTimeout(() => t.remove(), 220); }, 4200);
}

function mensajeError(error) {
  return error && error.message ? error.message : "Ocurrió un error inesperado.";
}

// ---------------------------------------------------------------------------
// Ventanas propias (en lugar de confirm/alert del navegador)
// ---------------------------------------------------------------------------

/**
 * Ventana modal con el estilo del panel. Devuelve una promesa: true si se
 * acepta. Con tono "danger" el botón va en rojo y el foco queda en
 * «Cancelar», para que un Enter distraído no borre nada.
 */
function modal({ titulo, texto, detalle = [], aceptar = "Aceptar", cancelar = "Cancelar", tono = "primary", icono }) {
  return new Promise((resolve) => {
    const peligro = tono === "danger";
    const dlg = h("dialog", { class: "admin-dialog admin-dialog-sm tpl-modal", "aria-labelledby": "tplModalTitulo", "aria-describedby": "tplModalTexto" });
    let resuelto = false;
    const cerrar = (valor) => {
      if (resuelto) return;
      resuelto = true;
      dlg.close();
      dlg.remove();
      resolve(valor);
    };
    const btnCancelar = cancelar ? h("button", { class: "button-secondary", type: "button", onclick: () => cerrar(false) }, cancelar) : null;
    const btnAceptar = h("button", { class: peligro ? "button-danger" : "button-primary", type: "button", onclick: () => cerrar(true) }, aceptar);
    dlg.append(h("div", { class: "tpl-modal-box" },
      h("header", { class: "admin-dialog-header" },
        h("div", { class: "admin-dialog-heading" },
          h("span", { class: "admin-dialog-icon" + (peligro ? " admin-dialog-icon-warning" : ""), "aria-hidden": "true" }, icono || (peligro ? "!" : "✓")),
          h("div", {},
            h("p", { class: "eyebrow" }, peligro ? "Confirmación requerida" : "Plantilla de inspección"),
            h("h2", { id: "tplModalTitulo" }, titulo))),
        h("button", { class: "button-ghost admin-dialog-close", type: "button", "aria-label": "Cerrar", onclick: () => cerrar(false) }, "×")),
      h("div", { class: "admin-dialog-body" },
        h("p", { id: "tplModalTexto", class: "tpl-modal-texto" }, texto || ""),
        detalle.length ? h("ul", { class: "tpl-modal-detalle" }, ...detalle.map((d) => h("li", {}, d))) : null),
      h("footer", { class: "admin-dialog-footer" }, btnCancelar, btnAceptar)));
    dlg.addEventListener("cancel", (e) => { e.preventDefault(); cerrar(false); });
    dlg.addEventListener("click", (e) => { if (e.target === dlg) cerrar(false); });
    cerrarAyudas();
    document.body.append(dlg);
    dlg.showModal();
    (peligro && btnCancelar ? btnCancelar : btnAceptar).focus();
  });
}

// ---------------------------------------------------------------------------
// Ayuda contextual: un «?» junto a cada sección, con instrucciones cortas
// ---------------------------------------------------------------------------

const AYUDA = {
  pasos: { titulo: "Pasos del formulario", puntos: [
    "Cada paso es una pantalla del celular; el inspector avanza con «Siguiente». Agrupe las preguntas de una misma zona (faja, torre, herrajes…).",
    "Haga clic en un campo para editarlo a la derecha. Arrastre ⠿ para cambiar el orden o pasarlo a otro paso.",
    "Recomendación: de 4 a 8 campos por paso, y solo lo imprescindible como obligatorio.",
    "Todo campo nuevo aparece solo en la hoja 1 del PDF, y al borrarlo desaparece de ahí. No hace falta tocar el PDF."] },
  campo: { titulo: "Cómo configurar un campo", puntos: [
    "Pregunta: el texto que lee el inspector. Corto y claro.",
    "Tipo: «Una opción» si cabe una sola respuesta; «Varias opciones» si puede marcar varias; «Número» para medidas (indique la unidad); «Tabla de fases» para marcar por R, S y T.",
    "Obligatorio: el celular no deja guardar sin responderlo.",
    "Ayuda para el inspector: una pista corta que aparece bajo la pregunta en el celular."] },
  fotos: { titulo: "Fotografías que se piden", puntos: [
    "Cada fila es una foto que el celular pedirá, en este orden. Arrastre ⠿ para ordenarlas.",
    "Tomas: cuántas fotos de ese mismo tipo se piden (por ejemplo, 2 de puesta a tierra).",
    "Obligatoria: la estructura no figura completa hasta que se tome.",
    "Panorámica ★: va en grande en la hoja 1 del PDF. Solo puede haber una.",
    "Toda foto nueva entra sola al PDF, en el panel de su categoría (si no existe, se crea). Al borrarla, se quita del PDF.",
    "Cómo salen en el PDF se ajusta en «Diseño del PDF → Páginas de fotografías»."] },
  hoja1: { titulo: "Hoja 1 del PDF", puntos: [
    "Es la hoja de datos: cada cuadro de color muestra uno o varios campos del formulario.",
    "Arrastre un cuadro para moverlo y la esquina ◢ para cambiar su tamaño. Se acomoda solo a la rejilla y no puede encimarse con otro.",
    "Clic en un cuadro: título, color, columnas y campos. Clic en el encabezado: logos, título y colores.",
    "Los campos nuevos del formulario se ubican solos; los borrados se quitan (y su cuadro, si queda vacío).",
    "«Armar automáticamente» rehace la hoja 1 y las fotos desde el formulario: útil al empezar una plantilla nueva.",
    "Si quita un cuadro a mano, sus campos no salen hasta que los ubique: lo indica el aviso de «sin ubicar».",
    "Use «Ver PDF de prueba» para ver el resultado real antes de guardar."] },
  encabezado: { titulo: "Encabezado", puntos: [
    "Se repite en todas las hojas del PDF.",
    "Logos: PNG, JPG o WEBP. Se recortan los bordes vacíos y ambos se ajustan al mismo tamaño (35 × 13 mm). Lo ideal es un logo horizontal con fondo transparente.",
    "Título en dos líneas como máximo. La barra lleva el nombre del proyecto y el código de la estructura."] },
  cuadro: { titulo: "Cuadro de la hoja 1", puntos: [
    "Con un solo campo de opciones se imprime la lista de opciones con una X en la respuesta.",
    "Con varios campos se imprime «Etiqueta: respuesta» en filas.",
    "Columnas reparte las filas en 2, 3 o 4 columnas, útil en cuadros anchos.",
    "Si el texto no entra, agrande el cuadro."] },
  paginas: { titulo: "Páginas de fotografías", puntos: [
    "Así salen las hojas 2 en adelante, a escala. Cada panel es una banda de color con sus fotos en cuadrícula.",
    "Clic en un panel para editarlo a la derecha: título, color, fotos por fila, alto de las fotos y orden.",
    "Arrastre una foto sobre otra para cambiar el orden, o sobre la banda de otro panel para pasarla a ese panel.",
    "Clic en una foto: elija un tamaño rápido (Pequeña… Toda la fila) o arrastre su esquina azul. Las demás se acomodan solas en los huecos.",
    "Recomendación: 3 por fila para fotos de detalle y 2 por fila para las que deben verse grandes (conductores, faja, retenida).",
    "Ninguna foto se pierde: la que no está en un panel sale al final, en «Otras fotografías»."] },
  panel: { titulo: "Panel de fotos", puntos: [
    "Fotos por fila: menos columnas = fotos más grandes. Vale para las fotos sin tamaño propio.",
    "Tamaño propio: clic en una foto de la hoja y cámbiela con «Tamaño rápido», con las barras o arrastrando su esquina.",
    "Alto de las fotos: «Automático» lo ajusta al ancho; con un valor fijo todas las fotos del panel miden lo mismo.",
    "«Empezar en página nueva» fuerza un salto de hoja antes del panel.",
    "Título vacío = el panel no lleva banda de título."] },
  cierre: { titulo: "Cierre del reporte", puntos: [
    "Comentarios: el campo de texto largo del formulario que se imprime al final.",
    "Firmas: hasta 3, con nombre y cargo; se imprimen con una línea y «Fecha:» debajo.",
    "Firma con imagen: «Subir firma» acepta PNG o JPG. Si la firma está sobre papel blanco, el fondo se vuelve transparente solo.",
    "Arrastre la firma para acomodarla sobre la línea y use «Tamaño» para agrandarla o achicarla. «Centrar» la devuelve al medio."] }
};

function cerrarAyudas(excepto) {
  document.querySelectorAll(".tpl-ayuda-wrap").forEach((w) => {
    if (w === excepto) return;
    w.querySelector(".tpl-ayuda-pop").hidden = true;
    w.querySelector(".tpl-ayuda").setAttribute("aria-expanded", "false");
  });
}

/** Botón «?» con un globo de instrucciones. `derecha` lo abre hacia la izquierda. */
function ayuda(clave, derecha) {
  const info = AYUDA[clave];
  if (!info) return null;
  const pop = h("div", { class: "tpl-ayuda-pop", role: "note", hidden: true },
    h("strong", {}, info.titulo), ...info.puntos.map((p) => h("p", {}, p)));
  const btn = h("button", { type: "button", class: "tpl-ayuda", "aria-label": "Ayuda: " + info.titulo, "aria-expanded": "false", title: "Ayuda" }, "?");
  const wrap = h("span", { class: "tpl-ayuda-wrap" + (derecha ? " tpl-ayuda-der" : "") }, btn, pop);
  btn.addEventListener("click", (e) => {
    e.stopPropagation();
    cerrarAyudas(wrap);
    pop.hidden = !pop.hidden;
    btn.setAttribute("aria-expanded", String(!pop.hidden));
  });
  return wrap;
}
document.addEventListener("click", (e) => { if (!e.target.closest?.(".tpl-ayuda-wrap")) cerrarAyudas(); });
document.addEventListener("keydown", (e) => { if (e.key === "Escape") cerrarAyudas(); });

/** Contraste: texto blanco u oscuro sobre un color. */
function textoSobre(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || "");
  if (!m) return "#fff";
  const n = parseInt(m[1], 16);
  const luz = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;
  return luz > 0.62 ? "#212529" : "#fff";
}

const PALETA = [
  ["#305496", "Azul"], ["#2F75B5", "Azul medio"], ["#0070C0", "Azul claro"], ["#0A2D64", "Azul marino"],
  ["#548235", "Verde"], ["#176B55", "Verde petróleo"], ["#BF8F00", "Dorado"], ["#C65911", "Naranja"],
  ["#A43A3A", "Rojo"], ["#7030A0", "Morado"], ["#7B7B7B", "Gris"], ["#333F4F", "Grafito"]
];

const TIPOS = {
  seleccion: { nombre: "Una opción", icono: "◉", desc: "El inspector elige una sola respuesta de una lista." },
  multiple: { nombre: "Varias opciones", icono: "☑", desc: "Puede marcar varias respuestas a la vez." },
  si_no: { nombre: "Sí / No", icono: "◐", desc: "Dos botones grandes. Ideal para verificaciones." },
  numero: { nombre: "Número", icono: "#", desc: "Medidas o cantidades, con unidad y límites." },
  texto: { nombre: "Texto corto", icono: "Aa", desc: "Una línea: códigos, nombres, referencias." },
  parrafo: { nombre: "Párrafo", icono: "¶", desc: "Comentarios u observaciones largas." },
  fecha: { nombre: "Fecha", icono: "◷", desc: "Selector de fecha del calendario." },
  rst: { nombre: "Tabla de fases", icono: "▦", desc: "Filas de estados × columnas R, S, T." }
};
const CON_OPCIONES = ["seleccion", "multiple", "rst"];

// Geometría de la hoja 1 (milímetros), igual que PDFPlantilla.php.
// Rejilla de 48×96 (celdas de 3,96 × 2,5 mm). La anterior era de 24×48: las
// medidas «en celdas» de este archivo se escriben en esa unidad por SUB.
const HOJA = { ancho: 210, alto: 297, margen: 10, util: 190, y0: 42.5, cols: 48, filas: 96, fila: 2.5, minH: 4 };
HOJA.col = HOJA.util / HOJA.cols;
const SUB = 2;

/** Pasa una configuración de la rejilla anterior (24×48) a la actual sin moverla en la hoja. */
function rejillaActual(config) {
  const hoja = config.pdf.hoja1;
  const factor = Number(hoja.columnas || 24) === 24 ? SUB : 1;
  if (factor !== 1) hoja.cuadros.forEach((c) => { c.x *= factor; c.y *= factor; c.w *= factor; c.h *= factor; });
  hoja.columnas = HOJA.cols;
  hoja.filas = HOJA.filas;
  return config;
}

// ---------------------------------------------------------------------------
// Estado
// ---------------------------------------------------------------------------

const S = {
  lista: [],
  plantilla: null,   // lo último que devolvió el servidor
  config: null,      // copia de trabajo
  nombre: "",
  descripcion: "",
  dirty: false,
  guardando: false,
  tab: "formulario",
  sel: null,         // {paso, campo} en Formulario · {cuadro: id} | {encabezado: true} en PDF
  selPanel: null,    // índice del panel de fotos seleccionado (PDF, hojas 2+)
  selFoto: null,     // {ip, clave}: foto seleccionada en las hojas de fotos
  logos: new Map()   // archivo → object URL
};

function marcarCambio() {
  S.dirty = true;
  const estado = $("tplEstadoGuardado");
  estado.dataset.state = "dirty";
  estado.textContent = "Cambios sin guardar";
  $("btnGuardar").disabled = false;
  pintarResumenes();
}

window.addEventListener("beforeunload", (e) => {
  if (S.dirty) { e.preventDefault(); e.returnValue = ""; }
});

const campos = () => S.config.formulario.pasos.flatMap((p) => p.campos);
const campoPorClave = (clave) => campos().find((c) => c.clave === clave);
const clavesUsadas = () => new Set(campos().map((c) => c.clave));

// ---------------------------------------------------------------------------
// Lista de plantillas
// ---------------------------------------------------------------------------

async function cargarLista() {
  const cont = $("listaPlantillas");
  cont.setAttribute("aria-busy", "true");
  try {
    const data = await leerRespuesta(await fetchConToken("admin/plantillas.php"));
    S.lista = data.data || [];
    pintarLista();
  } catch (error) {
    mostrarMensaje($("listaMensaje"), mensajeError(error));
  } finally {
    cont.setAttribute("aria-busy", "false");
  }
}

function pintarLista() {
  const cont = $("listaPlantillas");
  cont.replaceChildren();
  if (!S.lista.length) {
    cont.append(h("div", { class: "tpl-empty" },
      h("strong", {}, "Aún no hay plantillas"),
      h("p", {}, "Cree la primera con «Nueva plantilla».")));
    return;
  }
  for (const p of S.lista) {
    const card = h("article", { class: "tpl-card" + (p.activa ? "" : " is-inactive") },
      h("div", { class: "tpl-card-top" },
        h("div", { class: "tpl-card-icon", "aria-hidden": "true" }, "▤"),
        crearInsignia(p.activa ? "Activa" : "Desactivada", p.activa ? "success" : "neutral")),
      h("h2", {}, p.nombre),
      h("p", { class: "tpl-card-desc" }, p.descripcion || "Sin descripción."),
      h("ul", { class: "tpl-card-stats" },
        h("li", {}, h("strong", {}, p.campos), " campos", h("small", {}, p.obligatorios + " obligatorios")),
        h("li", {}, h("strong", {}, p.fotos), " fotos", h("small", {}, p.fotos_obligatorias + " obligatorias")),
        h("li", {}, h("strong", {}, p.cuadros_pdf), " cuadros", h("small", {}, "en la hoja 1"))),
      h("p", { class: "tpl-card-meta" },
        p.proyectos ? `Usada en ${p.proyectos} proyecto${p.proyectos === 1 ? "" : "s"}` : "Sin proyectos asignados",
        " · versión ", String(p.version)),
      h("div", { class: "tpl-card-actions" },
        h("button", { class: "button-primary button-sm", type: "button", onclick: () => abrirEditor(p.id) }, "Editar"),
        h("button", { class: "button-secondary button-sm", type: "button", onclick: () => abrirNueva(p.id) }, "Duplicar"),
        h("button", { class: "button-ghost button-sm", type: "button", onclick: () => cambiarActiva(p) },
          p.activa ? "Desactivar" : "Reactivar")));
    cont.append(card);
  }
}

async function cambiarActiva(p) {
  if (p.activa && !(await modal({
    titulo: "Desactivar plantilla", tono: "danger", aceptar: "Desactivar",
    texto: `¿Desactivar «${p.nombre}»?`,
    detalle: ["Los proyectos que ya la usan siguen funcionando igual.",
      "Solo deja de ofrecerse al crear proyectos nuevos.", "Puede reactivarla cuando quiera."]
  }))) return;
  try {
    await leerRespuesta(await fetchConToken("admin/plantillas.php", {
      method: "PATCH", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: p.id, accion: p.activa ? "desactivar" : "reactivar" })
    }));
    toast(p.activa ? "Plantilla desactivada." : "Plantilla reactivada.");
    cargarLista();
  } catch (error) {
    toast(mensajeError(error), "error");
  }
}

function abrirNueva(duplicarDe) {
  const dlg = $("dlgNueva");
  const form = $("formNueva");
  form.reset();
  limpiarMensaje($("nuevaMensaje"));
  const select = $("nuevaOrigen");
  select.replaceChildren(...S.lista.map((p) => new Option(p.nombre, p.id)));
  if (!S.lista.length) form.querySelector('input[value="blanco"]').checked = true;
  if (duplicarDe) {
    select.value = String(duplicarDe);
    const base = S.lista.find((p) => p.id === duplicarDe);
    $("nuevaNombre").value = base ? base.nombre + " (copia)" : "";
  }
  dlg.showModal();
  $("nuevaNombre").focus();
}

$("formNueva").addEventListener("change", () => {
  $("nuevaOrigen").disabled = $("formNueva").origen.value !== "copia";
});

$("formNueva").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const boton = $("nuevaCrear");
  const payload = { nombre: form.nombre.value, descripcion: form.descripcion.value };
  if (form.origen.value === "copia" && form.duplicar_de.value) payload.duplicar_de = Number(form.duplicar_de.value);
  establecerCargando(boton, true, "Creando…");
  try {
    const data = await leerRespuesta(await fetchConToken("admin/plantillas.php", {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload)
    }));
    $("dlgNueva").close();
    toast("Plantilla creada.");
    await cargarLista();
    abrirEditor(data.data.id);
  } catch (error) {
    mostrarMensaje($("nuevaMensaje"), mensajeError(error));
  } finally {
    establecerCargando(boton, false);
  }
});

document.querySelectorAll("dialog [data-cerrar]").forEach((b) =>
  b.addEventListener("click", () => b.closest("dialog").close()));

$("btnNueva").addEventListener("click", () => abrirNueva());

// ---------------------------------------------------------------------------
// Editor: carga, pestañas, guardado
// ---------------------------------------------------------------------------

async function abrirEditor(id) {
  try {
    const data = await leerRespuesta(await fetchConToken("admin/plantillas.php?id=" + id));
    S.plantilla = data.data;
    S.config = rejillaActual(clonar(data.data.config));
    limpiarPdf();
    S.nombre = data.data.nombre;
    S.descripcion = data.data.descripcion || "";
    S.dirty = false;
    S.sel = null;
    $("tplNombre").value = S.nombre;
    $("tplVersion").textContent = "v" + data.data.version;
    const estado = $("tplEstadoGuardado");
    estado.dataset.state = "saved";
    estado.textContent = "Guardado";
    $("btnGuardar").disabled = true;
    $("vistaLista").hidden = true;
    $("vistaEditor").hidden = false;
    history.replaceState(null, "", "?plantilla=" + id);
    cambiarTab(S.tab);
    window.scrollTo({ top: 0 });
  } catch (error) {
    toast(mensajeError(error), "error");
  }
}

async function cerrarEditor() {
  if (S.dirty && !(await modal({
    titulo: "Cambios sin guardar", tono: "danger", aceptar: "Salir sin guardar", cancelar: "Seguir editando",
    texto: "Si sale ahora, se perderán los cambios que no guardó."
  }))) return;
  S.dirty = false;
  $("vistaEditor").hidden = true;
  $("vistaLista").hidden = false;
  history.replaceState(null, "", "plantillas.html");
  cargarLista();
}
$("btnVolver").addEventListener("click", cerrarEditor);

$("tplNombre").addEventListener("input", (e) => { S.nombre = e.target.value; marcarCambio(); });

document.querySelectorAll(".tpl-tabs [data-tab]").forEach((b) =>
  b.addEventListener("click", () => cambiarTab(b.dataset.tab)));

function cambiarTab(tab) {
  S.tab = tab;
  S.sel = tab === "formulario" && S.config.formulario.pasos[0]?.campos.length ? { paso: 0, campo: 0 } : null;
  if (tab === "pdf") S.sel = { encabezado: true };
  S.selPanel = null;
  S.selFoto = null;
  document.querySelectorAll(".tpl-tabs [data-tab]").forEach((b) =>
    b.setAttribute("aria-selected", String(b.dataset.tab === tab)));
  pintarResumenes();
  pintarTab();
}

function pintarTab() {
  const cont = $("tplContenido");
  cont.replaceChildren();
  limpiarMensaje($("editorMensaje"));
  if (S.tab === "formulario") cont.append(vistaFormulario());
  if (S.tab === "fotos") cont.append(vistaFotos());
  if (S.tab === "pdf") cont.append(vistaPdf());
  if (S.tab === "ajustes") cont.append(vistaAjustes());
  // La hoja del PDF necesita su ancho real: se monta después de insertarla.
  if (S.tab === "pdf") requestAnimationFrame(() => { montarHoja(); montarPaginas(); });
}

function pintarResumenes() {
  if (!S.config) return;
  const lista = campos();
  const oblig = lista.filter((c) => c.obligatorio).length;
  $("resumenFormulario").textContent = `${S.config.formulario.pasos.length} pasos · ${lista.length} campos · ${oblig} obligatorios`;
  const fotos = S.config.fotos;
  $("resumenFotos").textContent = `${fotos.length} fotos · ${fotos.filter((f) => f.obligatoria).length} obligatorias`;
  const sinUbicar = camposSinUbicar().length;
  $("resumenPdf").textContent = `${S.config.pdf.hoja1.cuadros.length} cuadros` + (sinUbicar ? ` · ${sinUbicar} sin ubicar` : "");
}

/** Validación local, antes de molestar al servidor. */
function problemasLocales() {
  const errores = [];
  S.config.formulario.pasos.forEach((p, i) => {
    if (!p.titulo.trim()) errores.push(`El paso ${i + 1} no tiene título.`);
    p.campos.forEach((c) => {
      if (!c.etiqueta.trim()) errores.push(`Hay un campo sin nombre en «${p.titulo}».`);
      if (CON_OPCIONES.includes(c.tipo) && !(c.opciones || []).some((o) => o.texto.trim()))
        errores.push(`«${c.etiqueta || "Campo"}» necesita al menos una opción.`);
    });
  });
  S.config.fotos.forEach((f) => { if (!f.nombre.trim()) errores.push("Hay una fotografía sin nombre."); });
  if (!S.nombre.trim()) errores.push("La plantilla necesita un nombre.");
  return errores;
}

/** Quita opciones vacías antes de enviar. */
function configParaEnviar() {
  const c = clonar(S.config);
  c.formulario.pasos.forEach((p) => p.campos.forEach((campo) => {
    if (campo.opciones) campo.opciones = campo.opciones.filter((o) => o.texto.trim());
  }));
  return c;
}

$("btnGuardar").addEventListener("click", guardar);
document.addEventListener("keydown", (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s" && !$("vistaEditor").hidden) {
    e.preventDefault();
    if (S.dirty) guardar();
  }
});

async function guardar() {
  if (S.guardando) return;
  limpiarPdf();
  const errores = problemasLocales();
  if (errores.length) {
    mostrarMensaje($("editorMensaje"), errores.slice(0, 4).join(" "));
    toast("Revise los avisos antes de guardar.", "error");
    return;
  }
  const lista = campos();
  const proyectos = S.plantilla.resumen?.proyectos ?? 0;
  const siguiente = (Number(S.plantilla.version) || 0) + 1;
  const ok = await modal({
    titulo: "Guardar cambios", icono: "✓", aceptar: "Guardar versión v" + siguiente,
    texto: `Se guardará «${S.nombre}» como versión v${siguiente}.`,
    detalle: [
      `${S.config.formulario.pasos.length} pasos · ${lista.length} campos (${lista.filter((c) => c.obligatorio).length} obligatorios)`,
      `${S.config.fotos.length} fotografías · ${S.config.pdf.hoja1.cuadros.length} cuadros en la hoja 1 · ${paginarFotos().paginas.length} hoja(s) de fotos`,
      proyectos
        ? `La usan ${proyectos} proyecto${proyectos === 1 ? "" : "s"}: los celulares recibirán esta versión al sincronizar y los PDF se generarán con el nuevo diseño.`
        : "Todavía ningún proyecto la usa."
    ]
  });
  if (!ok) return;
  S.guardando = true;
  const boton = $("btnGuardar");
  establecerCargando(boton, true, "Guardando…");
  try {
    const data = await leerRespuesta(await fetchConToken("admin/plantillas.php", {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        id: S.plantilla.id, accion: "actualizar", version: S.plantilla.version,
        nombre: S.nombre, descripcion: S.descripcion, config: configParaEnviar()
      })
    }));
    const resumenPrevio = S.plantilla.resumen;
    S.plantilla = data.data;
    if (!S.plantilla.resumen) S.plantilla.resumen = resumenPrevio;
    // Se adopta la versión normalizada del servidor (claves y posiciones ya
    // corregidas) para que lo que se ve sea exactamente lo que se guardó.
    S.config = rejillaActual(clonar(data.data.config));
    S.dirty = false;
    $("tplVersion").textContent = "v" + data.data.version;
    const estado = $("tplEstadoGuardado");
    estado.dataset.state = "saved";
    estado.textContent = "Guardado";
    limpiarMensaje($("editorMensaje"));
    toast("Plantilla guardada. Los teléfonos la recibirán al sincronizar.");
    pintarTab();
  } catch (error) {
    mostrarMensaje($("editorMensaje"), mensajeError(error));
    toast("No se guardó: " + mensajeError(error), "error");
  } finally {
    S.guardando = false;
    establecerCargando(boton, false);
    boton.disabled = !S.dirty;
  }
}

$("btnVistaPrevia").addEventListener("click", async () => {
  const boton = $("btnVistaPrevia");
  const ventana = window.open("about:blank", "_blank");
  establecerCargando(boton, true, "Generando…");
  try {
    const resp = await fetchConToken("admin/plantilla_vista_previa.php", {
      method: "POST", timeoutMs: 120000,
      headers: { "Content-Type": "application/json", Accept: "application/pdf" },
      body: JSON.stringify({ plantilla_id: S.plantilla.id, config: configParaEnviar() })
    });
    if (!(resp.headers.get("content-type") || "").includes("application/pdf")) {
      await leerRespuesta(resp);
      throw new Error("El servidor no devolvió un PDF.");
    }
    const url = URL.createObjectURL(await resp.blob());
    if (ventana) ventana.location.href = url; else window.open(url, "_blank");
    setTimeout(() => URL.revokeObjectURL(url), 120000);
  } catch (error) {
    ventana?.close();
    toast(mensajeError(error), "error");
  } finally {
    establecerCargando(boton, false);
  }
});

// ---------------------------------------------------------------------------
// Pestaña 1: Formulario
// ---------------------------------------------------------------------------

function vistaFormulario() {
  return h("div", { class: "tpl-split" },
    h("section", { class: "tpl-col-main", "aria-label": "Pasos y campos" },
      h("div", { class: "tpl-section-head" },
        h("div", {},
          h("h2", {}, "Pasos del formulario", ayuda("pasos")),
          h("p", {}, "Cada paso es una pantalla en el celular. Arrastre ⠿ para ordenar.")),
        h("button", { class: "button-secondary button-sm", type: "button", onclick: agregarPaso }, "＋ Agregar paso")),
      h("div", { id: "listaPasos", class: "tpl-pasos" }, ...S.config.formulario.pasos.map(tarjetaPaso))),
    h("aside", { class: "tpl-col-side", "aria-label": "Propiedades del campo" },
      h("div", { id: "inspector", class: "tpl-inspector surface" }, inspectorCampo()),
      h("div", { id: "vistaCelular" }, vistaCelular())));
}

function refrescarFormulario(conInspector = true) {
  const lista = $("listaPasos");
  if (!lista) return;
  lista.replaceChildren(...S.config.formulario.pasos.map(tarjetaPaso));
  if (conInspector) $("inspector").replaceChildren(inspectorCampo());
  $("vistaCelular").replaceChildren(vistaCelular());
}

function agregarPaso() {
  const pasos = S.config.formulario.pasos;
  const ids = new Set(pasos.map((p) => p.id));
  pasos.push({ id: claveUnica("paso_" + (pasos.length + 1), ids), titulo: "Paso " + (pasos.length + 1), campos: [] });
  marcarCambio();
  refrescarFormulario();
  setTimeout(() => document.querySelector(`#listaPasos .tpl-paso:last-child input`)?.select(), 30);
}

let arrastre = null; // {tipo: "campo"|"paso", paso, campo}

function tarjetaPaso(paso, ip) {
  const pasos = S.config.formulario.pasos;
  const titulo = h("input", {
    class: "tpl-paso-titulo", value: paso.titulo, maxlength: 80, "aria-label": "Título del paso " + (ip + 1),
    oninput: (e) => { paso.titulo = e.target.value; marcarCambio(); $("vistaCelular").replaceChildren(vistaCelular()); }
  });
  const card = h("section", { class: "tpl-paso", dataset: { paso: ip } },
    h("header", { class: "tpl-paso-head" },
      h("span", {
        class: "tpl-drag", title: "Arrastrar paso", draggable: "true", "aria-hidden": "true",
        ondragstart: (e) => { arrastre = { tipo: "paso", paso: ip }; e.dataTransfer.effectAllowed = "move"; card.classList.add("is-dragging"); },
        ondragend: () => card.classList.remove("is-dragging")
      }, "⠿"),
      h("span", { class: "tpl-paso-num" }, String(ip + 1)),
      titulo,
      h("span", { class: "tpl-paso-count" }, paso.campos.length + (paso.campos.length === 1 ? " campo" : " campos")),
      h("div", { class: "tpl-icon-group" },
        botonIcono("arriba", "Subir paso", ip === 0, () => moverPaso(ip, -1)),
        botonIcono("abajo", "Bajar paso", ip === pasos.length - 1, () => moverPaso(ip, 1)),
        botonIcono("borrar", "Eliminar paso", pasos.length === 1, () => eliminarPaso(ip), "danger"))),
    h("ol", { class: "tpl-campos" }, ...paso.campos.map((c, ic) => filaCampo(c, ip, ic))),
    h("button", { class: "tpl-add-campo", type: "button", onclick: () => elegirTipo(ip) },
      h("span", { "aria-hidden": "true" }, "＋"), "Agregar campo a «" + (paso.titulo || "este paso") + "»"));

  // Soltar un campo al final del paso o un paso encima de otro.
  card.addEventListener("dragover", (e) => { if (arrastre) { e.preventDefault(); card.classList.add("is-over"); } });
  card.addEventListener("dragleave", () => card.classList.remove("is-over"));
  card.addEventListener("drop", (e) => {
    e.preventDefault();
    card.classList.remove("is-over");
    if (!arrastre) return;
    if (arrastre.tipo === "paso") moverPasoA(arrastre.paso, ip);
    else if (!e.target.closest(".tpl-campo")) moverCampo(arrastre.paso, arrastre.campo, ip, paso.campos.length);
    arrastre = null;
  });
  return card;
}

function botonIcono(icono, etiqueta, deshabilitado, accion, tono) {
  return h("button", {
    class: "tpl-icon-btn" + (tono ? " is-" + tono : ""), type: "button", title: etiqueta, "aria-label": etiqueta,
    disabled: deshabilitado, onclick: (e) => { e.stopPropagation(); accion(); }
  }, ICONOS[icono] ? ico(icono) : icono);
}

function filaCampo(campo, ip, ic) {
  const sel = S.sel && S.sel.paso === ip && S.sel.campo === ic;
  const tipo = TIPOS[campo.tipo] || TIPOS.texto;
  const detalle = CON_OPCIONES.includes(campo.tipo)
    ? (campo.opciones || []).filter((o) => o.texto).map((o) => o.texto).slice(0, 4).join(" · ") + ((campo.opciones || []).length > 4 ? " …" : "")
    : campo.tipo === "numero" ? [campo.unidad, campo.min !== null && campo.min !== undefined ? "mín " + campo.min : "", campo.max ? "máx " + campo.max : ""].filter(Boolean).join(" · ")
    : tipo.desc;
  const fila = h("li", {
    class: "tpl-campo" + (sel ? " is-selected" : ""), tabindex: "0", draggable: "true",
    "aria-label": `${campo.etiqueta}, ${tipo.nombre}${campo.obligatorio ? ", obligatorio" : ""}`,
    onclick: () => seleccionarCampo(ip, ic),
    onkeydown: (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); seleccionarCampo(ip, ic); } },
    ondragstart: (e) => { arrastre = { tipo: "campo", paso: ip, campo: ic }; e.dataTransfer.effectAllowed = "move"; fila.classList.add("is-dragging"); },
    ondragend: () => fila.classList.remove("is-dragging"),
    ondragover: (e) => { if (arrastre?.tipo === "campo") { e.preventDefault(); fila.classList.add("is-over"); } },
    ondragleave: () => fila.classList.remove("is-over"),
    ondrop: (e) => {
      e.preventDefault(); e.stopPropagation(); fila.classList.remove("is-over");
      if (arrastre?.tipo === "campo") moverCampo(arrastre.paso, arrastre.campo, ip, ic);
      arrastre = null;
    }
  },
    h("span", { class: "tpl-drag", "aria-hidden": "true" }, "⠿"),
    h("span", { class: "tpl-tipo-icono tpl-tipo-" + campo.tipo, "aria-hidden": "true" }, tipo.icono),
    h("span", { class: "tpl-campo-copy" },
      h("strong", {}, campo.etiqueta || "Sin nombre"),
      h("small", {}, tipo.nombre + (detalle ? " — " + detalle : ""))),
    campo.obligatorio ? h("span", { class: "tpl-badge-oblig" }, "Obligatorio") : h("span", { class: "tpl-badge-opc" }, "Opcional"),
    h("div", { class: "tpl-icon-group" },
      botonIcono("copiar", "Duplicar campo", false, () => duplicarCampo(ip, ic)),
      botonIcono("borrar", "Eliminar campo", false, () => eliminarCampo(ip, ic), "danger")));
  return fila;
}

function seleccionarCampo(ip, ic) {
  S.sel = { paso: ip, campo: ic };
  refrescarFormulario();
}

function moverPaso(ip, delta) { moverPasoA(ip, ip + delta); }
function moverPasoA(desde, hasta) {
  const pasos = S.config.formulario.pasos;
  if (desde === hasta || hasta < 0 || hasta >= pasos.length) return;
  const [p] = pasos.splice(desde, 1);
  pasos.splice(hasta, 0, p);
  S.sel = null;
  marcarCambio();
  refrescarFormulario();
}

async function eliminarPaso(ip) {
  const paso = S.config.formulario.pasos[ip];
  if (!(await modal({
    titulo: "Eliminar paso", tono: "danger", aceptar: "Eliminar paso",
    texto: `¿Eliminar el paso «${paso.titulo || "sin título"}»?`,
    detalle: paso.campos.length
      ? [`También se eliminan sus ${paso.campos.length} campo${paso.campos.length === 1 ? "" : "s"} y se quitan de la hoja 1 del PDF.`,
        "Las respuestas ya guardadas en inspecciones no se borran.", "Puede deshacerlo saliendo sin guardar."]
      : ["El paso está vacío."]
  }))) return;
  paso.campos.forEach((c) => quitarCampoDelPdf(c.clave));
  S.config.formulario.pasos.splice(ip, 1);
  S.sel = null;
  marcarCambio();
  refrescarFormulario();
}

function moverCampo(pDesde, cDesde, pHasta, cHasta) {
  const pasos = S.config.formulario.pasos;
  const [campo] = pasos[pDesde].campos.splice(cDesde, 1);
  if (pDesde === pHasta && cHasta > cDesde) cHasta--;
  pasos[pHasta].campos.splice(Math.min(cHasta, pasos[pHasta].campos.length), 0, campo);
  S.sel = { paso: pHasta, campo: pasos[pHasta].campos.indexOf(campo) };
  marcarCambio();
  refrescarFormulario();
}

function duplicarCampo(ip, ic) {
  const original = S.config.formulario.pasos[ip].campos[ic];
  const copia = clonar(original);
  copia.etiqueta = original.etiqueta + " (copia)";
  copia.clave = claveUnica(original.clave + "_copia", clavesUsadas());
  S.config.formulario.pasos[ip].campos.splice(ic + 1, 0, copia);
  ubicarCampoEnPdf(copia, original);
  S.sel = { paso: ip, campo: ic + 1 };
  marcarCambio();
  refrescarFormulario();
}

async function eliminarCampo(ip, ic) {
  const campo = S.config.formulario.pasos[ip].campos[ic];
  if (!(await modal({
    titulo: "Eliminar campo", tono: "danger", aceptar: "Eliminar campo",
    texto: `¿Eliminar el campo «${campo.etiqueta || "sin nombre"}»?`,
    detalle: ["Se quita también de la hoja 1 del PDF.",
      "Las respuestas ya guardadas en inspecciones no se borran, pero dejan de mostrarse.", "Puede deshacerlo saliendo sin guardar."]
  }))) return;
  S.config.formulario.pasos[ip].campos.splice(ic, 1);
  quitarCampoDelPdf(campo.clave);
  S.sel = null;
  marcarCambio();
  refrescarFormulario();
}

/** Un campo borrado deja de aparecer en el PDF; el cuadro que queda vacío se quita. */
function quitarCampoDelPdf(clave) {
  const cuadros = S.config.pdf.hoja1.cuadros;
  for (let i = cuadros.length - 1; i >= 0; i--) {
    const cuadro = cuadros[i];
    if (!cuadro.campos || !cuadro.campos.includes(clave)) continue;
    cuadro.campos = cuadro.campos.filter((k) => k !== clave);
    if (!cuadro.campos.length) cuadros.splice(i, 1);
  }
  if (S.config.pdf.comentarios.campo === clave) S.config.pdf.comentarios.campo = null;
}

// --- El PDF sigue al formulario y a las fotografías ---------------------------
//
// Lo que se arma en «Formulario» y «Fotografías» aparece solo en el PDF: un
// campo nuevo recibe su cuadro en la hoja 1, una foto nueva entra al panel de
// su categoría y lo que se borra desaparece (sin dejar cuadros ni paneles
// vacíos). El diseño manual (posición, tamaño, colores) se respeta.

const TIPOS_SIMPLES = ["texto", "numero", "fecha", "si_no"];
const normal = (t) => String(t ?? "").trim().toLowerCase();

/** Ubica un campo recién creado en la hoja 1 sin quitarle la selección al usuario. */
function ubicarCampoEnPdf(campo, vecino) {
  const pdf = S.config.pdf;
  if (pdf.hoja1.cuadros.some((c) => (c.campos || []).includes(campo.clave)) || pdf.comentarios.campo === campo.clave) return;
  // Un párrafo es, casi siempre, el comentario final del reporte.
  if (campo.tipo === "parrafo" && !pdf.comentarios.campo) { pdf.comentarios.campo = campo.clave; return; }
  // Un dato simple se suma al cuadro de su vecino si ese cuadro ya agrupa datos simples.
  if (TIPOS_SIMPLES.includes(campo.tipo) && vecino) {
    const grupo = pdf.hoja1.cuadros.find((c) => c.tipo === "campos" && (c.campos || []).length > 1 && c.campos.includes(vecino.clave)
      && c.campos.every((k) => TIPOS_SIMPLES.includes(campoPorClave(k)?.tipo)));
    if (grupo) {
      grupo.campos.splice(grupo.campos.indexOf(vecino.clave) + 1, 0, campo.clave);
      crecerCuadro(grupo);
      return;
    }
  }
  nuevoCuadro("campos", [campo.clave], { seleccionar: false, silencioso: true });
}

/** Agranda un cuadro hasta el alto que necesita, solo si hay lugar libre debajo. */
function crecerCuadro(c) {
  const lista = (c.campos || []).map(campoPorClave).filter(Boolean);
  if (!lista.length) return;
  for (let alto = altoSugerido(lista, c.columnas || 1); alto > c.h; alto--) {
    if (c.y + alto <= HOJA.filas && !seSolapa(c, c.x, c.y, c.w, alto)) { c.h = alto; return; }
  }
}

function crecerCuadrosDe(clave) {
  S.config.pdf.hoja1.cuadros.filter((c) => (c.campos || []).includes(clave)).forEach(crecerCuadro);
}

/** El título de un cuadro de un solo campo sigue al nombre del campo mientras no se haya personalizado. */
function renombrarTituloEnPdf(clave, antes, despues) {
  for (const c of S.config.pdf.hoja1.cuadros) {
    if (c.campos?.length === 1 && c.campos[0] === clave && (c.titulo === antes || !String(c.titulo || "").trim())) {
      c.titulo = (despues || "").slice(0, 80);
    }
  }
}

function fotoEnPanel(clave) {
  return S.config.pdf.paneles.find((p) => p.fotos.includes(clave)) || null;
}

/** Una foto nueva (o que dejó de ser panorámica) entra al panel de su categoría. */
function fotoAlPdf(f) {
  const pdf = S.config.pdf;
  if (f.panoramica) {
    fotoFueraDePaneles(f.clave);
    if (!pdf.hoja1.cuadros.some((c) => c.tipo === "panoramica")) nuevoCuadro("panoramica", null, { seleccionar: false, silencioso: true });
    return;
  }
  const paneles = pdf.paneles;
  // Sin paneles propios el PDF arma uno por categoría: no hay nada que hacer.
  if (!paneles.length || fotoEnPanel(f.clave)) return;
  const cat = normal(f.categoria || "General");
  const categoriaDe = (k) => normal(S.config.fotos.find((x) => x.clave === k)?.categoria);
  let destino = paneles.find((p) => normal(p.titulo) === cat)
    || paneles.find((p) => p.fotos.some((k) => k !== f.clave && categoriaDe(k) === cat));
  if (!destino) {
    const modelo = paneles[paneles.length - 1];
    destino = nuevoPanel({ titulo: (f.categoria || "General").slice(0, 80), color: modelo?.color || "#7B7B7B", columnas: modelo?.columnas || 3 });
    paneles.push(destino);
  }
  destino.fotos.push(f.clave);
}

/** Saca una foto de los paneles; el panel que queda vacío se quita. */
function fotoFueraDePaneles(clave) {
  const paneles = S.config.pdf.paneles;
  for (let i = paneles.length - 1; i >= 0; i--) {
    if (!paneles[i].fotos.includes(clave)) continue;
    paneles[i].fotos = paneles[i].fotos.filter((k) => k !== clave);
    if (paneles[i].tamanos && !Array.isArray(paneles[i].tamanos)) delete paneles[i].tamanos[clave];
    if (!paneles[i].fotos.length) paneles.splice(i, 1);
  }
}

function renombrarClaveFoto(vieja, nueva) {
  if (vieja === nueva) return;
  S.config.pdf.paneles.forEach((p) => {
    p.fotos = p.fotos.map((k) => (k === vieja ? nueva : k));
    const t = tamanosDe(p);
    if (t[vieja]) { t[nueva] = t[vieja]; delete t[vieja]; }
  });
}

/** Al cambiar de categoría la foto se va al panel de la nueva, salvo que se haya movido a mano a otro panel. */
function fotoCambioCategoria(f, anterior) {
  const panel = fotoEnPanel(f.clave);
  const deSuCategoria = (k) => k !== f.clave && normal(S.config.fotos.find((x) => x.clave === k)?.categoria) === normal(anterior);
  if (panel && normal(panel.titulo) !== normal(anterior) && !panel.fotos.some(deSuCategoria)) return;
  fotoFueraDePaneles(f.clave);
  fotoAlPdf(f);
}

/**
 * Deja el PDF coherente con el formulario y las fotos: quita campos y fotos
 * que ya no existen (y los cuadros/paneles que por eso quedan vacíos) y saca
 * la panorámica de los paneles. Se ejecuta al abrir y antes de guardar.
 */
function limpiarPdf() {
  const pdf = S.config.pdf;
  const claves = new Set(campos().map((c) => c.clave));
  const cuadros = pdf.hoja1.cuadros;
  for (let i = cuadros.length - 1; i >= 0; i--) {
    const c = cuadros[i];
    if (c.tipo !== "campos" || !c.campos?.length) continue;
    c.campos = c.campos.filter((k) => claves.has(k));
    if (!c.campos.length) cuadros.splice(i, 1);
  }
  if (pdf.comentarios.campo && !claves.has(pdf.comentarios.campo)) pdf.comentarios.campo = null;
  const fotos = new Map(S.config.fotos.map((f) => [f.clave, f]));
  for (let i = pdf.paneles.length - 1; i >= 0; i--) {
    const p = pdf.paneles[i];
    if (!p.fotos.length) continue;
    p.fotos = p.fotos.filter((k) => fotos.has(k) && !fotos.get(k).panoramica);
    if (!p.fotos.length) pdf.paneles.splice(i, 1);
  }
  for (const p of pdf.paneles) {
    const t = tamanosDe(p);
    for (const k of Object.keys(t)) if (!p.fotos.includes(k)) delete t[k];
  }
}

/** Vuelve a armar la hoja 1 y los paneles de fotos desde cero. */
async function rehacerPdf() {
  if (!(await modal({
    titulo: "Armar el PDF automáticamente", aceptar: "Armar de nuevo",
    texto: "Se vuelve a armar la hoja 1 y las páginas de fotos a partir del formulario y de las fotografías.",
    detalle: ["Un cuadro por paso para los datos simples y uno por cada pregunta de opciones.",
      "Un panel de fotos por categoría, en el orden de la lista.",
      "Se conservan el encabezado, los logos, los comentarios y las firmas.",
      "Se pierden las posiciones hechas a mano y los cuadros de texto. Puede deshacerlo saliendo sin guardar."]
  }))) return;
  const pdf = S.config.pdf;
  pdf.hoja1.cuadros.length = 0;
  if (S.config.fotos.some((f) => f.panoramica)) {
    pdf.hoja1.cuadros.push({ id: "panoramica", tipo: "panoramica", titulo: "Foto Panorámica", color: "#F0F0F0", x: SUB * 14, y: 0, w: SUB * 10, h: SUB * 24 });
  }
  let color = 0;
  let sinLugar = 0;
  const ponerCuadro = (titulo, lista) => {
    const c = nuevoCuadro("campos", lista.map((x) => x.clave),
      { seleccionar: false, silencioso: true, titulo: titulo.slice(0, 80), color: PALETA[color % PALETA.length][0] });
    if (c) color++; else sinLugar += lista.length;
  };
  for (const paso of S.config.formulario.pasos) {
    const resto = paso.campos.filter((c) => c.clave !== pdf.comentarios.campo);
    const simples = resto.filter((c) => TIPOS_SIMPLES.includes(c.tipo));
    if (simples.length) ponerCuadro(paso.titulo || "Datos", simples);
    for (const c of resto.filter((x) => !TIPOS_SIMPLES.includes(x.tipo))) {
      if (c.tipo === "parrafo" && !pdf.comentarios.campo) { pdf.comentarios.campo = c.clave; continue; }
      ponerCuadro(c.etiqueta || "Campo", [c]);
    }
  }
  pdf.paneles = [];
  asegurarPaneles();
  S.sel = { encabezado: true };
  S.selPanel = null;
  S.selFoto = null;
  marcarCambio();
  pintarTab();
  toast(sinLugar ? `PDF armado. ${sinLugar} campo(s) no cupieron en la hoja 1: achique algunos cuadros.` : "PDF armado con todo el formulario y las fotografías.",
    sinLugar ? "error" : undefined);
}

function elegirTipo(ip) {
  const dlg = $("dlgTipo");
  const grid = $("tiposGrid");
  grid.replaceChildren(...Object.entries(TIPOS).map(([tipo, t]) =>
    h("button", { class: "tpl-tipo-card", type: "button", onclick: () => { dlg.close(); agregarCampo(ip, tipo); } },
      h("span", { class: "tpl-tipo-icono tpl-tipo-" + tipo, "aria-hidden": "true" }, t.icono),
      h("strong", {}, t.nombre),
      h("small", {}, t.desc))));
  dlg.showModal();
}

function agregarCampo(ip, tipo) {
  const hermanos = S.config.formulario.pasos[ip].campos;
  const vecino = hermanos[hermanos.length - 1] || null;
  const usadas = clavesUsadas();
  const etiqueta = "Nuevo campo " + (campos().length + 1);
  const campo = { clave: claveUnica(etiqueta, usadas), etiqueta, tipo, obligatorio: false, ayuda: "", _nuevo: true };
  ajustarPorTipo(campo);
  S.config.formulario.pasos[ip].campos.push(campo);
  ubicarCampoEnPdf(campo, vecino);
  S.sel = { paso: ip, campo: S.config.formulario.pasos[ip].campos.length - 1 };
  marcarCambio();
  refrescarFormulario();
  setTimeout(() => { const i = $("insEtiqueta"); i?.focus(); i?.select(); }, 40);
}

/** Completa las propiedades que necesita cada tipo, sin perder las existentes. */
function ajustarPorTipo(campo) {
  if (CON_OPCIONES.includes(campo.tipo) && !(campo.opciones || []).length) {
    campo.opciones = campo.tipo === "rst"
      ? [{ valor: "buen_estado", texto: "Buen estado" }, { valor: "mal_estado", texto: "Mal estado" }]
      : [{ valor: "opcion_1", texto: "Opción 1" }, { valor: "opcion_2", texto: "Opción 2" }];
  }
  if (campo.tipo === "rst" && !campo.fases) campo.fases = ["R", "S", "T"];
  if (campo.tipo === "numero") {
    campo.unidad ??= ""; campo.decimales ??= 2; campo.min ??= 0; campo.max ??= null;
  }
  if (campo.tipo === "texto" || campo.tipo === "parrafo") campo.max_largo ??= campo.tipo === "parrafo" ? 2000 : 200;
}

function campoSeleccionado() {
  if (!S.sel || S.sel.paso === undefined) return null;
  return S.config.formulario.pasos[S.sel.paso]?.campos[S.sel.campo] || null;
}

function inspectorCampo() {
  const campo = campoSeleccionado();
  if (!campo) {
    return h("div", { class: "tpl-inspector-empty" },
      h("span", { "aria-hidden": "true" }, "☝"),
      h("strong", {}, "Seleccione un campo"),
      h("p", {}, "Aquí podrá cambiar su nombre, tipo, opciones y si es obligatorio."));
  }
  const refrescarLista = () => {
    $("listaPasos").replaceChildren(...S.config.formulario.pasos.map(tarjetaPaso));
    $("vistaCelular").replaceChildren(vistaCelular());
  };
  const cambio = (fn, repintarInspector) => (e) => {
    fn(e);
    marcarCambio();
    refrescarLista();
    if (repintarInspector) $("inspector").replaceChildren(inspectorCampo());
  };

  const etiqueta = h("input", {
    id: "insEtiqueta", value: campo.etiqueta, maxlength: 120, required: true,
    oninput: cambio((e) => {
      const antes = campo.etiqueta;
      campo.etiqueta = e.target.value;
      renombrarTituloEnPdf(campo.clave, antes, campo.etiqueta);
      // Mientras el campo es nuevo, su clave sigue al nombre; luego queda fija
      // para no desconectar respuestas ya guardadas.
      if (campo._nuevo) {
        const usadas = clavesUsadas(); usadas.delete(campo.clave);
        const vieja = campo.clave;
        campo.clave = claveUnica(campo.etiqueta || "campo", usadas);
        renombrarClaveEnPdf(vieja, campo.clave);
        const clave = $("insClave"); if (clave) clave.value = campo.clave;
      }
    })
  });

  const tipoSelect = h("select", {
    id: "insTipo",
    onchange: cambio((e) => { campo.tipo = e.target.value; ajustarPorTipo(campo); crecerCuadrosDe(campo.clave); }, true)
  }, ...Object.entries(TIPOS).map(([k, t]) => new Option(t.icono + "  " + t.nombre, k, false, k === campo.tipo)));

  const obligatorio = interruptor("Obligatorio", "El inspector no podrá guardar sin responderlo.", campo.obligatorio,
    cambio((e) => { campo.obligatorio = e.target.checked; }));

  const bloques = [
    h("div", { class: "tpl-inspector-head" },
      h("span", { class: "tpl-tipo-icono tpl-tipo-" + campo.tipo, "aria-hidden": "true" }, TIPOS[campo.tipo].icono),
      h("div", {}, h("p", { class: "eyebrow" }, "Campo seleccionado"), h("h3", {}, campo.etiqueta || "Sin nombre")), ayuda("campo", true)),
    campoForm("insEtiqueta", "Pregunta o nombre del dato", etiqueta),
    campoForm("insTipo", "Tipo de respuesta", tipoSelect),
    obligatorio,
    campoForm("insAyuda", "Ayuda para el inspector (opcional)", h("input", {
      id: "insAyuda", value: campo.ayuda || "", maxlength: 200, placeholder: "Ej.: medir desde el eje de la torre",
      oninput: cambio((e) => { campo.ayuda = e.target.value; })
    }))
  ];

  if (campo.tipo === "numero") {
    bloques.push(h("div", { class: "tpl-grid-2" },
      campoForm("insUnidad", "Unidad", h("input", { id: "insUnidad", value: campo.unidad || "", maxlength: 12, placeholder: "m, kg, °C…",
        oninput: cambio((e) => { campo.unidad = e.target.value; }) })),
      campoForm("insDec", "Decimales", h("select", { id: "insDec", onchange: cambio((e) => { campo.decimales = Number(e.target.value); }) },
        ...[0, 1, 2, 3, 4].map((n) => new Option(n === 0 ? "Entero" : String(n), n, false, n === Number(campo.decimales ?? 2))))),
      campoForm("insMin", "Mínimo", h("input", { id: "insMin", type: "number", step: "any", value: campo.min ?? "",
        oninput: cambio((e) => { campo.min = e.target.value === "" ? null : Number(e.target.value); }) })),
      campoForm("insMax", "Máximo", h("input", { id: "insMax", type: "number", step: "any", value: campo.max ?? "", placeholder: "Sin límite",
        oninput: cambio((e) => { campo.max = e.target.value === "" ? null : Number(e.target.value); }) }))));
  }

  if (campo.tipo === "rst") {
    bloques.push(campoForm("insFases", "Columnas (fases)", h("input", {
      id: "insFases", value: (campo.fases || []).join(", "), placeholder: "R, S, T",
      oninput: cambio((e) => {
        campo.fases = e.target.value.split(/[,;\s]+/).map((f) => f.trim().toUpperCase()).filter(Boolean).slice(0, 6);
        if (!campo.fases.length) campo.fases = ["R", "S", "T"];
      })
    }), "Separadas por comas. Por defecto R, S, T."));
  }

  if (CON_OPCIONES.includes(campo.tipo)) bloques.push(editorOpciones(campo, () => { crecerCuadrosDe(campo.clave); marcarCambio(); refrescarLista(); }));

  bloques.push(h("details", { class: "tpl-avanzado" },
    h("summary", {}, "Avanzado"),
    campoForm("insClave", "Clave interna", h("input", {
      id: "insClave", value: campo.clave, maxlength: 48, spellcheck: "false",
      onchange: cambio((e) => {
        const usadas = clavesUsadas(); usadas.delete(campo.clave);
        const nueva = claveUnica(e.target.value || campo.etiqueta, usadas);
        renombrarClaveEnPdf(campo.clave, nueva);
        campo.clave = nueva; campo._nuevo = false; e.target.value = nueva;
      })
    }), "Se usa en el Excel y en la base. Cámbiela solo antes de empezar las inspecciones.")));

  return h("div", { class: "tpl-inspector-body" }, ...bloques);
}

function renombrarClaveEnPdf(vieja, nueva) {
  for (const cuadro of S.config.pdf.hoja1.cuadros) {
    if (cuadro.campos) cuadro.campos = cuadro.campos.map((k) => (k === vieja ? nueva : k));
  }
  if (S.config.pdf.comentarios.campo === vieja) S.config.pdf.comentarios.campo = nueva;
}

function campoForm(id, etiqueta, control, ayuda) {
  return h("div", { class: "campo" },
    h("label", { for: id }, etiqueta),
    control,
    ayuda ? h("small", {}, ayuda) : null);
}

function interruptor(etiqueta, ayuda, marcado, onchange) {
  const id = "sw_" + Math.random().toString(36).slice(2, 8);
  return h("label", { class: "tpl-switch", for: id },
    h("input", { id, type: "checkbox", role: "switch", checked: Boolean(marcado), onchange }),
    h("span", { class: "tpl-switch-track", "aria-hidden": "true" }),
    h("span", { class: "tpl-switch-copy" }, h("strong", {}, etiqueta), ayuda ? h("small", {}, ayuda) : null));
}

function editorOpciones(campo, alCambiar) {
  const lista = h("ol", { class: "tpl-opciones" });
  const pintar = () => {
    lista.replaceChildren(...campo.opciones.map((op, i) => h("li", {},
      h("span", { class: "tpl-opcion-num", "aria-hidden": "true" }, String(i + 1)),
      h("input", {
        value: op.texto, maxlength: 80, "aria-label": "Opción " + (i + 1), placeholder: "Texto de la opción",
        oninput: (e) => {
          op.texto = e.target.value;
          // El valor interno sigue al texto solo en opciones nuevas.
          if (op._nueva || !op.valor) op.valor = slug(op.texto || "opcion");
          alCambiar();
        },
        onkeydown: (e) => {
          if (e.key === "Enter") { e.preventDefault(); agregar(i + 1); }
          if (e.key === "Backspace" && !e.target.value && campo.opciones.length > 1) { e.preventDefault(); quitar(i, true); }
        }
      }),
      botonIcono("arriba", "Subir opción", i === 0, () => mover(i, -1)),
      botonIcono("abajo", "Bajar opción", i === campo.opciones.length - 1, () => mover(i, 1)),
      botonIcono("cerrar", "Quitar opción", campo.opciones.length === 1, () => quitar(i), "danger"))));
  };
  const enfocar = (i) => setTimeout(() => lista.querySelectorAll("input")[i]?.focus(), 10);
  const agregar = (pos) => {
    campo.opciones.splice(pos ?? campo.opciones.length, 0, { valor: "", texto: "", _nueva: true });
    pintar(); alCambiar(); enfocar(pos ?? campo.opciones.length - 1);
  };
  const quitar = (i, enfocarAnterior) => { campo.opciones.splice(i, 1); pintar(); alCambiar(); if (enfocarAnterior) enfocar(Math.max(0, i - 1)); };
  const mover = (i, d) => { const [o] = campo.opciones.splice(i, 1); campo.opciones.splice(i + d, 0, o); pintar(); alCambiar(); };
  pintar();

  const pegar = h("details", { class: "tpl-pegar" },
    h("summary", {}, "Pegar una lista"),
    h("textarea", { rows: 4, placeholder: "Una opción por línea", id: "insPegar" }),
    h("button", {
      class: "button-secondary button-sm", type: "button", onclick: () => {
        const lineas = $("insPegar").value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
        if (!lineas.length) return;
        const existentes = new Set(campo.opciones.map((o) => o.texto.toLowerCase()));
        lineas.forEach((t) => { if (!existentes.has(t.toLowerCase())) campo.opciones.push({ valor: slug(t), texto: t, _nueva: true }); });
        campo.opciones = campo.opciones.filter((o) => o.texto.trim());
        $("insPegar").value = "";
        pintar(); alCambiar();
      }
    }, "Agregar a la lista"));

  return h("div", { class: "tpl-opciones-box" },
    h("div", { class: "tpl-opciones-head" },
      h("strong", {}, campo.tipo === "rst" ? "Filas de la tabla" : "Opciones"),
      h("small", {}, "Enter agrega otra")),
    lista,
    h("button", { class: "tpl-add-opcion", type: "button", onclick: () => agregar() }, "＋ Agregar opción"),
    pegar);
}

/** Maqueta del paso en un celular: lo que verá el inspector. */
function vistaCelular() {
  const ip = S.sel?.paso ?? 0;
  const paso = S.config.formulario.pasos[ip];
  if (!paso) return h("div");
  const total = S.config.formulario.pasos.length;
  const control = (c) => {
    switch (c.tipo) {
      case "seleccion":
        return (c.opciones || []).length <= 5
          ? h("div", { class: "cel-chips" }, ...(c.opciones || []).map((o, i) => h("span", { class: i === 0 ? "is-on" : "" }, o.texto || "…")))
          : h("div", { class: "cel-input cel-select" }, "Seleccione…");
      case "multiple":
        return h("div", { class: "cel-chips" }, ...(c.opciones || []).map((o) => h("span", {}, "✓ " + (o.texto || "…"))));
      case "si_no": return h("div", { class: "cel-sino" }, h("span", { class: "is-on" }, "Sí"), h("span", {}, "No"));
      case "numero": return h("div", { class: "cel-input" }, "0", c.unidad ? h("em", {}, c.unidad) : null);
      case "fecha": return h("div", { class: "cel-input" }, "dd/mm/aaaa ◷");
      case "parrafo": return h("div", { class: "cel-input cel-area" }, "Escriba aquí…");
      case "rst":
        return h("table", { class: "cel-rst" },
          h("tr", {}, h("th", {}), ...(c.fases || []).map((f) => h("th", {}, f))),
          ...(c.opciones || []).slice(0, 5).map((o) => h("tr", {}, h("td", {}, o.texto), ...(c.fases || []).map(() => h("td", {}, "☐")))));
      default: return h("div", { class: "cel-input" }, "Texto…");
    }
  };
  return h("figure", { class: "tpl-celular", "aria-label": "Vista previa en el celular" },
    h("figcaption", {}, "Así se ve en el celular"),
    h("div", { class: "cel-marco" },
      h("div", { class: "cel-barra" }, h("span", {}, "Paso " + (ip + 1) + " de " + total), h("strong", {}, paso.titulo || "Sin título")),
      h("div", { class: "cel-progreso" }, h("span", { style: { width: ((ip + 1) / total) * 100 + "%" } })),
      h("div", { class: "cel-cuerpo" },
        paso.campos.length ? null : h("p", { class: "cel-vacio" }, "Este paso no tiene campos."),
        ...paso.campos.map((c, ic) => h("div", { class: "cel-campo" + (S.sel?.campo === ic ? " is-sel" : "") },
          h("label", {}, c.etiqueta || "Sin nombre", c.obligatorio ? h("b", {}, " *") : null),
          c.ayuda ? h("small", {}, c.ayuda) : null,
          control(c)))),
      h("div", { class: "cel-pie" }, h("span", {}, "Anterior"), h("span", { class: "is-on" }, ip + 1 === total ? "Guardar" : "Siguiente"))));
}

// ---------------------------------------------------------------------------
// Pestaña 2: Fotografías
// ---------------------------------------------------------------------------

function vistaFotos() {
  const fotos = S.config.fotos;
  const categorias = [...new Set(fotos.map((f) => f.categoria).filter(Boolean))];
  const datalist = h("datalist", { id: "listaCategorias" }, ...categorias.map((c) => h("option", { value: c })));
  const lista = h("ol", { id: "listaFotos", class: "tpl-fotos" });
  const pintar = () => {
    lista.replaceChildren(...fotos.map((f, i) => filaFoto(f, i, pintar)));
    pintarResumenes();
    $("fotosStats")?.replaceChildren(...statsFotos());
  };
  pintar();

  const agregar = () => {
    const usadas = new Set(fotos.map((f) => f.clave));
    const nombre = "Foto " + (fotos.length + 1);
    const nueva = { clave: claveUnica(nombre, usadas), nombre, obligatoria: false, cantidad: 1,
      categoria: categorias[categorias.length - 1] || "General", ayuda: "", panoramica: false, _nueva: true };
    fotos.push(nueva);
    fotoAlPdf(nueva);
    marcarCambio(); pintar();
    setTimeout(() => lista.querySelector("li:last-child input")?.select(), 20);
  };

  return h("div", { class: "tpl-fotos-wrap" },
    datalist,
    h("div", { class: "tpl-section-head" },
      h("div", {},
        h("h2", {}, "Fotografías que se piden", ayuda("fotos")),
        h("p", {}, "El orden es el que verá el inspector. Marque cuál es la panorámica: va en grande en la hoja 1 del PDF.")),
      h("div", { class: "acciones" },
        h("button", { class: "button-ghost button-sm", type: "button", onclick: () => {
          cambiarTab("pdf");
          setTimeout(() => $("titPaneles")?.scrollIntoView({ behavior: "smooth", block: "start" }), 120);
        } }, "Ver cómo salen en el PDF →"),
        h("details", { class: "tpl-pegar tpl-pegar-inline" },
          h("summary", { class: "button-secondary button-sm" }, "Pegar lista"),
          h("div", { class: "tpl-pegar-pop" },
            h("textarea", { id: "fotosPegar", rows: 6, placeholder: "Un nombre de foto por línea" }),
            h("button", { class: "button-primary button-sm", type: "button", onclick: () => {
              const usadas = new Set(fotos.map((f) => f.clave));
              $("fotosPegar").value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean).forEach((n) => {
                const clave = claveUnica(n, usadas); usadas.add(clave);
                const nueva = { clave, nombre: n.slice(0, 80), obligatoria: false, cantidad: 1, categoria: categorias[categorias.length - 1] || "General", ayuda: "", panoramica: false, _nueva: true };
                fotos.push(nueva);
                fotoAlPdf(nueva);
              });
              $("fotosPegar").value = ""; marcarCambio(); pintar();
            } }, "Agregar fotos"))),
        h("button", { class: "button-primary button-sm", type: "button", onclick: agregar }, "＋ Agregar foto"))),
    h("div", { id: "fotosStats", class: "tpl-stats" }, ...statsFotos()),
    h("div", { class: "tpl-fotos-head", "aria-hidden": "true" },
      h("span", {}), h("span", {}), h("span", {}, "Nombre de la foto"), h("span", {}, "Categoría"), h("span", {}, "Tomas"),
      h("span", {}, "Obligatoria"), h("span", {}, "Panorámica"), h("span", {})),
    lista,
    fotos.length ? null : h("div", { class: "tpl-empty" }, h("strong", {}, "Sin fotografías"), h("p", {}, "Agregue la primera con «Agregar foto».")));
}

function statsFotos() {
  const fotos = S.config.fotos;
  const tomas = fotos.reduce((s, f) => s + Number(f.cantidad || 1), 0);
  const pano = fotos.find((f) => f.panoramica);
  return [
    h("span", { class: "tpl-stat" }, h("strong", {}, fotos.length), " tipos de foto"),
    h("span", { class: "tpl-stat" }, h("strong", {}, tomas), " tomas en total"),
    h("span", { class: "tpl-stat is-red" }, h("strong", {}, fotos.filter((f) => f.obligatoria).length), " obligatorias"),
    h("span", { class: "tpl-stat" + (pano ? "" : " is-warn") }, pano ? "Panorámica: " + pano.nombre : "Sin panorámica elegida")
  ];
}

let arrastreFoto = null;

function filaFoto(f, i, repintar) {
  const fotos = S.config.fotos;
  let catAntes = f.categoria;
  const cambio = (fn) => (e) => { fn(e); marcarCambio(); repintar(); };
  const fila = h("li", {
    class: "tpl-foto" + (f.panoramica ? " is-pano" : ""), draggable: "true",
    ondragstart: () => { arrastreFoto = i; fila.classList.add("is-dragging"); },
    ondragend: () => fila.classList.remove("is-dragging"),
    ondragover: (e) => { if (arrastreFoto !== null) { e.preventDefault(); fila.classList.add("is-over"); } },
    ondragleave: () => fila.classList.remove("is-over"),
    ondrop: (e) => {
      e.preventDefault(); fila.classList.remove("is-over");
      if (arrastreFoto === null || arrastreFoto === i) return;
      const [x] = fotos.splice(arrastreFoto, 1); fotos.splice(i, 0, x);
      arrastreFoto = null; marcarCambio(); repintar();
    }
  },
    h("span", { class: "tpl-drag", "aria-hidden": "true", title: "Arrastrar" }, "⠿"),
    h("span", { class: "tpl-foto-thumb" + (f.panoramica ? " is-pano" : ""), title: f.panoramica ? "Panorámica (hoja 1)" : "Foto " + (i + 1) },
      ico(f.panoramica ? "panoramica" : "camara"), h("b", {}, String(i + 1))),
    h("input", {
      value: f.nombre, maxlength: 80, "aria-label": "Nombre de la foto " + (i + 1), placeholder: "Nombre de la foto",
      oninput: (e) => {
        f.nombre = e.target.value;
        if (f._nueva) {
          const u = new Set(fotos.map((x) => x.clave)); u.delete(f.clave);
          const vieja = f.clave;
          f.clave = claveUnica(f.nombre || "foto", u);
          renombrarClaveFoto(vieja, f.clave);
        }
        marcarCambio();
      }
    }),
    h("input", { value: f.categoria, maxlength: 60, list: "listaCategorias", "aria-label": "Categoría", placeholder: "Categoría",
      oninput: (e) => { f.categoria = e.target.value; marcarCambio(); },
      onchange: () => { fotoCambioCategoria(f, catAntes); catAntes = f.categoria; marcarCambio(); } }),
    h("div", { class: "tpl-stepper", "aria-label": "Cantidad de tomas" },
      h("button", { type: "button", "aria-label": "Una toma menos", disabled: f.cantidad <= 1 || f.panoramica,
        onclick: cambio(() => { f.cantidad = Math.max(1, f.cantidad - 1); }) }, "−"),
      h("output", {}, String(f.cantidad)),
      h("button", { type: "button", "aria-label": "Una toma más", disabled: f.cantidad >= 10 || f.panoramica,
        onclick: cambio(() => { f.cantidad = Math.min(10, f.cantidad + 1); }) }, "+")),
    h("label", { class: "tpl-mini-switch", title: "Obligatoria" },
      h("input", { type: "checkbox", checked: f.obligatoria, "aria-label": "Obligatoria", onchange: cambio((e) => { f.obligatoria = e.target.checked; }) }),
      h("span", { "aria-hidden": "true" })),
    h("label", { class: "tpl-radio-pano", title: "Usar como panorámica del PDF" },
      h("input", { type: "radio", name: "panoramica", checked: f.panoramica, "aria-label": "Panorámica",
        onchange: cambio(() => {
          const antes = fotos.filter((x) => x.panoramica && x !== f);
          fotos.forEach((x) => { x.panoramica = x === f; });
          f.cantidad = 1;
          antes.forEach(fotoAlPdf); // la anterior vuelve a su panel
          fotoAlPdf(f);
        }) }),
      h("span", { "aria-hidden": "true" }, "★")),
    h("div", { class: "tpl-icon-group" },
      botonIcono("borrar", "Eliminar foto", false, async () => {
        if (!(await modal({
          titulo: "Eliminar fotografía", tono: "danger", aceptar: "Eliminar foto",
          texto: `¿Eliminar la foto «${f.nombre || "sin nombre"}»?`,
          detalle: ["El celular dejará de pedirla y se quita de los paneles del PDF.",
            "Las fotos ya tomadas en inspecciones anteriores no se borran del NAS.", "Puede deshacerlo saliendo sin guardar."]
        }))) return;
        fotos.splice(i, 1);
        fotoFueraDePaneles(f.clave);
        marcarCambio(); repintar();
      }, "danger")));
  return fila;
}

// ---------------------------------------------------------------------------
// Pestaña 3: Diseño del PDF
// ---------------------------------------------------------------------------

function camposSinUbicar() {
  const ubicados = new Set(S.config.pdf.hoja1.cuadros.flatMap((c) => c.campos || []));
  const comentario = S.config.pdf.comentarios.campo;
  return campos().filter((c) => !ubicados.has(c.clave) && c.clave !== comentario);
}

function vistaPdf() {
  return h("div", { class: "tpl-pdf" },
    h("div", { class: "tpl-split tpl-split-pdf" },
      h("section", { class: "tpl-col-main", "aria-label": "Hoja 1 del PDF" },
        h("div", { class: "tpl-section-head" },
          h("div", {},
            h("h2", {}, "Hoja 1", ayuda("hoja1")),
            h("p", {}, "Arrastre los cuadros para moverlos y la esquina ◢ para cambiar su tamaño. Se acomodan solos a la rejilla.")),
          h("div", { class: "acciones" },
            h("button", { class: "button-ghost button-sm tpl-btn-magia", type: "button", title: "Rehace la hoja 1 y las fotos desde el formulario", onclick: rehacerPdf },
              ico("magia"), "Armar automáticamente"),
            h("button", { class: "button-secondary button-sm", type: "button", onclick: () => nuevoCuadro("campos") }, "＋ Cuadro"),
            h("button", { class: "button-secondary button-sm", type: "button", onclick: () => nuevoCuadro("texto") }, "＋ Texto"),
            S.config.pdf.hoja1.cuadros.some((c) => c.tipo === "panoramica") ? null
              : h("button", { class: "button-secondary button-sm", type: "button", onclick: () => nuevoCuadro("panoramica") }, "＋ Panorámica"))),
        h("div", { id: "avisoSinUbicar" }, avisoSinUbicar()),
        h("div", { class: "tpl-hoja-wrap" }, h("div", { id: "hoja", class: "tpl-hoja" }))),
      h("aside", { class: "tpl-col-side" }, h("div", { id: "inspectorPdf", class: "tpl-inspector surface" }))),
    seccionPaneles(),
    seccionCierre());
}

/** Se llama después de insertar la vista: necesita el ancho real de la hoja. */
function montarHoja() {
  const hoja = $("hoja");
  if (!hoja) return;
  const escala = hoja.clientWidth / HOJA.ancho; // px por mm
  hoja.style.setProperty("--mm", escala + "px");
  hoja.style.height = HOJA.alto * escala + "px";
  hoja.replaceChildren(encabezadoHoja(escala), rejillaHoja(escala), ...S.config.pdf.hoja1.cuadros.map((c) => cuadroHoja(c, escala)));
  $("inspectorPdf").replaceChildren(inspectorPdf());
}

window.addEventListener("resize", () => { if (S.tab === "pdf") montarHoja(); });

function encabezadoHoja(escala) {
  const e = S.config.pdf.encabezado;
  const mm = (v) => v * escala + "px";
  const logo = (archivo, lado) => {
    const caja = h("div", { class: "hoja-logo", style: { left: mm(lado === "izq" ? 10 : 165), top: mm(6), width: mm(35), height: mm(13) } });
    if (archivo) urlLogo(archivo).then((url) => { if (url) caja.append(h("img", { src: url, alt: "" })); });
    else caja.append(h("span", {}, "Logo"));
    return caja;
  };
  const sel = Boolean(S.sel?.encabezado);
  return h("div", {
    class: "hoja-encabezado" + (sel ? " is-selected" : ""), role: "button", tabindex: "0",
    "aria-label": "Encabezado: logos, título y colores", style: { height: mm(HOJA.y0 - 1) },
    onclick: () => { S.sel = { encabezado: true }; montarHoja(); },
    onkeydown: (ev) => { if (ev.key === "Enter") { S.sel = { encabezado: true }; montarHoja(); } }
  },
    logo(e.logo_izquierdo, "izq"), logo(e.logo_derecho, "der"),
    h("div", { class: "hoja-titulo", style: { left: mm(48), width: mm(114), top: mm(7), fontSize: mm(3.2) } },
      h("strong", {}, e.titulo || "Título del servicio"), e.subtitulo ? h("strong", {}, e.subtitulo) : null),
    h("div", { class: "hoja-barra", style: { left: mm(10), top: mm(21), width: mm(190), height: mm(8), fontSize: mm(3.4) } },
      h("span", { style: { background: e.color_barra, color: e.color_texto_barra, width: mm(130) } }, "NOMBRE DEL PROYECTO"),
      h("span", { class: "hoja-il", style: { width: mm(10) } }, e.etiqueta_codigo || "IL"),
      h("span", { class: "hoja-cod", style: { width: mm(50) } }, "E-001")),
    h("div", { class: "hoja-meta", style: { left: mm(10), top: mm(30.5), width: mm(190), fontSize: mm(2.6) } },
      h("span", {}, "Contratista · Ubicación · UTM E · UTM N"), h("span", {}, "Fecha de inspección · Página 1")),
    h("span", { class: "hoja-editar" }, "✎ Editar encabezado"));
}

function rejillaHoja(escala) {
  return h("div", {
    class: "hoja-rejilla",
    style: {
      left: HOJA.margen * escala + "px", top: HOJA.y0 * escala + "px",
      width: HOJA.util * escala + "px", height: HOJA.filas * HOJA.fila * escala + "px",
      backgroundSize: `${HOJA.col * escala}px ${HOJA.fila * escala}px`
    }
  });
}

function rectCuadro(c, escala) {
  return {
    left: (HOJA.margen + c.x * HOJA.col) * escala, top: (HOJA.y0 + c.y * HOJA.fila) * escala,
    width: c.w * HOJA.col * escala, height: c.h * HOJA.fila * escala
  };
}

function seSolapa(c, x, y, w, hh) {
  return S.config.pdf.hoja1.cuadros.some((o) => o !== c &&
    x < o.x + o.w && x + w > o.x && y < o.y + o.h && y + hh > o.y);
}

function cuadroHoja(c, escala) {
  const r = rectCuadro(c, escala);
  const sel = S.sel?.cuadro === c.id;
  const el = h("div", {
    class: "hoja-cuadro tipo-" + c.tipo + (sel ? " is-selected" : ""), tabindex: "0", role: "button",
    "aria-label": `${c.titulo || "Cuadro"}: columna ${c.x + 1}, fila ${c.y + 1}, ${c.w} por ${c.h}`,
    style: { left: r.left + "px", top: r.top + "px", width: r.width + "px", height: r.height + "px", "--c": c.color, "--ct": textoSobre(c.color) }
  });
  const pad = 0.6 * escala;
  el.style.padding = pad + "px";

  if (c.tipo === "panoramica") {
    el.append(h("div", { class: "hc-pano" }, h("span", { class: "hc-pano-ico" }, ico("panoramica")), h("small", {}, "Foto panorámica")),
      h("div", { class: "hc-pie" }, c.titulo || "Foto Panorámica"));
  } else {
    el.append(h("div", { class: "hc-titulo" }, c.titulo || (c.tipo === "texto" ? "Texto" : "Sin título")));
    const cuerpo = h("div", { class: "hc-cuerpo", style: { columnCount: c.columnas || 1 } });
    if (c.tipo === "texto") cuerpo.append(h("p", {}, c.texto || "Texto libre…"));
    else {
      const lista = (c.campos || []).map(campoPorClave).filter(Boolean);
      if (!lista.length) cuerpo.append(h("em", { class: "hc-vacio" }, "Elija qué campos muestra →"));
      else if (lista.length === 1 && ["seleccion", "multiple", "si_no", "rst"].includes(lista[0].tipo)) {
        const ops = lista[0].tipo === "si_no" ? [{ texto: "Sí" }, { texto: "No" }] : lista[0].opciones || [];
        ops.forEach((o) => cuerpo.append(h("div", { class: "hc-fila" }, h("span", {}, o.texto), h("i", {}))));
      } else lista.forEach((campo) => cuerpo.append(h("div", { class: "hc-fila hc-par" }, h("span", {}, campo.etiqueta + ":"), h("i", {}))));
    }
    el.append(cuerpo);
  }
  const asa = h("span", { class: "hc-asa", title: "Cambiar tamaño", "aria-hidden": "true" }, "◢");
  el.append(asa);

  const seleccionar = () => { if (S.sel?.cuadro !== c.id) { S.sel = { cuadro: c.id }; montarHoja(); } };
  el.addEventListener("keydown", (e) => moverConTeclado(e, c));
  el.addEventListener("pointerdown", (e) => iniciarArrastre(e, c, el, escala, e.target === asa ? "tamano" : "mover", seleccionar));
  return el;
}

function moverConTeclado(e, c) {
  const d = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
  if (e.key === "Enter") { S.sel = { cuadro: c.id }; montarHoja(); return; }
  if (e.key === "Delete") { eliminarCuadro(c); return; }
  if (!d) return;
  e.preventDefault();
  let { x, y, w, h: hh } = c;
  if (e.shiftKey) { w = Math.max(1, Math.min(HOJA.cols - x, w + d[0])); hh = Math.max(HOJA.minH, Math.min(HOJA.filas - y, hh + d[1])); }
  else { x = Math.max(0, Math.min(HOJA.cols - w, x + d[0])); y = Math.max(0, Math.min(HOJA.filas - hh, y + d[1])); }
  if (seSolapa(c, x, y, w, hh)) { toast("Ese espacio está ocupado.", "error"); return; }
  Object.assign(c, { x, y, w, h: hh });
  S.sel = { cuadro: c.id };
  marcarCambio();
  montarHoja();
  document.querySelector(".hoja-cuadro.is-selected")?.focus();
}

function iniciarArrastre(e, c, el, escala, modo, seleccionar) {
  if (e.button !== 0) return;
  e.preventDefault();
  const inicio = { px: e.clientX, py: e.clientY, x: c.x, y: c.y, w: c.w, h: c.h };
  const colPx = HOJA.col * escala;
  const filaPx = HOJA.fila * escala;
  let propuesta = { ...inicio };
  let movido = false;
  el.setPointerCapture(e.pointerId);
  el.classList.add("is-dragging");

  const mover = (ev) => {
    const dx = Math.round((ev.clientX - inicio.px) / colPx);
    const dy = Math.round((ev.clientY - inicio.py) / filaPx);
    if (dx || dy) movido = true;
    if (modo === "mover") {
      propuesta = { ...inicio,
        x: Math.max(0, Math.min(HOJA.cols - inicio.w, inicio.x + dx)),
        y: Math.max(0, Math.min(HOJA.filas - inicio.h, inicio.y + dy)) };
    } else {
      propuesta = { ...inicio,
        w: Math.max(1, Math.min(HOJA.cols - inicio.x, inicio.w + dx)),
        h: Math.max(HOJA.minH, Math.min(HOJA.filas - inicio.y, inicio.h + dy)) };
    }
    const r = rectCuadro({ ...c, ...propuesta }, escala);
    Object.assign(el.style, { left: r.left + "px", top: r.top + "px", width: r.width + "px", height: r.height + "px" });
    el.classList.toggle("is-blocked", seSolapa(c, propuesta.x, propuesta.y, propuesta.w, propuesta.h));
  };
  const soltar = () => {
    el.removeEventListener("pointermove", mover);
    el.classList.remove("is-dragging", "is-blocked");
    if (!movido) { seleccionar(); return; }
    if (seSolapa(c, propuesta.x, propuesta.y, propuesta.w, propuesta.h)) {
      toast("Ese espacio está ocupado por otro cuadro.", "error");
    } else {
      Object.assign(c, { x: propuesta.x, y: propuesta.y, w: propuesta.w, h: propuesta.h });
      marcarCambio();
    }
    S.sel = { cuadro: c.id };
    montarHoja();
  };
  el.addEventListener("pointermove", mover);
  el.addEventListener("pointerup", soltar, { once: true });
  el.addEventListener("pointercancel", soltar, { once: true });
}

/** Primer hueco libre de w×h en la rejilla, de arriba abajo. */
function huecoLibre(w, hh) {
  for (let y = 0; y <= HOJA.filas - hh; y++) {
    for (let x = 0; x <= HOJA.cols - w; x++) {
      if (!seSolapa(null, x, y, w, hh)) return { x, y };
    }
  }
  return null;
}

function altoSugerido(lista, columnas = 1) {
  if (lista.length === 1 && ["seleccion", "multiple", "rst"].includes(lista[0].tipo))
    return SUB * Math.min(14, 2 + Math.ceil((lista[0].opciones || []).length / (lista[0].tipo === "rst" ? 1 : columnas)));
  if (lista.length === 1 && lista[0].tipo === "si_no") return SUB * 4;
  return SUB * Math.min(14, 2 + Math.ceil(lista.length / columnas));
}

function nuevoCuadro(tipo, clavesCampos, opciones = {}) {
  const cuadros = S.config.pdf.hoja1.cuadros;
  const ids = new Set(cuadros.map((c) => c.id));
  const lista = (clavesCampos || []).map(campoPorClave).filter(Boolean);
  const w = SUB * (tipo === "panoramica" ? 10 : tipo === "texto" ? 8 : (lista.length > 1 ? 8 : 5));
  const hh = tipo === "panoramica" ? SUB * 24 : tipo === "texto" ? SUB * 4 : altoSugerido(lista);
  // Primero el tamaño ideal; si no cabe, más bajo y luego más angosto.
  let lugar = null;
  for (const ancho of [...new Set([w, Math.min(w, SUB * 4)])]) {
    for (let alto = hh; alto >= Math.min(hh, SUB * 3) && !lugar; alto--) {
      const sitio = huecoLibre(ancho, alto);
      if (sitio) lugar = { ...sitio, w: ancho, h: alto };
    }
    if (lugar) break;
  }
  if (!lugar) {
    if (!opciones.silencioso) toast("La hoja 1 está llena. Achique o quite algún cuadro.", "error");
    return null;
  }
  const color = opciones.color || PALETA[cuadros.length % PALETA.length][0];
  const c = {
    id: claveUnica(tipo === "panoramica" ? "panoramica" : (lista[0]?.clave || "cuadro"), ids), tipo,
    titulo: opciones.titulo || (tipo === "panoramica" ? "Foto Panorámica" : tipo === "texto" ? "Nota" : (lista[0]?.etiqueta || "Nuevo cuadro")).slice(0, 80),
    color: tipo === "panoramica" ? "#F0F0F0" : color, x: lugar.x, y: lugar.y, w: Math.min(lugar.w, HOJA.cols - lugar.x), h: lugar.h
  };
  if (tipo === "campos") { c.campos = lista.map((x) => x.clave); c.columnas = 1; }
  if (tipo === "texto") c.texto = "";
  cuadros.push(c);
  if (opciones.seleccionar !== false) S.sel = { cuadro: c.id };
  marcarCambio();
  if (S.tab === "pdf") montarHoja();
  $("avisoSinUbicar")?.replaceChildren(avisoSinUbicar());
  return c;
}

async function eliminarCuadro(c) {
  if (!(await modal({
    titulo: "Quitar cuadro", tono: "danger", aceptar: "Quitar cuadro",
    texto: `¿Quitar el cuadro «${c.titulo || "sin título"}» de la hoja 1?`,
    detalle: ["Sus campos siguen en el formulario del celular; solo dejan de imprimirse hasta que los ubique en otro cuadro."]
  }))) return;
  const cuadros = S.config.pdf.hoja1.cuadros;
  cuadros.splice(cuadros.indexOf(c), 1);
  S.sel = { encabezado: true };
  marcarCambio();
  montarHoja();
  $("avisoSinUbicar")?.replaceChildren(avisoSinUbicar());
}

function avisoSinUbicar() {
  const sueltos = camposSinUbicar();
  if (!sueltos.length) return h("p", { class: "tpl-ok" }, "✓ Todos los campos del formulario aparecen en la hoja 1.");
  return h("div", { class: "tpl-aviso" },
    h("span", { "aria-hidden": "true" }, "!"),
    h("p", {}, h("strong", {}, `${sueltos.length} campo${sueltos.length === 1 ? "" : "s"} no aparece${sueltos.length === 1 ? "" : "n"} en el PDF: `),
      sueltos.slice(0, 6).map((c) => c.etiqueta).join(", ") + (sueltos.length > 6 ? "…" : "")),
    h("button", { class: "button-secondary button-sm", type: "button", onclick: () => {
      let creados = 0;
      for (const campo of camposSinUbicar()) { if (nuevoCuadro("campos", [campo.clave])) creados++; else break; }
      if (creados) toast(`Se agregaron ${creados} cuadros. Acomódelos a su gusto.`);
    } }, "Ubicar automáticamente"));
}

// --- Inspector del PDF -------------------------------------------------------

function inspectorPdf() {
  if (S.sel?.cuadro) {
    const c = S.config.pdf.hoja1.cuadros.find((x) => x.id === S.sel.cuadro);
    if (c) return inspectorCuadro(c);
  }
  return inspectorEncabezado();
}

function selectorColor(valor, alCambiar, etiqueta) {
  const wrap = h("div", { class: "tpl-colores", role: "radiogroup", "aria-label": etiqueta || "Color" });
  const pintar = (actual) => {
    wrap.replaceChildren(
      ...PALETA.map(([hex, nombre]) => h("button", {
        type: "button", class: "tpl-swatch" + (actual.toUpperCase() === hex ? " is-on" : ""), role: "radio",
        "aria-checked": String(actual.toUpperCase() === hex), title: nombre, "aria-label": nombre, style: { background: hex },
        onclick: () => { alCambiar(hex); pintar(hex); }
      })),
      h("label", { class: "tpl-swatch tpl-swatch-libre", title: "Otro color" },
        h("input", { type: "color", value: actual, "aria-label": "Otro color", oninput: (e) => { alCambiar(e.target.value.toUpperCase()); pintar(e.target.value); } }),
        h("span", { "aria-hidden": "true" }, "＋")));
  };
  pintar(valor || "#305496");
  return wrap;
}

function inspectorCuadro(c) {
  const repintar = () => { marcarCambio(); montarHoja(); $("avisoSinUbicar")?.replaceChildren(avisoSinUbicar()); };
  const repintarSinInspector = () => {
    marcarCambio();
    const hoja = $("hoja");
    const escala = hoja.clientWidth / HOJA.ancho;
    const viejo = hoja.querySelector(".hoja-cuadro.is-selected");
    viejo?.replaceWith(cuadroHoja(c, escala));
    $("avisoSinUbicar")?.replaceChildren(avisoSinUbicar());
  };
  const bloques = [
    h("div", { class: "tpl-inspector-head" },
      h("span", { class: "tpl-color-dot", style: { background: c.color }, "aria-hidden": "true" }),
      h("div", {}, h("p", { class: "eyebrow" }, c.tipo === "panoramica" ? "Foto panorámica" : c.tipo === "texto" ? "Cuadro de texto" : "Cuadro de datos"),
        h("h3", {}, c.titulo || "Sin título")), ayuda("cuadro", true)),
    campoForm("cTitulo", "Título", h("input", { id: "cTitulo", value: c.titulo, maxlength: 80,
      oninput: (e) => { c.titulo = e.target.value; repintarSinInspector(); } })),
    h("div", { class: "campo" }, h("span", { class: "tpl-label" }, c.tipo === "panoramica" ? "Color del pie" : "Color del título"),
      selectorColor(c.color, (hex) => { c.color = hex; repintarSinInspector(); }))
  ];

  if (c.tipo === "campos") {
    bloques.push(campoForm("cCols", "Columnas dentro del cuadro", h("div", { class: "tpl-segmented", id: "cCols" },
      ...[1, 2, 3, 4].map((n) => h("button", { type: "button", class: (c.columnas || 1) === n ? "is-on" : "", "aria-pressed": String((c.columnas || 1) === n),
        onclick: () => { c.columnas = n; repintar(); } }, String(n))))));
    // Lista de campos: los del cuadro primero (en su orden), luego el resto por paso.
    const box = h("div", { class: "tpl-campos-cuadro" });
    const pintarCampos = () => {
      const enOtros = new Map();
      S.config.pdf.hoja1.cuadros.forEach((o) => { if (o !== c) (o.campos || []).forEach((k) => enOtros.set(k, o.titulo)); });
      box.replaceChildren(
        h("p", { class: "tpl-label" }, "Campos que muestra"),
        ...(c.campos.length ? c.campos.map((k, i) => {
          const campo = campoPorClave(k);
          return h("div", { class: "tpl-campo-sel" },
            h("span", { class: "tpl-tipo-icono tpl-tipo-" + campo.tipo, "aria-hidden": "true" }, TIPOS[campo.tipo].icono),
            h("span", {}, campo.etiqueta),
            botonIcono("arriba", "Subir", i === 0, () => { c.campos.splice(i - 1, 0, c.campos.splice(i, 1)[0]); pintarCampos(); repintarSinInspector(); }),
            botonIcono("cerrar", "Quitar del cuadro", false, () => { c.campos.splice(i, 1); pintarCampos(); repintarSinInspector(); }, "danger"));
        }) : [h("p", { class: "tpl-muted" }, "Ninguno todavía. Elija abajo.")]),
        h("details", { class: "tpl-agregar-campos", open: !c.campos.length },
          h("summary", {}, "＋ Agregar campos a este cuadro"),
          ...S.config.formulario.pasos.map((p) => h("div", { class: "tpl-grupo-campos" },
            h("strong", {}, p.titulo),
            ...p.campos.filter((x) => !c.campos.includes(x.clave)).map((x) => h("button", {
              type: "button", class: "tpl-campo-opcion" + (enOtros.has(x.clave) ? " is-usado" : ""),
              title: enOtros.has(x.clave) ? "Ya está en «" + enOtros.get(x.clave) + "»" : "",
              onclick: () => {
                c.campos.push(x.clave);
                if (c.campos.length === 1 && (!c.titulo || c.titulo === "Nuevo cuadro")) c.titulo = x.etiqueta;
                pintarCampos(); repintarSinInspector();
              }
            }, h("span", { "aria-hidden": "true" }, TIPOS[x.tipo].icono), x.etiqueta, enOtros.has(x.clave) ? h("small", {}, " · en otro cuadro") : null))))));
    };
    pintarCampos();
    bloques.push(box);
  }

  if (c.tipo === "texto") {
    bloques.push(campoForm("cTexto", "Texto", h("textarea", { id: "cTexto", rows: 4, maxlength: 600, value: c.texto || "",
      oninput: (e) => { c.texto = e.target.value; repintarSinInspector(); } })));
  }

  bloques.push(
    h("div", { class: "tpl-posicion" },
      h("span", {}, `Columna ${c.x + 1} · Fila ${c.y + 1}`), h("span", {}, `${c.w} × ${c.h} celdas`),
      h("small", {}, "Con el cuadro seleccionado: flechas para mover, Mayús + flechas para cambiar tamaño.")),
    h("div", { class: "tpl-inspector-actions" },
      h("button", { class: "button-secondary button-sm", type: "button", onclick: () => {
        const copia = clonar(c);
        const lugar = huecoLibre(c.w, c.h);
        if (!lugar) { toast("No hay espacio libre para duplicarlo.", "error"); return; }
        copia.id = claveUnica(c.id + "_copia", new Set(S.config.pdf.hoja1.cuadros.map((x) => x.id)));
        Object.assign(copia, lugar);
        if (copia.tipo === "panoramica") { toast("Solo puede haber una panorámica.", "error"); return; }
        S.config.pdf.hoja1.cuadros.push(copia);
        S.sel = { cuadro: copia.id };
        repintar();
      } }, ico("copiar"), "Duplicar"),
      h("button", { class: "button-ghost button-sm tpl-danger", type: "button", onclick: () => eliminarCuadro(c) }, ico("borrar"), "Quitar cuadro")));
  return h("div", { class: "tpl-inspector-body" }, ...bloques);
}

function inspectorEncabezado() {
  const e = S.config.pdf.encabezado;
  const repintar = () => { marcarCambio(); const hoja = $("hoja"); hoja.firstChild.replaceWith(encabezadoHoja(hoja.clientWidth / HOJA.ancho)); };
  return h("div", { class: "tpl-inspector-body" },
    h("div", { class: "tpl-inspector-head" },
      h("span", { class: "tpl-tipo-icono", "aria-hidden": "true" }, "▔"),
      h("div", {}, h("p", { class: "eyebrow" }, "Encabezado"), h("h3", {}, "Logos, título y colores")), ayuda("encabezado", true)),
    h("div", { class: "tpl-logos" },
      cargadorLogo("izquierdo", "Logo izquierdo", e.logo_izquierdo, (archivo) => { e.logo_izquierdo = archivo; repintar(); }),
      cargadorLogo("derecho", "Logo derecho", e.logo_derecho, (archivo) => { e.logo_derecho = archivo; repintar(); })),
    h("p", { class: "tpl-muted" }, "PNG, JPG o WEBP. Se recortan los bordes vacíos y se ajustan a 35 × 13 mm para que ambos se vean del mismo tamaño."),
    campoForm("eTitulo", "Título (línea 1)", h("input", { id: "eTitulo", value: e.titulo, maxlength: 120,
      oninput: (ev) => { e.titulo = ev.target.value; repintar(); } })),
    campoForm("eSub", "Título (línea 2)", h("input", { id: "eSub", value: e.subtitulo, maxlength: 120,
      oninput: (ev) => { e.subtitulo = ev.target.value; repintar(); } })),
    h("div", { class: "campo" }, h("span", { class: "tpl-label" }, "Color de la barra del proyecto"),
      selectorColor(e.color_barra, (hex) => { e.color_barra = hex; e.color_texto_barra = textoSobre(hex) === "#fff" ? "#FFFFFF" : "#212529"; repintar(); })),
    campoForm("eEtiqueta", "Etiqueta junto al código", h("input", { id: "eEtiqueta", value: e.etiqueta_codigo, maxlength: 6,
      oninput: (ev) => { e.etiqueta_codigo = ev.target.value; repintar(); } }), "Por ejemplo «IL» (inspección ligera)."));
}

async function urlLogo(archivo) {
  if (!archivo || !S.plantilla) return null;
  if (S.logos.has(archivo)) return S.logos.get(archivo);
  try {
    // «v=2»: una dirección nueva, para no usar copias viejas que el navegador
    // guardó por un año cuando el archivo ya no estaba en el servidor.
    const url = await objectUrlConToken(`admin/plantilla_logo.php?plantilla_id=${S.plantilla.id}&archivo=${encodeURIComponent(archivo)}&v=2`);
    S.logos.set(archivo, url);
    return url;
  } catch {
    return null;
  }
}

/** Sube un logo o una firma; el servidor la recorta y la normaliza. Devuelve el nombre del archivo. */
async function subirImagenPlantilla(file, lado) {
  const datos = new FormData();
  datos.append("plantilla_id", S.plantilla.id);
  datos.append("lado", lado);
  datos.append("archivo", file);
  const r = await leerRespuesta(await fetchConToken("admin/plantilla_logo.php", { method: "POST", body: datos, timeoutMs: 60000 }));
  return r.archivo;
}

function cargadorLogo(lado, etiqueta, archivo, alCambiar) {
  const id = "logo_" + lado;
  const vista = h("div", { class: "tpl-logo-vista" }, h("span", {}, "Sin logo"));
  if (archivo) urlLogo(archivo).then((url) => {
    vista.replaceChildren(url ? h("img", { src: url, alt: etiqueta })
      : h("span", { class: "tpl-logo-falta" }, "Falta en el servidor: vuelva a subirlo"));
  });
  const inputArchivo = h("input", {
    id, type: "file", accept: "image/png,image/jpeg,image/webp", class: "sr-only",
    onchange: async (ev) => {
      const file = ev.target.files[0];
      if (!file) return;
      vista.classList.add("is-loading");
      try {
        const r = { archivo: await subirImagenPlantilla(file, lado) };
        alCambiar(r.archivo);
        const url = await urlLogo(r.archivo);
        if (url) vista.replaceChildren(h("img", { src: url, alt: etiqueta }));
        toast("Logo listo. Guarde la plantilla para aplicarlo.");
      } catch (error) {
        toast(mensajeError(error), "error");
      } finally {
        vista.classList.remove("is-loading");
        ev.target.value = "";
      }
    }
  });
  return h("div", { class: "tpl-logo" },
    h("span", { class: "tpl-label" }, etiqueta),
    vista,
    h("div", { class: "acciones" },
      inputArchivo,
      h("label", { for: id, class: "button-secondary button-sm" }, archivo ? "Cambiar" : "Subir logo"),
      archivo ? h("button", { class: "button-ghost button-sm", type: "button", onclick: () => { alCambiar(null); vista.replaceChildren(h("span", {}, "Sin logo")); } }, "Quitar") : null));
}

// --- Paneles de fotos, comentarios y firmas ----------------------------------

function fotosSinPanel() {
  const usadas = new Set(S.config.pdf.paneles.flatMap((p) => p.fotos));
  return S.config.fotos.filter((f) => !f.panoramica && !usadas.has(f.clave));
}

// --- Páginas de fotografías (hoja 2 en adelante) ------------------------------
//
// Vista previa a escala de las hojas de fotos con la MISMA paginación que
// plantillaPdfPaneles() en PDFPlantilla.php: lo que se ve aquí es lo que sale
// en el PDF. Se edita con clic en un panel (inspector a la derecha) o
// arrastrando fotos entre paneles.
//
// Cada foto ocupa un ancho de 2 a 12 columnas (de 12) y un alto múltiplo de
// 5 mm; el del panel o uno propio. Se ubica en el primer hueco libre, así las
// fotos chicas llenan los espacios que dejan las grandes.

const PAG = { limite: 287 };
const FOTO = { cols: 12, fila: 5, minW: 2, minH: 20, maxH: 220 };
FOTO.col = HOJA.util / FOTO.cols;
let arrastrePagina = null; // {ip, clave}

function nuevoPanel(extra) {
  return { titulo: "Nuevo panel", color: "#7B7B7B", columnas: 3, alto_foto: 0, fotos: [], tamanos: {},
    mostrar_nombres: true, nueva_pagina: false, ...extra };
}

/** Mapa de tamaños propios del panel (el servidor puede devolver [] si está vacío). */
function tamanosDe(p) {
  if (!p.tamanos || Array.isArray(p.tamanos) || typeof p.tamanos !== "object") p.tamanos = {};
  return p.tamanos;
}

const anchoPorFila = (p) => FOTO.cols / Math.max(1, Math.min(4, Number(p.columnas) || 3));
const altoAutoFoto = (w) => Math.max(FOTO.minH, Math.min(70, Math.round(w * FOTO.col * 0.79 / 5) * 5));
const medidaTexto = (w, hi) => `${mmTexto(w * FOTO.col)} × ${hi} mm`;

/** Tamaño de una foto en su panel: propio o el del panel. Igual que plantillaPdfTamanoFoto(). */
function tamanoFoto(p, clave) {
  const t = (p.tamanos && !Array.isArray(p.tamanos) && p.tamanos[clave]) || {};
  const w = Number(t.w) > 0 ? Math.max(FOTO.minW, Math.min(FOTO.cols, Math.trunc(Number(t.w)))) : anchoPorFila(p);
  const base = Number(t.h) > 0 ? Math.trunc(Number(t.h)) : (Number(p.alto_foto) > 0 ? Math.trunc(Number(p.alto_foto)) : altoAutoFoto(w));
  const hi = Math.max(FOTO.minH, Math.min(FOTO.maxH, Math.round(base / 5) * 5));
  const hn = p.mostrar_nombres === false ? 0 : 5;
  return { w, hi, hn, filas: (hi + hn) / FOTO.fila, propio: Number(t.w) > 0 || Number(t.h) > 0 };
}

/** Primer hueco libre de w×filas en la rejilla ocupada (igual que plantillaPdfHuecoFoto()). */
function huecoFoto(ocupado, capacidad, w, filas) {
  for (let r = 0; r + filas <= capacidad; r++) {
    for (let c = 0; c + w <= FOTO.cols; c++) {
      let libre = true;
      for (let rr = r; rr < r + filas && libre; rr++) {
        for (let cc = c; cc < c + w; cc++) if (ocupado[rr]?.[cc]) { libre = false; break; }
      }
      if (libre) return { r, c };
    }
  }
  return null;
}

/** Sin paneles configurados el PDF arma uno por categoría (igual que el servidor). */
function panelesEfectivos() {
  const paneles = S.config.pdf.paneles;
  if (paneles.length) return { auto: false, paneles };
  const porCategoria = new Map();
  for (const f of S.config.fotos) {
    if (f.panoramica) continue;
    const cat = f.categoria || "General";
    if (!porCategoria.has(cat)) porCategoria.set(cat, []);
    porCategoria.get(cat).push(f.clave);
  }
  return { auto: true, paneles: [...porCategoria].map(([titulo, fotos]) => nuevoPanel({ titulo, fotos })) };
}

/** Si el PDF usa los paneles automáticos, los vuelve editables (mismo resultado). */
function asegurarPaneles() {
  const ef = panelesEfectivos();
  if (ef.auto) S.config.pdf.paneles = ef.paneles;
  return S.config.pdf.paneles;
}

function geometriaPanel(p) {
  const cols = Math.max(1, Math.min(4, Number(p.columnas) || 3));
  const span = anchoPorFila(p);
  const ancho = span * FOTO.col;
  const altoImg = Number(p.alto_foto) > 0 ? Number(p.alto_foto) : altoAutoFoto(span);
  const altoNom = p.mostrar_nombres === false ? 0 : 5;
  return { cols, span, ancho, altoImg, altoNom, altoCelda: altoImg + altoNom };
}

const mmTexto = (v) => (Math.round(v * 10) / 10).toString().replace(".", ",");

/** Fotos que no están en ningún panel propio: el PDF las imprime al final, en «Otras fotografías». */
function panelSueltas() {
  const paneles = S.config.pdf.paneles;
  if (!paneles.length) return null;
  const usadas = new Set(paneles.flatMap((p) => p.fotos));
  const sueltas = S.config.fotos.filter((f) => !f.panoramica && !usadas.has(f.clave)).map((f) => f.clave);
  return sueltas.length ? nuevoPanel({ titulo: "Otras fotografías", fotos: sueltas, _sueltas: true }) : null;
}

function paginarFotos() {
  const ef = panelesEfectivos();
  const auto = ef.auto;
  const extra = auto ? null : panelSueltas();
  const paneles = extra ? [...ef.paneles, extra] : ef.paneles;
  const fotoPorClave = new Map(S.config.fotos.map((f) => [f.clave, f]));
  const paginas = [];
  const vacios = [];
  let pag = null;
  let y = HOJA.y0;
  const nueva = () => { pag = { items: [] }; paginas.push(pag); y = HOJA.y0; };
  let primero = true;
  paneles.forEach((p, ip) => {
    const celdas = [];
    for (const clave of p.fotos) {
      const f = fotoPorClave.get(clave);
      if (!f) continue;
      const t = tamanoFoto(p, clave);
      const n = Math.max(1, Number(f.cantidad) || 1);
      for (let i = 1; i <= n; i++) {
        celdas.push({ ...t, clave, indice: i, nombre: f.nombre + (n > 1 ? ` (${i})` : ""), obligatoria: f.obligatoria });
      }
    }
    if (!celdas.length) { vacios.push(ip); return; }
    if (!pag) nueva();
    const alto = String(p.titulo || "").trim() !== "" ? 6 : 0;
    const maxFilas = Math.floor((PAG.limite - HOJA.y0 - alto) / FOTO.fila);
    for (const c of celdas) if (c.filas > maxFilas) { c.filas = maxFilas; c.hi = maxFilas * FOTO.fila - c.hn; }
    if (!primero && (p.nueva_pagina || y + alto + celdas[0].filas * FOTO.fila > PAG.limite)) nueva();
    primero = false;

    let tope = 0;
    let capacidad = 0;
    let ocupado = [];
    let usadas = 0;
    const tramo = () => {
      if (alto) pag.items.push({ tipo: "titulo", ip, y, h: alto });
      tope = y + alto;
      capacidad = Math.floor((PAG.limite - tope) / FOTO.fila);
      ocupado = [];
      usadas = 0;
    };
    tramo();
    for (const c of celdas) {
      let pos = huecoFoto(ocupado, capacidad, c.w, c.filas);
      if (!pos) { nueva(); tramo(); pos = huecoFoto(ocupado, capacidad, c.w, c.filas); }
      for (let rr = pos.r; rr < pos.r + c.filas; rr++) {
        ocupado[rr] ??= [];
        for (let cc = pos.c; cc < pos.c + c.w; cc++) ocupado[rr][cc] = true;
      }
      usadas = Math.max(usadas, pos.r + c.filas);
      pag.items.push({ tipo: "celda", ip, ...c, span: c.w, x: HOJA.margen + pos.c * FOTO.col, y: tope + pos.r * FOTO.fila, w: c.w * FOTO.col });
    }
    y = tope + usadas * FOTO.fila + 4;
  });
  if (S.config.pdf.comentarios.campo) {
    if (!pag || y + 30 > PAG.limite) nueva();
    pag.items.push({ tipo: "comentarios", y });
    y += 20;
  }
  if (S.config.pdf.firmas.length) {
    if (!pag || y + 48 > PAG.limite) nueva();
    y += 22;
    pag.items.push({ tipo: "firmas", y });
    y += 18;
  }
  return { auto, paneles, paginas, vacios };
}

function seccionPaneles() {
  return h("section", { class: "tpl-bloque tpl-bloque-paginas", "aria-labelledby": "titPaneles" },
    h("div", { class: "tpl-split tpl-split-pdf" },
      h("div", { class: "tpl-col-main" },
        h("div", { class: "tpl-section-head" },
          h("div", {},
            h("h2", { id: "titPaneles" }, "Páginas de fotografías", ayuda("paginas")),
            h("p", {}, "Hojas 2 en adelante, a escala. Clic en un panel para editarlo; arrastre las fotos para ordenarlas o cambiarlas de panel.")),
          h("button", { class: "button-secondary button-sm", type: "button", onclick: agregarPanel }, "＋ Panel")),
        h("div", { id: "avisoPaginas" }),
        h("div", { class: "tpl-hoja-wrap" }, h("div", { id: "paginas", class: "tpl-paginas" }))),
      h("aside", { class: "tpl-col-side", "aria-label": "Propiedades del panel" },
        h("div", { id: "inspectorPaneles", class: "tpl-inspector surface" }))));
}

/** Pinta las hojas de fotos. Con conInspector=false no toca el panel derecho (para no perder el foco al escribir). */
function montarPaginas(conInspector = true) {
  const cont = $("paginas");
  if (!cont) return;
  const r = paginarFotos();
  if (S.selPanel != null && (r.auto || !S.config.pdf.paneles[S.selPanel])) S.selPanel = null;
  if (S.selFoto && !(S.config.pdf.paneles[S.selFoto.ip]?.fotos.includes(S.selFoto.clave))) S.selFoto = null;
  $("avisoPaginas").replaceChildren(avisoPaginas(r));
  cont.replaceChildren(...r.paginas.map((_, i) => h("div", { class: "tpl-hoja tpl-pagina", role: "group", "aria-label": "Hoja " + (i + 2) })));
  const primera = cont.firstElementChild;
  if (!primera) {
    cont.append(h("div", { class: "tpl-empty" }, h("strong", {}, "Sin hojas de fotos"), h("p", {}, "Agregue fotografías en la pestaña «Fotografías».")));
  } else {
    const escala = primera.clientWidth / HOJA.ancho;
    [...cont.children].forEach((el, i) => {
      el.style.height = HOJA.alto * escala + "px";
      el.append(encabezadoPagina(escala, i + 2), ...r.paginas[i].items.map((it) => itemPagina(it, r.paneles, escala)));
    });
  }
  if (conInspector) $("inspectorPaneles").replaceChildren(inspectorPaneles(r));
}

function encabezadoPagina(escala, n) {
  const e = S.config.pdf.encabezado;
  const mm = (v) => v * escala + "px";
  const logo = (archivo, x) => {
    const caja = h("div", { class: "hoja-logo", style: { left: mm(x), top: mm(6), width: mm(35), height: mm(13) } });
    if (archivo) urlLogo(archivo).then((url) => { if (url) caja.append(h("img", { src: url, alt: "" })); });
    else caja.append(h("span", {}, "Logo"));
    return caja;
  };
  return h("div", { class: "pg-encabezado", style: { height: mm(HOJA.y0 - 1) } },
    logo(e.logo_izquierdo, 10), logo(e.logo_derecho, 165),
    h("div", { class: "hoja-titulo", style: { left: mm(48), width: mm(114), top: mm(7), fontSize: mm(3.2) } },
      h("strong", {}, e.titulo || "Título del servicio"), e.subtitulo ? h("strong", {}, e.subtitulo) : null),
    h("div", { class: "hoja-barra", style: { left: mm(10), top: mm(21), width: mm(190), height: mm(8), fontSize: mm(3.4) } },
      h("span", { style: { background: e.color_barra, color: e.color_texto_barra, width: mm(130) } }, "NOMBRE DEL PROYECTO"),
      h("span", { class: "hoja-il", style: { width: mm(10) } }, e.etiqueta_codigo || "IL"),
      h("span", { class: "hoja-cod", style: { width: mm(50) } }, "E-001")),
    h("div", { class: "hoja-meta", style: { left: mm(10), top: mm(30.5), width: mm(190), fontSize: mm(2.6) } },
      h("span", {}, "Contratista · Ubicación · UTM E · UTM N"), h("span", {}, "Página " + n)));
}

function itemPagina(it, paneles, escala) {
  const mm = (v) => v * escala + "px";
  if (it.tipo === "titulo" || it.tipo === "celda") {
    const p = paneles[it.ip];
    const sel = S.selPanel === it.ip ? " is-selected" : "";
    const elegir = () => seleccionarPanel(it.ip);
    if (it.tipo === "titulo") {
      const el = h("div", {
        class: "pg-titulo" + sel, role: "button", tabindex: "0", title: "Editar el panel «" + p.titulo + "»",
        style: { left: mm(HOJA.margen), top: mm(it.y), width: mm(HOJA.util), height: mm(it.h), background: p.color, color: textoSobre(p.color), fontSize: mm(2.5) },
        onclick: elegir, onkeydown: (e) => { if (e.key === "Enter") elegir(); }
      }, (p.titulo || "").toUpperCase());
      zonaSoltar(el, it.ip, null);
      return el;
    }
    const fotoSel = S.selFoto?.ip === it.ip && S.selFoto?.clave === it.clave;
    const elegirFoto = () => seleccionarFoto(it.ip, it.clave);
    const el = h("div", {
      class: "pg-celda" + sel + (fotoSel ? " is-foto-sel" : ""), draggable: "true", role: "button", tabindex: "0",
      title: it.nombre + " · clic para cambiar su tamaño, arrastre para moverla",
      style: { left: mm(it.x), top: mm(it.y), width: mm(it.w), height: mm(it.hi + it.hn) },
      onclick: elegirFoto, onkeydown: (e) => { if (e.key === "Enter") elegirFoto(); }
    },
      h("div", { class: "pg-img", style: { height: mm(it.hi), fontSize: mm(2.4) } },
        h("span", { class: "pg-cam", style: { width: mm(Math.min(13, it.hi / 2.6)), height: mm(Math.min(13, it.hi / 2.6)) } }, ico("camara")),
        it.hn ? null : h("small", {}, it.nombre),
        it.obligatoria ? h("em", {}, "Obligatoria") : null),
      it.hn ? h("div", { class: "pg-nombre", style: { height: mm(it.hn), background: p.color, color: textoSobre(p.color), fontSize: mm(2.2) } }, it.nombre) : null);
    if (fotoSel && S.config.pdf.paneles[it.ip]) {
      el.append(
        h("span", { class: "pg-medida" }, medidaTexto(it.span, it.hi)),
        h("span", { class: "pg-asa", title: "Arrastre para agrandar o achicar", "aria-hidden": "true",
          onpointerdown: (e) => redimensionarFoto(e, el, it, escala) }));
    }
    el.addEventListener("dragstart", (e) => {
      if (el.classList.contains("is-resizing")) { e.preventDefault(); return; }
      asegurarPaneles();
      arrastrePagina = { ip: it.ip, clave: it.clave };
      e.dataTransfer.effectAllowed = "move";
      e.dataTransfer.setData("text/plain", it.clave);
      el.classList.add("is-dragging");
    });
    el.addEventListener("dragend", () => { arrastrePagina = null; el.classList.remove("is-dragging"); });
    zonaSoltar(el, it.ip, it.clave);
    return el;
  }
  if (it.tipo === "comentarios") {
    return h("div", { class: "pg-coment", style: { left: mm(HOJA.margen), top: mm(it.y), width: mm(HOJA.util), height: mm(14), fontSize: mm(2.8) } },
      h("div", { style: { height: mm(8) } }, S.config.pdf.comentarios.titulo || "COMENTARIOS / OBSERVACIONES"),
      h("div", { class: "pg-coment-texto", style: { fontSize: mm(2.4) } }, "Texto del campo «" + (campoPorClave(S.config.pdf.comentarios.campo)?.etiqueta || "comentarios") + "»"));
  }
  const firmas = S.config.pdf.firmas;
  const ancho = HOJA.util / firmas.length;
  return h("div", { class: "pg-firmas", style: { left: mm(HOJA.margen), top: mm(it.y), width: mm(HOJA.util), fontSize: mm(2.6) } },
    ...firmas.map((f) => {
      const col = h("div", { style: { width: mm(ancho), position: "relative" } },
        h("span", { class: "pg-linea", "aria-hidden": "true" }),
        h("strong", {}, f.nombre || "Nombre"), h("span", {}, f.cargo || "Cargo"), h("span", {}, "Fecha:"));
      if (f.imagen) {
        urlLogo(f.imagen).then((url) => {
          if (!url) return;
          col.prepend(h("img", { src: url, alt: "", class: "pg-firma-img", style: {
            height: mm(f.alto || 16), maxWidth: mm(ancho - 4), left: `calc(50% + ${mm(f.dx || 0)})`, bottom: `calc(100% - ${mm(1 + (f.dy || 0))})`
          } }));
        });
      }
      return col;
    }));
}

function zonaSoltar(el, ip, claveAntes) {
  el.addEventListener("dragover", (e) => {
    if (!arrastrePagina) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
    el.classList.add("is-drop");
  });
  el.addEventListener("dragleave", () => el.classList.remove("is-drop"));
  el.addEventListener("drop", (e) => {
    e.preventDefault();
    el.classList.remove("is-drop");
    if (!arrastrePagina) return;
    const origen = arrastrePagina;
    arrastrePagina = null;
    moverFotoPanel(origen, ip, claveAntes);
  });
}

/** Mueve una foto antes de `claveAntes` en el panel destino (o al final si es null). */
function moverFotoPanel(origen, ipDestino, claveAntes) {
  if (claveAntes === origen.clave) return;
  const paneles = asegurarPaneles();
  // El panel «Otras fotografías» no existe en la configuración: soltar ahí
  // saca la foto de su panel; sacarla de ahí la agrega al destino.
  const desde = paneles[origen.ip];
  const hasta = paneles[ipDestino];
  if (!desde && !hasta) return;
  const tam = desde ? tamanosDe(desde)[origen.clave] : null;
  if (desde) {
    desde.fotos = desde.fotos.filter((k) => k !== origen.clave);
    delete tamanosDe(desde)[origen.clave];
  }
  if (hasta) {
    if (tam) tamanosDe(hasta)[origen.clave] = tam;
    hasta.fotos = hasta.fotos.filter((k) => k !== origen.clave);
    let pos = claveAntes ? hasta.fotos.indexOf(claveAntes) : -1;
    if (pos < 0) pos = hasta.fotos.length;
    hasta.fotos.splice(pos, 0, origen.clave);
  }
  S.selPanel = hasta ? ipDestino : null;
  S.selFoto = hasta ? { ip: ipDestino, clave: origen.clave } : null;
  marcarCambio();
  montarPaginas();
}

/** Índice real del panel; «Otras fotografías» se convierte en un panel editable. */
function panelReal(ip) {
  const paneles = asegurarPaneles();
  if (ip < paneles.length) return ip;
  const extra = panelSueltas();
  if (!extra) return null;
  delete extra._sueltas;
  paneles.push(extra);
  marcarCambio();
  return paneles.length - 1;
}

function seleccionarFoto(ip, clave) {
  const real = panelReal(ip);
  if (real == null) return;
  S.selPanel = real;
  S.selFoto = { ip: real, clave };
  montarPaginas();
}

function fijarTamano(p, clave, w, hi, conInspector = true) {
  tamanosDe(p)[clave] = { w, h: hi };
  marcarCambio();
  montarPaginas(conInspector);
}

/** Esquina ◢ de la foto seleccionada: arrastrar cambia ancho (columnas) y alto (de 5 en 5 mm). */
function redimensionarFoto(e, el, it, escala) {
  const p = S.config.pdf.paneles[it.ip];
  if (!p || e.button > 0) return;
  e.preventDefault();
  e.stopPropagation();
  const caja = el.getBoundingClientRect();
  const img = el.querySelector(".pg-img");
  const medida = el.querySelector(".pg-medida");
  let w = it.span;
  let hi = it.hi;
  el.draggable = false;
  el.classList.add("is-resizing");
  const mover = (ev) => {
    w = Math.max(FOTO.minW, Math.min(FOTO.cols, Math.round((ev.clientX - caja.left) / (FOTO.col * escala))));
    hi = Math.max(FOTO.minH, Math.min(FOTO.maxH, Math.round(((ev.clientY - caja.top) / escala - it.hn) / 5) * 5));
    el.style.width = w * FOTO.col * escala + "px";
    el.style.height = (hi + it.hn) * escala + "px";
    if (img) img.style.height = hi * escala + "px";
    if (medida) medida.textContent = medidaTexto(w, hi);
  };
  const soltar = () => {
    window.removeEventListener("pointermove", mover);
    el.classList.remove("is-resizing");
    el.draggable = true;
    if (w !== it.span || hi !== it.hi) fijarTamano(p, it.clave, w, hi);
  };
  window.addEventListener("pointermove", mover);
  window.addEventListener("pointerup", soltar, { once: true });
  window.addEventListener("pointercancel", soltar, { once: true });
}

function moverEnPanel(p, clave, delta) {
  const i = p.fotos.indexOf(clave);
  const j = i + delta;
  if (i < 0 || j < 0 || j >= p.fotos.length) return;
  p.fotos.splice(i, 1);
  p.fotos.splice(j, 0, clave);
  marcarCambio();
  montarPaginas();
}

const TALLAS = [["Pequeña", 3, 35], ["Mediana", 4, 50], ["Grande", 6, 75], ["Muy grande", 8, 105], ["Toda la fila", 12, 140]];

/** Bloque del inspector para la foto seleccionada: tamaños rápidos, ancho, alto y orden. */
function bloqueFoto(p, ip) {
  const clave = S.selFoto.clave;
  const f = S.config.fotos.find((x) => x.clave === clave);
  const t = tamanoFoto(p, clave);
  const pos = p.fotos.indexOf(clave);
  const rango = (etiqueta, min, max, paso, valor, texto, alCambiar) => {
    const salida = h("output", {}, texto(valor));
    return h("div", { class: "campo" },
      h("span", { class: "tpl-label" }, etiqueta),
      h("div", { class: "tpl-rango" },
        h("input", { type: "range", min, max, step: paso, value: valor, "aria-label": etiqueta,
          oninput: (e) => { const v = Number(e.target.value); salida.textContent = texto(v); alCambiar(v, false); },
          onchange: (e) => alCambiar(Number(e.target.value), true) }),
        salida));
  };
  return h("div", { class: "tpl-foto-sel" },
    h("div", { class: "tpl-inspector-head" },
      h("span", { class: "tpl-foto-thumb" }, ico("camara")),
      h("div", {}, h("p", { class: "eyebrow" }, "Foto seleccionada"), h("h3", {}, f?.nombre || clave)),
      botonIcono("cerrar", "Cerrar", false, () => { S.selFoto = null; montarPaginas(); })),
    h("span", { class: "tpl-label" }, "Tamaño rápido"),
    h("div", { class: "tpl-tallas", role: "group", "aria-label": "Tamaño rápido" }, ...TALLAS.map(([nombre, w, hi]) => {
      const activo = t.w === w && t.hi === hi;
      return h("button", { type: "button", class: "tpl-talla" + (activo ? " is-on" : ""), "aria-pressed": String(activo),
        title: medidaTexto(w, hi), onclick: () => fijarTamano(p, clave, w, hi) },
        h("span", { class: "tpl-talla-hoja", "aria-hidden": "true" },
          h("span", { style: { width: (w / FOTO.cols) * 100 + "%", height: Math.max(18, Math.min(100, (hi / 140) * 100)) + "%" } })),
        h("small", {}, nombre));
    })),
    rango("Ancho", FOTO.minW, FOTO.cols, 1, t.w, (v) => `${mmTexto(v * FOTO.col)} mm`, (v, fin) => fijarTamano(p, clave, v, t.hi, fin)),
    rango("Alto", FOTO.minH, FOTO.maxH, 5, t.hi, (v) => `${v} mm`, (v, fin) => fijarTamano(p, clave, t.w, v, fin)),
    h("p", { class: "tpl-tip" }, "También puede arrastrar la esquina azul de la foto. Las demás fotos se acomodan solas en los espacios libres."),
    h("div", { class: "tpl-inspector-actions" },
      h("button", { class: "button-secondary button-sm", type: "button", disabled: pos <= 0, onclick: () => moverEnPanel(p, clave, -1) },
        ico("arriba"), "Antes"),
      h("button", { class: "button-secondary button-sm", type: "button", disabled: pos >= p.fotos.length - 1, onclick: () => moverEnPanel(p, clave, 1) },
        ico("abajo"), "Después"),
      h("button", { class: "button-ghost button-sm", type: "button", title: "Todas las fotos del panel con este tamaño", onclick: () => {
        p.fotos.forEach((k) => { tamanosDe(p)[k] = { w: t.w, h: t.hi }; });
        marcarCambio();
        montarPaginas();
        toast("Todas las fotos del panel quedaron de " + medidaTexto(t.w, t.hi) + ".");
      } }, "Igual para todas"),
      t.propio ? h("button", { class: "button-ghost button-sm", type: "button", title: "Vuelve al tamaño del panel", onclick: () => {
        delete tamanosDe(p)[clave];
        marcarCambio();
        montarPaginas();
      } }, "Tamaño del panel") : null));
}

function seleccionarPanel(ip) {
  const real = panelReal(ip);
  if (real == null) return;
  ip = real;
  S.selFoto = null;
  S.selPanel = ip;
  montarPaginas();
}

function agregarPanel() {
  const paneles = asegurarPaneles();
  paneles.push(nuevoPanel());
  S.selPanel = paneles.length - 1;
  marcarCambio();
  montarPaginas();
  $("inspectorPaneles")?.scrollIntoView({ behavior: "smooth", block: "nearest" });
}

function avisoPaginas(r) {
  const usadas = new Set(S.config.pdf.paneles.flatMap((p) => p.fotos));
  const sueltas = r.auto ? [] : S.config.fotos.filter((f) => !f.panoramica && !usadas.has(f.clave));
  const partes = [];
  if (r.auto && r.paneles.length) {
    partes.push(h("div", { class: "tpl-aviso tpl-aviso-info" }, h("span", { "aria-hidden": "true" }, "i"),
      h("p", {}, "Se arma automáticamente un panel por categoría de foto. Haga clic en cualquier panel para personalizarlo.")));
  }
  if (sueltas.length) {
    const s = sueltas.length === 1 ? "" : "s";
    partes.push(h("div", { class: "tpl-aviso tpl-aviso-info" }, h("span", { "aria-hidden": "true" }, "i"),
      h("p", {}, h("strong", {}, `${sueltas.length} foto${s} sin panel: `),
        "sale" + (sueltas.length === 1 ? "" : "n") + " al final, en «Otras fotografías» (" +
        sueltas.slice(0, 4).map((f) => f.nombre).join(", ") + (sueltas.length > 4 ? "…" : "") + ")."),
      h("button", { class: "button-secondary button-sm", type: "button", onclick: () => seleccionarPanel(S.config.pdf.paneles.length) }, "Editar ese panel")));
  }
  if (!r.auto && r.paginas.length) {
    partes.push(h("p", { class: "tpl-ok" }, `✓ Todas las fotografías aparecen en el PDF · ${r.paginas.length} hoja${r.paginas.length === 1 ? "" : "s"} de fotos.`));
  }
  if (!r.auto && r.vacios.length) {
    partes.push(h("p", { class: "tpl-muted" }, "Paneles sin fotos (no se imprimen): " +
      r.vacios.map((i) => "«" + (r.paneles[i].titulo || "sin título") + "»").join(", ")));
  }
  return h("div", { class: "tpl-avisos-paginas" }, ...partes);
}

function inspectorPaneles(r) {
  const paneles = S.config.pdf.paneles;
  const ip = S.selPanel;
  const p = ip != null && !r.auto ? paneles[ip] : null;
  if (!p) {
    const n = r.paginas.length;
    return h("div", { class: "tpl-inspector-body" },
      h("div", { class: "tpl-inspector-head" },
        h("span", { class: "tpl-color-dot", style: { background: "#7B7B7B" }, "aria-hidden": "true" }),
        h("div", {}, h("p", { class: "eyebrow" }, "Páginas de fotografías"),
          h("h3", {}, `${n} hoja${n === 1 ? "" : "s"} · ${r.paneles.length} panel${r.paneles.length === 1 ? "" : "es"}`)),
        ayuda("paginas", true)),
      h("p", { class: "tpl-muted" }, "Elija un panel para editarlo:"),
      h("div", { class: "tpl-lista-paneles" }, ...r.paneles.map((x, i) => h("button", {
        type: "button", class: "tpl-panel-btn", onclick: () => seleccionarPanel(i)
      },
        h("span", { class: "tpl-color-dot", style: { background: x.color }, "aria-hidden": "true" }),
        h("span", {}, h("strong", {}, x.titulo || "Sin título"),
          h("small", {}, `${x.fotos.length} foto${x.fotos.length === 1 ? "" : "s"} · ${x.columnas} por fila`))))),
      h("button", { class: "button-secondary button-sm", type: "button", onclick: agregarPanel }, "＋ Agregar panel"));
  }

  const g = geometriaPanel(p);
  const fotoPorClave = new Map(S.config.fotos.map((f) => [f.clave, f]));
  const enOtro = new Map();
  paneles.forEach((o) => { if (o !== p) o.fotos.forEach((k) => enOtro.set(k, o.titulo || "sin título")); });
  const cambio = (conInspector = true) => { marcarCambio(); montarPaginas(conInspector); };
  const automatico = !(Number(p.alto_foto) > 0);
  const altoAuto = altoAutoFoto(g.span);
  const propios = p.fotos.filter((k) => tamanoFoto(p, k).propio).length;
  const conFoto = S.selFoto?.ip === ip && p.fotos.includes(S.selFoto.clave);

  return h("div", { class: "tpl-inspector-body" },
    conFoto ? bloqueFoto(p, ip) : h("p", { class: "tpl-tip tpl-tip-foto" }, ico("camara"),
      "Haga clic en una foto de la hoja para agrandarla o achicarla."),
    h("div", { class: "tpl-inspector-head" },
      h("span", { class: "tpl-color-dot", style: { background: p.color }, "aria-hidden": "true" }),
      h("div", {}, h("p", { class: "eyebrow" }, "Panel de fotos"), h("h3", {}, p.titulo || "Sin título")),
      ayuda("panel", true)),
    campoForm("pTitulo", "Título del panel", h("input", { id: "pTitulo", value: p.titulo, maxlength: 80, placeholder: "Vacío = sin banda de título",
      oninput: (e) => { p.titulo = e.target.value; cambio(false); } })),
    h("div", { class: "campo" }, h("span", { class: "tpl-label" }, "Color"),
      selectorColor(p.color, (hex) => { p.color = hex; cambio(false); }, "Color del panel")),
    campoForm("pCols", "Fotos por fila", h("div", { class: "tpl-segmented", id: "pCols" },
      ...[1, 2, 3, 4].map((n) => h("button", { type: "button", class: g.cols === n ? "is-on" : "", "aria-pressed": String(g.cols === n),
        onclick: () => { p.columnas = n; cambio(); } }, String(n)))),
      `Cada foto mide ${mmTexto(g.ancho)} mm de ancho × ${mmTexto(g.altoImg)} mm de alto` + (propios ? `, salvo ${propios} con tamaño propio.` : ".")),
    propios ? h("button", { class: "button-ghost button-sm", type: "button", onclick: () => { p.tamanos = {}; S.selFoto = null; cambio(); } },
      `Quitar los ${propios} tamaño${propios === 1 ? "" : "s"} propio${propios === 1 ? "" : "s"}`) : null,
    h("div", { class: "campo" },
      h("span", { class: "tpl-label" }, "Alto de las fotos"),
      interruptor("Automático", `Se ajusta al ancho (≈ ${mmTexto(altoAuto)} mm).`, automatico, (e) => {
        p.alto_foto = e.target.checked ? 0 : Math.max(25, Math.round(altoAuto / 5) * 5);
        cambio();
      }),
      automatico ? null : h("div", { class: "tpl-rango" },
        h("input", { type: "range", min: 25, max: 120, step: 5, value: p.alto_foto, "aria-label": "Alto de las fotos en milímetros",
          oninput: (e) => { p.alto_foto = Number(e.target.value); e.target.nextElementSibling.textContent = p.alto_foto + " mm"; cambio(false); },
          // Al soltar se rehace el panel derecho para actualizar las medidas.
          onchange: () => cambio() }),
        h("output", {}, p.alto_foto + " mm"))),
    interruptor("Nombre bajo cada foto", "Banda de color con el nombre de la foto.", p.mostrar_nombres !== false,
      (e) => { p.mostrar_nombres = e.target.checked; cambio(); }),
    interruptor("Empezar en página nueva", "Salto de hoja antes de este panel.", Boolean(p.nueva_pagina),
      (e) => { p.nueva_pagina = e.target.checked; cambio(false); }),
    h("div", { class: "tpl-campos-cuadro" },
      h("p", { class: "tpl-label" }, `Fotos del panel (${p.fotos.length})`),
      ...(p.fotos.length ? p.fotos.map((k, i) => {
        const f = fotoPorClave.get(k);
        const tf = tamanoFoto(p, k);
        return h("div", { class: "tpl-campo-sel" + (S.selFoto?.ip === ip && S.selFoto.clave === k ? " is-on" : "") },
          h("span", { class: "tpl-tipo-icono tpl-tipo-foto" }, ico("camara")),
          h("button", { type: "button", class: "tpl-link-foto", title: "Seleccionar para cambiar su tamaño", onclick: () => seleccionarFoto(ip, k) },
            (f?.nombre || k) + (f && f.cantidad > 1 ? ` ×${f.cantidad}` : ""),
            tf.propio ? h("small", {}, medidaTexto(tf.w, tf.hi)) : null),
          botonIcono("arriba", "Subir", i === 0, () => { p.fotos.splice(i - 1, 0, p.fotos.splice(i, 1)[0]); cambio(); }),
          botonIcono("cerrar", "Quitar del panel (pasa a «Otras fotografías»)", false, () => { p.fotos.splice(i, 1); cambio(); }, "danger"));
      }) : [h("p", { class: "tpl-muted" }, "Sin fotos todavía. Agréguelas abajo.")]),
      h("select", {
        class: "tpl-panel-add", "aria-label": "Agregar foto al panel",
        onchange: (e) => { if (e.target.value) { p.fotos.push(e.target.value); cambio(); } }
      }, new Option("＋ Agregar foto a este panel…", ""),
        ...S.config.fotos.filter((f) => !f.panoramica && !p.fotos.includes(f.clave))
          .map((f) => new Option(f.nombre + (enOtro.has(f.clave) ? `  (está en «${enOtro.get(f.clave)}»)` : ""), f.clave)))),
    h("div", { class: "tpl-inspector-actions" },
      h("div", { class: "tpl-icon-group" },
        botonIcono("arriba", "Subir panel", ip === 0, () => { paneles.splice(ip - 1, 0, paneles.splice(ip, 1)[0]); S.selPanel = ip - 1; cambio(); }),
        botonIcono("abajo", "Bajar panel", ip === paneles.length - 1, () => { paneles.splice(ip + 1, 0, paneles.splice(ip, 1)[0]); S.selPanel = ip + 1; cambio(); })),
      h("button", { class: "button-secondary button-sm", type: "button", title: "Nuevo panel con el mismo estilo, sin fotos", onclick: () => {
        paneles.splice(ip + 1, 0, { ...clonar(p), titulo: (p.titulo || "Panel") + " (copia)", fotos: [], tamanos: {} });
        S.selPanel = ip + 1;
        cambio();
      } }, ico("copiar"), "Copiar estilo"),
      h("button", { class: "button-ghost button-sm tpl-danger", type: "button", onclick: async () => {
        const ok = await modal({
          titulo: "Eliminar panel", tono: "danger", aceptar: "Eliminar panel",
          texto: `¿Eliminar el panel «${p.titulo || "sin título"}»?`,
          detalle: [`Sus ${p.fotos.length} foto${p.fotos.length === 1 ? "" : "s"} pasan al final del PDF, en «Otras fotografías».`,
            "El celular las sigue pidiendo igual.", "Puede deshacerlo saliendo sin guardar."]
        });
        if (!ok) return;
        paneles.splice(ip, 1);
        S.selPanel = null;
        cambio();
      } }, ico("borrar"), "Eliminar")),
    h("button", { class: "button-ghost button-sm", type: "button", onclick: () => { S.selPanel = null; montarPaginas(); } }, "← Ver todos los paneles"));
}

window.addEventListener("resize", () => { if (S.tab === "pdf") montarPaginas(false); });


function seccionCierre() {
  const pdf = S.config.pdf;
  const textos = campos().filter((c) => c.tipo === "parrafo" || c.tipo === "texto");
  const firmas = h("div", { class: "tpl-firmas" });
  const pintarFirmas = () => {
    firmas.replaceChildren(...pdf.firmas.map((f, i) => tarjetaFirma(f, i, pintarFirmas)),
      pdf.firmas.length < 3 ? h("button", { class: "tpl-firma tpl-firma-nueva", type: "button", onclick: () => {
        pdf.firmas.push({ nombre: "", cargo: "", imagen: null, alto: 16, dx: 0, dy: 0 }); marcarCambio(); pintarFirmas();
      } }, "＋ Agregar firmante") : null);
    requestAnimationFrame(() => montarPaginas(false));
  };
  pintarFirmas();
  return h("section", { class: "surface tpl-bloque" },
    h("div", { class: "tpl-section-head" }, h("div", {}, h("h2", {}, "Cierre del reporte", ayuda("cierre")), h("p", {}, "Comentarios y firmas, al final del PDF."))),
    h("div", { class: "tpl-grid-2" },
      campoForm("cmCampo", "Campo de comentarios", h("select", { id: "cmCampo", onchange: (e) => {
        pdf.comentarios.campo = e.target.value || null; marcarCambio(); $("avisoSinUbicar")?.replaceChildren(avisoSinUbicar()); montarPaginas(false);
      } }, new Option("— Sin bloque de comentarios —", ""), ...textos.map((c) => new Option(c.etiqueta, c.clave, false, c.clave === pdf.comentarios.campo)))),
      campoForm("cmTitulo", "Título del bloque", h("input", { id: "cmTitulo", value: pdf.comentarios.titulo, maxlength: 80,
        oninput: (e) => { pdf.comentarios.titulo = e.target.value; marcarCambio(); } }))),
    h("p", { class: "tpl-label" }, "Firmas (hasta 3)"),
    firmas);
}

// --- Firma con imagen ------------------------------------------------------------
//
// La imagen (PNG; el servidor vuelve transparente el fondo blanco) se apoya
// sobre la línea. Se acomoda arrastrándola en el recuadro o con los botones;
// el tamaño es su alto en mm. Mismas medidas que plantillaPdfFirmas().

const FIRMA = { escala: 2.6, minAlto: 8, maxAlto: 35, dx: 30, dy: 12 };

function tarjetaFirma(f, i, repintar) {
  const pdf = S.config.pdf;
  f.alto ??= 16; f.dx ??= 0; f.dy ??= 0;
  const mm = (v) => v * FIRMA.escala + "px";
  const anchoCol = HOJA.util / Math.max(1, pdf.firmas.length);
  const zona = h("div", { class: "tpl-firma-zona", style: { height: mm(38) } });
  const medida = h("output", { class: "tpl-firma-medida" });
  const aplicar = (img) => {
    if (!img) return;
    img.style.height = mm(f.alto);
    img.style.maxWidth = mm(anchoCol - 4);
    img.style.left = `calc(50% + ${mm(f.dx)})`;
    img.style.bottom = mm(-1 - f.dy);
    medida.textContent = `${f.alto} mm de alto`;
  };
  const cambio = () => { marcarCambio(); montarPaginas(false); };
  const input = h("input", { type: "file", accept: "image/png,image/jpeg,image/webp", class: "sr-only", id: "firmaImg" + i,
    onchange: async (ev) => {
      const file = ev.target.files[0];
      if (!file) return;
      zona.classList.add("is-loading");
      try {
        f.imagen = await subirImagenPlantilla(file, "firma");
        f.dx = 0; f.dy = 0;
        marcarCambio();
        toast("Firma lista. Arrástrela para acomodarla y guarde la plantilla.");
        repintar();
      } catch (error) {
        toast(mensajeError(error), "error");
      } finally {
        zona.classList.remove("is-loading");
        ev.target.value = "";
      }
    } });

  if (f.imagen) {
    urlLogo(f.imagen).then((url) => {
      if (!url) { zona.replaceChildren(h("span", { class: "tpl-logo-falta" }, "Falta en el servidor: vuelva a subirla")); return; }
      const img = h("img", { src: url, alt: "Firma de " + (f.nombre || "firmante"), class: "tpl-firma-img", draggable: "false",
        title: "Arrástrela para acomodarla" });
      aplicar(img);
      img.addEventListener("pointerdown", (e) => {
        if (e.button > 0) return;
        e.preventDefault();
        img.setPointerCapture(e.pointerId);
        img.classList.add("is-moving");
        const x0 = e.clientX; const y0 = e.clientY; const dx0 = f.dx; const dy0 = f.dy;
        const mover = (ev) => {
          f.dx = Math.max(-FIRMA.dx, Math.min(FIRMA.dx, Math.round(dx0 + (ev.clientX - x0) / FIRMA.escala)));
          f.dy = Math.max(-FIRMA.dy, Math.min(FIRMA.dy, Math.round(dy0 + (ev.clientY - y0) / FIRMA.escala)));
          aplicar(img);
        };
        const soltar = () => {
          img.removeEventListener("pointermove", mover);
          img.classList.remove("is-moving");
          if (f.dx !== dx0 || f.dy !== dy0) cambio();
        };
        img.addEventListener("pointermove", mover);
        img.addEventListener("pointerup", soltar, { once: true });
        img.addEventListener("pointercancel", soltar, { once: true });
      });
      zona.replaceChildren(img, h("span", { class: "tpl-firma-guia", "aria-hidden": "true" }));
    });
  } else {
    zona.append(h("label", { for: "firmaImg" + i, class: "tpl-firma-subir" }, ico("mas"), h("strong", {}, "Subir firma"), h("small", {}, "PNG o JPG · fondo blanco o transparente")));
  }

  const tamano = f.imagen ? h("div", { class: "tpl-firma-controles" },
    h("div", { class: "tpl-rango" },
      h("span", { class: "tpl-label" }, "Tamaño"),
      h("input", { type: "range", min: FIRMA.minAlto, max: FIRMA.maxAlto, step: 1, value: f.alto, "aria-label": "Alto de la firma en milímetros",
        oninput: (e) => { f.alto = Number(e.target.value); aplicar(zona.querySelector("img")); },
        onchange: cambio }),
      medida),
    h("div", { class: "tpl-firma-botones" },
      botonIcono("arriba", "Subir un poco", false, () => { f.dy = Math.max(-FIRMA.dy, f.dy - 1); aplicar(zona.querySelector("img")); cambio(); }),
      botonIcono("abajo", "Bajar un poco", false, () => { f.dy = Math.min(FIRMA.dy, f.dy + 1); aplicar(zona.querySelector("img")); cambio(); }),
      h("button", { class: "button-ghost button-sm", type: "button", onclick: () => { f.dx = 0; f.dy = 0; aplicar(zona.querySelector("img")); cambio(); } }, "Centrar"),
      h("label", { for: "firmaImg" + i, class: "button-ghost button-sm" }, "Cambiar"),
      h("button", { class: "button-ghost button-sm tpl-danger", type: "button", onclick: () => { f.imagen = null; cambio(); repintar(); } }, "Quitar firma"))) : null;
  medida.textContent = `${f.alto} mm de alto`;

  return h("div", { class: "tpl-firma" + (f.imagen ? " con-imagen" : "") },
    input,
    zona,
    h("span", { class: "tpl-firma-linea", "aria-hidden": "true" }),
    h("input", { value: f.nombre, placeholder: "Nombre", maxlength: 80, "aria-label": "Nombre del firmante " + (i + 1),
      oninput: (e) => { f.nombre = e.target.value; marcarCambio(); } }),
    h("input", { value: f.cargo, placeholder: "Cargo", maxlength: 80, "aria-label": "Cargo del firmante " + (i + 1),
      oninput: (e) => { f.cargo = e.target.value; marcarCambio(); } }),
    tamano,
    h("button", { class: "button-ghost button-sm tpl-danger", type: "button", onclick: async () => {
      if (!(await modal({ titulo: "Quitar firmante", tono: "danger", aceptar: "Quitar",
        texto: `¿Quitar a «${f.nombre || "este firmante"}» del cierre del PDF?`, detalle: ["Puede deshacerlo saliendo sin guardar."] }))) return;
      pdf.firmas.splice(i, 1); marcarCambio(); repintar();
    } }, ico("borrar"), "Quitar firmante"));
}

// ---------------------------------------------------------------------------
// Pestaña 4: Ajustes
// ---------------------------------------------------------------------------

function vistaAjustes() {
  const p = S.plantilla;
  return h("div", { class: "tpl-ajustes" },
    h("section", { class: "surface tpl-bloque" },
      h("h2", {}, "Descripción"),
      campoForm("ajDesc", "Para qué sirve esta plantilla", h("textarea", { id: "ajDesc", rows: 3, maxlength: 500, value: S.descripcion,
        oninput: (e) => { S.descripcion = e.target.value; marcarCambio(); } }))),
    h("section", { class: "surface tpl-bloque" },
      h("h2", {}, "Uso"),
      h("ul", { class: "tpl-uso" },
        h("li", {}, h("strong", {}, p.resumen?.proyectos ?? 0), " proyecto(s) usan esta plantilla."),
        h("li", {}, "Versión guardada: ", h("strong", {}, "v" + p.version), ". Cada guardado crea una versión nueva en el historial."),
        h("li", {}, "Para usarla: ", h("a", { href: "admin.html" }, "Administración → Proyectos"), " → crear o editar un proyecto → «Plantilla de inspección».")),
      h("div", { class: "tpl-aviso tpl-aviso-info" },
        h("span", { "aria-hidden": "true" }, "i"),
        h("p", {}, "Cambiar una plantilla ya en uso es seguro: los teléfonos reciben la nueva versión al sincronizar y el PDF se regenera con el nuevo diseño. Las respuestas de campos que se eliminen quedan guardadas, pero dejan de mostrarse."))));
}

// ---------------------------------------------------------------------------
// Arranque
// ---------------------------------------------------------------------------

(async () => {
  await cargarLista();
  const id = Number(new URLSearchParams(location.search).get("plantilla"));
  if (id) abrirEditor(id);
})();
