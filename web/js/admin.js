"use strict";

verificarSesion();
const adminUser = obtenerUsuario();
if (!adminUser || adminUser.rol !== "administrador") {
  window.location.replace("proyectos.html");
}

const state = {
  section: "proyectos",
  page: 1,
  limit: 10,
  total: 0,
  rows: [],
  projects: [],
  contextLine: "",
  activeLinesByProject: new Map(),
  archivedRows: [],
  loading: false
};

const byId = (id) => document.getElementById(id);
const body = byId("adminBody");
const message = byId("adminMensaje");
const dialog = byId("adminDialog");
const form = byId("adminForm");
const fields = byId("dialogCampos");
const importDialog = byId("importDialog");
const importForm = byId("importForm");
const confirmDialog = byId("confirmDialog");
const confirmForm = byId("confirmForm");
const tableRegion = byId("adminTableRegion");
const formDialog = byId("formDialog");
const formEditor = byId("formEditor");
const managedModals = [dialog, importDialog, confirmDialog, formDialog];
const modalFocusOrigins = new WeakMap();
const centrallyClosedModals = new WeakSet();
let modalScrollSnapshot = null;
let confirmation = null;
const importState = {
  projectId: "",
  contextLine: "",
  allowCreateLines: false,
  file: null,
  preview: null,
  busy: false,
  reactivatePostIds: new Set()
};

const sectionMeta = {
  proyectos: {
    title: "Proyectos",
    singular: "proyecto",
    help: "Cree, edite, archive o reactive proyectos sin alterar sus datos relacionados.",
    head: ["Proyecto", "Estado", "Líneas", "Estructuras", "Acciones"]
  },
  lineas: {
    title: "Líneas",
    singular: "línea",
    help: "Administre los metadatos y la disponibilidad de las líneas.",
    head: ["Línea", "Proyecto", "Estado", "Estructuras", "Datos de campo", "Acciones"]
  },
  estructuras: {
    title: "Estructuras",
    singular: "estructura",
    help: "Consulte el padrón, sus coordenadas UTM y el estado de cada inspección.",
    head: ["Estructura", "Proyecto / línea", "UTM", "Estado", "Inspección", "Acciones"]
  },
  archivados: {
    title: "Archivados",
    singular: "registro",
    help: "Elementos inactivos disponibles para reactivación segura.",
    head: ["Elemento", "Tipo", "Ubicación", "Estado", "Acciones"]
  },
  auditoria: {
    title: "Historial administrativo",
    singular: "evento",
    help: "Registro de usuario, fecha, acción y resultado de los cambios administrativos.",
    head: ["Fecha", "Usuario", "Acción", "Entidad", "Referencia", "Resultado"]
  }
};

const endpointByType = {
  proyectos: "admin/proyectos.php",
  lineas: "admin/lineas.php",
  estructuras: "admin/estructuras.php",
  auditoria: "admin/auditoria.php"
};

function errorMessage(error) {
  return error && error.message ? error.message : "Ocurrió un error inesperado.";
}

function displayName(type, row) {
  if (type === "proyectos") return row.nombre_proyecto || "Proyecto";
  if (type === "lineas") return row.nombre || "Línea";
  return row.codigo || ("Estructura #" + row.id);
}

function isActive(type, row) {
  if (type === "proyectos") return row.estado === "activo";
  return Number(row.activa ?? row.activo) === 1;
}

function createStaticIcon(text, className) {
  const icon = document.createElement("span");
  icon.className = className || "admin-action-icon";
  icon.setAttribute("aria-hidden", "true");
  icon.textContent = text;
  return icon;
}

function showToast(text, tone) {
  const toast = document.createElement("div");
  toast.className = "admin-toast admin-toast-" + (tone || "success");
  toast.setAttribute("role", tone === "error" ? "alert" : "status");
  toast.append(
    createStaticIcon(tone === "error" ? "!" : "✓", "admin-toast-icon"),
    Object.assign(document.createElement("span"), { textContent: text })
  );
  byId("toastRegion").append(toast);
  window.setTimeout(() => {
    toast.classList.add("is-leaving");
    window.setTimeout(() => toast.remove(), 220);
  }, 4200);
}

function input(name, label, value, options) {
  const config = options || {};
  const wrap = document.createElement("div");
  wrap.className = "campo" + (config.wide ? " admin-field-wide" : "");
  const fieldLabel = document.createElement("label");
  fieldLabel.htmlFor = "f_" + name;
  fieldLabel.textContent = label;
  const element = document.createElement("input");
  element.id = "f_" + name;
  element.name = name;
  element.type = config.type || "text";
  element.value = value ?? "";
  element.required = config.required !== false;
  if (config.placeholder) element.placeholder = config.placeholder;
  if (config.inputMode) element.inputMode = config.inputMode;
  if (config.maxLength) element.maxLength = config.maxLength;
  wrap.append(fieldLabel, element);
  if (config.help) {
    const help = document.createElement("small");
    help.textContent = config.help;
    wrap.append(help);
  }
  return wrap;
}

function selectField(name, label, options, value, config) {
  const settings = config || {};
  const wrap = document.createElement("div");
  wrap.className = "campo" + (settings.wide ? " admin-field-wide" : "");
  const fieldLabel = document.createElement("label");
  fieldLabel.htmlFor = "f_" + name;
  fieldLabel.textContent = label;
  const element = document.createElement("select");
  element.id = "f_" + name;
  element.name = name;
  element.required = settings.required !== false;
  options.forEach((option) => {
    element.add(new Option(option[1], option[0], false, String(option[0]) === String(value ?? "")));
  });
  wrap.append(fieldLabel, element);
  return wrap;
}

function formSection(title, description, controls) {
  const section = document.createElement("section");
  section.className = "admin-form-section";
  const heading = document.createElement("div");
  heading.className = "admin-form-section-heading";
  const h3 = document.createElement("h3");
  h3.textContent = title;
  heading.append(h3);
  if (description) {
    const copy = document.createElement("p");
    copy.textContent = description;
    heading.append(copy);
  }
  const grid = document.createElement("div");
  grid.className = "admin-form-grid";
  grid.append(...controls);
  section.append(heading, grid);
  return section;
}

function payloadForm() {
  return Object.fromEntries(new FormData(form).entries());
}

function stateBadge(active) {
  return crearInsignia(active ? "Activo" : "Inactivo", active ? "success" : "neutral");
}

function setPanelLoading(loading) {
  state.loading = loading;
  tableRegion.setAttribute("aria-busy", String(loading));
  byId("adminConteo").textContent = loading ? "Cargando" : state.total + " resultados";
  byId("paginaAnterior").disabled = loading || state.page <= 1;
  byId("paginaSiguiente").disabled = loading || state.page * state.limit >= state.total;
}

function renderSkeleton() {
  const columns = sectionMeta[state.section].head.length;
  body.replaceChildren();
  for (let rowIndex = 0; rowIndex < 5; rowIndex += 1) {
    const row = document.createElement("tr");
    row.className = "admin-skeleton-row";
    for (let cellIndex = 0; cellIndex < columns; cellIndex += 1) {
      const cell = document.createElement("td");
      const line = document.createElement("span");
      line.className = "admin-skeleton-line" + (cellIndex === 0 ? " admin-skeleton-line-wide" : "");
      cell.append(line);
      row.append(cell);
    }
    body.append(row);
  }
}

let openMenu = null;

function closeActionMenu() {
  if (!openMenu) return;
  openMenu.trigger.setAttribute("aria-expanded", "false");
  openMenu.menu.remove();
  openMenu = null;
}

function positionActionMenu(trigger, menu) {
  const triggerRect = trigger.getBoundingClientRect();
  const menuRect = menu.getBoundingClientRect();
  const gap = 6;
  const edge = 10;
  let top = triggerRect.bottom + gap;
  let left = triggerRect.right - menuRect.width;
  if (top + menuRect.height > window.innerHeight - edge) {
    top = Math.max(edge, triggerRect.top - menuRect.height - gap);
  }
  left = Math.min(Math.max(edge, left), window.innerWidth - menuRect.width - edge);
  menu.style.top = Math.round(top) + "px";
  menu.style.left = Math.round(left) + "px";
}

function menuButton(label, icon, action, danger) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "admin-context-item" + (danger ? " is-danger" : "");
  button.append(createStaticIcon(icon), Object.assign(document.createElement("span"), { textContent: label }));
  button.addEventListener("click", () => {
    closeActionMenu();
    action();
  });
  return button;
}

function openActionMenu(trigger, row, type) {
  if (openMenu && openMenu.trigger === trigger) {
    closeActionMenu();
    return;
  }
  closeActionMenu();
  const active = isActive(type, row);
  const menu = document.createElement("div");
  menu.className = "admin-context-menu";
  menu.setAttribute("role", "menu");
  menu.append(menuButton("Editar", "✎", () => openEditor(type, row), false));
  // Un proyecto con plantilla tiene su propio formulario y sus propias fotos:
  // el editor fijo y la configuración de imágenes no aplican a sus estructuras.
  const usaPlantilla = type === "estructuras" && Boolean(projectById(row.proyecto_id)?.plantilla_id);
  if (type === "estructuras" && !usaPlantilla) {
    menu.append(menuButton("Editar formulario", "▤", () => openFormEditor(row), false));
    // Abre una página completa, no un modal: la galería necesita scroll normal
    // y la configuración de impresión no es parte de la edición del padrón.
    menu.append(menuButton("Configurar imágenes", "▦", () => openPhotoConfig(row), false));
  }
  if (type === "proyectos") {
    menu.append(menuButton("Ver líneas", "⌁", () => showRelated("lineas", row), false));
    if (row.plantilla_id) {
      menu.append(menuButton("Diseñar su plantilla", "▤", () => {
        window.location.href = "plantillas.html?plantilla=" + encodeURIComponent(row.plantilla_id);
      }, false));
    }
  }
  if (type === "lineas") {
    menu.append(menuButton("Ver estructuras", "⌖", () => showRelated("estructuras", row), false));
  }
  const separator = document.createElement("div");
  separator.className = "admin-context-separator";
  separator.setAttribute("role", "separator");
  menu.append(separator);
  if (active && (type === "lineas" || type === "estructuras")) {
    menu.append(menuButton(
      type === "lineas" ? "Limpiar datos de línea" : "Limpiar inspección",
      "⌫",
      () => previewClear(type === "lineas" ? "linea" : "estructura", row),
      true
    ));
  }
  menu.append(menuButton(active ? "Archivar" : "Reactivar", active ? "▣" : "↻", () => toggleActive(type, row, !active), active));
  document.body.append(menu);
  trigger.setAttribute("aria-expanded", "true");
  openMenu = { trigger, menu };
  positionActionMenu(trigger, menu);
  const first = menu.querySelector("button");
  if (first) first.focus({ preventScroll: true });
}

