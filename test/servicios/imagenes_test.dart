import 'dart:io';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/core/dimensiones_imagen.dart';
import 'package:pruebaoffline/core/contrato_fotos.dart';
import 'package:pruebaoffline/data/remoto/cliente_api.dart';
import 'package:pruebaoffline/services/imagenesPoste_service.dart';
import 'package:pruebaoffline/servicios/imagenes/cola_procesamiento.dart';
import 'package:pruebaoffline/servicios/imagenes/perfil_dispositivo.dart';

/// Construye un JPEG mínimo pero válido en cabeceras, con las dimensiones
/// pedidas y un segmento EXIF de relleno delante del SOF0 — igual que un
/// archivo real de cámara, donde el SOF no está al principio.
Uint8List jpegDePrueba({
  required int ancho,
  required int alto,
  int bytesExif = 4096,
}) {
  final b = BytesBuilder();
  b.add([0xFF, 0xD8]); // SOI

  // APP1 (EXIF) de relleno: obliga al lector a saltar por longitud.
  final longitudApp1 = bytesExif + 2;
  b.add([0xFF, 0xE1, (longitudApp1 >> 8) & 0xFF, longitudApp1 & 0xFF]);
  b.add(List<int>.filled(bytesExif, 0x00));

  // SOF0: longitud 17, precisión 8, alto, ancho, 3 componentes.
  b.add([0xFF, 0xC0, 0x00, 0x11, 0x08]);
  b.add([(alto >> 8) & 0xFF, alto & 0xFF]);
  b.add([(ancho >> 8) & 0xFF, ancho & 0xFF]);
  b.add([0x03]);
  b.add(List<int>.filled(9, 0x00)); // datos de componentes

  b.add([0xFF, 0xD9]); // EOI
  return b.toBytes();
}

Uint8List pngDePrueba({required int ancho, required int alto}) {
  final b = BytesBuilder();
  b.add([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]); // firma
  b.add([0x00, 0x00, 0x00, 0x0D]); // longitud IHDR
  b.add([0x49, 0x48, 0x44, 0x52]); // 'IHDR'
  final d = ByteData(8)
    ..setUint32(0, ancho)
    ..setUint32(4, alto);
  b.add(d.buffer.asUint8List());
  b.add(List<int>.filled(5, 0x00));
  return b.toBytes();
}

