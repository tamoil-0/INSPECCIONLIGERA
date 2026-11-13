# CLAUDE.md — ECOING · Inspección Ligera

Sistema de inspección ligera de líneas de transmisión: API PHP (`api/`), web administrativa estática (`web/`),
app móvil Flutter (rama `app-movil`). Producción en un NAS QNAP con Docker (`Dockerfile`, `docker-compose.yml`).

- Local: XAMPP 8.2 (`C:\xampp82`), web en `http://localhost/INSPEECIONLIGERAECOING/web/`, salud en `api/health.php`.
- Pruebas PHP: `api/tests/*.php` (se ejecutan con `php`). Pruebas JS: `web/tests/*.js` (con `node`).
- El repositorio es **público**: nunca versionar dumps con datos, firmas, `.env` ni paquetes de despliegue.
- PHP mínimo: 8.2.35 (parche PHP2Shell). El `Dockerfile` falla si la imagen trae una versión anterior.

## Diseño

- Fuente de verdad visual: [`DESIGN.md`](DESIGN.md) (tokens, escala, componentes e inconsistencias pendientes).
- Antes de cualquier cambio de UI, seguir la skill `.claude/skills/design-system`.
- Para verificar, seguir la skill `.claude/skills/ui-review` (capturas 375/768/1440 en `.ui-review/`).
- Skills externas disponibles: `frontend-design`, `web-design-guidelines`, `ui-ux-pro-max` (en `.claude/skills/`).
- Solo se cambia presentación: estilos, layout, textos, estados y accesibilidad. Nunca endpoints, datos, auth ni base de datos.