function actionMenu(row, type) {
  const trigger = document.createElement("button");
  trigger.type = "button";
  trigger.className = "admin-action-trigger";
  trigger.setAttribute("aria-label", "Acciones para " + displayName(type, row));
  trigger.setAttribute("aria-haspopup", "menu");
  trigger.setAttribute("aria-expanded", "false");
  trigger.textContent = "⋮";
  trigger.addEventListener("click", (event) => {
    event.stopPropagation();
    openActionMenu(trigger, row, type);
  });
  return trigger;
}

document.addEventListener("click", (event) => {
  if (openMenu && !openMenu.menu.contains(event.target) && event.target !== openMenu.trigger) closeActionMenu();
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && openMenu) {
    const trigger = openMenu.trigger;
    closeActionMenu();
    trigger.focus();
  }
});
window.addEventListener("resize", closeActionMenu);
window.addEventListener("scroll", closeActionMenu, true);

function renderHead(labels) {
  const row = document.createElement("tr");
  labels.forEach((label) => {
    const header = document.createElement("th");
    header.scope = "col";
    header.textContent = label;
    row.append(header);
  });
  byId("adminHead").replaceChildren(row);
}

function limitedCell(text, label) {
  const cell = celda(text, label);
  cell.classList.add("admin-cell-limit");
  if (text) cell.title = String(text);
  return cell;
}

function emptyState(columns, title, detail) {
  const row = document.createElement("tr");
  const cell = document.createElement("td");
  cell.colSpan = columns;
  cell.className = "estado-vacio";
  const content = document.createElement("div");
  content.className = "admin-empty-content";
  const icon = createStaticIcon("⌕", "admin-empty-icon");
  const heading = document.createElement("strong");
  heading.textContent = title;
  const copy = document.createElement("span");
  copy.textContent = detail;
  content.append(icon, heading, copy);
  cell.append(content);
  row.append(cell);
  body.append(row);
}

function appendActions(row, type, tableRow) {
  const cell = celda("", "Acciones");
  cell.classList.add("admin-action-cell");
  cell.append(actionMenu(row, type));
  tableRow.append(cell);
}

function renderProject(row) {
  const tableRow = document.createElement("tr");
  tableRow.append(celdaCompuesta(
    row.nombre_proyecto,
    "#" + row.id + " · " + (row.plantilla_id ? "Plantilla: " + (row.plantilla_nombre || "#" + row.plantilla_id) : "Formulario fijo"),
    "Proyecto"
  ));
  const status = celda("", "Estado");
  status.append(insigniaEstado(row.estado));
  tableRow.append(
    status,
    celda((row.lineas_activas ?? 0) + "/" + (row.lineas_total ?? 0), "Líneas"),
    celda((row.estructuras_activas ?? 0) + "/" + (row.estructuras_total ?? 0), "Estructuras")
  );
  appendActions(row, "proyectos", tableRow);
  return tableRow;
}

function renderLine(row) {
  const tableRow = document.createElement("tr");
  tableRow.append(
    celdaCompuesta(row.nombre, row.ubicacion || "Sin ubicación registrada", "Línea"),
    limitedCell(row.nombre_proyecto, "Proyecto")
  );
  const status = celda("", "Estado");
  status.append(stateBadge(Number(row.activa) === 1));
  tableRow.append(
    status,
    celda((row.estructuras_activas ?? 0) + "/" + (row.estructuras_total ?? 0), "Estructuras"),
    limitedCell(
      (row.formularios ?? 0) + " formularios · " + (row.fotos ?? 0) + " fotos · " + (row.rst ?? 0) + " RST",
      "Datos de campo"
    )
  );
  appendActions(row, "lineas", tableRow);
  return tableRow;
}

function renderStructure(row) {
  const tableRow = document.createElement("tr");
  tableRow.append(
    celdaCompuesta(row.codigo, "Estructura " + row.estructura, "Estructura"),
    limitedCell(row.nombre_proyecto + " · " + row.linea, "Proyecto / línea"),
    limitedCell(
      formatearCoordenada(row.utm_x) + " / " + formatearCoordenada(row.utm_y) + (row.zona ? " · " + row.zona : ""),
      "UTM"
    )
  );
  const status = celda("", "Estado");
  status.append(stateBadge(Number(row.activo) === 1));
  tableRow.append(
    status,
    limitedCell(
      (Number(row.formulario_subido) ? "Formulario recibido" : "Pendiente") + " · " + (row.fotos_capturadas || 0) + " fotos",
      "Inspección"
    )
  );
  appendActions(row, "estructuras", tableRow);
  return tableRow;
}

function renderAudit(row) {
  const tableRow = document.createElement("tr");
  tableRow.append(
    limitedCell(formatearFecha(row.creado_en, true), "Fecha"),
    limitedCell(row.usuario_nombre, "Usuario"),
    limitedCell(humanizarValor(row.accion), "Acción"),
    limitedCell(humanizarValor(row.entidad_tipo), "Entidad"),
    limitedCell(
      [
        row.proyecto_id && "P#" + row.proyecto_id,
        row.linea,
        row.poste_id && "E#" + row.poste_id
      ].filter(Boolean).join(" · ") || "—",
      "Referencia"
    )
  );
  const result = celda("", "Resultado");
  result.append(crearInsignia(row.resultado === "ok" ? "Correcto" : "Error", row.resultado === "ok" ? "success" : "danger"));
  tableRow.append(result);
  return tableRow;
}

function renderArchived(row) {
  const type = row._type;
  const label = displayName(type, row);
  const tableRow = document.createElement("tr");
  tableRow.append(
    celdaCompuesta(label, "#" + row.id, "Elemento"),
    limitedCell(humanizarValor(type.slice(0, -1)), "Tipo"),
    limitedCell(row.ubicacion || row.ubicaciones || row.nombre_proyecto || "—", "Ubicación")
  );
  const status = celda("", "Estado");
  status.append(stateBadge(false));
  tableRow.append(status);
  appendActions(row, type, tableRow);
  return tableRow;
}

function renderPagination() {
  const totalPages = Math.max(1, Math.ceil(state.total / state.limit));
  const first = state.total ? ((state.page - 1) * state.limit) + 1 : 0;
  const last = state.total ? Math.min(state.page * state.limit, state.total) : 0;
  byId("paginacionResumen").textContent = "Mostrando " + first + "–" + last + " de " + state.total;
  byId("paginaTexto").textContent = "Página " + state.page + " de " + totalPages;
  byId("paginaAnterior").disabled = state.loading || state.page <= 1;
  byId("paginaSiguiente").disabled = state.loading || state.page >= totalPages;

  const numbers = byId("paginaNumeros");
  numbers.replaceChildren();
  const controls = byId("paginaAnterior").parentElement;
  controls.hidden = totalPages <= 1;
  if (totalPages <= 1) return;

  const pages = [...new Set([1, state.page - 1, state.page, state.page + 1, totalPages])]
    .filter((page) => page >= 1 && page <= totalPages)
    .sort((a, b) => a - b);
  let previous = 0;
  pages.forEach((page) => {
    if (previous && page - previous > 1) {
      const ellipsis = document.createElement("span");
      ellipsis.className = "admin-page-ellipsis";
      ellipsis.textContent = "…";
      numbers.append(ellipsis);
    }
    const button = document.createElement("button");
    button.type = "button";
    button.className = "admin-page-number" + (page === state.page ? " activo" : "");
    button.textContent = String(page);
    button.setAttribute("aria-label", "Ir a la página " + page);
    if (page === state.page) button.setAttribute("aria-current", "page");
    button.addEventListener("click", () => {
      if (page === state.page || state.loading) return;
      state.page = page;
      load();
    });
    numbers.append(button);
    previous = page;
  });
}

function prepareSectionView() {
  const meta = sectionMeta[state.section];
  byId("adminTitulo").textContent = meta.title;
  byId("adminBreadcrumbActual").textContent = meta.title;
  byId("adminAyuda").textContent = state.contextLine && state.section === "estructuras"
    ? "Mostrando las estructuras asociadas a " + state.contextLine + "."
    : meta.help;
  byId("nuevoRegistro").hidden = ["archivados", "auditoria"].includes(state.section);
  byId("nuevoRegistro").querySelector("span:last-child").textContent = "Nueva " + meta.singular;
  byId("importarExcel").hidden = state.section !== "lineas"
    && !(state.section === "estructuras" && Boolean(state.contextLine));
  byId("campoProyecto").hidden = ["proyectos", "auditoria"].includes(state.section);
  byId("campoEstado").hidden = ["archivados", "auditoria"].includes(state.section);
  byId("adminBuscar").placeholder = state.section === "auditoria"
    ? "Usuario, acción o referencia"
    : "Nombre, línea, código o ubicación";
  document.querySelectorAll("[data-section]").forEach((tab) => {
    const selected = tab.dataset.section === state.section;
    tab.classList.toggle("activo", selected);
    tab.setAttribute("aria-selected", String(selected));
  });
  renderHead(meta.head);
}

function render() {
  const meta = sectionMeta[state.section];
  prepareSectionView();
  body.replaceChildren();
  byId("adminConteo").textContent = state.total + (state.total === 1 ? " resultado" : " resultados");
  if (!state.rows.length) {
    emptyState(
      meta.head.length,
      "No se encontraron registros",
      "Pruebe con otros términos o limpie los filtros aplicados."
    );
  } else {
    state.rows.forEach((row) => {
      if (state.section === "proyectos") body.append(renderProject(row));
      else if (state.section === "lineas") body.append(renderLine(row));
      else if (state.section === "estructuras") body.append(renderStructure(row));
      else if (state.section === "auditoria") body.append(renderAudit(row));
      else body.append(renderArchived(row));
    });
  }
  setPanelLoading(false);
  renderPagination();
}

async function loadProjects() {
  const selected = byId("adminProyecto").value;
  const response = await fetchConToken("admin/proyectos.php?estado=activo&limite=100");
  const data = await leerRespuesta(response);
  state.projects = Array.isArray(data.data) ? data.data : [];
  const projectSelect = byId("adminProyecto");
  projectSelect.replaceChildren(new Option("Todos los proyectos", ""));
  state.projects.forEach((project) => projectSelect.add(new Option(project.nombre_proyecto, project.id)));
  if ([...projectSelect.options].some((option) => option.value === String(selected))) {
    projectSelect.value = selected;
  }
}

