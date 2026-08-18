import 'dart:async';
import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:sqflite/sqflite.dart';
import 'package:path/path.dart';
import 'dart:convert';

import 'migraciones.dart';

class DatabaseHelper {
  static final DatabaseHelper _instance = DatabaseHelper._internal();
  static Database? _database;

  factory DatabaseHelper() => _instance;

  DatabaseHelper._internal();

  /// Nombre del archivo de base. Se puede cambiar en pruebas para trabajar
  /// contra una base temporal y no tocar la del dispositivo.
  @visibleForTesting
  static String nombreArchivo = 'app_local.db';

  /// Cierra y olvida la instancia en memoria. Solo para pruebas: permite abrir
  /// una base limpia entre casos.
  @visibleForTesting
  static Future<void> reiniciarParaPruebas() async {
    await _database?.close();
    _database = null;
  }

  Future<Database> get database async {
    if (_database != null) return _database!;
    _database = await _initDatabase();
    return _database!;
  }

  Future<Database> _initDatabase() async {
    final dbPath = await getDatabasesPath();
    final path = join(dbPath, nombreArchivo);

    return await openDatabase(
      path,
      version: Migraciones.version,
      onCreate: _onCreate,
      onUpgrade: _onUpgrade,
      // NOTA: no se activa `PRAGMA foreign_keys = ON` en P0 a propósito.
      // Las tablas declaran FK hacia `postes`, pero la app nunca las ha
      // aplicado; activarlas ahora convertiría inserciones que hoy funcionan
      // en silencio en fallos nuevos en campo. Se evaluará con pruebas en la
      // fase de arquitectura.
    );
  }

  /// Instalación nueva: crea el esquema base y luego aplica todas las
  /// migraciones, para que un teléfono nuevo y uno actualizado converjan al
  /// mismo esquema exacto.
  Future<void> _onCreate(Database db, int version) async {
    await _crearEsquemaBase(db);
    await Migraciones.aplicar(db, 1, version);
  }

  /// Actualización de una instalación existente. Nunca borra datos: ver
  /// `lib/database/migraciones.dart` y `MIGRATIONS.md`.
  Future<void> _onUpgrade(Database db, int desde, int hasta) async {
    await Migraciones.aplicar(db, desde, hasta);
  }

