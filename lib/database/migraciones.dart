import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import '../core/estados_sync.dart';

/// Migraciones de la base local.
///
/// ## Reglas que respeta este archivo
///
/// 1. **Nunca borra información del inspector.** Todas las migraciones son
///    aditivas (`ALTER TABLE ADD COLUMN`). Cuando hay que quitar filas
///    duplicadas, primero se copian a una tabla de historial.
/// 2. **Es idempotente.** Cada paso comprueba si ya está aplicado antes de
///    ejecutarse. Si la app muere a mitad de una migración, el siguiente
///    arranque la termina sin romperse.
/// 3. **Se aplica igual en instalación nueva y en actualización.** `onCreate`
///    crea el esquema v1 y luego llama a [aplicar], de modo que un teléfono
///    nuevo y uno que viene de la versión anterior acaban con exactamente el
///    mismo esquema.
///
/// Documentación completa del historial en `MIGRATIONS.md`.
class Migraciones {
  const Migraciones._();

  /// Versión de esquema que espera esta compilación de la app.
  static const int version = 2;

  static const Uuid _uuid = Uuid();

  /// Aplica todas las migraciones necesarias para pasar de [desde] a [hasta].
  ///
  /// No abre transacción propia: `sqflite` ya ejecuta `onUpgrade` y `onCreate`
  /// dentro de una, y anidarlas provocaría un bloqueo.
  static Future<void> aplicar(Database db, int desde, int hasta) async {
    for (var v = desde + 1; v <= hasta; v++) {
      switch (v) {
        case 2:
          await _v2EstadosYTrazabilidad(db);
          break;
      }
    }
  }

