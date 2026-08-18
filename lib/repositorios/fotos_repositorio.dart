import 'dart:io';

import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import '../core/almacenamiento_fotos.dart';
import '../core/estados_sync.dart';
import '../database/database_helper.dart';

/// Una fotografía de inspección tal como vive en el teléfono.
class FotoLocal {
  final int id;
  final String uuid;
  final int posteId;
  final String nombreFoto;
  final String rutaArchivo;
  final String estado;
  final int intentos;
  final String? ultimoError;
  final String? utmEste;
  final String? utmNorte;
  final String? zona;
  final double? latitud;
  final double? longitud;
  final double? precisionGps;
  final String? fechaCaptura;
  final int? tamanoBytes;
  final String? checksum;

  const FotoLocal({
    required this.id,
    required this.uuid,
    required this.posteId,
    required this.nombreFoto,
    required this.rutaArchivo,
    required this.estado,
    required this.intentos,
    this.ultimoError,
    this.utmEste,
    this.utmNorte,
    this.zona,
    this.latitud,
    this.longitud,
    this.precisionGps,
    this.fechaCaptura,
    this.tamanoBytes,
    this.checksum,
  });

  factory FotoLocal.desdeFila(Map<String, dynamic> f) => FotoLocal(
    id: f['id'] as int,
    uuid: (f['uuid'] ?? '').toString(),
    posteId: f['poste_id'] as int,
    nombreFoto: (f['nombre_foto'] ?? '').toString(),
    rutaArchivo: (f['ruta_archivo'] ?? '').toString(),
    estado: (f['estado'] ?? EstadoSync.pendiente).toString(),
    intentos: (f['intentos'] as int?) ?? 0,
    ultimoError: f['ultimo_error'] as String?,
    utmEste: f['utm_este']?.toString(),
    utmNorte: f['utm_norte']?.toString(),
    zona: f['zona']?.toString(),
    latitud: (f['latitud'] as num?)?.toDouble(),
    longitud: (f['longitud'] as num?)?.toDouble(),
    precisionGps: (f['precision_gps'] as num?)?.toDouble(),
    fechaCaptura: f['fecha_captura']?.toString(),
    tamanoBytes: (f['tamano_optimizado'] as int?) ?? (f['tamano_original'] as int?),
    checksum: f['checksum'] as String?,
  );

  File get archivo => File(rutaArchivo);
  bool get estaSincronizada => estado == EstadoSync.sincronizado;

  /// Metadatos en el formato que espera `ImagenesPosteService`.
  Map<String, dynamic> get metadatosParaSubida => {
    'utm_este': utmEste ?? '',
    'utm_norte': utmNorte ?? '',
    'zona': zona ?? '',
    'fecha': fechaCaptura ?? '',
    'uuid': uuid,
    'checksum': checksum ?? '',
  };
}

/// Única puerta de entrada para las fotografías de inspección.
///
/// ## Invariante que garantiza esta clase
///
/// **La foto se copia a almacenamiento permanente y se registra en SQLite
/// ANTES de intentar cualquier subida — haya internet o no.** Si la subida
/// falla, se pierde el intento, nunca la fotografía.
///
/// La versión anterior solo guardaba en SQLite en la rama sin conexión: con
/// internet, un error de red hacía desaparecer las 22 fotos de una torre.
class FotosRepositorio {
  final DatabaseHelper _db;
  final AlmacenamientoFotos _almacen;
  final Uuid _uuid;

  FotosRepositorio({
    DatabaseHelper? db,
    AlmacenamientoFotos? almacen,
  })  : _db = db ?? DatabaseHelper(),
        _almacen = almacen ?? AlmacenamientoFotos(),
        _uuid = const Uuid();

  AlmacenamientoFotos get almacen => _almacen;

  // ===========================================================================
  // Captura
  // ===========================================================================

