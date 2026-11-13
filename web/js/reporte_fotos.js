"use strict";

/**
 * Piezas compartidas para la fuente de fotografías del reporte.
 *
 * Las usan tres pantallas:
 *
 *   formulario_poste.html      -> selector NAS/Backup junto a «Abrir reporte PDF»
 *   detalle_poste.html         -> selector NAS/Backup para el PDF y el ZIP
 *   admin_imagenes_poste.html  -> pantalla «Configurar imágenes» del panel admin
 *
 * ## Qué se envía al servidor
 *
 * Sólo la clave de la fuente (`current` o `backup`) y, al guardar en el panel
 * admin, nombres de slot. NUNCA una ruta ni un nombre de archivo: el servidor
 * vuelve a resolver cada archivo por su cuenta.
 *
 * ## Dónde se decide qué se imprime
 *
 * En el panel admin, y se guarda. La vista normal del reporte sólo elige la
 * fuente: el PDF aplica solo la selección guardada para esa estructura y esa
 * fuente. Sin configuración guardada se imprime todo lo disponible.
 */

/** Las dos fuentes que se ofrecen en la interfaz. `auto` no se muestra. */
const FUENTES_FOTO = [
  { clave: "current", etiqueta: "NAS", descripcion: "Formulario y fotografías sincronizados actualmente." },
  { clave: "backup", etiqueta: "Backup", descripcion: "Formulario y fotografías encontrados en el respaldo móvil." }
];

const FUENTE_FOTO_PREDETERMINADA = "current";

function etiquetaFuenteFoto(clave) {
  return FUENTES_FOTO.find((fuente) => fuente.clave === clave)?.etiqueta || clave;
}

/** Crea un elemento con clase y texto en una sola línea. */
function fotoNodo(tag, texto = "", clase = "") {
  const nodo = document.createElement(tag);
  if (texto !== "" && texto !== null && texto !== undefined) nodo.textContent = String(texto);
  if (clase) nodo.className = clase;
  return nodo;
}

/**
 * Selector compacto «Fuente de fotos: [NAS ▼]».
 *
 * Devuelve el `<label>`; el `<select>` se lee con `.control`, que es la
 * propiedad nativa de HTMLLabelElement (de solo lectura: no se le asigna nada).
 * Se recuerda la última elección del usuario en esta pestaña para no tener que
 * volver a elegirla en cada estructura.
 */
function crearSelectorFuenteFoto(id, etiqueta = "Fuente de fotos") {
  const wrap = document.createElement("label");
  wrap.className = "campo campo-inline foto-fuente";
  wrap.htmlFor = id;
  wrap.append(fotoNodo("span", etiqueta));
  const select = document.createElement("select");
  select.id = id;
  FUENTES_FOTO.forEach((fuente) => {
    const option = document.createElement("option");
    option.value = fuente.clave;
    option.textContent = fuente.etiqueta;
    option.title = fuente.descripcion;
    select.append(option);
  });
  select.value = recordarFuenteFoto();
  select.title = FUENTES_FOTO.find((f) => f.clave === select.value)?.descripcion || "";
  select.addEventListener("change", () => {
    recordarFuenteFoto(select.value);
    select.title = FUENTES_FOTO.find((f) => f.clave === select.value)?.descripcion || "";
  });
  wrap.append(select);
  return wrap;
}

/** Lee o guarda la fuente elegida para esta pestaña. */
function recordarFuenteFoto(valor) {
  const clave = "ecoing_fuente_fotos";
  try {
    if (valor === undefined) {
      const guardada = sessionStorage.getItem(clave);
      return FUENTES_FOTO.some((f) => f.clave === guardada) ? guardada : FUENTE_FOTO_PREDETERMINADA;
    }
    sessionStorage.setItem(clave, valor);
  } catch {
    // Modo privado o almacenamiento bloqueado: se usa el valor por defecto.
  }
  return valor ?? FUENTE_FOTO_PREDETERMINADA;
}

/**
 * Aviso cuando el reporte de Backup salió sin el formulario del respaldo.
 *
 * En `backup` el informe completo procede de `Formulario/formulario.json`. Si
 * ese archivo falta o no se puede leer, el reporte sale SIN formulario a
 * propósito: rellenarlo con los datos actuales sería presentarlos como si
 * fueran del respaldo. Devuelve null cuando no hay nada que advertir.
 */
function avisoFormularioRespaldo(resultado) {
  const estados = {
    FORMULARIO_AUSENTE: 'no existe formulario.json en el respaldo',
    FORMULARIO_ILEGIBLE: 'el formulario.json del respaldo no se puede leer',
    FORMULARIO_SIN_DATOS: 'el formulario.json del respaldo no trae datos',
    FORMULARIO_DE_OTRA_ESTRUCTURA: 'el formulario.json del respaldo es de otra estructura'
  };
  const motivo = estados[resultado?.backupForm];
  if (!motivo) return null;
  return 'El reporte se generó con las fotografías del respaldo pero sin su formulario: '
    + motivo + '. No se han usado los datos actuales en su lugar.';
}