  // ===========================================================================
  // v2 — Estados de sincronización explícitos y trazabilidad de fotografías
  //
  // Problema que resuelve:
  //   · `imagenes_poste_local.sincronizada` era un booleano que se marcaba en 1
  //     sin comprobar la respuesta del servidor. No había forma de saber si una
  //     foto falló, cuántas veces se intentó, ni por qué.
  //   · `formularios_pendientes` acumulaba una fila por cada envío (sin UNIQUE
  //     sobre poste_id) y la columna `enviado` nunca se actualizaba.
  //   · No existía identificador estable para detectar duplicados ni checksum
  //     para verificar integridad del archivo.
  // ===========================================================================
  static Future<void> _v2EstadosYTrazabilidad(Database db) async {
    // --- 1. Fotografías: trazabilidad completa -------------------------------
    await _agregarColumnas(db, 'imagenes_poste_local', {
      'uuid': 'TEXT',
      'proyecto_id': 'INTEGER',
      'linea': 'TEXT',
      'ruta_original': 'TEXT',
      'ruta_miniatura': 'TEXT',
      'tamano_original': 'INTEGER',
      'tamano_optimizado': 'INTEGER',
      'ancho': 'INTEGER',
      'alto': 'INTEGER',
      'formato': 'TEXT',
      'latitud': 'REAL',
      'longitud': 'REAL',
      'precision_gps': 'REAL',
      'estado': 'TEXT',
      'intentos': 'INTEGER DEFAULT 0',
      'ultimo_error': 'TEXT',
      'fecha_ultimo_intento': 'TEXT',
      'checksum': 'TEXT',
      'id_remoto': 'TEXT',
      'creado_en': 'TEXT',
    });

    // --- 2. Formularios: estado real + trazabilidad -------------------------
    await _agregarColumnas(db, 'formularios_pendientes', {
      'uuid': 'TEXT',
      'estado': 'TEXT',
      'intentos': 'INTEGER DEFAULT 0',
      'ultimo_error': 'TEXT',
      'fecha_ultimo_intento': 'TEXT',
      'actualizado_en': 'TEXT',
      'id_remoto': 'TEXT',
    });

    await _agregarColumnas(db, 'poste_datos', {'actualizado_en': 'TEXT'});

    // --- 3. Traducir los booleanos antiguos a estados explícitos ------------
    // `sincronizada = 1` en la versión anterior NO garantizaba confirmación del
    // servidor (se marcaba ignorando el resultado de la subida). Aun así se
    // respeta el valor existente para no reenviar de golpe todo el histórico:
    // el usuario puede forzar la revisión desde la pantalla de sincronización.
    await db.execute('''
      UPDATE imagenes_poste_local
         SET estado = CASE WHEN sincronizada = 1 THEN ? ELSE ? END
       WHERE estado IS NULL
    ''', [EstadoSync.sincronizado, EstadoSync.pendiente]);

    await db.execute('''
      UPDATE formularios_pendientes
         SET estado = CASE WHEN enviado = 1 THEN ? ELSE ? END
       WHERE estado IS NULL
    ''', [EstadoSync.sincronizado, EstadoSync.pendiente]);

    // --- 4. Archivar duplicados de formularios ANTES de desduplicar ---------
    // No se borra nada sin copia: los borradores repetidos del mismo poste se
    // conservan en una tabla de historial por si hubiera que auditarlos.
    await db.execute('''
      CREATE TABLE IF NOT EXISTS formularios_pendientes_historial (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        poste_id INTEGER NOT NULL,
        datos_json TEXT NOT NULL,
        creado_en TEXT,
        archivado_en TEXT,
        motivo TEXT
      )
    ''');

    final duplicados = await db.rawQuery('''
      SELECT id, poste_id, datos_json, creado_en
        FROM formularios_pendientes
       WHERE id NOT IN (SELECT MAX(id) FROM formularios_pendientes GROUP BY poste_id)
    ''');

    if (duplicados.isNotEmpty) {
      final ahora = DateTime.now().toIso8601String();
      final batch = db.batch();
      for (final fila in duplicados) {
        batch.insert('formularios_pendientes_historial', {
          'poste_id': fila['poste_id'],
          'datos_json': fila['datos_json'],
          'creado_en': fila['creado_en'],
          'archivado_en': ahora,
          'motivo': 'duplicado consolidado en migración v2',
        });
      }
      await batch.commit(noResult: true);

      await db.execute('''
        DELETE FROM formularios_pendientes
         WHERE id NOT IN (SELECT MAX(id) FROM formularios_pendientes GROUP BY poste_id)
      ''');
    }

    // --- 5. Un solo borrador vigente por poste ------------------------------
    await db.execute(
      'CREATE UNIQUE INDEX IF NOT EXISTS ux_form_pendiente_poste '
      'ON formularios_pendientes(poste_id)',
    );

    // --- 6. Identificadores locales estables (idempotencia de reintentos) ---
    await _rellenarUuid(db, 'imagenes_poste_local');
    await _rellenarUuid(db, 'formularios_pendientes');

    // --- 7. Índices de consulta --------------------------------------------
    // Las listas de la app filtran siempre por estos campos y hasta ahora
    // hacían recorrido completo de tabla.
    const indices = [
      'CREATE INDEX IF NOT EXISTS idx_postes_proyecto_linea ON postes(proyecto_id, linea)',
      'CREATE INDEX IF NOT EXISTS idx_postes_estructura ON postes(estructura)',
      'CREATE INDEX IF NOT EXISTS idx_img_poste_estado ON imagenes_poste_local(poste_id, estado)',
      'CREATE INDEX IF NOT EXISTS idx_img_estado ON imagenes_poste_local(estado)',
      'CREATE INDEX IF NOT EXISTS idx_img_checksum ON imagenes_poste_local(checksum)',
      'CREATE INDEX IF NOT EXISTS idx_form_estado ON formularios_pendientes(estado)',
      'CREATE INDEX IF NOT EXISTS idx_rst_poste ON poste_secciones_rst(poste_id)',
    ];
    for (final sql in indices) {
      await db.execute(sql);
    }
  }

  // ===========================================================================
  // Utilidades
  // ===========================================================================

  /// Nombres de columna existentes en [tabla].
  static Future<Set<String>> columnas(Database db, String tabla) async {
    final info = await db.rawQuery('PRAGMA table_info($tabla)');
    return info.map((c) => c['name'].toString()).toSet();
  }

  /// Agrega solo las columnas que falten. Volver a ejecutarlo no hace nada.
  static Future<void> _agregarColumnas(
    Database db,
    String tabla,
    Map<String, String> definiciones,
  ) async {
    final existentes = await columnas(db, tabla);
    for (final entrada in definiciones.entries) {
      if (existentes.contains(entrada.key)) continue;
      await db.execute(
        'ALTER TABLE $tabla ADD COLUMN ${entrada.key} ${entrada.value}',
      );
    }
  }

  /// Asigna un UUID a las filas que aún no lo tienen.
  static Future<void> _rellenarUuid(Database db, String tabla) async {
    final sinUuid = await db.query(
      tabla,
      columns: ['id'],
      where: 'uuid IS NULL OR uuid = ?',
      whereArgs: [''],
    );
    if (sinUuid.isEmpty) return;

    final batch = db.batch();
    for (final fila in sinUuid) {
      batch.update(
        tabla,
        {'uuid': _uuid.v4()},
        where: 'id = ?',
        whereArgs: [fila['id']],
      );
    }
    await batch.commit(noResult: true);
  }
}