  /// Persiste una captura y la deja en cola de envío.
  ///
  /// Orden estricto de operaciones:
  ///   1. copiar el archivo a almacenamiento permanente y verificar la copia;
  ///   2. recién entonces insertar la fila en SQLite con estado `pending`.
  ///
  /// Si el paso 1 falla, no queda ninguna fila apuntando a la nada.
  /// Si el paso 2 falla, se borra la copia para no dejar archivos huérfanos.
  ///
  /// Reemplazar una foto ya existente del mismo `nombre_foto` conserva el
  /// UUID y borra el archivo antiguo solo después de que el nuevo esté
  /// verificado en disco.
  Future<FotoLocal> registrarCaptura({
    required File archivoTemporal,
    required int posteId,
    required String nombreFoto,
    int? proyectoId,
    String? linea,
    double? latitud,
    double? longitud,
    double? precisionGps,
    String? utmEste,
    String? utmNorte,
    String? zona,
    DateTime? fechaCaptura,
  }) async {
    final db = await _db.database;
    final anterior = await _filaPorNombre(db, posteId, nombreFoto);
    final uuid = (anterior?['uuid'] as String?)?.isNotEmpty == true
        ? anterior!['uuid'] as String
        : _uuid.v4();

    // 1) Copia durable verificada. Lanza si no se puede garantizar.
    final persistida = await _almacen.persistir(
      origen: archivoTemporal,
      posteId: posteId,
      nombreFoto: nombreFoto,
      uuid: uuid,
      proyectoId: proyectoId,
    );

    final ahora = DateTime.now();
    final valores = <String, dynamic>{
      'uuid': uuid,
      'poste_id': posteId,
      'proyecto_id': proyectoId,
      'linea': linea,
      'nombre_foto': nombreFoto,
      'ruta_archivo': persistida.ruta,
      'tamano_original': persistida.tamanoBytes,
      'checksum': persistida.checksum,
      'formato': _formatoDe(persistida.ruta),
      'latitud': latitud,
      'longitud': longitud,
      'precision_gps': precisionGps,
      'utm_este': utmEste,
      'utm_norte': utmNorte,
      'zona': zona,
      'fecha_captura': (fechaCaptura ?? ahora).toIso8601String(),
      'fecha_inspeccion': (fechaCaptura ?? ahora).toIso8601String(),
      'creado_en': ahora.toIso8601String(),
      'estado': EstadoSync.pendiente,
      'sincronizada': 0,
      'intentos': 0,
      'ultimo_error': null,
      'fecha_ultimo_intento': null,
      'id_remoto': null,
    };

    int id;
    try {
      if (anterior != null) {
        id = anterior['id'] as int;
        await db.update('imagenes_poste_local', valores,
            where: 'id = ?', whereArgs: [id]);
      } else {
        id = await db.insert('imagenes_poste_local', valores,
            conflictAlgorithm: ConflictAlgorithm.replace);
      }
    } catch (e) {
      // El registro es la fuente de verdad: sin fila, el archivo no sirve.
      await _almacen.eliminar(persistida.ruta);
      rethrow;
    }

    // 2) Ahora sí es seguro retirar la copia antigua.
    final rutaAnterior = anterior?['ruta_archivo']?.toString();
    if (rutaAnterior != null &&
        rutaAnterior.isNotEmpty &&
        rutaAnterior != persistida.ruta) {
      await _almacen.eliminar(rutaAnterior);
    }

    final fila = await db.query('imagenes_poste_local',
        where: 'id = ?', whereArgs: [id], limit: 1);
    return FotoLocal.desdeFila(fila.first);
  }

  // ===========================================================================
  // Lectura
  // ===========================================================================

  /// Todas las fotos registradas de un poste, en cualquier estado.
  /// Es lo que permite que reabrir una estructura recupere el trabajo previo
  /// en lugar de pedir las 22 fotos otra vez.
  Future<List<FotoLocal>> fotosDePoste(int posteId) async {
    final db = await _db.database;
    final filas = await db.query(
      'imagenes_poste_local',
      where: 'poste_id = ?',
      whereArgs: [posteId],
      orderBy: 'nombre_foto ASC',
    );
    return filas.map(FotoLocal.desdeFila).toList();
  }

  /// Fotos de un poste que todavía deben enviarse.
  Future<List<FotoLocal>> pendientesDePoste(int posteId) async {
    final db = await _db.database;
    final marcadores = List.filled(EstadoSync.enviables.length, '?').join(',');
    final filas = await db.query(
      'imagenes_poste_local',
      where: 'poste_id = ? AND estado IN ($marcadores)',
      whereArgs: [posteId, ...EstadoSync.enviables],
      orderBy: 'id ASC',
    );
    return filas.map(FotoLocal.desdeFila).toList();
  }

  /// Conteo por estado para el resumen de sincronización.
  Future<Map<String, int>> resumenPorEstado({int? proyectoId}) async {
    final db = await _db.database;
    final filas = await db.rawQuery(
      proyectoId == null
          ? 'SELECT estado, COUNT(*) AS n FROM imagenes_poste_local GROUP BY estado'
          : 'SELECT i.estado AS estado, COUNT(*) AS n '
              'FROM imagenes_poste_local i '
              'JOIN postes p ON p.id = i.poste_id '
              'WHERE p.proyecto_id = ? GROUP BY i.estado',
      proyectoId == null ? null : [proyectoId],
    );
    return {
      for (final f in filas)
        (f['estado'] ?? EstadoSync.pendiente).toString(): (f['n'] as int?) ?? 0,
    };
  }

