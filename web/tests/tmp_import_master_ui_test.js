"use strict";

const { execFileSync } = require("node:child_process");
const path = require("node:path");

const cwd = path.resolve(__dirname, "../..");
const php = (code, args = []) => execFileSync("php", ["-r", code, ...args], { cwd, encoding: "utf8" }).trim();
const setup = JSON.parse(php(`
require "api/config/database.php"; require "api/config/jwt.php";
$conn->prepare("INSERT INTO proyectos(nombre_proyecto,contratista,ubicacion,estado) VALUES('DEV UI MASTER','LOCAL','PROJECT','activo')")->execute();
$pid=(int)$conn->lastInsertId();
$conn->prepare("INSERT INTO lineas(proyecto_id,nombre,ubicacion,activa) VALUES(?,'L-001','LINE',1)")->execute([$pid]);
$conn->prepare("INSERT INTO postes(codigo,linea,estructura,proyecto_id,utm_x,utm_y,zona,ubicaciones,activo) VALUES('L-001-01','L-001','01',?,1,2,'19S','OLD',1)")->execute([$pid]);
$active=(int)$conn->lastInsertId(); $conn->prepare("INSERT INTO poste_datos(poste_id) VALUES(?)")->execute([$active]);
$conn->prepare("INSERT INTO postes(codigo,linea,estructura,proyecto_id,utm_x,utm_y,zona,ubicaciones,activo,desactivado_en) VALUES('L-001-02','L-001','02',?,3,4,'19S','OLD',0,'2026-08-01')")->execute([$pid]);
$archived=(int)$conn->lastInsertId(); $conn->prepare("INSERT INTO poste_datos(poste_id) VALUES(?)")->execute([$archived]);
echo json_encode(["project"=>$pid,"archived"=>$archived,"token"=>generateJWT(1,"endpoint_ui_test","administrador")]);
`));

let socket;
let sequence = 0;
const pending = new Map();
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function command(method, params = {}) {
  const id = ++sequence;
  socket.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}

async function evaluate(expression) {
  const response = await command("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
  if (response.exceptionDetails) throw new Error(response.exceptionDetails.text || "Runtime error");
  return response.result.value;
}

async function waitFor(expression, timeout = 20000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    if (await evaluate(expression)) return;
    await delay(120);
  }
  throw new Error("Timeout: " + expression);
}

async function main() {
  let target;
  for (let attempt = 0; attempt < 40; attempt++) {
    try {
      target = await fetch("http://127.0.0.1:9333/json/new?about:blank", { method: "PUT" }).then((response) => response.json());
      break;
    } catch {
      await delay(150);
    }
  }
  if (!target) throw new Error("Chrome CDP no disponible.");
  socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    socket.onopen = resolve;
    socket.onerror = reject;
    socket.onmessage = (event) => {
      const message = JSON.parse(event.data);
      if (!message.id || !pending.has(message.id)) return;
      const item = pending.get(message.id);
      pending.delete(message.id);
      if (message.error) item.reject(new Error(message.error.message));
      else item.resolve(message.result || {});
    };
  });
  await command("Runtime.enable");
  await command("Page.enable");
  await command("DOM.enable");
  const user = JSON.stringify({ id: 1, nombre_usuario: "endpoint_ui_test", rol: "administrador" });
  await command("Page.addScriptToEvaluateOnNewDocument", {
    source: `sessionStorage.setItem("token",${JSON.stringify(setup.token)});sessionStorage.setItem("usuario",${JSON.stringify(user)});sessionStorage.setItem("token_expira_en",String(Date.now()+3600000));`
  });
  await command("Page.navigate", { url: "http://127.0.0.1/INSPEECIONLIGERAECOING/web/admin.html" });
  await waitFor(`document.readyState === "complete" && document.getElementById("adminTableRegion")?.getAttribute("aria-busy") === "false"`);
  await evaluate(`document.querySelector('[data-section="lineas"]').click()`);
  await waitFor(`document.getElementById("adminTableRegion").getAttribute("aria-busy") === "false"`);
  await evaluate(`(()=>{const s=document.getElementById("adminProyecto");s.value=${JSON.stringify(String(setup.project))};s.dispatchEvent(new Event("change",{bubbles:true}));return true;})()`);
  await waitFor(`document.getElementById("adminTableRegion").getAttribute("aria-busy") === "false"`);
  await evaluate(`document.getElementById("importarExcel").click()`);
  await waitFor(`document.getElementById("importDialog").open === true`);
  const documentNode = await command("DOM.getDocument", { depth: -1 });
  const inputNode = await command("DOM.querySelector", { nodeId: documentNode.root.nodeId, selector: "#importArchivo" });
  await command("DOM.setFileInputFiles", {
    nodeId: inputNode.nodeId,
    files: [path.join(cwd, "data", "Santuario-Yura.xlsx")]
  });
  await waitFor(`document.querySelectorAll("#importPreviewBody > tr").length === 88 && document.getElementById("importProgress").hidden`, 30000);
  const before = await evaluate(`({
    dialog:document.getElementById("importDialog").open,
    project:document.getElementById("importProyecto").textContent,
    summary:document.getElementById("importResumen").textContent,
    firstStatus:document.querySelector("#importPreviewBody tr .admin-import-status").firstChild.textContent,
    diffs:document.querySelectorAll("#importPreviewBody tr:first-child .admin-import-diff-table tbody tr").length,
    archivedChecks:document.querySelectorAll(".import-reactivate").length,
    confirmDisabled:document.getElementById("importConfirmar").disabled
  })`);
  await evaluate(`document.querySelector(".import-reactivate").click()`);
  const authorized = await evaluate(`({
    confirmation:document.getElementById("importConfirmacionTexto").textContent,
    confirmDisabled:document.getElementById("importConfirmar").disabled,
    button:document.getElementById("importConfirmar").textContent
  })`);
  await evaluate(`document.getElementById("importConfirmar").click()`);
  await waitFor(`document.getElementById("importDialog").open === false && document.getElementById("adminTableRegion").getAttribute("aria-busy") === "false"`, 30000);
  const after = await evaluate(`({
    title:document.getElementById("adminTitulo").textContent,
    count:document.getElementById("adminConteo").textContent,
    pagination:document.getElementById("paginacionResumen").textContent,
    toast:document.getElementById("toastRegion").textContent
  })`);
  console.log(JSON.stringify({ before, authorized, after }));
}

main().finally(async () => {
  try {
    php(`require "api/config/database.php"; $id=(int)$argv[1]; $conn->prepare("DELETE FROM admin_auditoria WHERE proyecto_id=? AND usuario_nombre='endpoint_ui_test'")->execute([$id]); $conn->prepare("DELETE FROM lineas WHERE proyecto_id=?")->execute([$id]); $conn->prepare("DELETE FROM proyectos WHERE id=?")->execute([$id]);`, [String(setup.project)]);
  } finally {
    if (socket?.readyState === WebSocket.OPEN) {
      try { await command("Browser.close"); } catch {}
    }
  }
}).catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
