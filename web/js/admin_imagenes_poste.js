"use strict";

/**
 * Pantalla «Configurar imágenes» del panel administrativo.
 *
 * Es una PÁGINA, no un modal: scroll normal del navegador, cabecera y breadcrumb
 * de administración, y una rejilla compacta de miniaturas.
 *
 * ## Qué hace y qué no
 *
 * Guarda únicamente qué fotografías pueden salir en el reporte PDF, por
 * estructura y por fuente (NAS / Backup). No elimina, no archiva, no mueve y no
 * modifica ninguna fotografía ni ningún dato de inspección.
 *
 * ## Sin configuración se imprime TODO
 *
 * Es la regla que gobierna esta pantalla. Una estructura que nunca se configuró
 * llega con todas sus fotografías disponibles marcadas. El backend guarda las
 * EXCLUIDAS, no las incluidas, así que «sin configuración» y «configurada sin
 * excluir nada» son lo mismo, y una fotografía que aparezca más tarde se
 * imprime por defecto.
 *
 * De ahí que `imprimir` se normalice a `true` cuando la respuesta no lo diga:
 * la ausencia de información no puede significar «no imprimir».
 *
 * ## Dos selecciones independientes
 *
 * NAS y Backup no contienen los mismos archivos, así que cada fuente tiene su
 * propia selección. Se editan y se guardan por separado: al guardar sólo se
 * envía la fuente que está en pantalla y el backend deja la otra como estaba.
 *
 * ## Todo lo que llega del servidor pasa por `normalizarDatos()`
 *
 * Una sola frontera de normalización; después el resto del archivo lee los
 * campos sin comprobar nada. Antes se leía `datos.configuracion.existe` a pelo:
 * con una respuesta que no trajera esa clave —por ejemplo la de una versión
 * anterior del endpoint— la excepción saltaba ANTES de rellenar la selección,
 * así que la pantalla se quedaba a la vez con el error y con cero fotografías
 * marcadas. Un objeto ausente es un caso normal, no un fallo.
 */

verificarSesion();

const query = new URLSearchParams(location.search);
const posteId = Number(query.get("poste_id"));
const proyectoId = Number(query.get("proyecto_id"));
if (!Number.isInteger(posteId) || posteId <= 0 || !Number.isInteger(proyectoId) || proyectoId <= 0) {
  location.replace("admin.html");
}

const mensaje = document.getElementById("mensaje");
const selectorFuente = document.getElementById("fuenteImagenes");
const galeria = document.getElementById("galeria");
const galeriaVacia = document.getElementById("galeriaVacia");
const resumenFuente = document.getElementById("resumenFuente");
const ayudaFuente = document.getElementById("ayudaFuente");
const pendienteGuardar = document.getElementById("pendienteGuardar");
const botonGuardar = document.getElementById("guardar");

/** Payload ya normalizado. Aquí nunca se guarda la respuesta cruda. */
let datos = null;
/** Selección en edición por fuente: Set de slots marcados como «Imprimir». */
const seleccion = new Map();
/** Selección tal como está guardada, para saber si hay cambios pendientes. */
const seleccionGuardada = new Map();

const FUENTES_CONOCIDAS = {
  current: { etiqueta: "NAS", descripcion: "Fotografías sincronizadas actualmente." },
  backup: { etiqueta: "Backup", descripcion: "Fotografías disponibles en el respaldo." }
};

const MOTIVOS = {
  no_disponible_en_respaldo: "No disponible en el respaldo",
  sin_archivo_oficial: "Sin archivo legible en el NAS",
  no_registrada: "No registrada en esta inspección"
};

const texto = (valor) => (typeof valor === "string" && valor.trim() !== "" ? valor.trim() : null);

/**
 * Deja un slot en una forma con la que se puede trabajar sin comprobar nada.
 *
 * `existe` y `disponible` son lo mismo —hay archivo o no lo hay— y se aceptan
 * los dos nombres por si la respuesta viene de otra versión del endpoint. Un
 * slot sin objeto de imagen no es un error: es un slot sin fotografía y se
 * pinta como tal.
 */