function filtersFor(endpoint, status) {
  const params = new URLSearchParams({ pagina: String(state.page), limite: String(state.limit) });
  const search = byId("adminBuscar").value.trim();
  const projectId = byId("adminProyecto").value;
  if (search) params.set("busqueda", search);
  if (projectId && endpoint !== endpointByType.proyectos && endpoint !== endpointByType.auditoria) {
    params.set("proyecto_id", projectId);
  }
  if (status) params.set("estado", status);
  if (state.contextLine && endpoint === endpointByType.estructuras) params.set("linea", state.contextLine);
  return params;
}

async function fetchAllArchived(endpoint, status) {
  const all = [];
  let page = 1;
  let total = 0;
  do {
    const params = filtersFor(endpoint, status);
    params.set("pagina", String(page));
    params.set("limite", "100");
    const data = await leerRespuesta(await fetchConToken(endpoint + "?" + params.toString()));
    const rows = Array.isArray(data.data) ? data.data : [];
    all.push(...rows);
    total = Number(data.total ?? all.length);
    page += 1;
    if (!rows.length) break;
  } while (all.length < total);
  return all;
}

async function loadArchived() {
  const results = await Promise.all([
    fetchAllArchived(endpointByType.proyectos, "cancelado"),
    fetchAllArchived(endpointByType.lineas, "inactiva"),
    fetchAllArchived(endpointByType.estructuras, "inactiva")
  ]);
  state.archivedRows = [
    ...results[0].map((row) => ({ ...row, _type: "proyectos" })),
    ...results[1].map((row) => ({ ...row, _type: "lineas" })),
    ...results[2].map((row) => ({ ...row, _type: "estructuras" }))
  ];
  state.total = state.archivedRows.length;
  const totalPages = Math.max(1, Math.ceil(state.total / state.limit));
  if (state.page > totalPages) state.page = totalPages;
  const offset = (state.page - 1) * state.limit;
  state.rows = state.archivedRows.slice(offset, offset + state.limit);
}

async function load() {
  closeActionMenu();
  limpiarMensaje(message);
  prepareSectionView();
  setPanelLoading(true);
  renderSkeleton();
  try {
    if (state.section === "archivados") {
      await loadArchived();
    } else {
      const endpoint = endpointByType[state.section];
      const selectedStatus = byId("adminEstado").value;
      let status = "";
      if (state.section === "proyectos" && selectedStatus) status = selectedStatus === "inactivo" ? "cancelado" : "activo";
      if (state.section === "lineas" && selectedStatus) status = selectedStatus === "inactivo" ? "inactiva" : "activa";
      if (state.section === "estructuras" && selectedStatus) status = selectedStatus === "inactivo" ? "inactiva" : "activa";
      const params = filtersFor(endpoint, status);
      const data = await leerRespuesta(await fetchConToken(endpoint + "?" + params.toString()));
      state.rows = Array.isArray(data.data) ? data.data : [];
      state.total = Number(data.total ?? state.rows.length);
      const totalPages = Math.max(1, Math.ceil(state.total / state.limit));
      if (state.page > totalPages) {
        state.page = totalPages;
        return load();
      }
    }
    render();
  } catch (error) {
    state.rows = [];
    state.total = 0;
    render();
    mostrarMensaje(message, errorMessage(error));
    showToast("No se pudieron cargar los datos.", "error");
  }
}

function switchSection(section, options) {
  const config = options || {};
  state.section = section;
  state.page = 1;
  state.contextLine = config.line || "";
  if (config.clearSearch) byId("adminBuscar").value = "";
  if (config.projectId !== undefined) byId("adminProyecto").value = String(config.projectId || "");
  return load();
}

/**
 * Configuración de impresión de las fotografías de una estructura.
 *
 * Se lleva el proyecto y la línea en la URL para que la pantalla pueda volver
 * exactamente a este listado.
 */
function openPhotoConfig(row) {
  const params = new URLSearchParams({
    poste_id: String(row.id),
    proyecto_id: String(row.proyecto_id)
  });
  if (row.linea) params.set("linea", String(row.linea));
  window.location.href = "admin_imagenes_poste.html?" + params.toString();
}

function showRelated(section, row) {
  if (section === "lineas") {
    switchSection("lineas", { projectId: row.id, clearSearch: true });
  } else {
    rememberActiveLine(row);
    switchSection("estructuras", { projectId: row.proyecto_id, line: row.nombre, clearSearch: true });
  }
}

function projectOptions(row) {
  const options = state.projects.map((project) => [project.id, project.nombre_proyecto]);
  if (row && row.proyecto_id && !options.some((option) => String(option[0]) === String(row.proyecto_id))) {
    options.push([row.proyecto_id, (row.nombre_proyecto || "Proyecto #" + row.proyecto_id) + " (archivado)"]);
  }
  return options;
}

function projectById(projectId) {
  return state.projects.find((project) => String(project.id) === String(projectId)) || null;
}

function invalidateActiveLines(projectId) {
  if (projectId) state.activeLinesByProject.delete(String(projectId));
}

function rememberActiveLine(line) {
  if (!line?.proyecto_id || Number(line.activa) !== 1) return;
  const key = String(line.proyecto_id);
  const cached = state.activeLinesByProject.get(key) || { complete: false, rows: [] };
  const rows = cached.rows.filter((item) => String(item.id) !== String(line.id));
  rows.push(line);
  state.activeLinesByProject.set(key, { complete: cached.complete, rows });
}

async function activeLinesForProject(projectId) {
  const key = String(projectId || "");
  if (!key) return [];
  const cached = state.activeLinesByProject.get(key);
  if (cached?.complete) return cached.rows;

  const rows = [];
  let page = 1;
  let total = 0;
  do {
    const params = new URLSearchParams({
      proyecto_id: key,
      estado: "activa",
      pagina: String(page),
      limite: "100"
    });
    const data = await leerRespuesta(await fetchConToken("admin/lineas.php?" + params.toString()));
    const batch = Array.isArray(data.data) ? data.data : [];
    rows.push(...batch.filter((line) => Number(line.activa) === 1));
    total = Number(data.total ?? rows.length);
    page += 1;
    if (!batch.length) break;
  } while (rows.length < total);

  const unique = [...new Map(rows.map((line) => [String(line.id), line])).values()];
  state.activeLinesByProject.set(key, { complete: true, rows: unique });
  return unique;
}

function resolveStructureDefaults(projectId, preferredLine, lines) {
  const project = projectById(projectId);
  let line = lines.find((item) => item.nombre === preferredLine) || null;
  if (!line && lines.length === 1) line = lines[0];
  return {
    projectId: String(projectId || ""),
    line: line?.nombre || "",
    location: String(line?.ubicacion || project?.ubicacion || "")
  };
}

function replaceLineOptions(select, lines, preferredLine, emptyLabel) {
  const placeholder = new Option(
    lines.length ? "Seleccione una línea" : (emptyLabel || "No hay líneas activas"),
    ""
  );
  placeholder.disabled = true;
  select.replaceChildren(placeholder);
  lines.forEach((line) => select.add(new Option(line.nombre, line.nombre)));
  const selected = lines.some((line) => line.nombre === preferredLine)
    ? preferredLine
    : (lines.length === 1 ? lines[0].nombre : "");
  select.value = selected;
  select.disabled = Boolean(emptyLabel && !lines.length);
  return selected;
}

function applyLocationSuggestion(input, suggestion, force) {
  const nextSuggestion = String(suggestion || "");
  const manuallyChanged = input.dataset.locationManual === "true";
  if (force || !manuallyChanged) {
    input.value = nextSuggestion;
    input.dataset.locationManual = "false";
  }
  input.dataset.suggestedValue = nextSuggestion;
}

function trackManualLocation(input) {
  input.dataset.locationManual = String(input.value !== (input.dataset.suggestedValue || ""));
}

function setupStructureCreationContext(initialLines, initialDefaults) {
  const projectSelect = byId("f_proyecto_id");
  const lineSelect = byId("f_linea");
  const locationInput = byId("f_ubicaciones");
  const saveButton = byId("dialogGuardar");
  let currentLines = initialLines;
  let loadSequence = 0;

  const suggestLocation = (force) => {
    const defaults = resolveStructureDefaults(projectSelect.value, lineSelect.value, currentLines);
    applyLocationSuggestion(locationInput, defaults.location, force);
  };

  locationInput.addEventListener("input", () => trackManualLocation(locationInput));
  lineSelect.addEventListener("change", () => suggestLocation(false));
  projectSelect.addEventListener("change", async () => {
    const sequence = ++loadSequence;
    const projectId = projectSelect.value;
    currentLines = [];
    replaceLineOptions(lineSelect, [], "", projectId ? "Cargando líneas…" : "Seleccione primero un proyecto");
    applyLocationSuggestion(locationInput, projectById(projectId)?.ubicacion || "", false);
    limpiarMensaje(byId("dialogMensaje"));
    if (!projectId) {
      form.dataset.linesBusy = "false";
      saveButton.disabled = false;
      return;
    }

    form.dataset.linesBusy = "true";
    saveButton.disabled = true;
    try {
      const lines = await activeLinesForProject(projectId);
      if (sequence !== loadSequence || !dialog.open) return;
      currentLines = lines;
      replaceLineOptions(lineSelect, lines, "");
      suggestLocation(false);
    } catch (error) {
      if (sequence !== loadSequence || !dialog.open) return;
      replaceLineOptions(lineSelect, [], "", "No se pudieron cargar las líneas");
      mostrarMensaje(byId("dialogMensaje"), errorMessage(error));
    } finally {
      if (sequence === loadSequence) {
        form.dataset.linesBusy = "false";
        saveButton.disabled = false;
      }
    }
  });

  if (initialDefaults.projectId) {
    replaceLineOptions(lineSelect, currentLines, initialDefaults.line);
  } else {
    replaceLineOptions(lineSelect, [], "", "Seleccione primero un proyecto");
  }
  applyLocationSuggestion(locationInput, initialDefaults.location, true);
}

function resetImportResults() {
  importState.preview = null;
  importState.reactivatePostIds.clear();
  byId("importResultados").hidden = true;
  byId("importResumen").replaceChildren();
  byId("importLineas").replaceChildren();
  byId("importLineasFaltantes").replaceChildren();
  byId("importFaltantes").hidden = true;
  byId("importCrearLineas").checked = false;
  byId("importCrearLineas").disabled = false;
  byId("importErroresArchivo").replaceChildren();
  byId("importErroresArchivo").hidden = true;
  byId("importPreviewBody").replaceChildren();
  byId("importConfirmacionTexto").textContent = "";
  const confirmButton = byId("importConfirmar");
  confirmButton.disabled = true;
  confirmButton.textContent = "Importar padrón";
  delete confirmButton.dataset.textoOriginal;
}

