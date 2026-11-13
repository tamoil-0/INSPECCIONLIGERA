---
name: ui-review
description: Proceso de revisión visual de las páginas de ECOING con Playwright (capturas 375/768/1440, consola, teclado, checklist y hallazgos por severidad). Usar después de cualquier cambio de UI y antes de hacer commit.
---

# Revisión visual (ui-review)

## Preparación

- Entorno local: XAMPP (Apache + MySQL) con la web en `http://localhost/INSPEECIONLIGERAECOING/web/`.
  Comprobar `api/health.php` → `"status":"ok"`.
- Sesión para páginas privadas: generar un JWT con `generateJWT()` de `api/config/jwt.php` (usuario administrador local)
  e inyectarlo en `sessionStorage` (`token`, `usuario`, `token_expira_en`) con `context.addInitScript`. No crear usuarios ni tocar datos.
- Usar la librería `playwright` desde un script de Node (no depender del MCP).

## Páginas

| Archivo de captura | URL |
|---|---|
| 01-login | `index.html` |
| 02-setup | `setup.html` |
| 03-proyectos | `proyectos.html` |
| 04-detalle-proyecto | `detalle_proyecto.html?id=<proyecto>` |
| 05-lineas | `detalle_linea.html?id=<proyecto>` |
| 06-estructuras | `detalle_poste.html?proyecto_id=<proyecto>&linea=<linea>` |
| 07-formulario-poste | `formulario_poste.html?poste_id=<poste>` |
| 08-admin | `admin.html` |
| 09-admin-imagenes | `admin_imagenes_poste.html?poste_id=<poste>` |
| 10-plantillas | `plantillas.html` |

## Pasos

1. **Capturas** de página completa en **375, 768 y 1440 px** → `.ui-review/<antes|despues>/<pagina>-<ancho>.png`.
2. **Consola**: registrar `error`, `warning` y `pageerror`. Cero errores nuevos.
3. **Medición automática** por página y ancho: desborde horizontal (`scrollWidth - clientWidth`), controles < 44 px,
   botones/enlaces sin nombre accesible, imágenes sin `alt`, campos sin label, cantidad de `h1` y saltos de headings.
4. **Teclado**: recorrer con Tab la topbar, la acción principal y un formulario; el foco debe verse siempre y seguir el orden visual.
   `Esc` cierra diálogos y menús.
5. **Checklist visual** (mirando las capturas):
   - Jerarquía: un h1 claro, acción principal evidente, secundarias discretas.
   - Consistencia: mismos botones, radios, sombras y espaciados que el resto (ver `DESIGN.md`).
   - Tokens: ningún hex suelto nuevo en CSS.
   - Contraste AA en textos y estados.
   - Responsive: nada cortado ni desbordado en 375; tablas legibles como tarjetas; diálogos usables.
   - Estados: carga, vacío (con acción), error (con reintentar) y éxito.
   - Movimiento: solo en respuesta a acciones; respeta `prefers-reduced-motion`.
6. **Hallazgos** en una tabla por severidad:

| Severidad | Pantalla | Ancho | Hallazgo | Propuesta |
|---|---|---|---|---|
| Alta | … | … | Bloquea una tarea o incumple AA | … |
| Media | … | … | Confunde o se ve inconsistente | … |
| Baja | … | … | Pulido | … |

Corregir todo lo de severidad **Alta** antes del commit (máximo 3 rondas). Lo demás se anota en `.ui-review/diagnostico.md`.