function normalizarSlot(bruto, indice) {
  const origen = bruto && typeof bruto === "object" ? bruto : {};
  const imagen = origen.imagen && typeof origen.imagen === "object" ? origen.imagen : {};
  const clave = texto(origen.slot) || texto(imagen.slot) || `slot_${indice + 1}`;
  // Hay fotografía sólo si el servidor nombra un archivo: sin nombre no hay
  // nada que previsualizar ni que imprimir, diga lo que diga el resto.
  const archivo = texto(origen.archivo) || texto(imagen.archivo) || texto(imagen.nombre);
  const declarado = origen.disponible ?? origen.existe ?? imagen.disponible ?? imagen.existe;
  const existe = archivo !== null && declarado !== false;

  return {
    slot: clave,
    etiqueta: texto(origen.etiqueta) || texto(imagen.etiqueta) || clave.replace(/_/g, " "),
    panel: texto(origen.panel) || "",
    existe,
    disponible: existe,
    // Ausencia de dato = se imprime. Nunca al contrario.
    imprimir: origen.imprimir !== false,
    archivo: existe ? archivo : null,
    fecha_captura: texto(origen.fecha_captura) || texto(imagen.fecha_captura),
    modificado_en: texto(origen.modificado_en) || texto(imagen.modificado_en),
    tamano: texto(origen.tamano) || texto(imagen.tamano),
    motivo: texto(origen.motivo)
  };
}

/** Normaliza una fuente y recuenta sus totales desde los slots ya sanos. */
function normalizarFuente(bruto, indice) {
  const origen = bruto && typeof bruto === "object" ? bruto : {};
  const clave = texto(origen.clave) || Object.keys(FUENTES_CONOCIDAS)[indice] || `fuente_${indice + 1}`;
  const conocida = FUENTES_CONOCIDAS[clave] || {};
  const slots = (Array.isArray(origen.slots) ? origen.slots : []).map(normalizarSlot);

  return {
    clave,
    etiqueta: texto(origen.etiqueta) || conocida.etiqueta || clave,
    descripcion: texto(origen.descripcion) || conocida.descripcion || "",
    slots,
    // Se cuentan aquí en vez de copiarlos: así el contador y las casillas no
    // pueden discrepar entre ellos.
    disponibles: slots.filter((slot) => slot.disponible).length,
    no_disponibles: slots.filter((slot) => !slot.disponible).length
  };
}

/** Frontera única: de aquí sale un objeto que ya se puede usar sin más. */
function normalizarDatos(bruto) {
  const origen = bruto && typeof bruto === "object" ? bruto : {};
  const poste = origen.poste && typeof origen.poste === "object" ? origen.poste : {};
  const configuracion = origen.configuracion && typeof origen.configuracion === "object"
    ? origen.configuracion
    : {};
  const recibidas = new Map(
    (Array.isArray(origen.fuentes) ? origen.fuentes : [])
      .map(normalizarFuente)
      // `auto` sigue existiendo en la API por compatibilidad, pero no se configura.
      .filter((fuente) => Object.prototype.hasOwnProperty.call(FUENTES_CONOCIDAS, fuente.clave))
      .map((fuente) => [fuente.clave, fuente])
  );
  // Siempre las dos fuentes, en el orden del selector. Si faltara una, el
  // desplegable seguiría ofreciéndola y `fuenteActual()` caería en la otra:
  // se estaría editando NAS creyendo editar Backup. Una fuente ausente se
  // representa vacía, que es honesto y se ve en pantalla.
  const fuentes = Object.keys(FUENTES_CONOCIDAS).map(
    (clave, indice) => recibidas.get(clave) || normalizarFuente({ clave }, indice)
  );
  const predeterminada = texto(origen.fuente_predeterminada);

  return {
    poste: {
      codigo: texto(poste.codigo),
      linea: texto(poste.linea),
      estructura: texto(poste.estructura)
    },
    configuracion: {
      existe: configuracion.existe === true,
      actualizado_en: texto(configuracion.actualizado_en),
      actualizado_por: texto(configuracion.actualizado_por)
    },
    fuentes,
    total_slots: Number(origen.total_slots) > 0
      ? Number(origen.total_slots)
      : Math.max(...fuentes.map((fuente) => fuente.slots.length), 0),
    fuente_predeterminada: fuentes.some((fuente) => fuente.clave === predeterminada)
      ? predeterminada
      : fuentes[0].clave
  };
}

function fuenteActual() {
  if (!datos) return null;
  return datos.fuentes.find((fuente) => fuente.clave === selectorFuente.value) || datos.fuentes[0];
}

/**
 * Selección con la que se abre una fuente.
 *
 * Todo lo disponible que no esté excluido en lo guardado. Sin configuración
 * `imprimir` es `true` en todos los slots, así que una estructura nueva abre
 * con sus 26 de 26 marcadas.
 */
function marcadasPorDefecto(fuente) {
  return new Set(
    fuente.slots.filter((slot) => slot.disponible && slot.imprimir).map((slot) => slot.slot)
  );
}

function hayCambiosPendientes() {
  for (const [clave, actual] of seleccion) {
    const guardada = seleccionGuardada.get(clave) || new Set();
    if (actual.size !== guardada.size) return true;
    for (const slot of actual) if (!guardada.has(slot)) return true;
  }
  return false;
}

