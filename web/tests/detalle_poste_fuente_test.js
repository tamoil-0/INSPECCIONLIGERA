"use strict";

const fs = require("fs");
const path = require("path");

const file = path.join(__dirname, "..", "detalle_poste.html");
const html = fs.readFileSync(file, "utf8");
const failures = [];
let checks = 0;

function check(condition, message) {
  checks += 1;
  if (!condition) failures.push(message);
}

const inlineScripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)];
try {
  new Function(inlineScripts.at(-1)?.[1] || "");
  check(true, "El script inline compila.");
} catch (error) {
  check(false, `El script inline no compila: ${error.message}`);
}

check(html.includes("Fuente de información"), "El selector no se presenta como fuente general.");
check(
  html.includes("NAS muestra la información sincronizada actualmente.")
    && html.includes("Backup muestra la información encontrada en el respaldo móvil."),
  "Falta la explicación breve de ambas fuentes."
);
check(
  html.includes("avance_respaldo.php?proyecto_id=")
    && html.includes("&linea="),
  "La vista no pide el avance completo de la línea en una sola respuesta."
);
check(
  html.includes("response.data.estructuras.map")
    && html.includes("respaldoPorPoste"),
  "Los estados del Backup no se indexan por estructura en el cliente."
);
check(
  html.includes("respaldoDe(post)?.estado")
    && html.includes('estado === "sincronizada"')
    && html.includes('estado === "en_proceso"'),
  "Tarjetas y filtro no derivan su estado de Backup."
);
check(
  html.includes("respaldo?.formulario")
    && html.includes("respaldo?.fotos?.archivos")
    && html.includes("respaldo?.fotos?.sin_mapear"),
  "Las columnas Formulario/Fotos no consumen el contrato de Backup."
);
check(
  html.includes("No se muestran datos NAS en su lugar."),
  "No está protegida explícitamente la ausencia de fallback Backup -> NAS."
);
check(
  html.includes("fuenteFotoComoQuery(selectorFuenteLista.value)")
    && html.includes("fuenteFotoComoQuery(fuente)"),
  "PDF individual y ZIP no usan el mismo selector."
);
check(
  html.includes('selectorFuenteLista.addEventListener("change"')
    && html.includes("aplicarFuenteSeleccionada();"),
  "Cambiar el selector no vuelve a calcular la vista."
);

if (failures.length) {
  console.error(`FALLOS (${checks} comprobaciones):\n - ${failures.join("\n - ")}`);
  process.exit(1);
}
console.log(`OK: ${checks} comprobaciones del selector general NAS/Backup.`);
