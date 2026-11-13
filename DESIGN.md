# DESIGN.md — Sistema de diseño de ECOING · Inspección Ligera

Documenta lo que la web **ya usa** (`web/css/estilos.css` y `web/css/plantillas.css`).
No es una paleta nueva: es la referencia para que cualquier cambio de UI sea coherente.

- Stack del frontend: HTML estático + CSS propio + JavaScript sin framework (`web/js/*.js`).
  Sin build, sin npm. La API es PHP (`api/`).
- Público: personal administrativo y técnico de ECOING (oficina y campo), en español.
- Tono visual: operativo, sobrio, institucional. Azul marino como base, verde ECOING como acento.

## 1. Color

Todos los colores viven como variables en `:root` de `estilos.css`. **No usar hex sueltos.**

| Token | Hex | Uso |
|---|---|---|
| `--navy-950` | `#071827` | Topbar, fondo oscuro, `theme-color` |
| `--navy-900` | `#0b2239` | Títulos (h1–h3), texto fuerte |
| `--navy-800` | `#123553` | Botón por defecto, pestaña activa |
| `--blue-700` | `#155e9a` | Acción primaria, enlaces |
| `--blue-800` | `#0e507f` | Hover de la acción primaria |
| `--blue-600` | `#1976b9` | Bordes de arrastre, acentos |
| `--blue-100` / `--blue-50` | `#dceefa` / `#eff8fd` | Fondos informativos, hover de fila |
| `--green-700` / `--green-600` | `#176b55` / `#238166` | Éxito, marca (logo), progreso |
| `--green-100` | `#d9f2e9` | Fondo de éxito |
| `--amber-700` / `--amber-100` | `#9a5d08` / `#fff0ce` | Advertencia |
| `--amber-500` | `#d99413` | Borde de advertencia en métricas |
| `--amber-900` | `#78350f` | Texto sobre aviso ámbar |
| `--red-700` / `--red-100` | `#a43a3a` / `#fde4e4` | Error, peligro |
| `--red-800` | `#842e2e` | Hover de peligro |
| `--violet-700` / `--violet-100` | `#6b3fa0` / `#ece4f8` | Insignia "recuperado" (diagnóstico) |
| `--slate-950 … --slate-50` | `#101b25 … #f6f8fa` | Texto, bordes y fondos neutros |
| `--slate-400` | `#9aabb7` | Texto deshabilitado, separadores, bordes en hover |
| `--surface-muted` | `#fbfcfd` | Fondo de bloques internos (dl, secciones de formulario) |
| `--white` | `#ffffff` | Superficies |
| `--focus` | `#2e8ed0` | Anillo de foco |

Contraste verificado (AA, texto normal ≥ 4.5:1): `--slate-600` sobre blanco 5.0:1, `--blue-700` sobre blanco 6.4:1,
`--green-700` sobre `--green-100` 5.3:1, `--amber-700` sobre `--amber-100` 4.6:1, `--red-700` sobre `--red-100` 5.2:1.
`--slate-500` (3.6:1) **solo** para texto ≥ 18 px o información secundaria no esencial.

Excepción: `web/css/plantillas.css` usa negro/grises literales en la vista previa del PDF (lienzo del editor),
porque representan la impresión real, no la interfaz.

## 2. Tipografía

- Familia: `Inter, "Segoe UI", Roboto, Helvetica, Arial, sans-serif` (sin webfont: usa la del sistema, sin saltos de layout).
- Monoespaciada: `.text-mono` (códigos de estructura, rutas).
- Base: `15px / 1.55` en `body`. Inputs heredan la fuente.

Escala (usar estos valores, no inventar intermedios):

| Rol | Tamaño | Peso |
|---|---|---|
| h1 | `clamp(1.9rem, 3vw, 2.65rem)` | 780 |
| h2 | `clamp(1.3rem, 2vw, 1.62rem)` | 700 |
| h3 | `1rem` | 700 |
| Cuerpo | `.87rem – 1rem` | 400–620 |
| Etiqueta de campo | `.86rem` | 720 |
| Texto de apoyo / small | `.78rem` | 400–650 |
| Encabezado de tabla / eyebrow | `.72rem`, mayúsculas, tracking `.045em–.14em` | 790–800 |
| Mínimo absoluto | `.66rem` (solo insignias) | 800 |

## 3. Espaciado

Base de 4 px. Valores habituales: `6, 8, 9, 12, 14, 16, 18, 22, 26, 30 px`.
- Padding de superficie: `22px` escritorio, `17px` móvil.
- Separación entre bloques de página: `22px`. Gap de grillas de formulario: `14–18px`.
- Ancho máximo de contenido: `--content: 1280px`, con gutter de `20px` (escritorio) y `14px` (≤ 760 px).

## 4. Radios

