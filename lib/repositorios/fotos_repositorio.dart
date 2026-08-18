import 'dart:io';

import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import '../core/almacenamiento_fotos.dart';
import '../core/estados_sync.dart';
import '../database/database_helper.dart';

/// Resultado de una limpieza solicitada expresamente por el inspector.
class ResultadoLiberacionEspacio {
  final int archivos;
  final int bytes;

  const ResultadoLiberacionEspacio({
    required this.archivos,
    required this.bytes,
  });
}

/// Una fotografía de inspección tal como vive en el teléfono.
class FotoLocal {
  final int id;
  final String uuid;
  final int posteId;
  final String nombreFoto;
  final String rutaArchivo;
  final String? rutaOriginal;
  final String? rutaMiniatura;
  final int? ancho;
  final int? alto;
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
    this.rutaOriginal,
    this.rutaMiniatura,
    this.ancho,
    this.alto,
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
    rutaOriginal: f['ruta_original'] as String?,
    rutaMiniatura: f['ruta_miniatura'] as String?,
    ancho: f['ancho'] as int?,
    alto: f['alto'] as int?,
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
    tamanoBytes:
        (f['tamano_optimizado'] as int?) ?? (f['tamano_original'] as int?),
    checksum: f['checksum'] as String?,
  );

  File get archivo => File(rutaArchivo);
  bool get estaSincronizada => estado == EstadoSync.sincronizado;

  /// Archivo a mostrar en listas: la miniatura si existe, y si no la propia
  /// fotografía. Evita decodificar una imagen de 12 MP para pintar 55 píxeles.
  File get archivoParaMiniatura {
    final m = rutaMiniatura;
    if (m != null && m.isNotEmpty) {
      final f = File(m);
      if (f.existsSync()) return f;
    }
    return archivo;
  }

  bool get estaOptimizada => rutaMiniatura != null && rutaMiniatura!.isNotEmpty;

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
/// internet, un error de red hacía desaparecer todas las fotos de una torre.
class FotosRepositorio {
  final DatabaseHelper _db;
  final AlmacenamientoFotos _almacen;
  final Uuid _uuid;