  Future<void> _crearEsquemaBase(Database db) async {
    await db.execute('''
      CREATE TABLE proyectos (
        id INTEGER PRIMARY KEY,
        nombre_proyecto TEXT NOT NULL,
        contratista TEXT NOT NULL,
        ubicacion TEXT NOT NULL,
        estado TEXT NOT NULL,
        fecha_creacion TEXT
      )
    ''');

    await db.execute('''
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
    ubicaciones TEXT,                  -- 🕒 Fecha en que se descargó o creó el registro
    formulario_subido INTEGER DEFAULT 0,
    imagenes_subidas INTEGER DEFAULT 0,
    UNIQUE (codigo, proyecto_id)
  )
''');

    await db.execute('''
      CREATE TABLE poste_datos (
        poste_id INTEGER PRIMARY KEY,
        obstaculos_faja TEXT,
        estado_cuencas TEXT,
        marcado_arboles TEXT,
        criticidad_tala TEXT,
        criticidad_contacto TEXT,
        notificacion_propietario TEXT,
        tipo_torre TEXT,
        ubicacion TEXT,
        acceso_torre TEXT,
        estado_acceso TEXT,
        estado_placas_torre TEXT,
        estado_placas_linea TEXT,
        estado_placas_fases TEXT,
        peligro_cerco TEXT,
        peligro_torre TEXT,
        puesta_tierra TEXT,
        retenida TEXT,
        estado_base TEXT,
        limpiar_base TEXT,
        crucetas_mensuales TEXT,
        perfiles_angulares TEXT,
        malla_antiescalamiento TEXT,
        oxidos_base TEXT,
        cadena_aisladores TEXT,
        tipo_aislador TEXT,
        conductor_bajada_pat TEXT,
        conductor_guarda TEXT,
        comentarios TEXT, 
        fecha_inspeccion TEXT,
        sincronizado INTEGER DEFAULT 0,
        FOREIGN KEY (poste_id) REFERENCES postes(id) ON DELETE CASCADE
      )
    ''');

    await db.execute('''
      CREATE TABLE poste_secciones_rst (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        poste_id INTEGER NOT NULL,
        seccion TEXT NOT NULL,
        atributo TEXT NOT NULL,
        fase TEXT NOT NULL,
        sincronizado INTEGER DEFAULT 0,
        UNIQUE (poste_id, seccion, fase),
        FOREIGN KEY (poste_id) REFERENCES postes(id) ON DELETE CASCADE
      )
    ''');

    await db.execute('''
      CREATE TABLE formularios_pendientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        poste_id INTEGER NOT NULL,
        datos_json TEXT NOT NULL,
        enviado INTEGER DEFAULT 0,
        creado_en TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (poste_id) REFERENCES postes(id) ON DELETE CASCADE
      )
    ''');

    await db.execute('''
  CREATE TABLE imagenes_poste_local (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poste_id INTEGER NOT NULL,
    nombre_foto TEXT NOT NULL,
    ruta_archivo TEXT NOT NULL,
    fecha_inspeccion TEXT,       -- 🟡 nueva
    fecha_subida TEXT,           -- 🟢 nueva
    fecha_captura TEXT DEFAULT CURRENT_TIMESTAMP,
    utm_este TEXT,
    utm_norte TEXT,
    zona TEXT,
    sincronizada INTEGER DEFAULT 0,
    UNIQUE(poste_id, nombre_foto)
  )
''');
  }

  Future<void> insertOrUpdateProyectos(List<dynamic> proyectos) async {
    final db = await database;
    final batch = db.batch();

    for (var proyecto in proyectos) {
      batch.insert('proyectos', {
        'id': proyecto['id'],
        'nombre_proyecto': proyecto['nombre_proyecto'],
        'contratista': proyecto['contratista'],
        'ubicacion': proyecto['ubicacion'],
        'estado': proyecto['estado'],
        'fecha_creacion': proyecto['fecha_creacion'],
      }, conflictAlgorithm: ConflictAlgorithm.replace);
    }

    await batch.commit(noResult: true);
  }

  Future<List<Map<String, dynamic>>> getFormulariosPendientes() async {
    final db = await database;
    return await db.query('formularios_pendientes');
  }

  Future<List<Map<String, dynamic>>> obtenerTodasLasImagenes() async {
    final db = await database;

    final resultado = await db.rawQuery('''
    SELECT 
      i.poste_id,
      i.nombre_foto,
      i.ruta_archivo,
      p.proyecto_id
    FROM imagenes_poste_local i
    JOIN postes p ON i.poste_id = p.id
  ''');

    return resultado;
  }

  Future<void> insertOrUpdatePostes(List<dynamic> postes) async {
    final db = await database;
    final batch = db.batch();

    for (var poste in postes) {
      batch.insert('postes', {
        'id': poste['id'],
        'codigo': poste['codigo'],
        'linea': poste['linea'],
        'estructura': poste['estructura'],
        'ubicaciones': poste['ubicaciones'], // ✅ AÑADIDO

        'proyecto_id': poste['proyecto_id'],
        'fecha_inspeccion': poste['fecha_inspeccion'],
        'coordenadas_utm': poste['coordenadas_utm'],
        'sincronizado': poste['sincronizado'] == true ? 1 : 0,
        'creado_en': poste['creado_en'],
        'formulario_subido': poste['formulario_subido'] == true ? 1 : 0,
        'imagenes_subidas': poste['imagenes_subidas'] == true ? 1 : 0,
      }, conflictAlgorithm: ConflictAlgorithm.replace);
    }

    await batch.commit(noResult: true);
  }