function resetImportModal() {
  importForm.reset();
  importForm.dataset.busy = "false";
  importDialog.classList.remove("is-busy");
  byId("importDropZone").classList.remove("is-dragging");
  byId("importProyecto").textContent = "—";
  byId("importContextoLinea").textContent = "";
  byId("importArchivoNombre").textContent = "Ningún archivo seleccionado.";
  byId("importProgress").hidden = true;
  limpiarMensaje(byId("importMensaje"));
  resetImportResults();
  importState.projectId = "";
  importState.contextLine = "";
  importState.allowCreateLines = false;
  importState.file = null;
  importState.busy = false;
}

function updateImportConfirmState() {
  const preview = importState.preview;
  const missing = preview?.missing_lines || [];
  const missingConfirmed = missing.length === 0
    || (preview?.can_create_missing_lines && byId("importCrearLineas").checked);
  const allowed = Boolean(preview?.can_import)
    && Number(preview?.summary?.errores || 0) === 0
    && Number(preview?.summary?.conflictos || 0) === 0
    && missingConfirmed
    && !importState.busy;
  const button = byId("importConfirmar");
  button.disabled = !allowed;
  button.textContent = "Importar padrón";
  updateImportConfirmation();
}

function setImportBusy(busy, label) {
  importState.busy = Boolean(busy);
  importForm.dataset.busy = String(Boolean(busy));
  importDialog.classList.toggle("is-busy", Boolean(busy));
  byId("importProgress").hidden = !busy;
  importDialog.querySelectorAll("button, input").forEach((control) => {
    control.disabled = Boolean(busy);
  });
  const confirmButton = byId("importConfirmar");
  if (busy) establecerCargando(confirmButton, true, label || "Procesando…");
  else {
    establecerCargando(confirmButton, false);
    importDialog.querySelectorAll('[data-modal-close="importDialog"]').forEach((control) => { control.disabled = false; });
    byId("importArchivo").disabled = false;
    byId("descargarPlantilla").disabled = false;
    byId("importCrearLineas").disabled = !(importState.preview?.missing_lines?.length)
      || !importState.preview?.can_create_missing_lines;
    updateImportConfirmState();
  }
}

function appendImportList(target, items) {
  target.replaceChildren();
  items.forEach((item) => {
    const entry = document.createElement("li");
    entry.textContent = item.nombre + " → " + item.cantidad + " estructura" + (Number(item.cantidad) === 1 ? "" : "s");
    target.append(entry);
  });
}

function importPreviewCell(value) {
  const cell = document.createElement("td");
  cell.textContent = value === null || value === undefined || value === "" ? "—" : String(value);
  return cell;
}

function importDisplayValue(value) {
  return value === null || value === undefined || value === "" ? "—" : String(value);
}

function importClassificationMeta(classification) {
  const values = {
    nueva: ["NUEVA", "is-info"],
    existente_activa: ["EXISTENTE ACTIVA", "is-info"],
    existente_archivada: ["ARCHIVADA", "is-warning"],
    sin_cambios: ["SIN CAMBIOS", "is-valid"],
    conflicto: ["CONFLICTO", "is-error"],
    error: ["ERROR", "is-error"]
  };
  return values[classification] || ["ERROR", "is-error"];
}

function renderImportMasterDiffs(row) {
  const cell = document.createElement("td");
  cell.className = "admin-import-changes";
  const content = document.createElement("div");
  content.className = "admin-import-change-content";
  const changes = Array.isArray(row.cambios) ? row.cambios : [];

  if (changes.length) {
    const table = document.createElement("table");
    table.className = "admin-import-diff-table";
    const head = document.createElement("thead");
    const headRow = document.createElement("tr");
    ["Campo", "Actual", "Excel"].forEach((label) => {
      headRow.append(Object.assign(document.createElement("th"), { textContent: label }));
    });
    head.append(headRow);
    const body = document.createElement("tbody");
    changes.forEach((change) => {
      const diffRow = document.createElement("tr");
      [change.campo, importDisplayValue(change.actual), importDisplayValue(change.excel)].forEach((value) => {
        diffRow.append(Object.assign(document.createElement("td"), { textContent: String(value) }));
      });
      body.append(diffRow);
    });
    table.append(head, body);
    content.append(table);
  } else {
    const copy = document.createElement("small");
    copy.textContent = row.clasificacion === "nueva"
      ? "Se crearán el poste y una fila inicial de poste_datos."
      : row.clasificacion === "sin_cambios"
        ? "El padrón ya coincide. No se ejecutará UPDATE."
        : row.clasificacion === "existente_archivada"
          ? "El padrón ya coincide; permanecerá archivada salvo autorización."
          : "No hay cambios maestros disponibles.";
    content.append(copy);
  }

  if (row.linea_faltante) {
    const lineNotice = document.createElement("small");
    lineNotice.className = "admin-import-inline-notice";
    lineNotice.textContent = "Línea nueva: " + row.linea + ". Requiere confirmación.";
    content.append(lineNotice);
  }

  if (row.clasificacion === "existente_archivada" && row.poste_id) {
    if (changes.length) {
      const archivedNotice = document.createElement("small");
      archivedNotice.className = "admin-import-inline-notice";
      archivedNotice.textContent = "Sin autorización explícita esta estructura archivada no se modificará.";
      content.append(archivedNotice);
    }
    const label = document.createElement("label");
    label.className = "admin-import-reactivate";
    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.className = "import-reactivate";
    checkbox.dataset.postId = String(row.poste_id);
    label.append(
      checkbox,
      Object.assign(document.createElement("span"), {
        textContent: "Reactivar y actualizar con datos del Excel"
      })
    );
    content.append(label);
  }

  cell.append(content);
  return cell;
}

function updateImportConfirmation() {
  const preview = importState.preview;
  const target = byId("importConfirmacionTexto");
  if (!preview) {
    target.textContent = "";
    return;
  }
  const summary = preview.summary || {};
  if (!preview.can_import) {
    target.textContent = "La importación está bloqueada. Corrija todos los conflictos y errores del Excel antes de continuar.";
    return;
  }
  const projectName = preview.project?.name || byId("importProyecto").textContent;
  const selectedReactivations = importState.reactivatePostIds.size;
  target.textContent = "Proyecto destino: " + projectName + ". Nuevas: " + (summary.nuevas || 0) +
    ". Existentes activas a actualizar: " + (summary.existentes_actualizar || 0) +
    ". Archivadas detectadas: " + (summary.archivadas || 0) +
    ". Reactivaciones autorizadas: " + selectedReactivations +
    ". Sin cambios: " + (summary.sin_cambios || 0) +
    ". Los datos de inspección, poste_datos existentes, fotografías, RST y sincronización NO serán modificados.";
}

function renderImportPreview(preview) {
  importState.preview = preview;
  importState.reactivatePostIds.clear();
  byId("importResultados").hidden = false;
  const summary = preview.summary || {};
  const summaryItems = [
    ["Registros", summary.registros || 0, ""],
    ["Nuevas", summary.nuevas || 0, "is-success"],
    ["A actualizar", summary.existentes_actualizar || 0, "is-info"],
    ["Archivadas", summary.archivadas || 0, Number(summary.archivadas) ? "is-warning" : ""],
    ["Sin cambios", summary.sin_cambios || 0, ""],
    ["Conflictos", summary.conflictos || 0, Number(summary.conflictos) ? "is-error" : "is-success"],
    ["Con errores", summary.errores || 0, Number(summary.errores) ? "is-error" : "is-success"],
  ];
  const summaryNode = byId("importResumen");
  summaryNode.replaceChildren();
  summaryItems.forEach(([label, value, tone]) => {
    const item = document.createElement("div");
    item.className = "admin-import-stat" + (tone ? " " + tone : "");
    item.append(
      Object.assign(document.createElement("span"), { textContent: label }),
      Object.assign(document.createElement("strong"), { textContent: String(value) })
    );
    summaryNode.append(item);
  });

  appendImportList(byId("importLineas"), preview.line_groups || []);
  const missing = preview.missing_lines || [];
  const missingSection = byId("importFaltantes");
  missingSection.hidden = missing.length === 0;
  appendImportList(byId("importLineasFaltantes"), missing);
  const missingCheckbox = byId("importCrearLineas");
  missingCheckbox.checked = false;
  missingCheckbox.disabled = !preview.can_create_missing_lines;
  const missingCopy = missingSection.querySelector("label span");
  if (missingCopy) {
    missingCopy.textContent = preview.can_create_missing_lines
      ? "Confirmo crear estas líneas en el proyecto destino."
      : "Estas líneas no pueden crearse desde el contexto actual.";
  }

  const fileErrors = byId("importErroresArchivo");
  fileErrors.replaceChildren();
  const errors = Array.isArray(preview.file_errors) ? preview.file_errors : [];
  errors.forEach((error) => {
    fileErrors.append(Object.assign(document.createElement("li"), { textContent: error }));
  });
  fileErrors.hidden = errors.length === 0;

  const previewBody = byId("importPreviewBody");
  previewBody.replaceChildren();
  (preview.rows || []).forEach((row) => {
    const tableRow = document.createElement("tr");
    if (["conflicto", "error"].includes(row.clasificacion)) tableRow.className = "admin-import-row-error";
    tableRow.append(
      importPreviewCell(row.fila),
      importPreviewCell(row.linea),
      importPreviewCell(row.torre),
      importPreviewCell(row.codigo),
      importPreviewCell(row.utm_x),
      importPreviewCell(row.utm_y),
      importPreviewCell(row.ubicacion),
      importPreviewCell(row.zona)
    );
    const statusCell = document.createElement("td");
    const status = document.createElement("span");
    const [statusLabel, statusTone] = importClassificationMeta(row.clasificacion);
    status.className = "admin-import-status " + statusTone;
    status.append(document.createTextNode(statusLabel));
    if (row.errors?.length) {
      status.append(Object.assign(document.createElement("small"), { textContent: row.errors.join(" · ") }));
    }
    statusCell.append(status);
    tableRow.append(statusCell, renderImportMasterDiffs(row));
    previewBody.append(tableRow);
  });
  updateImportConfirmation();
  updateImportConfirmState();
}

