"use strict";

const webMarker = "/web/";
const markerIndex = window.location.pathname.toLowerCase().indexOf(webMarker);
const projectPath = markerIndex >= 0 ? window.location.pathname.slice(0, markerIndex) : "";
const API_BASE = `${window.location.origin}${projectPath}/api`;

function guardarToken(token, usuario) {
  sessionStorage.setItem("token", token);
  sessionStorage.setItem("usuario", JSON.stringify(usuario));
}

function obtenerToken() {
  return sessionStorage.getItem("token");
}

function obtenerUsuario() {
  try {
    return JSON.parse(sessionStorage.getItem("usuario") || "null");
  } catch {
    return null;
  }
}

function cerrarSesion() {
  sessionStorage.clear();
  window.location.replace("index.html");
}

function verificarSesion() {
  if (!obtenerToken()) {
    window.location.replace("index.html");
    return false;
  }
  return true;
}

function apiUrl(endpoint) {
  return `${API_BASE}/${String(endpoint).replace(/^\/+/, "")}`;
}

async function fetchConToken(endpoint, opciones = {}) {
  const token = obtenerToken();
  if (!token) {
    cerrarSesion();
    throw new Error("No hay una sesión activa.");
  }

  const headers = new Headers(opciones.headers || {});
  headers.set("Authorization", `Bearer ${token}`);
  const response = await fetch(apiUrl(endpoint), { ...opciones, headers });
  if (response.status === 401) {
    cerrarSesion();
    throw new Error("La sesión expiró.");
  }
  return response;
}

async function leerRespuesta(response) {
  const type = response.headers.get("content-type") || "";
  const data = type.includes("application/json") ? await response.json() : null;
  if (!response.ok) {
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
  const response = await fetchConToken(endpoint);
  if (!response.ok) {
    await leerRespuesta(response);
  }
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = nombreDescarga(response, fallback);
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

async function abrirPdfConToken(endpoint) {
  const target = window.open("about:blank", "_blank");
  try {
    const response = await fetchConToken(endpoint);
    if (!response.ok) {
      await leerRespuesta(response);
    }
    const url = URL.createObjectURL(await response.blob());
    if (target) {
      target.location.href = url;
    } else {
      window.location.href = url;
    }
    setTimeout(() => URL.revokeObjectURL(url), 120000);
  } catch (error) {
    target?.close();
    throw error;
  }
}

function celda(texto) {
  const td = document.createElement("td");
  td.textContent = texto ?? "";
  return td;
}

function filaVacia(tbody, columnas, mensaje) {
  tbody.replaceChildren();
  const row = document.createElement("tr");
  const td = celda(mensaje);
  td.colSpan = columnas;
  td.className = "estado-vacio";
  row.appendChild(td);
  tbody.appendChild(row);
}

function mostrarMensaje(elemento, mensaje, tipo = "error") {
  elemento.textContent = mensaje;
  elemento.className = `mensaje ${tipo}`;
}