  Future<bool> verificarPostePerteneceAProyecto(
    int posteId,
    int proyectoId,
  ) async {
    final db = await database;

    final result = await db.query(
      'postes',
      where: 'id = ? AND proyecto_id = ?',
      whereArgs: [posteId, proyectoId],
    );

    return result.isNotEmpty;
  }

  Future<void> guardarFormularioCompleto({
    required int posteId,
    required Map<String, dynamic> datos,
  }) async {
    final datosSolo = {...datos};

    // Eliminar claves RST o relacionadas con secciones que no van en poste_datos
    final clavesRST = [
      'conductores_fase_R',
      'conductores_cuellos_R',
      'estado_aisladores_R',
      'conductores_fase_S',
      'conductores_cuellos_S',
      'estado_aisladores_S',
      'conductores_fase_T',
      'conductores_cuellos_T',
      'estado_aisladores_T',
    ];

    datosSolo.removeWhere(
      (key, _) => key.startsWith('RST_') || clavesRST.contains(key),
    );
    //print("🟢 DATOS que se guardarán en SQLite: ${datosSolo}");

    // Guardar en poste_datos
    await guardarPosteDatos({
      'poste_id': posteId,
      ...datosSolo,
      'sincronizado': 1,
    });

    // Guardar en poste_secciones_rst
    for (final key in datos.keys) {
      if (key.startsWith('RST_')) {
        final partes = key.split('_'); // Ejemplo: RST_conductores_fase_R
        if (partes.length >= 4) {
          final seccion = partes[1] + '_' + partes[2];
          final fase = partes[3];
          final atributos = datos[key];

          if (atributos is List) {
            for (final atributo in atributos) {
              await guardarPosteSeccionRST({
                'poste_id': posteId,
                'seccion': seccion,
                'fase': fase,
                'atributo': atributo,
                'sincronizado': 1,
              });
            }
          }
        }
      }
    }
  }

  Future<List<Map<String, String>>> obtenerLineasConUbicacion(
    int proyectoId,
  ) async {
    final db = await database;
    final resultado = await db.rawQuery(
      '''
    SELECT DISTINCT linea, ubicaciones
    FROM postes
    WHERE proyecto_id = ? AND linea IS NOT NULL
  ''',
      [proyectoId],
    );

    return resultado
        .map(
          (fila) => {
            'linea': (fila['linea'] ?? '').toString(),
            'ubicacion': (fila['ubicaciones'] ?? 'Ubicación no disponible')
                .toString(),
          },
        )
        .toList();
  }

  Future<List<Map<String, dynamic>>> obtenerPostesPorProyecto(
    int proyectoId,
  ) async {
    final db = await database;
    return await db.query(
      'postes',
      where: 'proyecto_id = ?',
      whereArgs: [proyectoId],
    );
  }

  Future<void> guardarRSTLocal(
    int posteId,
    List<Map<String, dynamic>> registros,
  ) async {
    final db = await database;

    // 🧹 Borrar los datos antiguos de ese poste
    await db.delete(
      'poste_secciones_rst',
      where: 'poste_id = ?',
      whereArgs: [posteId],
    );

    // 💾 Insertar los nuevos registros (con sincronizado = 0)
    for (final registro in registros) {
      await db.insert('poste_secciones_rst', {
        'poste_id': posteId,
        'seccion': registro['seccion'],
        'atributo': registro['atributo'],
        'fase': registro['fase'],
        'sincronizado': 0,
      }, conflictAlgorithm: ConflictAlgorithm.replace);
    }

    //print("✅ RST guardado localmente para poste $posteId con ${registros.length} registros");
  }

