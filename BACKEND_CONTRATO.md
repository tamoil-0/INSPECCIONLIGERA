# Contrato verificado con el backend ECOING

Estado: verificado el 18 de agosto de 2026 contra el PHP incluido en `INSPEECIONLIGERAECOING/api` y mediante una prueba real sobre XAMPP.

La fuente detallada es `../docs/CONEXION_Y_SINCRONIZACION_MOVIL.md`. Este archivo resume las reglas que afectan directamente al código Flutter.

## Configuración y salud

- URL única: `API_BASE_URL`, recibida con `--dart-define`, validada y normalizada sin `/` final.
- Salud: `GET /info.php`, sin token. Comprueba API y conexión a MySQL.
- Producción: HTTPS obligatorio.
- Local Android debug: HTTP permitido y PC/teléfono en la misma red.

## Autenticación

- `POST /usuarios/login.php` con `nombre_usuario` y `contrasena`.
- Token JWT en `Authorization: Bearer <token>` para el resto de rutas.
- El token se guarda en Keystore/Keychain; nunca en logs ni URL.
- Un `401` pausa la cola sin borrar formularios ni fotografías.

## Padrón

- `GET /proyectos/listar.php?estado=activo`.
- `GET /postes/listar.php?proyecto_id=N`.
- Las búsquedas por línea y estructura siempre incluyen `proyecto_id`.
- SQLite conserva `utm_x`, `utm_y` y `zona` por separado y acepta números o texto decimal.
- Los indicadores `0/1`, `true/false` se normalizan antes de guardarse.

## Formulario y RST

- Formulario: `PUT /postes/actualiza-datos.php?poste_id=N`.
- `distancia_acceso` es obligatoria, numérica y no negativa.
- `cantidad_pat` es opcional, entera y no negativa.
- `distancia_poste_anterior`, `distancia_vertical` y
  `distancia_horizontal` son mediciones DMS opcionales en metros; aceptan
  decimales no negativos o `null` para limpiar el valor.
- Fecha: `YYYY-MM-DD HH:mm:ss` en hora local de Lima.
- RST: `POST /postes/agregar-seccion-rst.php?poste_id=N` con `registros`.
- Secciones RST: `conductores_fase`, `conductores_cuellos`, `conductores_guarda` y `estado_aisladores`.
- El backend hace upsert por poste y por combinación sección/fase; los reintentos no duplican.

El cliente mantiene `ENVIAR_NO_REVISADO=false`: la interfaz distingue campos no revisados, pero envía valores compatibles con el catálogo vigente. No activar el interruptor hasta ampliar el catálogo del backend y definir su representación en informes.

## Fotografías

- Ruta: `POST /postes/imagenes-poste.php?poste_id=N`.
- Exactamente 28 tipos definidos en `lib/core/contrato_fotos.dart`, idénticos a `photoTypes()` de PHP.
- Todos los tipos son necesarios para `imagenes_subidas=true`, incluidos `foto_panoramica`, `otros` y `puesta_tierra_2`.
- Lotes normales de 6. Ante HTTP `413`, el cliente divide automáticamente el lote.
- El cliente no fija el `Content-Type` multipart; la librería genera el boundary.
- Máximo del backend: 10 MB por imagen, JPEG/PNG real, 40 MP de entrada y 100 MB por petición.
- La respuesta vigente usa `resultados.imagen_N`. El cliente revisa cada elemento incluso si `success` global es falso por un fallo parcial.
- Cada foto confirmada sale de la cola; cada foto rechazada conserva archivo, error e intentos.
- El backend conserva una sola foto vigente por `poste_id + nombre_foto`; un reenvío reemplaza ese tipo y no crea duplicados.

## Confirmación final

Después de formulario, RST y fotos, consultar:

```text
GET /postes/sincronizacion_estado.php?poste_id=N
```

La estructura solo está completa cuando:

```text
acuse del formulario
AND acuse RST, si había registros
AND 28 tipos confirmados
AND formulario_subido == true
AND imagenes_subidas == true
```

Los originales fotográficos no se liberan automáticamente antes de esa verificación. Una respuesta HTTP 200 por sí sola nunca se interpreta como confirmación global.

## Resultado de integración local

La prueba controlada creó una estructura temporal, envió formulario, tres registros RST y los 28 tipos en lotes de 6. El servidor devolvió 28 confirmaciones y ambos indicadores finales en `true`. El registro, sus filas relacionadas y sus archivos se eliminaron al terminar; el padrón quedó nuevamente en 1,235 estructuras sin datos de prueba.