function actualizarPie() {
  const pendiente = hayCambiosPendientes();
  pendienteGuardar.textContent = pendiente ? "Hay cambios sin guardar." : "Sin cambios pendientes.";
  botonGuardar.disabled = !pendiente;
}

function actualizarResumen() {
  const fuente = fuenteActual();
  if (!fuente) return;
  const marcadas = seleccion.get(fuente.clave)?.size || 0;
  resumenFuente.textContent = `${fuente.disponibles} de ${datos.total_slots} imágenes disponibles`
    + ` · ${marcadas} se imprimirán`
    + ` · ${fuente.no_disponibles} no disponibles`;
  actualizarPie();
}

function pintarInsignia() {
  const badge = document.getElementById("estadoConfiguracion");
  if (datos.configuracion.existe) {
    badge.className = "status-badge status-success";
    badge.textContent = datos.configuracion.actualizado_en
      ? "Configurada " + formatearFecha(datos.configuracion.actualizado_en)
      : "Configurada";
    return;
  }
  badge.className = "status-badge status-neutral";
  badge.textContent = "Sin configurar: se imprime todo";
}

function tarjetaSlot(slot, fuente) {
  const card = document.createElement("article");
  card.className = "imgcfg-card";
  card.dataset.disponible = String(slot.disponible);
  card.append(miniaturaFoto(posteId, proyectoId, slot, fuente.clave));

  const cuerpo = fotoNodo("div", "", "imgcfg-card-body");
  cuerpo.append(fotoNodo("span", slot.etiqueta, "imgcfg-card-title"));

  const detalle = [];
  if (slot.disponible) {
    const fecha = slot.fecha_captura || slot.modificado_en;
    if (fecha) detalle.push(formatearFecha(fecha));
    if (slot.tamano) detalle.push(slot.tamano);
  } else {
    // El slot se sigue mostrando, con el motivo cuando el servidor lo da.
    detalle.push(MOTIVOS[slot.motivo] || "No disponible en " + fuente.etiqueta);
  }
  if (detalle.length) cuerpo.append(fotoNodo("span", detalle.join(" · "), "imgcfg-card-meta"));
  if (slot.panel) cuerpo.append(fotoNodo("span", slot.panel, "imgcfg-card-meta"));

  const label = document.createElement("label");
  label.className = "imgcfg-check";
  const check = document.createElement("input");
  check.type = "checkbox";
  check.checked = slot.disponible && (seleccion.get(fuente.clave)?.has(slot.slot) ?? false);
  // Una fotografía que no existe no se puede imprimir: la casilla queda fija.
  check.disabled = !slot.disponible;
  check.addEventListener("change", () => {
    const marcadas = seleccion.get(fuente.clave);
    if (!marcadas) return;
    if (check.checked) marcadas.add(slot.slot);
    else marcadas.delete(slot.slot);
    actualizarResumen();
  });
  label.append(check, fotoNodo("span", slot.disponible ? "Imprimir" : "Sin foto"));
  cuerpo.append(label);

  card.append(cuerpo);
  return card;
}

function pintarGaleria() {
  const fuente = fuenteActual();
  galeria.replaceChildren();
  if (!fuente) return;
  ayudaFuente.textContent = fuente.descripcion;

  // Los slots del catálogo se pintan siempre, con fotografía o sin ella: si
  // desaparecieran no habría forma de ver qué le falta a la estructura.
  fuente.slots.forEach((slot) => galeria.append(tarjetaSlot(slot, fuente)));
  galeria.hidden = fuente.slots.length === 0;

  const sinNada = fuente.disponibles === 0;
  galeriaVacia.hidden = !sinNada;
  if (sinNada) {
    galeriaVacia.textContent = fuente.slots.length === 0
      ? "No se pudo leer la lista de imágenes de esta estructura."
      : "No hay imágenes disponibles en " + fuente.etiqueta + ".";
  }
  actualizarResumen();
}

function aplicarTodas(marcar) {
  const fuente = fuenteActual();
  if (!fuente) return;
  // Una no disponible no se marca nunca, ni con «Seleccionar todas».
  seleccion.set(fuente.clave, marcar
    ? new Set(fuente.slots.filter((slot) => slot.disponible).map((slot) => slot.slot))
    : new Set());
  pintarGaleria();
}

function pintarCabecera() {
  const codigo = datos.poste.codigo || `Estructura ${posteId}`;
  document.title = `${codigo} · Configurar imágenes | ECOING`;
  document.getElementById("tituloEstructura").textContent = codigo;
  document.getElementById("migaEstructura").textContent = codigo;
  document.getElementById("subtituloEstructura").textContent =
    `${codigo} · Selecciona qué imágenes pueden aparecer en el reporte PDF.`;
  document.getElementById("verInspeccion").href =
    `formulario_poste.html?poste_id=${posteId}&proyecto_id=${proyectoId}`;
  // Vuelve al listado exacto del que se vino, no al inicio del panel.
  const vuelta = new URLSearchParams({ seccion: "estructuras", proyecto_id: String(proyectoId) });
  const linea = query.get("linea") || datos.poste.linea;
  if (linea) vuelta.set("linea", linea);
  document.getElementById("volverEstructuras").href = "admin.html?" + vuelta.toString();
}