/** Parámetro de consulta para `ver_pdf_poste.php` / `exportar_linea_pdf.php`. */
function fuenteFotoComoQuery(clave) {
  return clave && clave !== "auto" ? `&photo_source=${encodeURIComponent(clave)}` : "";
}

/** Visor grande, compartido por las pantallas que muestran miniaturas. */
const visorFoto = (() => {
  let dialog = null;
  let url = null;

  function construir() {
    dialog = document.createElement("dialog");
    dialog.className = "admin-dialog admin-dialog-sm";
    const wrap = document.createElement("div");
    const header = document.createElement("header");
    header.className = "admin-dialog-header";
    const heading = document.createElement("div");
    heading.className = "admin-dialog-heading";
    const titulos = document.createElement("div");
    titulos.append(fotoNodo("p", "Vista previa", "eyebrow"), fotoNodo("h2", "Fotografía"));
    heading.append(titulos);
    const cerrar = fotoNodo("button", "×", "button-ghost admin-dialog-close");
    cerrar.type = "button";
    cerrar.setAttribute("aria-label", "Cerrar vista previa");
    cerrar.addEventListener("click", () => dialog.close());
    header.append(heading, cerrar);
    const body = document.createElement("div");
    body.className = "admin-dialog-body";
    const cuerpo = fotoNodo("div", "", "diag-viewer");
    body.append(cuerpo);
    const footer = document.createElement("footer");
    footer.className = "admin-dialog-footer";
    const pie = fotoNodo("span", "", "cell-subtitle");
    const cerrarPie = fotoNodo("button", "Cerrar", "button-secondary");
    cerrarPie.type = "button";
    cerrarPie.addEventListener("click", () => dialog.close());
    footer.append(pie, cerrarPie);
    wrap.append(header, body, footer);
    dialog.append(wrap);
    dialog.addEventListener("close", () => {
      if (url) { URL.revokeObjectURL(url); url = null; }
      cuerpo.replaceChildren();
    });
    document.body.append(dialog);
    dialog.__titulo = titulos.querySelector("h2");
    dialog.__cuerpo = cuerpo;
    dialog.__pie = pie;
  }

  /**
   * @param {object} slot  entrada de `slots` devuelta por el backend
   * @param {string} fuente clave de la fuente que se está revisando
   */
  return async function abrir(posteId, proyectoId, slot, fuente) {
    if (!dialog) construir();
    dialog.__titulo.textContent = slot.etiqueta;
    dialog.__pie.textContent = [
      slot.slot,
      etiquetaFuenteFoto(fuente),
      slot.fecha_captura ? formatearFecha(slot.fecha_captura) : null,
      slot.tamano
    ].filter(Boolean).join(" · ");
    dialog.__cuerpo.replaceChildren(fotoNodo("p", "Cargando fotografía…"));
    dialog.showModal();
    try {
      url = await objectUrlConToken(
        `admin/ver_archivo_poste.php?poste_id=${posteId}&proyecto_id=${proyectoId}`
          + `&archivo=${encodeURIComponent(slot.archivo)}`
      );
      const img = document.createElement("img");
      img.src = url;
      img.alt = slot.etiqueta;
      dialog.__cuerpo.replaceChildren(
        img,
        fotoNodo("p", "Vista de solo lectura. El archivo original no se modifica.", "diag-cell-sub")
      );
    } catch (error) {
      dialog.__cuerpo.replaceChildren(fotoNodo("p", error.message));
    }
  };
})();

/**
 * Miniatura autenticada de un slot.
 *
 * Un `src` normal no lleva la cabecera Authorization, así que la imagen se trae
 * con fetch y se publica como blob. Se pide la versión reducida del visor
 * (`miniatura=1`) para no descargar megas por cada card.
 */
function miniaturaFoto(posteId, proyectoId, slot, fuente) {
  const boton = document.createElement("button");
  boton.type = "button";
  boton.className = "imgcfg-thumb";
  if (!slot.disponible || !slot.archivo) {
    boton.disabled = true;
    boton.title = "Sin fotografía disponible";
    boton.append(fotoNodo("span", "Sin foto", "imgcfg-thumb-empty"));
    return boton;
  }
  boton.title = "Ampliar " + slot.etiqueta;
  const img = document.createElement("img");
  img.alt = "";
  img.loading = "lazy";
  boton.append(img);
  objectUrlConToken(
    `admin/ver_archivo_poste.php?poste_id=${posteId}&proyecto_id=${proyectoId}`
      + `&miniatura=1&archivo=${encodeURIComponent(slot.archivo)}`
  ).then((objectUrl) => {
    img.src = objectUrl;
    img.addEventListener("load", () => URL.revokeObjectURL(objectUrl), { once: true });
  }).catch(() => {
    boton.replaceChildren(fotoNodo("span", "Sin vista", "imgcfg-thumb-empty"));
    boton.title = "No se pudo cargar la miniatura";
  });
  boton.addEventListener("click", () => visorFoto(posteId, proyectoId, slot, fuente));
  return boton;
}
