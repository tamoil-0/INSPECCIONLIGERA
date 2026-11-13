---
name: design-system
description: Reglas obligatorias para cualquier cambio de interfaz en la web de ECOING (web/*.html, web/css, web/js). Usar antes de tocar estilos, layout, textos de la interfaz, estados o accesibilidad.
---

# Sistema de diseño ECOING

Antes de cambiar cualquier pantalla:

1. **Leer `DESIGN.md`** (raíz del repo). Es la fuente de verdad de color, tipografía, espaciado, radios, sombras, breakpoints y componentes.
2. **Solo tokens.** Colores, radios y sombras salen de las variables de `:root` en `web/css/estilos.css`.
   Si falta un token, se agrega en `:root` y en `DESIGN.md`; nunca un hex suelto en un componente.
3. **Reutilizar componentes existentes** (`.page-heading`, `.surface`, `.campo`, `.tabla-responsive`, `.status-badge`,
   `.mensaje`, `.admin-dialog`, `.admin-toast`, botones). No crear variantes paralelas de algo que ya existe.
4. **Mobile-first.** Lo nuevo se escribe para 375 px y se amplía con `min-width`. Nada puede desbordar horizontalmente en 375 px.
5. **Estados obligatorios** en toda vista con datos: carga (skeleton/spinner), vacío (con acción sugerida),
   error (mensaje claro + botón "Reintentar") y éxito (mensaje o toast).
6. **Accesibilidad AA**: contraste ≥ 4.5:1 en texto normal, foco visible, navegación con teclado, `aria-label` en botones con solo ícono,
   `alt` en imágenes, un único `h1` y headings en orden, `aria-live` en mensajes dinámicos.
7. **Objetivos táctiles de 44 px** como mínimo en móvil (botones, enlaces de navegación, controles de paginación).
8. **Textos**: español, tono claro y directo, botones con verbo ("Guardar proyecto", no "Aceptar"), tildes correctas.
9. **No tocar lógica**: ni endpoints, ni datos, ni autenticación, ni base de datos. Si un cambio visual necesita JS, que solo afecte
   presentación (clases, atributos ARIA, textos, foco).
10. **Verificar con la skill `ui-review`** antes de dar la pantalla por terminada.
