import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/core/estados_sync.dart';
import 'package:pruebaoffline/database/migraciones.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Esquema exacto de la versión 1 (la que está instalada en los teléfonos de
/// campo). No se debe tocar: es el punto de partida real de la migración.
const List<String> _esquemaV1 = [
  '''
  CREATE TABLE proyectos (
    id INTEGER PRIMARY KEY,
    nombre_proyecto TEXT NOT NULL,
    contratista TEXT NOT NULL,
    ubicacion TEXT NOT NULL,
    estado TEXT NOT NULL,
    fecha_creacion TEXT
  )''',
  '''
  CREATE TABLE postes (
    id INTEGER PRIMARY KEY,
    codigo TEXT NOT NULL,
    linea TEXT,
    estructura TEXT,
    proyecto_id INTEGER NOT NULL,
    fecha_inspeccion TEXT,
    fecha_subida TEXT,
    coordenadas_utm TEXT,
    sincronizado INTEGER DEFAULT 0,
    creado_en TEXT,
    ubicaciones TEXT,
    formulario_subido INTEGER DEFAULT 0,
    imagenes_subidas INTEGER DEFAULT 0,
    UNIQUE (codigo, proyecto_id)
  )''',
  '''
  CREATE TABLE poste_datos (
    poste_id INTEGER PRIMARY KEY,
    obstaculos_faja TEXT,
    estado_cuencas TEXT,
    comentarios TEXT,
    fecha_inspeccion TEXT,
    sincronizado INTEGER DEFAULT 0
  )''',
  '''
  CREATE TABLE poste_secciones_rst (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poste_id INTEGER NOT NULL,
    seccion TEXT NOT NULL,
    atributo TEXT NOT NULL,
    fase TEXT NOT NULL,
    sincronizado INTEGER DEFAULT 0,
    UNIQUE (poste_id, seccion, fase)
  )''',
  '''
  CREATE TABLE formularios_pendientes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poste_id INTEGER NOT NULL,
    datos_json TEXT NOT NULL,
    enviado INTEGER DEFAULT 0,
    creado_en TEXT DEFAULT CURRENT_TIMESTAMP
  )''',
  '''
  CREATE TABLE imagenes_poste_local (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poste_id INTEGER NOT NULL,
    nombre_foto TEXT NOT NULL,
    ruta_archivo TEXT NOT NULL,
    fecha_inspeccion TEXT,
    fecha_subida TEXT,
    fecha_captura TEXT DEFAULT CURRENT_TIMESTAMP,
    utm_este TEXT,
    utm_norte TEXT,
    zona TEXT,
    sincronizada INTEGER DEFAULT 0,
    UNIQUE(poste_id, nombre_foto)
  )''',
];

/// Abre una base con datos representativos de un teléfono en producción.
Future<Database> _abrirV1ConDatos() async {
  final db = await databaseFactoryFfi.openDatabase(
    inMemoryDatabasePath,
    options: OpenDatabaseOptions(version: 1, singleInstance: false),
  );
  for (final sql in _esquemaV1) {
    await db.execute(sql);
  }

  await db.insert('proyectos', {
    'id': 7,
    'nombre_proyecto': 'LT 220kV Zona Sur',
    'contratista': 'Ecoing',
    'ubicacion': 'Arequipa',
    'estado': 'activo',
  });
  await db.insert('postes', {
    'id': 101,
    'codigo': 'P-101',
    'linea': 'L-1234',
    'estructura': '25',
    'proyecto_id': 7,
  });
  await db.insert('postes', {
    'id': 102,
    'codigo': 'P-102',
    'linea': 'L-1234',
    'estructura': '26',
    'proyecto_id': 7,
  });

  // Bug real de la v1: tres filas para el mismo poste porque no había UNIQUE.
  await db.insert('formularios_pendientes', {
    'poste_id': 101,
    'datos_json': '{"comentarios":"primer intento"}',
    'creado_en': '2025-06-01T10:00:00.000',
  });
  await db.insert('formularios_pendientes', {
    'poste_id': 101,
    'datos_json': '{"comentarios":"segundo intento"}',
    'creado_en': '2025-06-02T10:00:00.000',
  });
  await db.insert('formularios_pendientes', {
    'poste_id': 101,
    'datos_json': '{"comentarios":"version vigente"}',
    'creado_en': '2025-06-03T10:00:00.000',
  });
  await db.insert('formularios_pendientes', {
    'poste_id': 102,
    'datos_json': '{"comentarios":"otro poste"}',
    'enviado': 1,
    'creado_en': '2025-06-03T11:00:00.000',
  });

  await db.insert('imagenes_poste_local', {
    'poste_id': 101,
    'nombre_foto': 'placa',
    'ruta_archivo': '/cache/placa.jpg',
    'sincronizada': 0,
  });
  await db.insert('imagenes_poste_local', {
    'poste_id': 101,
    'nombre_foto': 'base_torre',
    'ruta_archivo': '/cache/base.jpg',
    'sincronizada': 1,
  });

  return db;
}

