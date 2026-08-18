"use strict";

const webMarker = "/web/";
const markerIndex = window.location.pathname.toLowerCase().indexOf(webMarker);
const projectPath = markerIndex >= 0 ? window.location.pathname.slice(0, markerIndex) : "";
const API_BASE = `${window.location.origin}${projectPath}/api`;

const ETIQUETAS = {
  id: "ID", poste_id: "ID del poste", proyecto_id: "ID del proyecto", codigo: "Código",
  linea: "Línea", estructura: "Estructura", ubicaciones: "Tramo / ubicación",
  ubicacion: "Tipo de ubicación", utm_x: "UTM Este", utm_y: "UTM Norte",
  utm_este: "UTM Este", utm_norte: "UTM Norte", zona: "Zona UTM",
  fecha_inspeccion: "Fecha de inspección", fecha_subida: "Última actualización",
  fecha_creacion: "Fecha de creación", creado_en: "Registrado en",
  formulario_subido: "Formulario", imagenes_subidas: "Imágenes completas",
  sincronizado: "Sincronización", obstaculos_faja: "Obstáculos en la faja",
  estado_cuencas: "Estado de cuencas", marcado_arboles: "Árboles marcados",
  criticidad_tala: "Criticidad de tala", criticidad_contacto: "Criticidad de contacto",
  notificacion_propietario: "Notificación al propietario", tipo_torre: "Tipo de torre",
  acceso_torre: "Acceso a la torre", estado_acceso: "Estado del acceso",
  estado_placas_torre: "Placas de torre", estado_placas_linea: "Placas de línea",
  estado_placas_fases: "Placas de fases", peligro_cerco: "Señal de peligro en cerco",
  peligro_torre: "Señal de peligro en torre", puesta_tierra: "Puesta a tierra",
  retenida: "Retenida", estado_base: "Estado de base", limpiar_base: "Requiere limpieza de base",
  crucetas_mensuales: "Crucetas / ménsulas", perfiles_angulares: "Perfiles angulares",
  malla_antiescalamiento: "Malla antiescalamiento", oxidos_base: "Óxido en la base",
  cadena_aisladores: "Cadena de aisladores", tipo_aislador: "Tipo de aislador",
  conductor_bajada_pat: "Conductor de bajada PAT", conductor_guarda: "Conductor de guarda",
  comentarios: "Comentarios", distancia_acceso: "Distancia de acceso", cantidad_pat: "Cantidad de PAT",
  distancia_poste_anterior: "Distancia al poste anterior", distancia_vertical: "Distancia vertical",
  distancia_horizontal: "Distancia horizontal"
};

const SESSION_KEYS = ["token", "usuario", "token_expira_en"];

function guardarToken(token, usuario, ttlSeconds = 0) {
  sessionStorage.setItem("token", token);
  sessionStorage.setItem("usuario", JSON.stringify(usuario));
  const ttl = Number(ttlSeconds);
  if (Number.isFinite(ttl) && ttl > 0) {
    sessionStorage.setItem("token_expira_en", String(Date.now() + (ttl * 1000)));
  } else {
    sessionStorage.removeItem("token_expira_en");
  }
}

function obtenerToken() {
  const token = sessionStorage.getItem("token");
  const expiresAt = Number(sessionStorage.getItem("token_expira_en") || 0);
  if (token && expiresAt > 0 && Date.now() >= expiresAt) {
    limpiarSesion();
    return null;
  }
  return token;
}

function obtenerUsuario() {
  try { return JSON.parse(sessionStorage.getItem("usuario") || "null"); }
  catch { return null; }
}

function limpiarSesion() {
  SESSION_KEYS.forEach((key) => sessionStorage.removeItem(key));
}

function cerrarSesion(redirigir = true) {
  limpiarSesion();
  if (redirigir) window.location.replace("index.html");
}

function verificarSesion() {
  if (!obtenerToken()) {
    window.location.replace("index.html");
    return false;
  }
  return true;
}

function apiUrl(endpoint) { return `${API_BASE}/${String(endpoint).replace(/^\/+/, "")}`; }

async function fetchConLimite(url, opciones = {}, timeoutMs = 30000) {
  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...opciones, signal: opciones.signal || controller.signal });
  } catch (error) {
    if (error?.name === "AbortError") throw new Error("El servidor tardó demasiado en responder.");
    throw error;
  } finally {
    window.clearTimeout(timer);
  }
}

