import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/core/almacenamiento_fotos.dart';
import 'package:pruebaoffline/core/estados_sync.dart';
import 'package:pruebaoffline/database/database_helper.dart';
import 'package:pruebaoffline/repositorios/borradores_repositorio.dart';
import 'package:pruebaoffline/repositorios/fotos_repositorio.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Pruebas del invariante central de P0:
///
///   **Ningún dato del inspector se pierde, y nada se marca como sincronizado
///   sin confirmación del servidor.**
///
/// Cada caso reproduce un escenario real de campo.
void main() {
  sqfliteFfiInit();
  databaseFactory = databaseFactoryFfi;

  late Directory temporal;
  late FotosRepositorio fotos;
  late BorradoresRepositorio borradores;
  late AlmacenamientoFotos almacen;
  var contador = 0;

  /// Simula una foto recién capturada por `image_picker`: un archivo en la
  /// carpeta de caché de la app, que Android puede purgar.
  Future<File> crearFotoEnCache(String nombre, {int kb = 8}) async {
    final cache = Directory('${temporal.path}/cache')
      ..createSync(recursive: true);
    final f = File('${cache.path}/$nombre.jpg');
    await f.writeAsBytes(List<int>.generate(kb * 1024, (i) => i % 256));
    return f;
  }

  setUp(() async {
    contador++;
    temporal = await Directory.systemTemp.createTemp('ecoing_p0_');
    AlmacenamientoFotos.baseDePruebas = temporal;

    // Cada caso trabaja contra una base propia dentro del directorio temporal,
    // para que no queden restos entre ejecuciones.
    await databaseFactory.setDatabasesPath(temporal.path);
    DatabaseHelper.nombreArchivo = 'prueba_$contador.db';
    await DatabaseHelper.reiniciarParaPruebas();

    almacen = AlmacenamientoFotos();
    fotos = FotosRepositorio(almacen: almacen);
    borradores = BorradoresRepositorio();

    // Un poste descargado, como después del login.
    final db = await DatabaseHelper().database;
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
  });

  tearDown(() async {
    await DatabaseHelper.reiniciarParaPruebas();
    AlmacenamientoFotos.baseDePruebas = null;
    try {
      temporal.deleteSync(recursive: true);
    } catch (_) {
      // En Windows el archivo de base puede quedar retenido un instante.
    }
  });

  group('Fotografías: guardar primero, confirmar después', () {
    test('la foto se registra en la base ANTES de cualquier subida', () async {
      final cache = await crearFotoEnCache('placa');

      final registrada = await fotos.registrarCaptura(
        archivoTemporal: cache,
        posteId: 101,
        nombreFoto: 'placa',
        proyectoId: 7,
      );

      // Está en la base, y en estado pendiente: no "sincronizada".
      final enBase = await fotos.fotosDePoste(101);
      expect(enBase, hasLength(1));
      expect(enBase.first.estado, EstadoSync.pendiente);
      expect(registrada.uuid, isNotEmpty);
      expect(registrada.checksum, isNotEmpty);
    });

    test('la foto sale de la caché a almacenamiento permanente', () async {
      final cache = await crearFotoEnCache('base_torre');
      final registrada = await fotos.registrarCaptura(
        archivoTemporal: cache,
        posteId: 101,
        nombreFoto: 'base_torre',
        proyectoId: 7,
      );

      expect(
        registrada.rutaArchivo,
        isNot(contains('cache')),
        reason: 'La ruta guardada no debe apuntar a la caché del sistema.',
      );
      expect(registrada.rutaArchivo, contains('inspecciones'));
      expect(registrada.rutaArchivo, contains('poste_101'));
      expect(File(registrada.rutaArchivo).existsSync(), isTrue);
    });

    test(
      'ANDROID PURGA LA CACHÉ: la foto sobrevive porque ya fue copiada',
      () async {
        final cache = await crearFotoEnCache('mensulas');
        final registrada = await fotos.registrarCaptura(
          archivoTemporal: cache,
          posteId: 101,
          nombreFoto: 'mensulas',
          proyectoId: 7,
        );

        // El sistema vacía la caché.
        Directory('${temporal.path}/cache').deleteSync(recursive: true);

        expect(cache.existsSync(), isFalse);
        expect(
          File(registrada.rutaArchivo).existsSync(),
          isTrue,
          reason: 'La copia permanente debe seguir ahí.',
        );
      },
    );

    test('el checksum corresponde al contenido real del archivo', () async {
      final cache = await crearFotoEnCache('crucetas');
      final registrada = await fotos.registrarCaptura(
        archivoTemporal: cache,
        posteId: 101,
        nombreFoto: 'crucetas',
        proyectoId: 7,
      );

      final recalculado = await almacen.calcularChecksum(
        File(registrada.rutaArchivo),
      );
      expect(registrada.checksum, recalculado);
    });

    test('un archivo vacío se rechaza y NO deja fila en la base', () async {
      final vacio = File('${temporal.path}/vacio.jpg')..writeAsBytesSync([]);

      await expectLater(
        fotos.registrarCaptura(
          archivoTemporal: vacio,
          posteId: 101,
          nombreFoto: 'placa',
          proyectoId: 7,
        ),
        throwsA(isA<AlmacenamientoFotoException>()),
      );

      expect(
        await fotos.fotosDePoste(101),
        isEmpty,
        reason: 'Nunca debe existir una fila que apunte a un archivo inválido.',
      );
    });

    test(
      'repetir una foto reemplaza en su sitio, sin dejar huérfanos',
      () async {
        final primera = await fotos.registrarCaptura(
          archivoTemporal: await crearFotoEnCache('placa', kb: 8),
          posteId: 101,
          nombreFoto: 'placa',
          proyectoId: 7,
        );

        final segunda = await fotos.registrarCaptura(
          archivoTemporal: await crearFotoEnCache('placa_v2', kb: 16),
          posteId: 101,
          nombreFoto: 'placa',
          proyectoId: 7,
        );

        // El UUID y la fila se conservan: el slot es el mismo.
        expect(segunda.uuid, primera.uuid);
        expect(segunda.id, primera.id);
        expect(await fotos.fotosDePoste(101), hasLength(1));

        // Y en disco queda exactamente un archivo, el nuevo.
        final carpeta = await almacen.carpetaDePoste(
          posteId: 101,
          proyectoId: 7,
        );
        final archivos = carpeta.listSync().whereType<File>().toList();
        expect(archivos, hasLength(1));
        expect(archivos.first.path, segunda.rutaArchivo);
        expect(archivos.first.lengthSync(), 16 * 1024);
        expect(segunda.tamanoBytes, greaterThan(primera.tamanoBytes!));
        // Sin temporales abandonados.
        expect(
          carpeta.listSync().where((e) => e.path.endsWith('.tmp')),
          isEmpty,
        );
      },
    );

    test('si la copia nueva falla, la foto anterior sigue intacta', () async {
      final primera = await fotos.registrarCaptura(
        archivoTemporal: await crearFotoEnCache('placa', kb: 8),
        posteId: 101,
        nombreFoto: 'placa',
        proyectoId: 7,
      );

      // Intento de reemplazo con un archivo inválido (0 bytes).
      final vacio = File('${temporal.path}/roto.jpg')..writeAsBytesSync([]);
      await expectLater(
        fotos.registrarCaptura(
          archivoTemporal: vacio,
          posteId: 101,
          nombreFoto: 'placa',
          proyectoId: 7,
        ),
        throwsA(isA<AlmacenamientoFotoException>()),
      );

      // La foto buena no se tocó.
      final enDisco = File(primera.rutaArchivo);
      expect(enDisco.existsSync(), isTrue);
      expect(enDisco.lengthSync(), 8 * 1024);
      expect((await fotos.fotosDePoste(101)).first.checksum, primera.checksum);
    });
  });

  group('Fotografías: transiciones de estado', () {
    Future<FotoLocal> unaFoto(String nombre) async => fotos.registrarCaptura(
      archivoTemporal: await crearFotoEnCache(nombre),
      posteId: 101,
      nombreFoto: nombre,
      proyectoId: 7,
    );

    test('un fallo de subida NO marca la foto como sincronizada', () async {
      final foto = await unaFoto('placa');

      await fotos.marcarSubiendo([foto.id]);
      await fotos.marcarFallida(foto.id, 'HTTP 500 del servidor');

      final tras = (await fotos.fotosDePoste(101)).first;
      expect(tras.estado, EstadoSync.fallido);
      expect(tras.estaSincronizada, isFalse);
      expect(tras.intentos, 1);
      expect(tras.ultimoError, contains('500'));
    });

    test('una foto fallida sigue en la cola de pendientes', () async {
      final foto = await unaFoto('conductor');
      await fotos.marcarFallida(foto.id, 'timeout');

      final pendientes = await fotos.pendientesDePoste(101);
      expect(
        pendientes.map((f) => f.id),
        contains(foto.id),
        reason: 'Lo que falla se reintenta; no se descarta.',
      );
    });

    test('solo la confirmación explícita lleva a sincronizado', () async {
      final foto = await unaFoto('retenida');
      await fotos.marcarSincronizada(foto.id, idRemoto: 'srv-987');

      final tras = (await fotos.fotosDePoste(101)).first;
      expect(tras.estado, EstadoSync.sincronizado);
      expect(await fotos.pendientesDePoste(101), isEmpty);
    });

    test('los intentos se acumulan en cada fallo', () async {
      final foto = await unaFoto('placa');
      await fotos.marcarFallida(foto.id, 'error 1');
      await fotos.marcarFallida(foto.id, 'error 2');
      await fotos.marcarFallida(foto.id, 'error 3');

      expect((await fotos.fotosDePoste(101)).first.intentos, 3);
      expect((await fotos.fotosDePoste(101)).first.ultimoError, contains('3'));
    });

    test(
      'LA APP MUERE DURANTE LA SUBIDA: al arrancar vuelve a la cola',
      () async {
        final foto = await unaFoto('placa');
        await fotos.marcarSubiendo([foto.id]);
        expect(
          (await fotos.fotosDePoste(101)).first.estado,
          EstadoSync.subiendo,
        );

        // Reinicio de la app.
        final recuperadas = await fotos.recuperarSubidasInterrumpidas();

        expect(recuperadas, 1);
        final tras = (await fotos.fotosDePoste(101)).first;
        expect(tras.estado, EstadoSync.pendiente);
        expect(tras.ultimoError, contains('interrumpida'));
        expect(await fotos.pendientesDePoste(101), hasLength(1));
      },
    );

    test(
      'un archivo borrado externamente se marca como fallido, no como bueno',
      () async {
        final foto = await unaFoto('placa');
        File(foto.rutaArchivo).deleteSync();

        final invalidas = await fotos.verificarArchivos(posteId: 101);

        expect(invalidas, 1);
        final tras = (await fotos.fotosDePoste(101)).first;
        expect(tras.estado, EstadoSync.fallido);
        expect(tras.ultimoError, contains('ya no está'));
      },
    );

    test('el resumen por estado cuenta correctamente', () async {
      final a = await unaFoto('placa');
      final b = await unaFoto('crucetas');
      await unaFoto('conductor');

      await fotos.marcarSincronizada(a.id);
      await fotos.marcarFallida(b.id, 'x');

      final resumen = await fotos.resumenPorEstado();
      expect(resumen[EstadoSync.sincronizado], 1);
      expect(resumen[EstadoSync.fallido], 1);
      expect(resumen[EstadoSync.pendiente], 1);
    });

    test('eliminar una foto borra fila y archivo', () async {
      final foto = await unaFoto('placa');
      final ruta = foto.rutaArchivo;

      await fotos.eliminar(foto.id);

      expect(await fotos.fotosDePoste(101), isEmpty);
      expect(File(ruta).existsSync(), isFalse);
    });

    test(
      'liberar espacio borra solo el original de una foto confirmada',
      () async {
        final foto = await unaFoto('placa');
        final original = File(foto.rutaArchivo);
        final optimizada = File('${original.path}_opt.jpg');
        await original.copy(optimizada.path);

        await fotos.aplicarOptimizacion(
          id: foto.id,
          rutaSubible: optimizada.path,
          rutaOriginal: original.path,
          tamanoSubible: await optimizada.length(),
          tamanoOriginal: await original.length(),
        );
        await fotos.marcarSincronizada(foto.id);

        final resultado = await fotos.liberarOriginalesSincronizados();
        final tras = (await fotos.fotosDePoste(101)).single;

        expect(resultado.archivos, 1);
        expect(resultado.bytes, greaterThan(0));
        expect(original.existsSync(), isFalse);
        expect(optimizada.existsSync(), isTrue);
        expect(tras.rutaArchivo, optimizada.path);
        expect(tras.rutaOriginal, isNull);
        expect(tras.estaSincronizada, isTrue);
      },
    );

    test('liberar espacio nunca toca originales pendientes', () async {
      final foto = await unaFoto('conductor');
      final original = File(foto.rutaArchivo);
      final optimizada = File('${original.path}_opt.jpg');
      await original.copy(optimizada.path);
      await fotos.aplicarOptimizacion(
        id: foto.id,
        rutaSubible: optimizada.path,
        rutaOriginal: original.path,
        tamanoSubible: await optimizada.length(),
        tamanoOriginal: await original.length(),
      );

      final resultado = await fotos.liberarOriginalesSincronizados();

      expect(resultado.archivos, 0);
      expect(original.existsSync(), isTrue);
      expect(optimizada.existsSync(), isTrue);
      expect(
        (await fotos.fotosDePoste(101)).single.rutaOriginal,
        original.path,
      );
    });

    test(
      'actualizar GPS conserva la foto y vuelve a ponerla en cola',
      () async {
        final foto = await unaFoto('retenida');
        await fotos.marcarSincronizada(foto.id);

        final actualizada = await fotos.actualizarUbicacion(
          id: foto.id,
          latitud: -12.0464,
          longitud: -77.0428,
          precisionGps: 4.5,
          utmEste: '277000',
          utmNorte: '8667000',
          zona: '18L',
        );

        expect(actualizada.rutaArchivo, foto.rutaArchivo);
        expect(actualizada.estado, EstadoSync.pendiente);
        expect(actualizada.estaSincronizada, isFalse);
        expect(actualizada.precisionGps, 4.5);
        expect(actualizada.zona, '18L');
        expect(File(actualizada.rutaArchivo).existsSync(), isTrue);
      },
    );
  });

  group('Formulario: borrador recuperable y sin duplicados', () {
    final datos = {
      'tipo_torre': 'alineamiento',
      'ubicacion': 'urbana',
      'acceso_torre': 'a_pie',
      'comentarios': 'Óxido en la base, requiere seguimiento',
      'estado_placas_torre': 'malo',
    };

    test('guardar y recuperar devuelve exactamente lo escrito', () async {
      await borradores.guardar(
        posteId: 101,
        datos: datos,
        rst: [
          {
            'seccion': 'conductores_fase',
            'atributo': 'hebras_rotas',
            'fase': 'R',
          },
        ],
      );

      final recuperado = await borradores.obtener(101);
      expect(recuperado, isNotNull);
      expect(recuperado!.datos['comentarios'], contains('Óxido'));
      expect(recuperado.datos['tipo_torre'], 'alineamiento');
      expect(recuperado.rst, hasLength(1));
      expect(
        recuperado.seleccionadosRst.keys,
        contains('conductores_fase|hebras_rotas|R'),
      );
      expect(recuperado.estado, EstadoSync.pendiente);
    });

    test(
      'GUARDAR VARIAS VECES no duplica: un solo borrador por poste',
      () async {
        for (var i = 1; i <= 5; i++) {
          await borradores.guardar(
            posteId: 101,
            datos: {...datos, 'comentarios': 'version $i'},
            rst: const [],
          );
        }

        final db = await DatabaseHelper().database;
        final filas = await db.query(
          'formularios_pendientes',
          where: 'poste_id = ?',
          whereArgs: [101],
        );
        expect(filas, hasLength(1));
        expect(
          (await borradores.obtener(101))!.datos['comentarios'],
          'version 5',
        );
      },
    );

    test('el UUID se mantiene entre guardados (idempotencia)', () async {
      await borradores.guardar(posteId: 101, datos: datos, rst: const []);
      final primero = (await borradores.obtener(101))!.uuid;

      await borradores.guardar(
        posteId: 101,
        datos: {...datos, 'comentarios': 'editado'},
        rst: const [],
      );
      expect((await borradores.obtener(101))!.uuid, primero);
    });

    test('el tablero RST se reemplaza, no se acumula', () async {
      await borradores.guardar(
        posteId: 101,
        datos: datos,
        rst: [
          {
            'seccion': 'conductores_fase',
            'atributo': 'hebras_rotas',
            'fase': 'R',
          },
          {
            'seccion': 'conductores_fase',
            'atributo': 'encanastillado',
            'fase': 'S',
          },
        ],
      );
      await borradores.guardar(
        posteId: 101,
        datos: datos,
        rst: [
          {
            'seccion': 'estado_aisladores',
            'atributo': 'buen_estado',
            'fase': 'T',
          },
        ],
      );

      final recuperado = await borradores.obtener(101);
      expect(recuperado!.rst, hasLength(1));
      expect(recuperado.rst.first['seccion'], 'estado_aisladores');
    });

    test(
      'un fallo de envío NO marca el formulario como sincronizado',
      () async {
        await borradores.guardar(posteId: 101, datos: datos, rst: const []);
        await borradores.marcarSubiendo(101);
        await borradores.marcarFallido(101, 'El servidor respondió HTML');

        final tras = (await borradores.obtener(101))!;
        expect(tras.estado, EstadoSync.fallido);
        expect(tras.estaSincronizado, isFalse);
        expect(tras.intentos, 1);
        expect(tras.ultimoError, contains('HTML'));
        // Y los datos siguen intactos.
        expect(tras.datos['comentarios'], contains('Óxido'));
      },
    );

    test('solo la confirmación del servidor marca sincronizado', () async {
      await borradores.guardar(
        posteId: 101,
        datos: datos,
        rst: [
          {
            'seccion': 'conductores_fase',
            'atributo': 'hebras_rotas',
            'fase': 'R',
          },
        ],
      );
      await borradores.marcarSincronizado(101, idRemoto: 'srv-55');

      final tras = (await borradores.obtener(101))!;
      expect(tras.estaSincronizado, isTrue);

      final db = await DatabaseHelper().database;
      final rst = await db.query(
        'poste_secciones_rst',
        where: 'poste_id = ?',
        whereArgs: [101],
      );
      expect(rst.first['sincronizado'], 1);
    });

    test(
      'LA APP MUERE DURANTE EL ENVÍO: el formulario vuelve a la cola',
      () async {
        await borradores.guardar(posteId: 101, datos: datos, rst: const []);
        await borradores.marcarSubiendo(101);

        final recuperados = await borradores.recuperarEnviosInterrumpidos();

        expect(recuperados, 1);
        expect((await borradores.obtener(101))!.estado, EstadoSync.pendiente);
      },
    );

    test('un datos_json corrupto no impide abrir el formulario', () async {
      final db = await DatabaseHelper().database;
      await db.insert('formularios_pendientes', {
        'poste_id': 101,
        'datos_json': '{esto no es json',
        'estado': EstadoSync.pendiente,
      });

      final recuperado = await borradores.obtener(101);
      expect(recuperado, isNotNull);
      expect(recuperado!.datos, isEmpty);
    });

    test('reeditar un formulario sincronizado conserva lo anterior', () async {
      // El escenario del bug: estructura "ya inventariada", el inspector pulsa
      // Editar. El borrador previo debe estar disponible para precargarlo.
      await borradores.guardar(posteId: 101, datos: datos, rst: const []);
      await borradores.marcarSincronizado(101);

      final alAbrirDeNuevo = await borradores.obtener(101);
      expect(alAbrirDeNuevo, isNotNull);
      expect(alAbrirDeNuevo!.datos['estado_placas_torre'], 'malo');
      expect(
        alAbrirDeNuevo.estaSincronizado,
        isTrue,
        reason:
            'La pantalla debe poder avisar que se está editando algo '
            'ya sincronizado.',
      );
    });
  });
}
