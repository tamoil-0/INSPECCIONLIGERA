import 'dart:convert';

import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

import '../core/estados_sync.dart';
import '../database/database_helper.dart';

/// Borrador del formulario técnico de un poste, guardado en el teléfono.
class BorradorFormulario {
  final int id;
  final String uuid;
  final int posteId;
  final Map<String, dynamic> datos;
  final List<Map<String, dynamic>> rst;
  final String estado;
  final int intentos;
  final String? ultimoError;
  final DateTime? creadoEn;
  final DateTime? actualizadoEn;

  const BorradorFormulario({
    required this.id,
    required this.uuid,
    required this.posteId,
    required this.datos,
    required this.rst,
    required this.estado,
    required this.intentos,
    this.ultimoError,
    this.creadoEn,
    this.actualizadoEn,
  });

  bool get estaSincronizado => estado == EstadoSync.sincronizado;

  /// Claves del tablero RST en el formato `seccion|atributo|fase` que usa
  /// `FormularioModal.seleccionados`.
  Map<String, bool> get seleccionadosRst => {
    for (final r in rst)
      '${r['seccion']}|${r['atributo']}|${r['fase']}': true,
  };
}

/// Persistencia de los borradores del formulario técnico.
///
/// ## Invariantes
///
/// * **Un solo borrador vigente por poste.** La migración v2 añadió un índice
///   único sobre `poste_id`; antes se insertaba una fila nueva en cada envío y
///   la cola crecía sin control.
/// * **Guardar siempre gana sobre enviar.** El borrador se escribe en SQLite
///   antes de cualquier intento de red.
/// * **Solo el servidor puede marcar `synced`.**
/// * **Al abrir el formulario se recupera el borrador previo**, de modo que
///   "Editar" no sobrescribe la inspección anterior con valores por defecto.
class BorradoresRepositorio {
  final DatabaseHelper _db;
  final Uuid _uuid;

  BorradoresRepositorio({DatabaseHelper? db})
      : _db = db ?? DatabaseHelper(),
        _uuid = const Uuid();