async function previewImportFile(file) {
  if (!file) return;
  resetImportResults();
  importState.file = null;
  byId("importArchivoNombre").textContent = "Archivo: " + (file.name || "sin nombre");
  limpiarMensaje(byId("importMensaje"));
  const lowerName = String(file.name || "").toLowerCase();
  if (!lowerName.endsWith(".xlsx") || lowerName.endsWith(".xlsm")) {
    mostrarMensaje(byId("importMensaje"), "Seleccione únicamente un archivo .xlsx sin macros.");
    return;
  }
  if (!file.size || file.size > 5 * 1024 * 1024) {
    mostrarMensaje(byId("importMensaje"), "El archivo debe pesar como máximo 5 MB.");
    return;
  }

  importState.file = file;
  byId("importArchivoNombre").textContent = "Archivo: " + file.name;
  const request = new FormData();
  request.append("archivo", file, file.name);
  request.append("proyecto_id", importState.projectId);
  request.append("linea_contexto", importState.contextLine);
  request.append("permitir_lineas_nuevas", importState.allowCreateLines ? "1" : "0");
  setImportBusy(true, "Validando Excel…");
  try {
    const response = await leerRespuesta(await fetchConToken("admin/importar_estructuras.php", {
      method: "POST",
      body: request,
      timeoutMs: 180000
    }));
    renderImportPreview(response.data);
    mostrarMensaje(byId("importMensaje"), response.message, response.data.can_import ? "ok" : "error");
  } catch (error) {
    resetImportResults();
    mostrarMensaje(byId("importMensaje"), errorMessage(error));
  } finally {
    setImportBusy(false);
  }
}

function openImportDialog() {
  closeActionMenu();
  const projectId = byId("adminProyecto").value;
  const project = projectById(projectId);
  if (!projectId || !project) {
    mostrarMensaje(message, "Seleccione primero el proyecto destino.");
    showToast("Seleccione un proyecto antes de importar.", "error");
    byId("adminProyecto").focus();
    return;
  }
  if (importDialog.open && !cerrarModal(importDialog)) return;
  resetModalState(importDialog);
  importState.projectId = String(projectId);
  importState.contextLine = state.section === "estructuras" ? String(state.contextLine || "") : "";
  importState.allowCreateLines = state.section === "lineas" && !importState.contextLine;
  importForm.dataset.busy = "false";
  byId("importProyecto").textContent = project.nombre_proyecto;
  byId("importContextoLinea").textContent = importState.contextLine
    ? "Línea de contexto: " + importState.contextLine + ". El archivo solo puede contener esta línea."
    : "Las líneas inexistentes requerirán su confirmación explícita.";
  abrirModal(importDialog);
  window.requestAnimationFrame(() => byId("importDropZone").focus());
}

function modalIsBusy(modal) {
  if (!modal) return false;
  const modalForm = modal.querySelector("form");
  return modal.classList.contains("is-busy")
    || modalForm?.dataset.busy === "true"
    || modalForm?.dataset.linesBusy === "true";
}

function syncModalScroll() {
  const hasOpenModal = managedModals.some((modal) => modal.open);
  if (hasOpenModal && !modalScrollSnapshot) {
    modalScrollSnapshot = {
      html: document.documentElement.style.overflow,
      body: document.body.style.overflow
    };
    document.documentElement.style.overflow = "hidden";
    document.body.style.overflow = "hidden";
    return;
  }
  if (!hasOpenModal && modalScrollSnapshot) {
    document.documentElement.style.overflow = modalScrollSnapshot.html;
    document.body.style.overflow = modalScrollSnapshot.body;
    modalScrollSnapshot = null;
  }
}

function abrirModal(modal) {
  if (!modal || modal.open) return false;
  modalFocusOrigins.set(modal, document.activeElement);
  modal.showModal();
  syncModalScroll();
  return true;
}

function resolveConfirmation(result) {
  if (!confirmation) return;
  const resolve = confirmation.resolve;
  confirmation = null;
  resolve(Boolean(result));
}

function cerrarModal(target, options) {
  const config = options || {};
  const modal = typeof target === "string" ? byId(target) : target;
  if (!modal || !modal.open) return false;
  if (modalIsBusy(modal) && !config.force) return false;

  if (modal === confirmDialog && config.resolveConfirmation !== false) {
    resolveConfirmation(Boolean(config.confirmed));
  }
  centrallyClosedModals.add(modal);
  modal.close(config.returnValue || (config.confirmed ? "confirmed" : "cancel"));
  resetModalState(modal);
  syncModalScroll();
  return true;
}

function resetModalState(modal) {
  modal.classList.remove("is-busy");
  if (modal === dialog) {
    const saveButton = byId("dialogGuardar");
    saveButton.disabled = false;
    saveButton.textContent = "Guardar cambios";
    delete saveButton.dataset.textoOriginal;
    form.reset();
    fields.replaceChildren();
    limpiarMensaje(byId("dialogMensaje"));
    delete form.dataset.type;
    delete form.dataset.id;
    delete form.dataset.busy;
    delete form.dataset.linesBusy;
    return;
  }

  if (modal === importDialog) {
    resetImportModal();
    return;
  }

  const acceptButton = byId("confirmAceptar");
  acceptButton.disabled = false;
  acceptButton.className = "button-danger";
  acceptButton.textContent = "Confirmar";
  delete acceptButton.dataset.textoOriginal;
  confirmForm.reset();
  limpiarMensaje(byId("confirmMensaje"));
  byId("confirmTexto").textContent = "";
  byId("confirmReferencia").textContent = "";
  byId("confirmCampo").hidden = true;
  byId("confirmAviso").hidden = true;
  delete confirmForm.dataset.busy;
}

function isBackdropClick(event, modal) {
  if (event.target !== modal) return false;
  const rect = modal.getBoundingClientRect();
  return event.clientX < rect.left
    || event.clientX > rect.right
    || event.clientY < rect.top
    || event.clientY > rect.bottom;
}

document.addEventListener("click", (event) => {
  const trigger = event.target.closest("[data-modal-close]");
  if (!trigger) return;
  event.preventDefault();
  cerrarModal(trigger.dataset.modalClose);
});

managedModals.forEach((modal) => {
  modal.addEventListener("cancel", (event) => {
    event.preventDefault();
    cerrarModal(modal);
  });
  modal.addEventListener("click", (event) => {
    if (!isBackdropClick(event, modal)) return;
    event.preventDefault();
    cerrarModal(modal);
  });
  modal.addEventListener("close", () => {
    const alreadyReset = centrallyClosedModals.delete(modal);
    if (!alreadyReset) {
      if (modal === confirmDialog) resolveConfirmation(false);
      resetModalState(modal);
    }
    syncModalScroll();
    if (modal.open) return;
    const focusOrigin = modalFocusOrigins.get(modal);
    modalFocusOrigins.delete(modal);
    window.requestAnimationFrame(() => {
      if (focusOrigin && focusOrigin.isConnected) focusOrigin.focus();
    });
  });
});

/**
 * Selector de plantilla del proyecto. Vacío = formulario fijo de siempre.
 * Si la lista no carga, el proyecto se puede crear igual (sin plantilla).
 */
async function templateSection(row) {
  const options = [["", "Formulario fijo (el de siempre)"]];
  let failed = false;
  try {
    const data = await leerRespuesta(await fetchConToken("admin/plantillas.php"));
    (data.data || []).forEach((t) => {
      const current = String(t.id) === String(row?.plantilla_id ?? "");
      if (Number(t.activa) === 1 || current) {
        options.push([t.id, t.nombre + " · " + t.campos + " datos · " + t.fotos + " fotos" + (Number(t.activa) === 1 ? "" : " (desactivada)")]);
      }
    });
  } catch (error) {
    failed = true;
  }
  const field = selectField("plantilla_id", "Plantilla de inspección", options, row?.plantilla_id ?? "", { wide: true, required: false });
  const help = document.createElement("small");
  help.textContent = failed
    ? "No se pudo cargar la lista de plantillas. Puede guardar y elegirla después."
    : "Define qué datos y fotos pide la app y cómo sale el PDF. No se puede cambiar cuando el proyecto ya tiene inspecciones.";
  const link = document.createElement("a");
  link.href = "plantillas.html";
  link.textContent = "Crear o editar plantillas ↗";
  link.className = "admin-inline-link";
  field.append(help, link);
  return formSection("Formulario y reporte", "Elija la plantilla que usará este proyecto en campo y en el PDF.", [field]);
}