  FotosRepositorio({DatabaseHelper? db, AlmacenamientoFotos? almacen})
    : _db = db ?? DatabaseHelper(),
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
      // La optimización de la captura anterior deja de ser válida.
      'ruta_original': null,
      'ruta_miniatura': null,
      'tamano_optimizado': null,
      'ancho': null,
      'alto': null,
    };

    int id;
    try {
      if (anterior != null) {
        id = anterior['id'] as int;
        await db.update(
          'imagenes_poste_local',
          valores,
          where: 'id = ?',
          whereArgs: [id],
        );
      } else {
        id = await db.insert(
          'imagenes_poste_local',
          valores,
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
    } catch (e) {
      // El registro es la fuente de verdad: sin fila, el archivo no sirve.
      await _almacen.eliminar(persistida.ruta);
      rethrow;
    }

    // 2) Ahora sí es seguro retirar la copia antigua y sus variantes
    //    (optimizada y miniatura), que corresponden a la foto reemplazada.
    if (anterior != null) {
      for (final clave in ['ruta_archivo', 'ruta_original', 'ruta_miniatura']) {
        final ruta = anterior[clave]?.toString();
        if (ruta != null && ruta.isNotEmpty && ruta != persistida.ruta) {
          await _almacen.eliminar(ruta);
        }
      }
    }

    final fila = await db.query(
      'imagenes_poste_local',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    return FotoLocal.desdeFila(fila.first);
  }

  // ===========================================================================
  // Optimización
  // ===========================================================================

  /// Guarda en la fila el resultado de optimizar la fotografía.
  ///
  /// Se llama **después** de que la foto ya esté registrada y a salvo: si la
  /// optimización falla, la fila sigue apuntando al archivo original y la foto
  /// se sube tal cual. La optimización nunca puede costar una fotografía.
  ///
  /// El checksum se **recalcula** sobre el archivo que efectivamente se va a
  /// subir: si se enviara el checksum del original y el backend deduplicara por
  /// él, la comparación no cuadraría nunca.
  Future<FotoLocal> aplicarOptimizacion({
    required int id,
    required String rutaSubible,
    String? rutaOriginal,
    String? rutaMiniatura,
    required int tamanoSubible,
    int? tamanoOriginal,
    int? ancho,
    int? alto,
  }) async {
    final db = await _db.database;
    final archivo = File(rutaSubible);
    if (!await archivo.exists()) {
      throw StateError(
        'La optimización apunta a un archivo que no existe: $rutaSubible',
      );
    }

    final checksum = await _almacen.calcularChecksum(archivo);

    await db.update(
      'imagenes_poste_local',
      {
        'ruta_archivo': rutaSubible,
        'ruta_original': rutaOriginal,
        'ruta_miniatura': rutaMiniatura,
        'tamano_optimizado': tamanoSubible,
        if (tamanoOriginal != null) 'tamano_original': tamanoOriginal,
        'ancho': ancho,
        'alto': alto,
        'checksum': checksum,
        'formato': _formatoDe(rutaSubible),
      },
      where: 'id = ?',
      whereArgs: [id],
    );

    final fila = await db.query(
      'imagenes_poste_local',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    return FotoLocal.desdeFila(fila.first);
  }

  /// Actualiza únicamente la georreferencia de una fotografía ya guardada.
  /// Sirve para reintentar el GPS sin obligar al inspector a repetir una foto
  /// técnicamente válida.
  Future<FotoLocal> actualizarUbicacion({
    required int id,
    required double latitud,
    required double longitud,
    required double precisionGps,
    required String utmEste,
    required String utmNorte,
    required String zona,
  }) async {
    final db = await _db.database;
    await db.update(
      'imagenes_poste_local',
      {
        'latitud': latitud,
        'longitud': longitud,
        'precision_gps': precisionGps,
        'utm_este': utmEste,
        'utm_norte': utmNorte,
        'zona': zona,
        // Cambiar metadatos de una foto confirmada exige volver a enviarla.
        'estado': EstadoSync.pendiente,
        'sincronizada': 0,
        'ultimo_error': null,
      },
      where: 'id = ?',
      whereArgs: [id],
    );
    final fila = await db.query(
      'imagenes_poste_local',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    if (fila.isEmpty) throw StateError('La fotografía $id ya no existe.');
    return FotoLocal.desdeFila(fila.first);
  }

  /// Fotos de un poste que aún no tienen miniatura: son las que quedaron sin
  /// optimizar (por ejemplo porque la app se cerró antes de que la cola llegara
  /// a ellas). Permite reintentarlo al reabrir la estructura.
  Future<List<FotoLocal>> sinOptimizar(int posteId) async {
    final db = await _db.database;
    final filas = await db.query(
      'imagenes_poste_local',
      where:
          'poste_id = ? AND estado != ? AND (ruta_miniatura IS NULL OR ruta_miniatura = ?)',
      whereArgs: [posteId, EstadoSync.sincronizado, ''],
      orderBy: 'id ASC',
    );
    return filas.map(FotoLocal.desdeFila).toList();
  }

  // ===========================================================================
  // Lectura
  // ===========================================================================

  /// Todas las fotos registradas de un poste, en cualquier estado.
  /// Es lo que permite que reabrir una estructura recupere el trabajo previo
  /// en lugar de pedir las 28 fotos otra vez.
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

  /// Fotos pendientes de enviar, con filtro por ámbito.
  ///
  /// Se hace JOIN con `postes` en lugar de usar las columnas `proyecto_id` y
  /// `linea` de la propia tabla porque esas se poblaron a partir de la v2: las
  /// filas heredadas las tienen a NULL y quedarían fuera del filtro.
  ///
  /// [fechaLimite] permite excluir lo que todavía está en espera de backoff.
  Future<List<FotoLocal>> pendientes({
    int? posteId,
    int? proyectoId,
    String? linea,
  }) async {
    final db = await _db.database;
    final condiciones = <String>[
      'i.estado IN (${List.filled(EstadoSync.enviables.length, '?').join(',')})',
    ];
    final args = <Object?>[...EstadoSync.enviables];

    if (posteId != null) {
      condiciones.add('i.poste_id = ?');
      args.add(posteId);
    }
    if (proyectoId != null) {
      condiciones.add('p.proyecto_id = ?');
      args.add(proyectoId);
    }
    if (linea != null) {
      condiciones.add('p.linea = ?');
      args.add(linea);
    }

    final filas = await db.rawQuery('''
      SELECT i.* FROM imagenes_poste_local i
      JOIN postes p ON p.id = i.poste_id
      WHERE ${condiciones.join(' AND ')}
      ORDER BY i.intentos ASC, i.id ASC
    ''', args);
    return filas.map(FotoLocal.desdeFila).toList();
  }

  /// Identificadores de postes con fotografías pendientes en el ámbito dado.
  Future<List<int>> postesConFotosPendientes({
    int? proyectoId,
    String? linea,
  }) async {
    final fotos = await pendientes(proyectoId: proyectoId, linea: linea);
    return fotos.map((f) => f.posteId).toSet().toList()..sort();
  }

  /// Fecha del último intento de una foto, para calcular el backoff.
  Future<DateTime?> ultimoIntentoDe(int id) async {
    final db = await _db.database;
    final filas = await db.query(
      'imagenes_poste_local',
      columns: ['fecha_ultimo_intento'],
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    if (filas.isEmpty) return null;
    return DateTime.tryParse(
      (filas.first['fecha_ultimo_intento'] ?? '').toString(),
    );
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
    final actual = await db.query(
      'imagenes_poste_local',
      columns: ['intentos'],
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    final intentos = actual.isEmpty
        ? 0
        : (actual.first['intentos'] as int?) ?? 0;

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
        'ultimo_error':
            'Subida interrumpida: la app se cerró durante el envío.',
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

  /// Elimina una fotografía por decisión explícita del inspector, con todas
  /// sus variantes (original, optimizada y miniatura).
  Future<void> eliminar(int id) async {
    final db = await _db.database;
    final filas = await db.query(
      'imagenes_poste_local',
      columns: ['ruta_archivo', 'ruta_original', 'ruta_miniatura'],
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    await db.delete('imagenes_poste_local', where: 'id = ?', whereArgs: [id]);
    if (filas.isEmpty) return;
    for (final clave in ['ruta_archivo', 'ruta_original', 'ruta_miniatura']) {
      await _almacen.eliminar(filas.first[clave]?.toString());
    }
  }

  /// Libera únicamente originales de fotografías ya confirmadas por el
  /// servidor. La imagen optimizada que se subió y su miniatura se conservan.
  ///
  /// Esta limpieza masiva solo se ejecuta por decisión explícita desde Ajustes.
  /// La política `liberarTrasSincronizar` usa la variante individual cuando el
  /// servidor confirma una foto. Si un archivo no se puede borrar, su ruta
  /// tampoco se limpia de SQLite, de modo que se pueda reintentar después.
  Future<ResultadoLiberacionEspacio> liberarOriginalesSincronizados() async {
    final db = await _db.database;
    final filas = await db.query(
      'imagenes_poste_local',
      columns: ['id', 'ruta_archivo', 'ruta_original'],
      where: 'estado = ? AND ruta_original IS NOT NULL AND ruta_original <> ?',
      whereArgs: [EstadoSync.sincronizado, ''],
    );

    var archivos = 0;
    var bytes = 0;
    for (final fila in filas) {
      final liberados = await liberarOriginalSincronizado(fila['id'] as int);
      if (liberados == null) continue;
      archivos++;
      bytes += liberados;
    }

    return ResultadoLiberacionEspacio(archivos: archivos, bytes: bytes);
  }

  /// Libera el original de una sola foto únicamente si ya está sincronizada.
  /// Devuelve los bytes liberados, o `null` si no había nada seguro que borrar.
  Future<int?> liberarOriginalSincronizado(int id) async {
    final db = await _db.database;
    final filas = await db.query(
      'imagenes_poste_local',
      columns: ['ruta_archivo', 'ruta_original'],
      where: 'id = ? AND estado = ?',
      whereArgs: [id, EstadoSync.sincronizado],
      limit: 1,
    );
    if (filas.isEmpty) return null;

    final original = filas.first['ruta_original']?.toString();
    final subible = filas.first['ruta_archivo']?.toString();
    if (original == null || original.isEmpty || original == subible) {
      return null;
    }

    final archivo = File(original);
    var tamano = 0;
    if (await archivo.exists()) {
      try {
        tamano = await archivo.length();
      } catch (_) {
        tamano = 0;
      }
      if (!await _almacen.eliminar(original)) return null;
    }

    await db.update(
      'imagenes_poste_local',
      {'ruta_original': null, 'tamano_original': null},
      where: 'id = ?',
      whereArgs: [id],
    );
    return tamano;
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