async function fetchConToken(endpoint, opciones = {}) {
  const token = obtenerToken();
  if (!token) {
    cerrarSesion();
    throw new Error("No hay una sesión activa.");
  }
  const { timeoutMs = 30000, ...fetchOptions } = opciones;
  const headers = new Headers(fetchOptions.headers || {});
  headers.set("Authorization", `Bearer ${token}`);
  headers.set("Accept", headers.get("Accept") || "application/json");
  const request = () => fetchConLimite(apiUrl(endpoint), { ...fetchOptions, headers }, timeoutMs);
  let response = await request();

  // Un 401 aislado no debe expulsar al usuario. Se confirma una vez con el
  // mismo token antes de invalidar la sesión; los endpoints protegidos no
  // ejecutan la operación cuando la autenticación falla.
  if (response.status === 401 && obtenerToken() === token) {
    response = await request();
  }
  if (response.status === 401) {
    cerrarSesion();
    throw new Error("La sesión expiró. Ingrese nuevamente.");
  }
  return response;
}

async function leerRespuesta(response) {
  const type = response.headers.get("content-type") || "";
  const data = type.includes("application/json") ? await response.json() : null;
  if (!response.ok || data?.success === false) {
    throw new Error(data?.error || `La solicitud falló (${response.status}).`);
  }
  return data;
}

function nombreDescarga(response, fallback) {
  const disposition = response.headers.get("content-disposition") || "";
  const match = disposition.match(/filename="?([^";]+)"?/i);
  return match ? match[1] : fallback;
}