async function openEditor(type, row, context) {
  closeActionMenu();
  if (dialog.open && !cerrarModal(dialog)) return;
  resetModalState(dialog);
  fields.replaceChildren();
  limpiarMensaje(byId("dialogMensaje"));
  form.dataset.type = type;
  form.dataset.id = row ? row.id : "";
  form.dataset.busy = "false";
  form.dataset.linesBusy = "false";

  const editing = Boolean(row);
  let structureLines = [];
  let structureDefaults = { projectId: "", line: "", location: "" };
  if (type === "estructuras") {
    const projectId = row?.proyecto_id || context?.projectId || "";
    try {
      structureLines = projectId ? await activeLinesForProject(projectId) : [];
    } catch (error) {
      mostrarMensaje(message, errorMessage(error));
      showToast("No se pudieron cargar las líneas activas.", "error");
      return;
    }
    structureDefaults = resolveStructureDefaults(projectId, row?.linea || context?.line || "", structureLines);
    if (editing) structureDefaults.location = String(row?.ubicaciones || "");
  }

  const meta = sectionMeta[type];
  const title = editing
    ? "Editar " + meta.singular
    : (type === "proyectos" ? "Nuevo proyecto" : type === "lineas" ? "Nueva línea" : "Nueva estructura");
  byId("dialogTitulo").textContent = title;
  byId("dialogDescripcion").textContent = editing
    ? "Revise los valores actuales y guarde únicamente los cambios necesarios."
    : "Complete los datos maestros. Los campos marcados son obligatorios.";
  byId("dialogIcono").textContent = editing ? "✎" : "＋";
  byId("dialogGuardar").textContent = editing ? "Guardar cambios" : "Crear registro";

  if (type === "proyectos") {
    fields.append(formSection(
      "Información general",
      "Datos que identifican el proyecto dentro del panel y la aplicación.",
      [
        input("nombre_proyecto", "Nombre del proyecto", row?.nombre_proyecto, { wide: true, maxLength: 150 }),
        input("contratista", "Contratista", row?.contratista, { maxLength: 150 }),
        input("ubicacion", "Ubicación", row?.ubicacion, { maxLength: 255 })
      ]
    ));
    fields.append(await templateSection(row));
  }

  if (type === "lineas") {
    fields.append(
      formSection(
        "Identificación",
        "Asocie la línea al proyecto correcto y conserve su denominación oficial.",
        [
          selectField("proyecto_id", "Proyecto", projectOptions(row), row?.proyecto_id, { wide: true }),
          input("nombre", "Código o nombre de línea", row?.nombre, {
            wide: true,
            maxLength: 100,
            placeholder: "Ej. L-6024"
          })
        ]
      ),
      formSection(
        "Ubicación",
        "Referencia geográfica general de la línea.",
        [
          input("ubicacion", "Ubicación", row?.ubicacion, {
            required: false,
            wide: true,
            maxLength: 255,
            placeholder: "Distrito, sector o tramo"
          })
        ]
      )
    );
  }

  if (type === "estructuras") {
    const structureProjects = projectOptions(row);
    if (!editing) structureProjects.unshift(["", "Seleccione un proyecto"]);
    fields.append(
      formSection(
        "Identificación",
        "Datos que vinculan la estructura con su proyecto y línea.",
        [
          selectField("proyecto_id", "Proyecto", structureProjects, structureDefaults.projectId, { wide: true }),
          selectField("linea", "Línea", [], structureDefaults.line, { wide: true }),
          input("estructura", "Estructura", row?.estructura, { maxLength: 100, placeholder: "Ej. 01" }),
          input("codigo", "Código", row?.codigo, {
            required: false,
            maxLength: 50,
            placeholder: "Automático si queda vacío",
            help: "Al crear, se genera a partir de la línea y la estructura."
          })
        ]
      ),
      formSection(
        "Ubicación y coordenadas",
        "Las coordenadas se conservan con la precisión ingresada.",
        [
          input("utm_x", "UTM X", row?.utm_x, {
            required: false,
            inputMode: "decimal",
            placeholder: "Ej. 387518.646"
          }),
          input("utm_y", "UTM Y", row?.utm_y, {
            required: false,
            inputMode: "decimal",
            placeholder: "Ej. 8249433.662"
          }),
          input("zona", "Zona UTM", row?.zona || "19S", { maxLength: 10 }),
          input("ubicaciones", "Ubicación", editing ? row?.ubicaciones : structureDefaults.location, {
            required: false,
            wide: true,
            maxLength: 255,
            placeholder: "Sector o referencia del poste"
          })
        ]
      )
    );

    const lineSelect = byId("f_linea");
    replaceLineOptions(
      lineSelect,
      structureLines,
      structureDefaults.line,
      structureDefaults.projectId ? "" : "Seleccione primero un proyecto"
    );
    if (!editing) setupStructureCreationContext(structureLines, structureDefaults);
  }

  if (row && type !== "proyectos") {
    const projectSelect = byId("f_proyecto_id");
    if (projectSelect) {
      projectSelect.disabled = true;
      projectSelect.title = "La asociación con el proyecto no se modifica desde esta edición.";
    }
  }

  abrirModal(dialog);
  window.requestAnimationFrame(() => {
    const firstField = fields.querySelector("input:not(:disabled), select:not(:disabled)");
    if (firstField) firstField.focus();
  });
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (form.dataset.busy === "true" || form.dataset.linesBusy === "true" || !form.reportValidity()) return;
  const type = form.dataset.type;
  const id = form.dataset.id;
  const affectedProjectId = type === "lineas" ? byId("f_proyecto_id")?.value : "";
  const payload = payloadForm();
  const endpoint = endpointByType[type];
  const saveButton = byId("dialogGuardar");
  if (id) {
    payload.id = Number(id);
    payload.accion = "actualizar";
  }
  form.dataset.busy = "true";
  dialog.classList.add("is-busy");
  establecerCargando(saveButton, true, id ? "Guardando…" : "Creando…");
  try {
    const data = await leerRespuesta(await fetchConToken(endpoint, {
      method: id ? "PATCH" : "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    }));
    if (type === "lineas") invalidateActiveLines(affectedProjectId || data.data?.proyecto_id);
    establecerCargando(saveButton, false);
    form.dataset.busy = "false";
    dialog.classList.remove("is-busy");
    cerrarModal(dialog, { force: true, returnValue: "success" });
    await loadProjects();
    await load();
    mostrarMensaje(message, data.message, "ok");
    showToast(data.message || "Cambios guardados.", "success");
  } catch (error) {
    mostrarMensaje(byId("dialogMensaje"), errorMessage(error));
  } finally {
    if (dialog.open) {
      if (form.dataset.busy === "true") establecerCargando(saveButton, false);
      form.dataset.busy = "false";
      dialog.classList.remove("is-busy");
    }
  }
});

function setConfirmBusy(busy, label) {
  const button = byId("confirmAceptar");
  confirmDialog.classList.toggle("is-busy", busy);
  confirmForm.dataset.busy = String(busy);
  confirmDialog.querySelectorAll("button, input").forEach((control) => {
    if (control === button && busy) return;
    control.disabled = busy;
  });
  if (busy) {
    establecerCargando(button, true, label || "Procesando…");
  } else {
    establecerCargando(button, false);
    byId("confirmValor").disabled = false;
    confirmDialog.querySelectorAll('[data-modal-close="confirmDialog"]').forEach((control) => { control.disabled = false; });
    const required = confirmation?.required || "";
    button.disabled = Boolean(required) && byId("confirmValor").value.trim() !== required;
  }
}

function finishConfirmation(result) {
  if (!confirmation) return false;
  setConfirmBusy(false);
  return cerrarModal(confirmDialog, {
    force: true,
    confirmed: Boolean(result),
    returnValue: result ? "confirmed" : "cancelled"
  });
}

function askConfirm(config) {
  if (confirmDialog.open && !cerrarModal(confirmDialog, { returnValue: "cancelled" })) {
    return Promise.resolve(false);
  }
  resetModalState(confirmDialog);
  limpiarMensaje(byId("confirmMensaje"));
  byId("confirmTitulo").textContent = config.title;
  byId("confirmTexto").textContent = config.text;
  byId("confirmReferencia").textContent = config.required || "";
  byId("confirmValor").value = "";
  byId("confirmCampo").hidden = !config.required;
  // Lista de cambios concretos: al tocar datos de una inspección ya hecha, el
  // administrador tiene que ver exactamente qué va a modificar antes de aceptar.
  const detalle = byId("confirmDetalle");
  if (detalle) {
    detalle.replaceChildren();
    const cambios = Array.isArray(config.details) ? config.details : [];
    cambios.forEach((cambio) => {
      const item = document.createElement("li");
      const nombre = document.createElement("strong");
      nombre.textContent = cambio.etiqueta;
      const valores = document.createElement("span");
      valores.className = "admin-confirm-change-values";
      const antes = document.createElement("span");
      antes.className = "admin-confirm-before";
      antes.textContent = cambio.antes;
      const despues = document.createElement("span");
      despues.className = "admin-confirm-after";
      despues.textContent = cambio.despues;
      valores.append(antes, Object.assign(document.createElement("span"), { textContent: "→", ariaHidden: "true" }), despues);
      item.append(nombre, valores);
      detalle.append(item);
    });
    detalle.hidden = cambios.length === 0;
  }
  byId("confirmAviso").hidden = config.notice === false;
  byId("confirmIcono").textContent = config.tone === "safe" ? "↻" : "!";
  byId("confirmIcono").classList.toggle("admin-dialog-icon-warning", config.tone !== "safe");
  const accept = byId("confirmAceptar");
  accept.className = config.tone === "safe" ? "button-primary" : "button-danger";
  accept.textContent = config.acceptLabel || "Confirmar";
  accept.disabled = Boolean(config.required);
  confirmForm.dataset.busy = "false";
  return new Promise((resolve) => {
    confirmation = {
      resolve,
      required: config.required || "",
      action: config.action,
      loadingLabel: config.loadingLabel || "Procesando…"
    };
    abrirModal(confirmDialog);
    window.requestAnimationFrame(() => {
      if (config.required) byId("confirmValor").focus();
      else accept.focus();
    });
  });
}

byId("confirmValor").addEventListener("input", () => {
  if (!confirmation || confirmForm.dataset.busy === "true") return;
  byId("confirmAceptar").disabled = Boolean(confirmation.required)
    && byId("confirmValor").value.trim() !== confirmation.required;
  limpiarMensaje(byId("confirmMensaje"));
});

confirmForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!confirmation || confirmForm.dataset.busy === "true") return;
  if (confirmation.required && byId("confirmValor").value.trim() !== confirmation.required) {
    mostrarMensaje(byId("confirmMensaje"), "Escriba " + confirmation.required + " exactamente para continuar.");
    return;
  }
  setConfirmBusy(true, confirmation.loadingLabel);
  try {
    await confirmation.action();
    finishConfirmation(true);
  } catch (error) {
    setConfirmBusy(false);
    mostrarMensaje(byId("confirmMensaje"), errorMessage(error));
  }
});

async function toggleActive(type, row, activate) {
  const label = displayName(type, row);
  let responseData = null;
  const confirmed = await askConfirm({
    title: activate ? "Reactivar " + sectionMeta[type].singular : "Archivar " + sectionMeta[type].singular,
    text: activate
      ? label + " volverá a estar disponible para las operaciones autorizadas."
      : label + " dejará de estar disponible para nuevas operaciones. No se eliminarán inspecciones, fotos ni datos históricos.",
    tone: activate ? "safe" : "danger",
    acceptLabel: activate ? "Sí, reactivar" : "Sí, archivar",
    loadingLabel: activate ? "Reactivando…" : "Archivando…",
    action: async () => {
      responseData = await leerRespuesta(await fetchConToken(endpointByType[type], {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: Number(row.id), accion: activate ? "reactivar" : "desactivar" })
      }));
    }
  });
  if (!confirmed) return;
  if (type === "lineas") invalidateActiveLines(row.proyecto_id);
  await load();
  mostrarMensaje(message, responseData.message, "ok");
  showToast(responseData.message || "Estado actualizado.", "success");
}

async function previewClear(scope, row) {
  let previewMessageVisible = false;
  try {
    mostrarMensaje(message, "Preparando el resumen de datos que se archivarán…", "info");
    previewMessageVisible = true;
    const request = scope === "linea"
      ? { alcance: "linea", proyecto_id: Number(row.proyecto_id), linea: row.nombre, solo_resumen: true }
      : { alcance: "estructura", poste_id: Number(row.id), solo_resumen: true };
    const preview = await leerRespuesta(await fetchConToken("admin/limpiar.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(request)
    }));
    limpiarMensaje(message);
    previewMessageVisible = false;
    const details = preview.data;
    const label = details.etiqueta;
    let responseData = null;
    const confirmed = await askConfirm({
      title: scope === "linea" ? "Limpiar datos de la línea" : "Limpiar inspección de estructura",
      text:
        label + ": se archivarán " + details.formularios + " formularios, " +
        details.fotos + " fotos y " + details.rst + " RST en " + details.estructuras +
        " estructura(s). El poste, código, línea, UTM y ubicación permanecerán sin cambios; el estado volverá a No llenado.",
      required: scope === "linea" ? label : "",
      tone: "danger",
      acceptLabel: "Archivar y limpiar",
      loadingLabel: "Archivando evidencia…",
      action: async () => {
        request.solo_resumen = false;
        request.confirmacion = label;
        responseData = await leerRespuesta(await fetchConToken("admin/limpiar.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(request)
        }));
      }
    });
    if (!confirmed) return;
    await load();
    mostrarMensaje(message, responseData.message, "ok");
    showToast(responseData.message || "Datos de inspección archivados.", "success");
  } catch (error) {
    if (previewMessageVisible) limpiarMensaje(message);
    mostrarMensaje(message, errorMessage(error));
    showToast("No se pudo completar la limpieza.", "error");
  }
}