Future<Set<String>> _columnas(Database db, String tabla) async {
  final info = await db.rawQuery('PRAGMA table_info($tabla)');
  return info.map((c) => c['name'].toString()).toSet();
}

Future<Set<String>> _indices(Database db) async {
  final filas = await db.rawQuery(
    "SELECT name FROM sqlite_master WHERE type = 'index'",
  );
  return filas.map((f) => (f['name'] ?? '').toString()).toSet();
}

void main() {
  sqfliteFfiInit();

  group('Migración v1 → v2', () {
    late Database db;

    setUp(() async {
      db = await _abrirV1ConDatos();
      await Migraciones.aplicar(db, 1, Migraciones.version);
    });

    tearDown(() async => db.close());

    test(
      'agrega las columnas de trazabilidad a imagenes_poste_local',
      () async {
        final cols = await _columnas(db, 'imagenes_poste_local');
        expect(
          cols,
          containsAll([
            'uuid',
            'estado',
            'intentos',
            'ultimo_error',
            'fecha_ultimo_intento',
            'checksum',
            'id_remoto',
            'ruta_miniatura',
            'tamano_original',
            'tamano_optimizado',
            'ancho',
            'alto',
            'latitud',
            'longitud',
            'precision_gps',
            'proyecto_id',
          ]),
        );
        // Y conserva las columnas originales.
        expect(cols, containsAll(['sincronizada', 'utm_este', 'zona']));
      },
    );

    test('agrega las columnas de estado a formularios_pendientes', () async {
      final cols = await _columnas(db, 'formularios_pendientes');
      expect(
        cols,
        containsAll([
          'uuid',
          'estado',
          'intentos',
          'ultimo_error',
          'fecha_ultimo_intento',
          'actualizado_en',
          'id_remoto',
        ]),
      );
      expect(cols, containsAll(['datos_json', 'enviado', 'creado_en']));
    });

    test('traduce los booleanos antiguos a estados explícitos', () async {
      final pendiente = await db.query(
        'imagenes_poste_local',
        where: 'nombre_foto = ?',
        whereArgs: ['placa'],
      );
      expect(pendiente.first['estado'], EstadoSync.pendiente);

      final sincronizada = await db.query(
        'imagenes_poste_local',
        where: 'nombre_foto = ?',
        whereArgs: ['base_torre'],
      );
      expect(sincronizada.first['estado'], EstadoSync.sincronizado);

      final form102 = await db.query(
        'formularios_pendientes',
        where: 'poste_id = ?',
        whereArgs: [102],
      );
      expect(form102.first['estado'], EstadoSync.sincronizado);
    });

    test(
      'deja un solo borrador por poste y conserva el más reciente',
      () async {
        final vigentes = await db.query(
          'formularios_pendientes',
          where: 'poste_id = ?',
          whereArgs: [101],
        );
        expect(vigentes, hasLength(1));
        expect(vigentes.first['datos_json'], contains('version vigente'));
      },
    );

    test('NO pierde los borradores duplicados: los archiva', () async {
      final historial = await db.query('formularios_pendientes_historial');
      expect(historial, hasLength(2));
      expect(
        historial.map((h) => h['datos_json'].toString()).toList(),
        containsAll([contains('primer intento'), contains('segundo intento')]),
      );
      expect(historial.first['motivo'], contains('duplicado'));
    });

    test('el índice único impide volver a duplicar borradores', () async {
      expect(
        () => db.insert('formularios_pendientes', {
          'poste_id': 101,
          'datos_json': '{}',
        }),
        throwsA(isA<DatabaseException>()),
      );
    });

    test('asigna UUID a todas las filas heredadas', () async {
      final imgs = await db.query('imagenes_poste_local');
      for (final f in imgs) {
        expect((f['uuid'] ?? '').toString(), isNotEmpty);
      }
      final forms = await db.query('formularios_pendientes');
      for (final f in forms) {
        expect((f['uuid'] ?? '').toString(), isNotEmpty);
      }
      // UUID distintos entre filas.
      final uuids = imgs.map((f) => f['uuid'].toString()).toSet();
      expect(uuids, hasLength(imgs.length));
    });

    test('crea los índices de consulta', () async {
      final idx = await _indices(db);
      expect(
        idx,
        containsAll([
          'idx_postes_proyecto_linea',
          'idx_postes_estructura',
          'idx_img_poste_estado',
          'idx_img_estado',
          'idx_form_estado',
          'idx_rst_poste',
          'ux_form_pendiente_poste',
        ]),
      );
    });

    test('no pierde ningún poste ni proyecto', () async {
      expect(await db.query('postes'), hasLength(2));
      expect(await db.query('proyectos'), hasLength(1));
    });

    test('v3 registra qué campos revisó el inspector', () async {
      final cols = await _columnas(db, 'formularios_pendientes');
      expect(cols, containsAll(['revisados_json', 'sin_revisar']));
      expect(await _columnas(db, 'poste_datos'), contains('revisados_json'));

      // Los borradores heredados quedan a NULL: marcarlos como 'todo revisado'
      // sería inventar un dato que nadie confirmó.
      final heredado = await db.query(
        'formularios_pendientes',
        where: 'poste_id = ?',
        whereArgs: [101],
      );
      expect(heredado.first['revisados_json'], isNull);
    });

    test('es idempotente: volver a aplicarla no rompe ni duplica', () async {
      await Migraciones.aplicar(db, 1, Migraciones.version);
      await Migraciones.aplicar(db, 1, Migraciones.version);

      expect(
        await db.query(
          'formularios_pendientes',
          where: 'poste_id = ?',
          whereArgs: [101],
        ),
        hasLength(1),
      );
      expect(await db.query('formularios_pendientes_historial'), hasLength(2));
      expect(await db.query('imagenes_poste_local'), hasLength(2));
    });
  });

  test('instalación nueva y actualización convergen al mismo esquema', () async {
    // Actualizada desde v1 (salto completo 1 -> versión actual).
    final actualizada = await _abrirV1ConDatos();
    await Migraciones.aplicar(actualizada, 1, Migraciones.version);

    // Instalación nueva: mismo esquema base + migraciones (lo que hace
    // DatabaseHelper._onCreate).
    final nueva = await databaseFactoryFfi.openDatabase(
      inMemoryDatabasePath,
      options: OpenDatabaseOptions(version: 1, singleInstance: false),
    );
    for (final sql in _esquemaV1) {
      await nueva.execute(sql);
    }
    await Migraciones.aplicar(nueva, 1, Migraciones.version);

    for (final tabla in [
      'imagenes_poste_local',
      'formularios_pendientes',
      'poste_datos',
    ]) {
      expect(
        await _columnas(nueva, tabla),
        equals(await _columnas(actualizada, tabla)),
        reason:
            'El esquema de $tabla difiere entre instalación nueva y '
            'actualizada; un teléfono nuevo y uno migrado deben ser idénticos.',
      );
    }

    await actualizada.close();
    await nueva.close();
  });

  test('un teléfono que ya estaba en v2 llega a v3 sin perder nada', () async {
    final db = await _abrirV1ConDatos();
    await Migraciones.aplicar(db, 1, 2);

    // Estado intermedio: v2 aplicada, con sus datos.
    expect(await _columnas(db, 'formularios_pendientes'), contains('estado'));
    expect(
      await _columnas(db, 'formularios_pendientes'),
      isNot(contains('revisados_json')),
    );
    final antes = await db.query('formularios_pendientes');

    await Migraciones.aplicar(db, 2, 3);

    expect(
      await _columnas(db, 'formularios_pendientes'),
      contains('revisados_json'),
    );
    final despues = await db.query('formularios_pendientes');
    expect(despues.length, antes.length);
    expect(despues.first['datos_json'], antes.first['datos_json']);
    expect(despues.first['uuid'], antes.first['uuid']);

    await db.close();
  });
}