async function descargarConToken(endpoint, fallback) {
  const response = await fetchConToken(endpoint, { timeoutMs: 1500000 });
  if (!response.ok) await leerRespuesta(response);
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = nombreDescarga(response, fallback);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function abrirPdfConToken(endpoint) {
  const target = window.open("about:blank", "_blank");
  try {
    const response = await fetchConToken(endpoint, { timeoutMs: 180000 });
    if (!response.ok) await leerRespuesta(response);
    const url = URL.createObjectURL(await response.blob());
    if (target) target.location.href = url;
    else window.location.href = url;
    window.setTimeout(() => URL.revokeObjectURL(url), 120000);
  } catch (error) {
    target?.close();
    throw error;
  }
}

function celda(texto, etiqueta = "") {
  const td = document.createElement("td");
  td.textContent = texto ?? "—";
  if (etiqueta) td.dataset.label = etiqueta;
  return td;
}

function celdaCompuesta(titulo, subtitulo, etiqueta = "") {
  const td = celda("", etiqueta);
  const strong = document.createElement("span");
  strong.className = "cell-title";
  strong.textContent = titulo ?? "—";
  td.appendChild(strong);
  if (subtitulo) {
    const small = document.createElement("span");
    small.className = "cell-subtitle";
    small.textContent = subtitulo;
    td.appendChild(small);
  }
  return td;
}

function filaVacia(tbody, columnas, mensaje) {
  tbody.replaceChildren();
  const row = document.createElement("tr");
  const td = celda("");
  td.colSpan = columnas;
  td.className = "estado-vacio";
  const content = document.createElement("div");
  content.className = "empty-content";
  const icon = document.createElement("span");
  icon.className = "empty-icon";
  icon.textContent = "—";
  const text = document.createElement("span");
  text.textContent = mensaje;
  content.append(icon, text);
  td.appendChild(content);
  row.appendChild(td);
  tbody.appendChild(row);
}

function filaCargando(tbody, columnas, mensaje = "Cargando información…") {
  tbody.replaceChildren();
  const row = document.createElement("tr");
  const td = celda("");
  td.colSpan = columnas;
  td.className = "estado-vacio loading-row";
  const content = document.createElement("div");
  content.className = "empty-content";
  const spinner = document.createElement("span");
  spinner.className = "spinner";
  const text = document.createElement("span");
  text.textContent = mensaje;
  content.append(spinner, text);
  td.appendChild(content);
  row.appendChild(td);
  tbody.appendChild(row);
}

function mostrarMensaje(elemento, mensaje, tipo = "error") {
  if (!elemento) return;
  elemento.textContent = mensaje || "";
  elemento.className = `mensaje ${tipo}`;
}

function limpiarMensaje(elemento) {
  if (!elemento) return;
  elemento.textContent = "";
  elemento.className = "mensaje";
}

function establecerCargando(button, cargando, texto = "Procesando…") {
  if (!button) return;
  if (cargando) {
    button.dataset.textoOriginal = button.textContent;
    button.disabled = true;
    button.replaceChildren();
    const spinner = document.createElement("span");
    spinner.className = "spinner";
    button.append(spinner, document.createTextNode(texto));
  } else {
    button.disabled = false;
    button.textContent = button.dataset.textoOriginal || button.textContent;
    delete button.dataset.textoOriginal;
  }
}

function humanizarClave(key) {
  return ETIQUETAS[key] || String(key).replaceAll("_", " ").replace(/^./, (char) => char.toUpperCase());
}

function humanizarValor(value) {
  if (value === null || value === undefined || value === "") return "—";
  if (value === true || value === 1 || value === "1") return "Sí";
  if (value === false || value === 0 || value === "0") return "No";
  if (typeof value === "string" && /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(value)) return formatearFecha(value);
  return String(value).replaceAll("_", " ").replace(/\b\w/g, (char) => char.toUpperCase());
}

function formatearFecha(value, soloFecha = false) {
  if (!value) return "—";
  const normalized = String(value).replace(" ", "T");
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) return String(value);
  const options = soloFecha
    ? { day: "2-digit", month: "short", year: "numeric" }
    : { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" };
  return new Intl.DateTimeFormat("es-PE", options).format(date);
}

function formatearCoordenada(value) {
  if (value === null || value === undefined || value === "") return "Sin dato";
  const number = Number(value);
  if (!Number.isFinite(number)) return String(value);
  return number.toLocaleString("es-PE", {
    useGrouping: false,
    minimumFractionDigits: 2,
    maximumFractionDigits: 5
  });
}

function crearInsignia(texto, tono = "neutral") {
  const badge = document.createElement("span");
  badge.className = `status-badge status-${tono}`;
  badge.textContent = texto;
  return badge;
}

function insigniaEstado(estado) {
  const value = String(estado || "").toLowerCase();
  const tones = { activo: "success", completado: "neutral", cancelado: "danger" };
  return crearInsignia(humanizarValor(value || "sin estado"), tones[value] || "neutral");
}

function insigniaBooleano(valor, positivo = "Completo", negativo = "Pendiente") {
  return crearInsignia(valor ? positivo : negativo, valor ? "success" : "warning");
}

function crearProgreso(valor, total) {
  const safeTotal = Math.max(0, Number(total) || 0);
  const safeValue = Math.max(0, Number(valor) || 0);
  const percent = safeTotal ? Math.min(100, Math.round((safeValue / safeTotal) * 100)) : 0;
  const wrap = document.createElement("div");
  wrap.className = "progress-wrap";
  const meta = document.createElement("div");
  meta.className = "progress-meta";
  const count = document.createElement("span");
  count.textContent = `${safeValue} de ${safeTotal}`;
  const percentage = document.createElement("strong");
  percentage.textContent = `${percent}%`;
  meta.append(count, percentage);
  const track = document.createElement("div");
  track.className = "progress-track";
  const bar = document.createElement("span");
  bar.className = "progress-bar";
  bar.style.width = `${percent}%`;
  track.appendChild(bar);
  wrap.append(meta, track);
  return wrap;
}

function inicialesUsuario(usuario) {
  const source = usuario?.nombre_completo || usuario?.nombre_usuario || "ECOING";
  return source.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join("").toUpperCase();
}

function pintarUsuario() {
  const usuario = obtenerUsuario();
  document.querySelectorAll("[data-user-name]").forEach((node) => {
    node.textContent = usuario?.nombre_completo || usuario?.nombre_usuario || "Usuario";
  });
  document.querySelectorAll("[data-user-role]").forEach((node) => {
    node.textContent = humanizarValor(usuario?.rol || "sesión activa");
  });
  document.querySelectorAll("[data-user-initials]").forEach((node) => {
    node.textContent = inicialesUsuario(usuario);
  });
}

async function comprobarApi() {
  const targets = [...document.querySelectorAll("[data-api-status]")];
  if (!targets.length) return false;
  targets.forEach((target) => {
    target.dataset.state = "checking";
    const label = target.querySelector("span");
    if (label) label.textContent = "Verificando API";
  });
  try {
    const response = await fetchConLimite(apiUrl("info.php"), { headers: { Accept: "application/json" } }, 5000);
    const online = response.ok;
    targets.forEach((target) => {
      target.dataset.state = online ? "online" : "offline";
      const label = target.querySelector("span");
      if (label) label.textContent = online ? "API disponible" : "API no disponible";
      target.title = online ? `Conectado a ${API_BASE}` : "No se pudo contactar al servidor";
    });
    return online;
  } catch {
    targets.forEach((target) => {
      target.dataset.state = "offline";
      const label = target.querySelector("span");
      if (label) label.textContent = "Sin conexión";
      target.title = "No se pudo contactar al servidor";
    });
    return false;
  }
}

function inicializarPasswordToggles() {
  document.querySelectorAll("[data-password-toggle]").forEach((button) => {
    button.addEventListener("click", () => {
      const input = document.getElementById(button.dataset.passwordToggle);
      if (!input) return;
      const visible = input.type === "text";
      input.type = visible ? "password" : "text";
      button.textContent = visible ? "Mostrar" : "Ocultar";
      button.setAttribute("aria-pressed", String(!visible));
    });
  });
}

function inicializarInterfaz() {
  pintarUsuario();
  inicializarPasswordToggles();
  document.querySelectorAll("[data-current-year]").forEach((node) => {
    node.textContent = String(new Date().getFullYear());
  });
  comprobarApi();
  window.addEventListener("online", comprobarApi);
  window.addEventListener("offline", comprobarApi);
  if (document.querySelector("[data-api-status]")) window.setInterval(comprobarApi, 60000);
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", inicializarInterfaz, { once: true });
} else {
  inicializarInterfaz();
}