byId("importarExcel").addEventListener("click", openImportDialog);

byId("descargarPlantilla").addEventListener("click", async () => {
  if (importState.busy) return;
  limpiarMensaje(byId("importMensaje"));
  setImportBusy(true, "Descargando plantilla…");
  try {
    await descargarConToken(
      "admin/importar_estructuras.php?plantilla=1",
      "Plantilla_Estructuras_ECOING.xlsx"
    );
  } catch (error) {
    mostrarMensaje(byId("importMensaje"), errorMessage(error));
  } finally {
    setImportBusy(false);
  }
});

const importDropZone = byId("importDropZone");
importDropZone.addEventListener("click", (event) => {
  if (importState.busy || event.target.closest("label")) return;
  byId("importArchivo").click();
});
importDropZone.addEventListener("keydown", (event) => {
  if (importState.busy || !["Enter", " "].includes(event.key)) return;
  event.preventDefault();
  byId("importArchivo").click();
});
["dragenter", "dragover"].forEach((eventName) => {
  importDropZone.addEventListener(eventName, (event) => {
    event.preventDefault();
    if (!importState.busy) importDropZone.classList.add("is-dragging");
  });
});
["dragleave", "drop"].forEach((eventName) => {
  importDropZone.addEventListener(eventName, (event) => {
    event.preventDefault();
    importDropZone.classList.remove("is-dragging");
  });
});
importDropZone.addEventListener("drop", (event) => {
  if (importState.busy) return;
  const files = [...(event.dataTransfer?.files || [])];
  if (files.length !== 1) {
    resetImportResults();
    importState.file = null;
    byId("importArchivoNombre").textContent = "Ningún archivo seleccionado.";
    mostrarMensaje(byId("importMensaje"), "Seleccione un solo archivo .xlsx.");
    return;
  }
  previewImportFile(files[0]);
});
byId("importArchivo").addEventListener("change", (event) => {
  const files = [...(event.target.files || [])];
  if (files.length === 1) previewImportFile(files[0]);
  event.target.value = "";
});
byId("importCrearLineas").addEventListener("change", updateImportConfirmState);
byId("importPreviewBody").addEventListener("change", (event) => {
  const checkbox = event.target.closest(".import-reactivate");
  if (!checkbox || importState.busy) return;
  const postId = Number(checkbox.dataset.postId);
  if (!Number.isInteger(postId) || postId <= 0) return;
  if (checkbox.checked) importState.reactivatePostIds.add(postId);
  else importState.reactivatePostIds.delete(postId);
  updateImportConfirmation();
});

importForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (importState.busy || byId("importConfirmar").disabled || !importState.preview) return;
  const preview = importState.preview;
  const missingLines = preview.missing_lines || [];
  const createLines = missingLines.length && byId("importCrearLineas").checked
    ? missingLines.map((line) => line.nombre)
    : [];
  const records = (preview.rows || []).map((row) => ({
    fila: row.fila,
    linea: row.linea,
    torre: row.torre,
    utm_x: row.utm_x,
    utm_y: row.utm_y,
    ubicacion: row.ubicacion,
    zona: row.zona
  }));
  setImportBusy(true, "Importando estructuras…");
  try {
    const response = await leerRespuesta(await fetchConToken("admin/importar_estructuras.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        accion: "importar",
        proyecto_id: Number(importState.projectId),
        linea_contexto: importState.contextLine,
        file_name: preview.file_name || importState.file?.name || "importacion.xlsx",
        crear_lineas: createLines,
        reactivar_postes: [...importState.reactivatePostIds],
        registros: records
      }),
      timeoutMs: 180000
    }));
    const projectId = response.data.proyecto_id;
    const importedLines = response.data.lineas || [];
    if ((response.data.lineas_creadas || []).length) invalidateActiveLines(projectId);
    setImportBusy(false);
    cerrarModal(importDialog, { force: true, returnValue: "success" });
    await switchSection("estructuras", {
      projectId,
      line: importedLines.length === 1 ? importedLines[0] : "",
      clearSearch: true
    });
    mostrarMensaje(message, response.message, "ok");
    showToast(response.message, "success");
  } catch (error) {
    mostrarMensaje(byId("importMensaje"), errorMessage(error));
  } finally {
    if (importDialog.open) setImportBusy(false);
  }
});

document.querySelectorAll("[data-section]").forEach((button) => {
  button.addEventListener("click", () => switchSection(button.dataset.section));
});

byId("nuevoRegistro").addEventListener("click", () => openEditor(state.section, null, {
  projectId: state.section === "estructuras" ? byId("adminProyecto").value : "",
  line: state.section === "estructuras" ? state.contextLine : ""
}));

byId("adminFiltros").addEventListener("submit", (event) => {
  event.preventDefault();
  state.page = 1;
  state.contextLine = "";
  load();
});

byId("adminLimpiarFiltros").addEventListener("click", () => {
  byId("adminBuscar").value = "";
  byId("adminProyecto").value = "";
  byId("adminEstado").value = "";
  state.contextLine = "";
  state.page = 1;
  load();
});

let searchTimer = 0;
byId("adminBuscar").addEventListener("input", () => {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => {
    state.page = 1;
    state.contextLine = "";
    load();
  }, 380);
});

byId("adminProyecto").addEventListener("change", () => {
  state.page = 1;
  state.contextLine = "";
  load();
});

byId("adminEstado").addEventListener("change", () => {
  state.page = 1;
  load();
});

byId("paginaAnterior").addEventListener("click", () => {
  if (state.loading || state.page <= 1) return;
  state.page -= 1;
  load();
});

byId("paginaSiguiente").addEventListener("click", () => {
  if (state.loading || state.page * state.limit >= state.total) return;
  state.page += 1;
  load();
});

async function initializeAdmin() {
  try {
    await loadProjects();
  } catch (error) {
    showToast("No se pudo cargar el selector de proyectos.", "error");
  }
  // `admin.html?seccion=estructuras&proyecto_id=2&linea=L-6027` permite volver
  // al listado exacto desde el que se abrió otra página.
  const params = new URLSearchParams(window.location.search);
  const section = params.get("seccion");
  if (section && ["proyectos", "lineas", "estructuras"].includes(section)) {
    await switchSection(section, {
      projectId: params.get("proyecto_id") || "",
      line: params.get("linea") || "",
      clearSearch: true
    });
    return;
  }
  await load();
}

// ===========================================================================
// Edición administrativa del formulario de inspección
// ===========================================================================
//
// El estado vivo del editor. `original` es la foto del formulario tal como
// estaba al abrirlo: todo el cálculo de «qué cambió» se hace contra ella, nunca
// contra el último guardado, para que el resumen de confirmación diga la verdad
// aunque el administrador vaya y vuelva sobre el mismo campo.
const formEditorState = {
  posteId: null,
  titulo: "",
  original: { campos: new Map(), rst: new Map() },
  etiquetas: new Map(),
  opciones: new Map(),
  tipos: new Map(),
  origen: { campos: "base", rst: "base" }
};

/**
 * Aviso cuando lo que se está viendo salió del respaldo del teléfono y no de la
 * base. Importa porque al guardar se consolida el formulario completo: si solo
 * se escribiera el campo tocado, el informe dejaría de recurrir al respaldo y
 * perdería el resto de las respuestas.
 */
function formEditorOrigenAviso() {
  const desdeRespaldo = [];
  if (formEditorState.origen.campos === "respaldo") desdeRespaldo.push("los campos del formulario");
  if (formEditorState.origen.rst === "respaldo") desdeRespaldo.push("las marcas R/S/T");
  if (desdeRespaldo.length === 0) return null;

  const aviso = document.createElement("p");
  aviso.className = "admin-origin-notice";
  aviso.textContent = "Esta inspección todavía no está en la base de datos: "
    + desdeRespaldo.join(" y ")
    + " se están leyendo del respaldo del teléfono, igual que hace el informe PDF. "
    + "Al guardar se consolidará el formulario completo en la base.";
  return aviso;
}

/**
 * ¿Cambió de verdad el valor de este campo?
 *
 * Los decimales llegan de la base como «10.000» y el usuario escribe «10»: son
 * el mismo número. Compararlos como texto marcaría un cambio que el backend
 * luego rechazaría con un 409, y el administrador vería «1 campo modificado»
 * seguido de un error sin sentido.
 */
function formEditorDiffiere(campo, antes, ahora) {
  if (formEditorState.tipos.get(campo) === "numero") {
    const vacioAntes = antes === "";
    const vacioAhora = ahora === "";
    if (vacioAntes || vacioAhora) return vacioAntes !== vacioAhora;
    return Number(antes) !== Number(ahora);
  }
  return antes !== ahora;
}

const SIN_RESPUESTA = "— Sin responder —";
const SIN_MARCA = "— Sin marca —";

function etiquetaDeValor(campo, valor) {
  if (valor === "" || valor === null || valor === undefined) return SIN_RESPUESTA;
  const catalogo = formEditorState.opciones.get(campo);
  if (!catalogo) return String(valor);
  // Un `set` guarda varias opciones separadas por coma.
  return String(valor)
    .split(",")
    .filter((parte) => parte !== "")
    .map((parte) => catalogo.get(parte) || parte)
    .join(", ") || SIN_RESPUESTA;
}

/** Control adecuado al tipo del campo, según lo que declaró el backend. */
function formEditorControl(campo) {
  const wrap = document.createElement("div");
  wrap.className = "campo" + (campo.tipo === "texto" || campo.tipo === "set" ? " admin-field-wide" : "");
  const etiqueta = document.createElement("label");
  etiqueta.htmlFor = "fe_" + campo.campo;
  etiqueta.textContent = campo.etiqueta;
  wrap.append(etiqueta);

  if (campo.tipo === "enum") {
    const select = document.createElement("select");
    select.id = "fe_" + campo.campo;
    select.dataset.campo = campo.campo;
    select.add(new Option(SIN_RESPUESTA, "", false, campo.valor === ""));
    campo.opciones.forEach((opcion) => {
      select.add(new Option(opcion.etiqueta, opcion.valor, false, opcion.valor === campo.valor));
    });
    wrap.append(select);
    return wrap;
  }

  if (campo.tipo === "set") {
    const marcados = new Set(String(campo.valor || "").split(",").filter(Boolean));
    const grupo = document.createElement("div");
    grupo.className = "admin-checkbox-group";
    grupo.id = "fe_" + campo.campo;
    grupo.dataset.campo = campo.campo;
    grupo.dataset.tipo = "set";
    campo.opciones.forEach((opcion) => {
      const item = document.createElement("label");
      item.className = "admin-checkbox";
      const caja = document.createElement("input");
      caja.type = "checkbox";
      caja.value = opcion.valor;
      caja.checked = marcados.has(opcion.valor);
      item.append(caja, Object.assign(document.createElement("span"), { textContent: opcion.etiqueta }));
      grupo.append(item);
    });
    wrap.append(grupo);
    return wrap;
  }

  if (campo.tipo === "texto") {
    const area = document.createElement("textarea");
    area.id = "fe_" + campo.campo;
    area.dataset.campo = campo.campo;
    area.rows = 3;
    area.maxLength = 2000;
    area.value = campo.valor;
    wrap.append(area);
    return wrap;
  }

  const numero = document.createElement("input");
  numero.id = "fe_" + campo.campo;
  numero.dataset.campo = campo.campo;
  numero.type = "number";
  numero.min = "0";
  numero.step = campo.campo === "cantidad_pat" ? "1" : "0.001";
  numero.value = campo.valor;
  wrap.append(numero);
  return wrap;
}