| Token | Valor | Uso |
|---|---|---|
| `--radius-xs` | `6px` | Chips internos, celdas pequeñas |
| `--radius-sm` | `8px` | Inputs, botones, mensajes |
| `--radius-md` | `12px` | Tarjetas de métrica, menús, pestañas |
| `--radius-lg` | `18px` | Superficies, tablas, paneles |
| `--radius-xl` | `26px` | Caja de acceso (login) |
| `999px` | — | Insignias, píldoras, barras de progreso |

## 5. Sombras

| Token | Uso |
|---|---|
| `--shadow-xs` | Superficies en reposo |
| `--shadow-sm` | Hover de tarjetas |
| `--shadow-md` | Menús contextuales, toasts (`0 16px 44px rgba(7,24,39,.18)`) |
| `--shadow-lg` | Caja de login, diálogos |

## 6. Breakpoints

Mobile-first en lo nuevo; el CSS heredado usa `max-width`:
`1040px` (filtros admin) · `980px` (tablet: se compacta la topbar) · `760px` (móvil: tablas a tarjetas, una columna) ·
`720px` (diálogos/diagnóstico) · `520px` · `430px`.

## 7. Componentes base (reutilizar, no duplicar)

| Componente | Clase | Cuándo |
|---|---|---|
| Topbar | `.topbar` + `.nav-link` | Todas las páginas privadas. La navegación se completa en `app.js` según el rol. |
| Migas | `.breadcrumbs` | Toda página bajo "Proyectos". |
| Encabezado | `.page-heading` (+ `.eyebrow`, h1, descripción, `.page-actions`) | Una por página, con un único h1. |
| Botones | `button` (marino), `.button-primary` (acción principal, 1 por vista), `.button-secondary`, `.button-ghost`, `.button-success`, `.button-danger`, `.button-sm` | Texto con verbo ("Guardar proyecto"). |
| Campo | `.campo` > `label` + control + `small` | Siempre con label visible. |
| Superficie | `.surface`, `.surface-header`, `.surface-body`, `.section-block` | Agrupar contenido. |
| Tabla | `.tabla-shell` > `.tabla-scroll` > `table.tabla-responsive` | En móvil se vuelve tarjetas (`td[data-label]`). |
| Métricas | `.metric-grid` > `.metric-card[data-tone]` | Resúmenes numéricos. |
| Insignias | `.status-badge.status-{success,warning,danger,neutral}` | Estados. |
| Mensajes | `.mensaje` (+ `.ok`, `.info`) con `aria-live` | Feedback en línea. |
| Toast | `.toast-region` > `.admin-toast` | Confirmaciones de acciones en admin. |
| Diálogo | `dialog.admin-dialog` (header/body/footer) | Formularios y confirmaciones. |
| Estados | `.estado-vacio` / `.empty-content`, `.admin-skeleton-line`, `.spinner` | Vacío con acción, carga, error con reintentar. |

Estados obligatorios en vistas con datos: **carga** (skeleton o spinner), **vacío** (con acción sugerida),
**error** (mensaje claro + "Reintentar") y **éxito**.

## 8. Accesibilidad mínima

- Foco visible (`:focus-visible` con `--focus`), nunca `outline: none` sin reemplazo.
- Objetivos táctiles ≥ 44×44 px en móvil (`--touch: 44px`).
- Botones con solo ícono: `aria-label`. Íconos decorativos: `aria-hidden="true"`.
- Un `h1` por página y headings en orden. `lang="es"`.
- Respetar `prefers-reduced-motion` (ya global).

## 9. Inconsistencias a unificar

| # | Inconsistencia | Riesgo | Estado |
|---|---|---|---|
| 1 | `--slate-400` se usa (`.admin-page-ellipsis`) pero no está definido | Bug: el color cae al heredado | ✅ Resuelta |
| 2 | Bloque "Editor del formulario" usa fallbacks de Tailwind (`#64748b`, `#e2e8f0`, `#f1f5f9`, `#f8fafc`, `#166534`, `#b45309`, `#305496`) | Segunda paleta mezclada | ✅ Resuelta |
| 3 | Hex sueltos repetidos: `#fbfcfd` (×7), `#0e507f`, `#842e2e`, `#9aabb7`, `#91a0aa`, `#d99413`, `#fffaf0`, `#fdf1d8`, `#6b3fa0` | Difícil de mantener | ✅ Resuelta (quedan solo colores decorativos de un solo uso: degradados del login, puntos de estado sobre la topbar oscura y brillo del skeleton) |
| 4 | Radios en px fuera de escala: `7px`, `9px`, `10px`, `11px`, `14px`, `16px`, `20px` | Esquinas distintas en componentes equivalentes | ✅ Resuelta (excepto logo, punto de color y marco de celular del editor, que son intencionales) |
| 5 | Sombras sueltas en menú contextual, toast y diálogo | Profundidad inconsistente | Pendiente |
| 6 | Alturas de controles: botones 42 px, `.button-sm` 36 px, paginación 34 px, acciones 38 px | Objetivos táctiles < 44 px en móvil | Pendiente |
| 7 | 48 tamaños de fuente distintos | Jerarquía difusa | Pendiente (se documenta la escala; se unifica al tocar cada pantalla) |