  // ===========================================================================
  // Transiciones de estado
  // ===========================================================================

  Future<void> marcarSubiendo(Iterable<int> ids) async {
    if (ids.isEmpty) return;
    final db = await _db.database;
    await db.update(
      'imagenes_poste_local',
      {
        'estado': EstadoSync.subiendo,
        'fecha_ultimo_intento': DateTime.now().toIso8601String(),
      },
      where: 'id IN (${List.filled(ids.length, '?').join(',')})',
      whereArgs: ids.toList(),
    );
  }

  /// Marca como sincronizada. **Solo debe llamarse con confirmación real del
  /// servidor.** Es el único camino a `synced`.
  Future<void> marcarSincronizada(int id, {String? idRemoto}) async {
    final db = await _db.database;
    await db.update(
      'imagenes_poste_local',
      {
        'estado': EstadoSync.sincronizado,
        'sincronizada': 1,
        'fecha_subida': DateTime.now().toIso8601String(),
        'ultimo_error': null,
        if (idRemoto != null) 'id_remoto': idRemoto,
      },
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  /// Marca el intento como fallido conservando la foto en cola.
  Future<void> marcarFallida(int id, String error) async {
    final db = await _db.database;
    final actual = await db.query('imagenes_poste_local',
        columns: ['intentos'], where: 'id = ?', whereArgs: [id], limit: 1);
    final intentos = actual.isEmpty ? 0 : (actual.first['intentos'] as int?) ?? 0;

    await db.update(
      'imagenes_poste_local',
      {
        'estado': EstadoSync.fallido,
        'sincronizada': 0,
        'intentos': intentos + 1,
        'ultimo_error': _recortarError(error),
        'fecha_ultimo_intento': DateTime.now().toIso8601String(),
      },
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  /// Devuelve a la cola las fotos que quedaron en `uploading` porque la app
  /// murió a mitad de una subida. Se llama al arrancar.
  Future<int> recuperarSubidasInterrumpidas() async {
    final db = await _db.database;
    return db.update(
      'imagenes_poste_local',
      {
        'estado': EstadoSync.pendiente,
        'ultimo_error': 'Subida interrumpida: la app se cerró durante el envío.',
      },
      where: 'estado = ?',
      whereArgs: [EstadoSync.subiendo],
    );
  }

  /// Detecta filas cuyo archivo ya no existe en disco (caché purgada por
  /// Android en versiones anteriores, o borrado externo) y las marca como
  /// fallidas con un mensaje claro, en lugar de fingir que están bien.
  ///
  /// Devuelve cuántas filas resultaron inválidas.
  Future<int> verificarArchivos({int? posteId}) async {
    final db = await _db.database;
    final filas = await db.query(
      'imagenes_poste_local',
      columns: ['id', 'ruta_archivo', 'estado'],
      where: posteId == null ? null : 'poste_id = ?',
      whereArgs: posteId == null ? null : [posteId],
    );

    var invalidas = 0;
    for (final f in filas) {
      final ruta = f['ruta_archivo']?.toString();
      if (await _almacen.existe(ruta)) continue;
      invalidas++;
      await db.update(
        'imagenes_poste_local',
        {
          'estado': EstadoSync.fallido,
          'ultimo_error':
              'El archivo de la fotografía ya no está en el teléfono. '
                  'Hay que volver a tomarla.',
        },
        where: 'id = ?',
        whereArgs: [f['id']],
      );
    }
    return invalidas;
  }

  /// Elimina una fotografía por decisión explícita del inspector.
  Future<void> eliminar(int id) async {
    final db = await _db.database;
    final filas = await db.query('imagenes_poste_local',
        columns: ['ruta_archivo'], where: 'id = ?', whereArgs: [id], limit: 1);
    await db.delete('imagenes_poste_local', where: 'id = ?', whereArgs: [id]);
    if (filas.isNotEmpty) {
      await _almacen.eliminar(filas.first['ruta_archivo']?.toString());
    }
  }

  // ===========================================================================
  // Internos
  // ===========================================================================

  Future<Map<String, dynamic>?> _filaPorNombre(
    Database db,
    int posteId,
    String nombreFoto,
  ) async {
    final filas = await db.query(
      'imagenes_poste_local',
      where: 'poste_id = ? AND nombre_foto = ?',
      whereArgs: [posteId, nombreFoto],
      limit: 1,
    );
    return filas.isEmpty ? null : filas.first;
  }

  String _formatoDe(String ruta) {
    final i = ruta.lastIndexOf('.');
    return i < 0 ? 'jpg' : ruta.substring(i + 1).toLowerCase();
  }

  String _recortarError(String error) =>
      error.length <= 500 ? error : '${error.substring(0, 500)}…';
}