/**
 * Una tabla R/S/T. Cada fase es un grupo de opción única, no casillas sueltas:
 * la clave única de la base es (poste, sección, fase), así que marcar un
 * atributo reemplaza al que hubiera en esa misma fase.
 */
function formEditorRstSection(seccion) {
  const bloque = document.createElement("section");
  bloque.className = "admin-rst-table";

  const titulo = document.createElement("h4");
  titulo.textContent = seccion.titulo;
  bloque.append(titulo);

  const tabla = document.createElement("table");
  const cabecera = document.createElement("thead");
  const filaCabecera = document.createElement("tr");
  filaCabecera.append(Object.assign(document.createElement("th"), { textContent: "Atributo", scope: "col" }));
  ["R", "S", "T"].forEach((fase) => {
    filaCabecera.append(Object.assign(document.createElement("th"), { textContent: fase, scope: "col" }));
  });
  cabecera.append(filaCabecera);

  const cuerpo = document.createElement("tbody");
  seccion.filas.forEach((fila) => {
    const tr = document.createElement("tr");
    tr.append(Object.assign(document.createElement("th"), { textContent: fila.etiqueta, scope: "row" }));
    fila.fases.forEach((casilla) => {
      const td = document.createElement("td");
      const radio = document.createElement("input");
      radio.type = "radio";
      radio.name = "rst_" + seccion.seccion + "_" + casilla.fase;
      radio.value = fila.atributo;
      radio.checked = casilla.marcado;
      radio.dataset.seccion = seccion.seccion;
      radio.dataset.fase = casilla.fase;
      radio.setAttribute("aria-label", fila.etiqueta + " en fase " + casilla.fase);
      td.append(radio);
      tr.append(td);
    });
    cuerpo.append(tr);
  });

  // Fila para dejar la fase sin marcar: sin ella no habría forma de deshacer.
  const limpiar = document.createElement("tr");
  limpiar.className = "admin-rst-clear";
  limpiar.append(Object.assign(document.createElement("th"), { textContent: SIN_MARCA, scope: "row" }));
  ["R", "S", "T"].forEach((fase) => {
    const td = document.createElement("td");
    const radio = document.createElement("input");
    radio.type = "radio";
    radio.name = "rst_" + seccion.seccion + "_" + fase;
    radio.value = "";
    radio.checked = !seccion.seleccion[fase];
    radio.dataset.seccion = seccion.seccion;
    radio.dataset.fase = fase;
    radio.setAttribute("aria-label", "Sin marca en fase " + fase);
    td.append(radio);
    limpiar.append(td);
  });
  cuerpo.append(limpiar);

  tabla.append(cabecera, cuerpo);
  bloque.append(tabla);
  return bloque;
}

/** Diferencia contra la foto inicial. Devuelve también el resumen legible. */
function formEditorChanges() {
  const campos = {};
  const rst = [];
  const resumen = [];

  formEditor.querySelectorAll("[data-campo]").forEach((control) => {
    const campo = control.dataset.campo;
    let valor;
    if (control.dataset.tipo === "set") {
      valor = Array.from(control.querySelectorAll("input:checked")).map((caja) => caja.value).join(",");
    } else {
      valor = String(control.value ?? "").trim();
    }
    const antes = formEditorState.original.campos.get(campo) ?? "";
    if (!formEditorDiffiere(campo, antes, valor)) return;
    campos[campo] = valor;
    resumen.push({
      etiqueta: formEditorState.etiquetas.get(campo) || campo,
      antes: etiquetaDeValor(campo, antes),
      despues: etiquetaDeValor(campo, valor)
    });
  });

  const vistas = new Set();
  formEditor.querySelectorAll("input[type=radio][data-seccion]").forEach((radio) => {
    if (!radio.checked) return;
    const clave = radio.dataset.seccion + "|" + radio.dataset.fase;
    if (vistas.has(clave)) return;
    vistas.add(clave);
    const antes = formEditorState.original.rst.get(clave) ?? "";
    if (radio.value === antes) return;
    rst.push({ seccion: radio.dataset.seccion, fase: radio.dataset.fase, atributo: radio.value });
    resumen.push({
      etiqueta: (formEditorState.etiquetas.get("seccion:" + radio.dataset.seccion) || radio.dataset.seccion)
        + " · fase " + radio.dataset.fase,
      antes: antes ? (formEditorState.etiquetas.get("rst:" + antes) || antes) : SIN_MARCA,
      despues: radio.value ? (formEditorState.etiquetas.get("rst:" + radio.value) || radio.value) : SIN_MARCA
    });
  });

  return { campos, rst, resumen };
}

function formEditorSyncCounter() {
  const { resumen } = formEditorChanges();
  const contador = byId("formContador");
  const guardar = byId("formGuardar");
  contador.textContent = resumen.length === 0
    ? "Sin cambios"
    : resumen.length === 1 ? "1 campo modificado" : resumen.length + " campos modificados";
  contador.classList.toggle("is-dirty", resumen.length > 0);
  guardar.disabled = resumen.length === 0;
}

function formEditorRender(data) {
  const contenedor = byId("formCampos");
  contenedor.replaceChildren();
  formEditorState.original.campos.clear();
  formEditorState.original.rst.clear();
  formEditorState.etiquetas.clear();
  formEditorState.opciones.clear();
  formEditorState.tipos.clear();
  formEditorState.origen = data.origen || { campos: "base", rst: "base" };
  const aviso = formEditorOrigenAviso();
  if (aviso) contenedor.append(aviso);

  data.campos.forEach((grupo) => {
    const controles = grupo.campos.map((campo) => {
      formEditorState.original.campos.set(campo.campo, campo.valor);
      formEditorState.etiquetas.set(campo.campo, campo.etiqueta);
      formEditorState.tipos.set(campo.campo, campo.tipo);
      if (campo.opciones.length > 0) {
        formEditorState.opciones.set(campo.campo, new Map(campo.opciones.map((o) => [o.valor, o.etiqueta])));
      }
      return formEditorControl(campo);
    });
    const vacios = grupo.campos.filter((campo) => !campo.relleno).length;
    contenedor.append(formSection(
      grupo.titulo,
      vacios === 0 ? "Todos los campos respondidos." : vacios + " sin responder de " + grupo.campos.length + ".",
      controles
    ));
  });

  const rstWrap = document.createElement("div");
  rstWrap.className = "admin-rst-grid";
  data.rst.forEach((seccion) => {
    formEditorState.etiquetas.set("seccion:" + seccion.seccion, seccion.titulo);
    seccion.filas.forEach((fila) => formEditorState.etiquetas.set("rst:" + fila.atributo, fila.etiqueta));
    ["R", "S", "T"].forEach((fase) => {
      formEditorState.original.rst.set(seccion.seccion + "|" + fase, seccion.seleccion[fase] || "");
    });
    rstWrap.append(formEditorRstSection(seccion));
  });
  contenedor.append(formSection(
    "Secciones R/S/T",
    "Cada fase admite un solo atributo por tabla: marcar uno reemplaza al anterior.",
    [rstWrap]
  ));

  formEditorSyncCounter();
}

async function openFormEditor(row) {
  if (formDialog.open && !cerrarModal(formDialog)) return;
  resetModalState(formDialog);
  limpiarMensaje(byId("formMensaje"));
  byId("formCampos").replaceChildren();
  formEditorState.posteId = row.id;
  formEditorState.titulo = row.codigo || ("Estructura #" + row.id);
  byId("formTitulo").textContent = "Formulario de " + formEditorState.titulo;
  byId("formDescripcion").textContent = "Se guardarán únicamente los campos que modifique.";
  byId("formGuardar").disabled = true;
  byId("formContador").textContent = "Cargando…";
  formEditor.dataset.busy = "false";
  abrirModal(formDialog);

  try {
    const respuesta = await leerRespuesta(await fetchConToken(
      "admin/formulario_poste.php?poste_id=" + encodeURIComponent(row.id)
    ));
    formEditorRender(respuesta.data);
  } catch (error) {
    mostrarMensaje(byId("formMensaje"), errorMessage(error));
    byId("formContador").textContent = "No se pudo cargar";
    showToast("No se pudo cargar el formulario.", "error");
  }
}

formEditor.addEventListener("input", formEditorSyncCounter);
formEditor.addEventListener("change", formEditorSyncCounter);

formEditor.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (formEditor.dataset.busy === "true") return;
  const { campos, rst, resumen } = formEditorChanges();
  if (resumen.length === 0) {
    mostrarMensaje(byId("formMensaje"), "No hay cambios que guardar.");
    return;
  }

  const aceptado = await askConfirm({
    title: "Confirmar cambios en el formulario",
    text: "Está modificando " + resumen.length + (resumen.length === 1 ? " campo" : " campos")
      + " de la inspección de " + formEditorState.titulo + "."
      + (formEditorState.origen.campos === "respaldo" || formEditorState.origen.rst === "respaldo"
        ? " Además se consolidará en la base el formulario que hoy vive en el respaldo del teléfono."
        : " El resto del formulario no se toca."),
    details: resumen,
    tone: "safe",
    notice: false,
    acceptLabel: "Guardar cambios",
    loadingLabel: "Guardando…",
    action: async () => {
      const respuesta = await leerRespuesta(await fetchConToken("admin/formulario_poste.php", {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ poste_id: formEditorState.posteId, campos, rst })
      }));
      formEditorRender(respuesta.data);
    }
  });

  if (aceptado) {
    showToast("Formulario actualizado.", "success");
    limpiarMensaje(byId("formMensaje"));
  }
});

initializeAdmin();