  /// Guarda (o actualiza) el borrador del poste junto con su tablero RST.
  ///
  /// Todo ocurre en una transacción: o queda el formulario con su RST, o no
  /// queda nada a medias.
  Future<BorradorFormulario> guardar({
    required int posteId,
    required Map<String, dynamic> datos,
    required List<Map<String, dynamic>> rst,
    bool marcarParaEnvio = true,
  }) async {
    final db = await _db.database;
    final ahora = DateTime.now().toIso8601String();

    await db.transaction((txn) async {
      final existente = await txn.query(
        'formularios_pendientes',
        columns: ['id', 'uuid', 'estado'],
        where: 'poste_id = ?',
        whereArgs: [posteId],
        limit: 1,
      );

      final uuid = existente.isNotEmpty &&
              (existente.first['uuid'] as String?)?.isNotEmpty == true
          ? existente.first['uuid'] as String
          : _uuid.v4();

      final valores = <String, dynamic>{
        'poste_id': posteId,
        'uuid': uuid,
        'datos_json': jsonEncode(datos),
        'enviado': 0,
        'estado': marcarParaEnvio ? EstadoSync.pendiente : EstadoSync.local,
        'ultimo_error': null,
        'actualizado_en': ahora,
      };

      if (existente.isEmpty) {
        valores['creado_en'] = ahora;
        valores['intentos'] = 0;
        await txn.insert('formularios_pendientes', valores,
            conflictAlgorithm: ConflictAlgorithm.replace);
      } else {
        await txn.update('formularios_pendientes', valores,
            where: 'id = ?', whereArgs: [existente.first['id']]);
      }

      // El tablero RST se reemplaza completo: es la foto exacta de lo que el
      // inspector tiene marcado ahora mismo.
      await txn.delete('poste_secciones_rst',
          where: 'poste_id = ?', whereArgs: [posteId]);
      for (final registro in rst) {
        await txn.insert(
          'poste_secciones_rst',
          {
            'poste_id': posteId,
            'seccion': registro['seccion'],
            'atributo': registro['atributo'],
            'fase': registro['fase'],
            'sincronizado': 0,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
    });

    final borrador = await obtener(posteId);
    return borrador!;
  }

  /// Recupera el borrador vigente del poste, o `null` si no hay ninguno.
  Future<BorradorFormulario?> obtener(int posteId) async {
    final db = await _db.database;
    final filas = await db.query(
      'formularios_pendientes',
      where: 'poste_id = ?',
      whereArgs: [posteId],
      orderBy: 'id DESC',
      limit: 1,
    );
    if (filas.isEmpty) return null;
    final f = filas.first;

    Map<String, dynamic> datos;
    try {
      datos = Map<String, dynamic>.from(
        jsonDecode((f['datos_json'] ?? '{}').toString()) as Map,
      );
    } catch (_) {
      // Un JSON corrupto no debe impedir abrir el formulario: se trata como
      // borrador vacío y se conserva la fila para inspección posterior.
      datos = <String, dynamic>{};
    }

    final rst = await db.query(
      'poste_secciones_rst',
      columns: ['seccion', 'atributo', 'fase'],
      where: 'poste_id = ?',
      whereArgs: [posteId],
      orderBy: 'seccion ASC, fase ASC',
    );

    return BorradorFormulario(
      id: f['id'] as int,
      uuid: (f['uuid'] ?? '').toString(),
      posteId: posteId,
      datos: datos,
      rst: rst.map((r) => Map<String, dynamic>.from(r)).toList(),
      estado: (f['estado'] ?? EstadoSync.pendiente).toString(),
      intentos: (f['intentos'] as int?) ?? 0,
      ultimoError: f['ultimo_error'] as String?,
      creadoEn: DateTime.tryParse((f['creado_en'] ?? '').toString()),
      actualizadoEn: DateTime.tryParse((f['actualizado_en'] ?? '').toString()),
    );
  }

  Future<void> marcarSubiendo(int posteId) async {
    final db = await _db.database;
    await db.update(
      'formularios_pendientes',
      {
        'estado': EstadoSync.subiendo,
        'fecha_ultimo_intento': DateTime.now().toIso8601String(),
      },
      where: 'poste_id = ?',
      whereArgs: [posteId],
    );
  }

  /// Único camino a `synced`: exige confirmación del servidor.
  Future<void> marcarSincronizado(int posteId, {String? idRemoto}) async {
    final db = await _db.database;
    await db.update(
      'formularios_pendientes',
      {
        'estado': EstadoSync.sincronizado,
        'enviado': 1,
        'ultimo_error': null,
        if (idRemoto != null) 'id_remoto': idRemoto,
      },
      where: 'poste_id = ?',
      whereArgs: [posteId],
    );
    await db.update(
      'poste_secciones_rst',
      {'sincronizado': 1},
      where: 'poste_id = ?',
      whereArgs: [posteId],
    );
  }

  Future<void> marcarFallido(int posteId, String error) async {
    final db = await _db.database;
    final actual = await db.query('formularios_pendientes',
        columns: ['intentos'],
        where: 'poste_id = ?',
        whereArgs: [posteId],
        limit: 1);
    final intentos =
        actual.isEmpty ? 0 : (actual.first['intentos'] as int?) ?? 0;

    await db.update(
      'formularios_pendientes',
      {
        'estado': EstadoSync.fallido,
        'enviado': 0,
        'intentos': intentos + 1,
        'ultimo_error':
            error.length <= 500 ? error : '${error.substring(0, 500)}…',
        'fecha_ultimo_intento': DateTime.now().toIso8601String(),
      },
      where: 'poste_id = ?',
      whereArgs: [posteId],
    );
  }

  /// Devuelve a la cola los formularios que quedaron en `uploading` porque la
  /// app se cerró durante el envío.
  Future<int> recuperarEnviosInterrumpidos() async {
    final db = await _db.database;
    return db.update(
      'formularios_pendientes',
      {
        'estado': EstadoSync.pendiente,
        'ultimo_error': 'Envío interrumpido: la app se cerró durante el envío.',
      },
      where: 'estado = ?',
      whereArgs: [EstadoSync.subiendo],
    );
  }

  /// Borradores pendientes de enviar, con filtro por ámbito.
  Future<List<BorradorFormulario>> pendientes({
    int? proyectoId,
    String? linea,
  }) async {
    final db = await _db.database;
    final condiciones = <String>[
      'f.estado IN (${List.filled(EstadoSync.enviables.length, '?').join(',')})',
    ];
    final args = <Object?>[...EstadoSync.enviables];

    if (proyectoId != null) {
      condiciones.add('p.proyecto_id = ?');
      args.add(proyectoId);
    }
    if (linea != null) {
      condiciones.add('p.linea = ?');
      args.add(linea);
    }

    final filas = await db.rawQuery('''
      SELECT f.poste_id FROM formularios_pendientes f
      JOIN postes p ON p.id = f.poste_id
      WHERE ${condiciones.join(' AND ')}
      ORDER BY f.intentos ASC, f.id ASC
    ''', args);

    final resultado = <BorradorFormulario>[];
    for (final fila in filas) {
      final borrador = await obtener(fila['poste_id'] as int);
      if (borrador != null) resultado.add(borrador);
    }
    return resultado;
  }

  /// Fecha del último intento, para calcular el backoff.
  Future<DateTime?> ultimoIntentoDe(int posteId) async {
    final db = await _db.database;
    final filas = await db.query(
      'formularios_pendientes',
      columns: ['fecha_ultimo_intento'],
      where: 'poste_id = ?',
      whereArgs: [posteId],
      limit: 1,
    );
    if (filas.isEmpty) return null;
    return DateTime.tryParse((filas.first['fecha_ultimo_intento'] ?? '').toString());
  }

  Future<Map<String, int>> resumenPorEstado({int? proyectoId}) async {
    final db = await _db.database;
    final filas = await db.rawQuery(
      proyectoId == null
          ? 'SELECT estado, COUNT(*) AS n FROM formularios_pendientes GROUP BY estado'
          : 'SELECT f.estado AS estado, COUNT(*) AS n '
              'FROM formularios_pendientes f '
              'JOIN postes p ON p.id = f.poste_id '
              'WHERE p.proyecto_id = ? GROUP BY f.estado',
      proyectoId == null ? null : [proyectoId],
    );
    return {
      for (final f in filas)
        (f['estado'] ?? EstadoSync.pendiente).toString(): (f['n'] as int?) ?? 0,
    };
  }
}