  Future<void> guardarPosteDatos(Map<String, dynamic> datos) async {
    final db = await database;

    // Convertir listas a String antes de insertar
    final Map<String, dynamic> datosProcesados = {};
    datos.forEach((key, value) {
      if (value is List || value is Map) {
        datosProcesados[key] = jsonEncode(value); // lo convierte a JSON string
      } else {
        datosProcesados[key] = value;
      }
    });

    await db.insert(
      'poste_datos',
      datosProcesados,
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> guardarPosteSeccionRST(Map<String, dynamic> seccion) async {
    final db = await database;
    await db.insert(
      'poste_secciones_rst',
      seccion,
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<List<Map<String, dynamic>>> getRSTPorPoste(int posteId) async {
    final db = await database;
    final result = await db.query(
      'poste_secciones_rst',
      where: 'poste_id = ?',
      whereArgs: [posteId],
    );

    return result
        .map(
          (row) => {
            "seccion": row["seccion"],
            "atributo": row["atributo"],
            "fase": row["fase"],
          },
        )
        .toList();
  }

  Future<List<Map<String, dynamic>>> getProyectos() async {
    final db = await database;
    return await db.query('proyectos');
  }

  Future<List<Map<String, dynamic>>> buscarPostesPorEstructuraLocal(
    String estructura,
    int proyectoId,
  ) async {
    final db = await database;
    return await db.query(
      'postes',
      where: 'estructura LIKE ? AND proyecto_id = ?',
      whereArgs: ['%$estructura%', proyectoId],
    );
  }

  Future<List<Map<String, dynamic>>> getPostesPorProyecto(
    int proyectoId,
  ) async {
    final db = await database;
    return await db.query(
      'postes',
      where: 'proyecto_id = ?',
      whereArgs: [proyectoId],
    );
  }

  Future<void> deleteAllProyectos() async {
    final db = await database;
    await db.delete('proyectos');
  }

  Future<void> eliminarBaseDeDatos() async {
    final dbPath = await getDatabasesPath();
    final path = join(dbPath, 'app_local.db');
    await deleteDatabase(path);
    // print('🗑️ Base de datos local eliminada');
  }

  Future<void> guardarFormularioPendiente({
    required int posteId,
    required Map<String, dynamic> datos,
  }) async {
    final db = await database;
    await db.insert('formularios_pendientes', {
      'poste_id': posteId,
      'datos_json': jsonEncode(datos),
      'enviado': 0,
      'creado_en': DateTime.now().toIso8601String(),
    }, conflictAlgorithm: ConflictAlgorithm.replace);
    // print('💾 Formulario guardado localmente para el poste $posteId');
  }

  Future<List<Map<String, dynamic>>> obtenerPostesConEstadoPorLinea(
    int proyectoId,
    String linea,
  ) async {
    final db = await database;

    final result = await db.rawQuery(
      '''
  SELECT 
    p.id as poste_id,
    p.codigo,
    p.estructura,
    p.formulario_subido,
    p.imagenes_subidas,
    CASE WHEN f.poste_id IS NOT NULL THEN 1 ELSE 0 END as formulario_local,
    COUNT(i.id) as imagenes_local
  FROM postes p
  LEFT JOIN formularios_pendientes f ON p.id = f.poste_id
  LEFT JOIN imagenes_poste_local i ON p.id = i.poste_id
  WHERE p.proyecto_id = ? AND p.linea = ?
  GROUP BY p.id
''',
      [proyectoId, linea],
    );

    return result;
  }

  // 🔍 Obtener formulario pendiente por poste
  Future<Map<String, dynamic>?> getFormularioPorPoste(int posteId) async {
    final db = await database;
    final result = await db.query(
      'formularios_pendientes',
      where: 'poste_id = ?',
      whereArgs: [posteId],
      limit: 1,
    );
    if (result.isNotEmpty) {
      return result.first;
    }
    return null;
  }

  // 🧠 Decodificar string JSON a mapa
  Future<Map<String, dynamic>> decodeJson(String jsonString) async {
    return Map<String, dynamic>.from(jsonDecode(jsonString));
  }

  Future<void> guardarImagenPosteLocal({
    required int posteId,
    required String nombreFoto,
    required String rutaArchivo,
    String? utmEste,
    String? utmNorte,
    String? zona,
    String? fechaInspeccion, // 🟡 nuevo
    String? fechaSubida, // 🟢 nuevo
  }) async {
    final db = await database;
    await db.insert('imagenes_poste_local', {
      'poste_id': posteId,
      'nombre_foto': nombreFoto,
      'ruta_archivo': rutaArchivo,
      'fecha_inspeccion': fechaInspeccion,
      'fecha_subida': fechaSubida,
      'utm_este': utmEste,
      'utm_norte': utmNorte,
      'zona': zona,
      'sincronizada': 0,
    }, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<List<Map<String, dynamic>>> obtenerImagenesDePoste(int posteId) async {
    final db = await database;
    return await db.query(
      'imagenes_poste_local',
      where: 'poste_id = ? AND sincronizada = 0',
      whereArgs: [posteId],
    );
  }

  Future<List<Map<String, dynamic>>> obtenerImagenesNoSincronizadas() async {
    final db = await database;
    return await db.query('imagenes_poste_local', where: 'sincronizada = 0');
  }

  Future<void> marcarPosteComoSincronizado({
    required int posteId,
    bool formulario = false,
    bool imagenes = false,
  }) async {
    final db = await database;
    final updateData = <String, dynamic>{};

    if (formulario) updateData['formulario_subido'] = 1;
    if (imagenes) updateData['imagenes_subidas'] = 1;

    if (updateData.isNotEmpty) {
      await db.update(
        'postes',
        updateData,
        where: 'id = ?',
        whereArgs: [posteId],
      );
    }
  }

  Future<void> marcarImagenComoSincronizada(int imagenId) async {
    final db = await database;
    await db.update(
      'imagenes_poste_local',
      {'sincronizada': 1},
      where: 'id = ?',
      whereArgs: [imagenId],
    );
  }

  Future<List<Map<String, dynamic>>> buscarPostesPorLineaLocal(
    int proyectoId,
    String linea,
  ) async {
    final db = await database;
    return await db.query(
      'postes',
      where: 'proyecto_id = ? AND linea = ?',
      whereArgs: [proyectoId, linea],
    );
  }

  Future<List<String>> obtenerLineasPorProyectoLocal(int proyectoId) async {
    final db = await database;
    final result = await db.rawQuery(
      'SELECT DISTINCT linea FROM postes WHERE proyecto_id = ? AND linea IS NOT NULL',
      [proyectoId],
    );
    return result.map((row) => row['linea']?.toString() ?? '').toList();
  }

  Future<List<Map<String, dynamic>>> obtenerTodosLosPostesConEstado(
    int proyectoId,
  ) async {
    final db = await database;

    final postes = await db.query(
      'postes',
      where: 'proyecto_id = ?',
      whereArgs: [proyectoId],
    );

    final List<Map<String, dynamic>> resultado = [];

    for (final poste in postes) {
      final posteId = poste['id'];

      // Formulario local pendiente
      final formularioLocal =
          Sqflite.firstIntValue(
            await db.rawQuery(
              'SELECT COUNT(*) FROM formularios_pendientes WHERE poste_id = ?',
              [posteId],
            ),
          )! >
          0;

      // Imágenes locales
      final imagenesLocales =
          Sqflite.firstIntValue(
            await db.rawQuery(
              'SELECT COUNT(*) FROM imagenes_poste_local WHERE poste_id = ?',
              [posteId],
            ),
          )! >
          0;

      final formularioServidor = poste['formulario_subido'] == 1;
      final imagenesSincronizadas = poste['imagenes_subidas'] == 1;

      resultado.add({
        'poste_id': posteId,
        'estructura': poste['estructura'] ?? '',
        'formulario_local': formularioLocal,
        'formulario_servidor': formularioServidor,
        'imagenes_local': imagenesLocales,
        'imagenes_servidor': imagenesSincronizadas,
      });
    }

    return resultado;
  }
}
