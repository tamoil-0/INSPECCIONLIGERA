"use strict";

const fs = require("fs");
const vm = require("vm");
const path = require("path");

const source = fs.readFileSync(path.join(__dirname, "..", "js", "app.js"), "utf8");
const start = source.indexOf("const SESSION_KEYS");
const end = source.indexOf("function apiUrl", start);
if (start < 0 || end < 0) throw new Error("No se encontro el bloque de sesion.");

const values = new Map();
const sessionStorage = {
  setItem: (key, value) => values.set(String(key), String(value)),
  getItem: (key) => values.has(String(key)) ? values.get(String(key)) : null,
  removeItem: (key) => values.delete(String(key)),
};
const context = { sessionStorage, window: { location: { replace: () => {} } }, console };
vm.createContext(context);
vm.runInContext(source.slice(start, end), context);

vm.runInContext('guardarToken("jwt-dev", {rol:"administrador"}, 60)', context);
if (vm.runInContext("obtenerToken()", context) !== "jwt-dev") throw new Error("No se guardo la sesion.");
vm.runInContext("cerrarSesion(false)", context);
if (["token", "usuario", "token_expira_en"].some((key) => sessionStorage.getItem(key) !== null)) {
  throw new Error("Logout no limpio sessionStorage.");
}

sessionStorage.setItem("token", "jwt-expirado");
sessionStorage.setItem("usuario", "{}");
sessionStorage.setItem("token_expira_en", String(Date.now() - 1));
if (vm.runInContext("obtenerToken()", context) !== null) throw new Error("La sesion expirada sigue activa.");
if (["token", "usuario", "token_expira_en"].some((key) => sessionStorage.getItem(key) !== null)) {
  throw new Error("La expiracion no limpio sessionStorage.");
}

console.log("OK - logout local y expiracion de sesion");