void main() {
  late Directory temporal;

  setUp(() async {
    temporal = await Directory.systemTemp.createTemp('ecoing_img_');
  });

  tearDown(() {
    try {
      temporal.deleteSync(recursive: true);
    } catch (_) {}
  });

  Future<File> escribir(String nombre, List<int> bytes) async {
    final f = File('${temporal.path}/$nombre');
    await f.writeAsBytes(bytes);
    return f;
  }

  group('LectorDimensiones · lee cabeceras sin decodificar', () {
    test('JPEG de 12 MP saltando un EXIF grande', () async {
      final f = await escribir(
        'p12mp.jpg',
        jpegDePrueba(ancho: 4000, alto: 3000, bytesExif: 16384),
      );
      final dims = await LectorDimensiones.leer(f);

      expect(dims, isNotNull);
      expect(dims!.ancho, 4000);
      expect(dims.alto, 3000);
      expect(dims.ladoMayor, 4000);
      expect(dims.esVertical, isFalse);
      expect(dims.megapixeles, 12);
    });

    test('JPEG vertical de 108 MP', () async {
      // 12000x9000 = 108 MP. Decodificarlo serían ~324 MB de bitmap; aquí se
      // leen unos pocos kilobytes de cabecera.
      final f = await escribir(
        'p108mp.jpg',
        jpegDePrueba(ancho: 9000, alto: 12000),
      );
      final dims = await LectorDimensiones.leer(f);

      expect(dims!.ancho, 9000);
      expect(dims.alto, 12000);
      expect(dims.esVertical, isTrue);
      expect(dims.megapixeles, 108);
      // Y el archivo leído es minúsculo: no se cargó ninguna imagen real.
      expect(await f.length(), lessThan(20 * 1024));
    });

    test('JPEG de 48 MP horizontal', () async {
      final f = await escribir(
        'p48mp.jpg',
        jpegDePrueba(ancho: 8000, alto: 6000),
      );
      final dims = await LectorDimensiones.leer(f);
      expect(dims!.ladoMayor, 8000);
      expect(dims.megapixeles, 48);
    });

    test('PNG', () async {
      final f = await escribir(
        'captura.png',
        pngDePrueba(ancho: 1080, alto: 2400),
      );
      final dims = await LectorDimensiones.leer(f);
      expect(dims!.ancho, 1080);
      expect(dims.alto, 2400);
    });

    test('archivo corrupto devuelve null en lugar de lanzar', () async {
      final f = await escribir('roto.jpg', [0xFF, 0xD8, 0xFF, 0xE1, 0x00]);
      expect(await LectorDimensiones.leer(f), isNull);
    });

    test('archivo vacío devuelve null', () async {
      final f = await escribir('vacio.jpg', <int>[]);
      expect(await LectorDimensiones.leer(f), isNull);
    });

    test('formato desconocido (HEIC) devuelve null, no error', () async {
      // Un null significa "no lo sé, procésala por si acaso".
      final f = await escribir('foto.heic', List<int>.filled(64, 0x42));
      expect(await LectorDimensiones.leer(f), isNull);
    });

    test('no lanza con un JPEG sin SOF (solo datos)', () async {
      final f = await escribir('sinsof.jpg', [
        0xFF,
        0xD8,
        0xFF,
        0xDA,
        0x00,
        0x02,
      ]);
      expect(await LectorDimensiones.leer(f), isNull);
    });
  });

  group('PerfilDispositivo', () {
    test('la gama baja limita más y no procesa en paralelo', () {
      expect(PerfilDispositivo.baja.ladoMayorMaximo, 2560);
      expect(PerfilDispositivo.baja.concurrencia, 1);
      expect(
        PerfilDispositivo.baja.ladoMayorMaximo,
        lessThan(PerfilDispositivo.alta.ladoMayorMaximo),
      );
    });

    test('la calidad se mantiene en el rango que conserva el detalle', () {
      for (final perfil in [
        PerfilDispositivo.baja,
        PerfilDispositivo.media,
        PerfilDispositivo.alta,
      ]) {
        expect(
          perfil.calidadJpeg,
          inInclusiveRange(85, 92),
          reason: 'Por debajo de 85 el número de una placa deja de leerse.',
        );
        expect(perfil.ladoMayorMaximo, greaterThanOrEqualTo(2560));
        expect(perfil.concurrencia, inInclusiveRange(1, 2));
      }
    });

    test('detectar() siempre devuelve un perfil válido', () {
      final p = PerfilDispositivo.detectar();
      expect(p.concurrencia, greaterThanOrEqualTo(1));
      expect(p.ladoMayorMaximo, greaterThan(0));
    });

    test('porNombre permite forzar el perfil desde Ajustes', () {
      expect(PerfilDispositivo.porNombre('baja'), PerfilDispositivo.baja);
      expect(PerfilDispositivo.porNombre('alta'), PerfilDispositivo.alta);
      expect(PerfilDispositivo.porNombre('inexistente'), isNull);
      expect(PerfilDispositivo.porNombre(null), isNull);
    });
  });

  group('ColaProcesamiento', () {
    test('respeta la concurrencia máxima', () async {
      final cola = ColaProcesamiento(concurrencia: 2);
      var simultaneas = 0;
      var pico = 0;

      Future<int> tarea(int i) async {
        simultaneas++;
        if (simultaneas > pico) pico = simultaneas;
        await Future<void>.delayed(const Duration(milliseconds: 20));
        simultaneas--;
        return i;
      }

      final resultados = await Future.wait(
        List.generate(8, (i) => cola.encolar(() => tarea(i))),
      );

      expect(resultados, [0, 1, 2, 3, 4, 5, 6, 7]);
      expect(pico, lessThanOrEqualTo(2));
      expect(pico, greaterThan(0));
    });

    test('con concurrencia 1 procesa estrictamente de una en una', () async {
      final cola = ColaProcesamiento(concurrencia: 1);
      var simultaneas = 0;
      var pico = 0;

      await Future.wait(
        List.generate(
          6,
          (i) => cola.encolar(() async {
            simultaneas++;
            if (simultaneas > pico) pico = simultaneas;
            await Future<void>.delayed(const Duration(milliseconds: 10));
            simultaneas--;
            return i;
          }),
        ),
      );

      expect(pico, 1);
    });

    test('un fallo no arrastra al resto de la cola', () async {
      final cola = ColaProcesamiento(concurrencia: 2);

      final resultados = await cola.encolarTodas<String>([
        () async => 'a',
        () async => throw StateError('la foto 2 está corrupta'),
        () async => 'c',
      ]);

      expect(resultados, ['a', null, 'c']);
      expect(cola.ocupada, isFalse);
    });

    test('propaga el error de una tarea concreta', () async {
      final cola = ColaProcesamiento(concurrencia: 1);
      await expectLater(
        cola.encolar<void>(() async => throw StateError('falló')),
        throwsA(isA<StateError>()),
      );
      // Y la cola sigue usable.
      expect(await cola.encolar(() async => 42), 42);
    });

    test('cancelarPendientes descarta lo que no empezó', () async {
      final cola = ColaProcesamiento(concurrencia: 1);
      final enMarcha = cola.encolar(() async {
        await Future<void>.delayed(const Duration(milliseconds: 40));
        return 'terminada';
      });
      final descartada = cola.encolar(() async => 'nunca');
      // El manejador se engancha ANTES de cancelar: si no, el error llegaría a
      // un futuro sin oyente y el framework lo reportaría como no capturado.
      final esperaDescartada = expectLater(
        descartada,
        throwsA(isA<StateError>()),
      );

      cola.cancelarPendientes();

      // La que ya estaba en marcha termina: cortar una compresión a medias
      // dejaría un archivo parcial.
      expect(await enMarcha, 'terminada');
      await esperaDescartada;
    });

    test('tras cancelar y reactivar vuelve a aceptar tareas', () async {
      final cola = ColaProcesamiento(concurrencia: 1);
      cola.cancelarPendientes();
      await expectLater(
        cola.encolar(() async => 1),
        throwsA(isA<StateError>()),
      );

      cola.reactivar();
      expect(await cola.encolar(() async => 7), 7);
    });

    test('28 fotos se procesan todas, sin perder ninguna', () async {
      final cola = ColaProcesamiento(concurrencia: 2);
      final procesadas = <int>[];

      await Future.wait(
        List.generate(
          ContratoFotos.tiposRequeridos.length,
          (i) => cola.encolar(() async {
            await Future<void>.delayed(const Duration(milliseconds: 2));
            procesadas.add(i);
            return i;
          }),
        ),
      );

      expect(procesadas, hasLength(ContratoFotos.cantidadRequerida));
      expect(procesadas.toSet(), hasLength(ContratoFotos.cantidadRequerida));
      expect(cola.pendientes, 0);
      expect(cola.activas, 0);
    });
  });

  group('Contrato y confirmación del backend', () {
    test('contiene exactamente los 28 tipos requeridos por PHP', () {
      expect(ContratoFotos.tiposRequeridos, hasLength(28));
      expect(
        ContratoFotos.tiposRequeridos,
        hasLength(ContratoFotos.cantidadRequerida),
      );
      expect(ContratoFotos.tiposRequeridos.toSet(), hasLength(28));
      expect(ContratoFotos.tiposRequeridos, contains('foto_panoramica'));
      expect(ContratoFotos.tiposRequeridos, contains('puesta_tierra_2'));
      expect(ContratoFotos.tiposRequeridos, contains('otros'));
      expect(ContratoFotos.fotosPorLote, inInclusiveRange(4, 8));
    });

    test('un lote parcial confirma solo las fotos aceptadas', () {
      final servicio = ImagenesPosteService(
        api: ClienteApi(baseUrl: 'https://ejemplo.test/api'),
      );
      final cuerpo = jsonEncode({
        'success': false,
        'resultados': {
          'imagen_0': {'success': true, 'nombre_foto': 'foto_panoramica'},
          'imagen_1': {'success': false, 'error': 'Imagen inválida.'},
        },
      });

      final resultado = servicio.interpretarRespuesta(200, cuerpo, {
        'foto_panoramica',
        'placa',
      });

      expect(resultado.confirmadas, {'foto_panoramica'});
      expect(resultado.rechazadas, {'placa'});
      expect(resultado.todoConfirmado, isFalse);
      expect(resultado.error, contains('inválida'));
    });

    test('401 de multipart dispara el cierre de sesión centralizado', () {
      var avisos = 0;
      ClienteApi.alVencerSesion = () => avisos++;
      final servicio = ImagenesPosteService(
        api: ClienteApi(baseUrl: 'https://ejemplo.test/api'),
      );

      final resultado = servicio.interpretarRespuesta(
        401,
        '{"success":false,"error":"token vencido"}',
        {'placa'},
      );

      expect(resultado.confirmadas, isEmpty);
      expect(resultado.rechazadas, {'placa'});
      expect(avisos, 1);
      ClienteApi.alVencerSesion = null;
    });
  });
}