/** Deja `seleccion` y `seleccionGuardada` en el estado con el que se carga. */
function sembrarSeleccion() {
  datos.fuentes.forEach((fuente) => {
    const marcadas = marcadasPorDefecto(fuente);
    seleccion.set(fuente.clave, new Set(marcadas));
    seleccionGuardada.set(fuente.clave, new Set(marcadas));
  });
}

async function cargar() {
  galeriaVacia.hidden = false;
  galeriaVacia.textContent = "Cargando imágenes…";
  try {
    const payload = await leerRespuesta(await fetchConToken(
      `admin/opciones_reporte_poste.php?poste_id=${posteId}&proyecto_id=${proyectoId}`,
      { timeoutMs: 120000 }
    ));
    datos = normalizarDatos(payload?.data);

    // Primero el estado y después lo que se pinta: así un fallo al rotular no
    // puede dejar la pantalla sin selección.
    sembrarSeleccion();
    selectorFuente.value = datos.fuente_predeterminada;
    pintarCabecera();
    pintarInsignia();
    pintarGaleria();
    limpiarMensaje(mensaje);
  } catch (error) {
    galeriaVacia.hidden = false;
    galeriaVacia.textContent = "No se pudieron cargar las imágenes de esta estructura.";
    mostrarMensaje(mensaje, error.message);
  }
}

async function guardar(event) {
  const fuente = fuenteActual();
  if (!fuente) return;
  const boton = event.currentTarget;
  establecerCargando(boton, true, "Guardando…");
  try {
    const marcadas = seleccion.get(fuente.clave) || new Set();
    const payload = await leerRespuesta(await fetchConToken("admin/opciones_reporte_poste.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        poste_id: posteId,
        proyecto_id: proyectoId,
        photo_source: fuente.clave,
        // Se envía lo que se desmarcó teniéndolo delante, no el complemento del
        // catálogo: un slot que hoy no tiene fotografía no debe quedar excluido
        // para cuando llegue.
        excluidos: fuente.slots
          .filter((slot) => slot.disponible && !marcadas.has(slot.slot))
          .map((slot) => slot.slot)
      })
    }));

    // Se reconstruye desde lo que el servidor dice que quedó guardado, no desde
    // lo que se envió.
    const guardado = payload?.data && typeof payload.data === "object" ? payload.data : {};
    datos.fuentes.forEach((entrada) => {
      const excluidos = Array.isArray(guardado.fuentes?.[entrada.clave]?.excluidos)
        ? guardado.fuentes[entrada.clave].excluidos
        : [];
      entrada.slots.forEach((slot) => { slot.imprimir = !excluidos.includes(slot.slot); });
      const marcadasAhora = marcadasPorDefecto(entrada);
      seleccionGuardada.set(entrada.clave, new Set(marcadasAhora));
      if (entrada.clave !== fuente.clave) seleccion.set(entrada.clave, new Set(marcadasAhora));
    });
    datos.configuracion = {
      existe: true,
      actualizado_en: texto(guardado.actualizado_en),
      actualizado_por: texto(guardado.actualizado_por)
    };
    pintarInsignia();
    actualizarResumen();
    mostrarMensaje(mensaje, `Configuración de ${fuente.etiqueta} guardada.`, "ok");
  } catch (error) {
    mostrarMensaje(mensaje, error.message);
  } finally {
    establecerCargando(boton, false);
    actualizarPie();
  }
}

selectorFuente.addEventListener("change", pintarGaleria);
document.getElementById("marcarTodas").addEventListener("click", () => aplicarTodas(true));
document.getElementById("quitarTodas").addEventListener("click", () => aplicarTodas(false));
document.getElementById("guardar").addEventListener("click", guardar);
document.getElementById("cancelar").addEventListener("click", () => {
  if (hayCambiosPendientes()
    && !window.confirm("Hay cambios sin guardar. ¿Salir sin guardarlos?")) return;
  location.href = document.getElementById("volverEstructuras").href;
});
window.addEventListener("beforeunload", (event) => {
  if (hayCambiosPendientes()) event.preventDefault();
});

// Nada que guardar hasta que se toque una casilla.
botonGuardar.disabled = true;

if (obtenerUsuario()?.rol !== "administrador") {
  location.replace("proyectos.html");
} else {
  cargar();
}
