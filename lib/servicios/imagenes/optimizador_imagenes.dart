import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:path/path.dart' as p;

import '../../core/dimensiones_imagen.dart';
import 'perfil_dispositivo.dart';

/// Qué se conserva en el teléfono después de optimizar.
enum PoliticaRetencion {
  /// Se conserva el original **y** la versión optimizada. Máxima fidelidad,
  /// máximo consumo de espacio (~14 MB por foto de 12 MP).
  conservarOriginal,

  /// Por defecto. Se conserva la versión optimizada de alta calidad y el
  /// original se retira **solo cuando la copia optimizada está verificada en
  /// disco**. La versión optimizada nunca se borra automáticamente.
  soloOptimizada,

  /// Se conservan las dos hasta que el servidor confirme la fotografía; el
  /// original se retira entonces. Es la más segura y la que más espacio pide.
  liberarTrasSincronizar,
}

/// Resultado de intentar optimizar una fotografía.
class ResultadoOptimizacion {
  /// Ruta del archivo que se debe subir (optimizado, o el original si no se
  /// pudo optimizar).
  final String rutaSubible;

  /// Ruta del original, si se conserva.
  final String? rutaOriginal;

  final String? rutaMiniatura;
  final int tamanoSubible;
  final int? tamanoOriginal;
  final int? ancho;
  final int? alto;

  /// `false` cuando se decidió no tocar la imagen (ya estaba bien) o cuando
  /// la optimización falló y se sigue con el original.
  final bool seOptimizo;

  /// Explicación legible: por qué se omitió o por qué falló.
  final String? nota;

  /// Milisegundos que tardó el procesamiento.
  final int milisegundos;

  const ResultadoOptimizacion({
    required this.rutaSubible,
    required this.tamanoSubible,
    required this.seOptimizo,
    this.rutaOriginal,
    this.rutaMiniatura,
    this.tamanoOriginal,
    this.ancho,
    this.alto,
    this.nota,
    this.milisegundos = 0,
  });

  double get ratioReduccion {
    if (tamanoOriginal == null || tamanoOriginal == 0) return 1;
    return tamanoSubible / tamanoOriginal!;
  }

  @override
  String toString() =>
      'ResultadoOptimizacion(optimizó: $seOptimizo, '
      '${(tamanoSubible / 1024 / 1024).toStringAsFixed(2)} MB, '
      '${ancho}x$alto, ${milisegundos}ms${nota == null ? '' : ', $nota'})';
}

/// Optimiza las fotografías de inspección **en el teléfono**, antes de subir.
///
/// ## Decisiones
///
/// * **Códecs nativos, no Dart.** `flutter_image_compress` delega en las
///   librerías del sistema. El paquete `image` es Dart puro: descomprimir un
///   JPEG de 108 MP tardaría decenas de segundos y reservaría cientos de MB,
///   inviable en gama baja. (Además `image` solo está en el árbol como
///   dependencia de desarrollo, vía `flutter_launcher_icons`: usarlo en `lib/`
///   rompería la compilación de release.)
///
/// * **No se bloquea la interfaz.** La compresión ocurre en un hilo nativo; el
///   hilo de UI de Dart solo espera el resultado.
///
/// * **No se recomprime lo que ya está bien.** Recomprimir un JPEG degrada la
///   imagen sin ganar nada. Si la foto ya cumple tamaño y peso y es JPEG, se
///   deja como está.
///
/// * **El detalle técnico manda sobre el peso.** Los límites de partida
///   (2560-3072 px, calidad 86-90) están elegidos para que sigan siendo
///   legibles el número de una placa, una grieta fina o el óxido en un perno.
///   Antes de bajarlos hay que mirar una foto de placa, no una gráfica.
///
/// * **Nunca se pierde la foto.** Si la optimización falla por cualquier
///   motivo, se devuelve el original como archivo subible. Un error de
///   compresión no puede costarle una visita a la torre al inspector.
class OptimizadorImagenes {
  final PerfilDispositivo perfil;
  final PoliticaRetencion politica;

  const OptimizadorImagenes({
    this.perfil = PerfilDispositivo.media,
    this.politica = PoliticaRetencion.soloOptimizada,
  });

  static const Set<String> _formatosQueExigenConversion = {
    '.heic',
    '.heif',
    '.webp',
    '.png',
  };

  /// Optimiza [original] dejando los archivos junto a él.
  ///
  /// [original] debe estar ya en almacenamiento permanente: este método no
  /// trabaja sobre la caché.
  Future<ResultadoOptimizacion> optimizar(File original) async {
    final cronometro = Stopwatch()..start();

    if (!await original.exists()) {
      throw ArgumentError('El archivo a optimizar no existe: ${original.path}');
    }
    final tamanoOriginal = await original.length();
    final dimensiones = await LectorDimensiones.leer(original);
    final extension = p.extension(original.path).toLowerCase();

    final decision = _decidir(
      tamanoOriginal: tamanoOriginal,
      dimensiones: dimensiones,
      extension: extension,
    );

    if (!decision.procesar) {
      // Se genera la miniatura de todas formas: la lista la necesita.
      final miniatura = await _generarMiniatura(original);
      cronometro.stop();
      return ResultadoOptimizacion(
        rutaSubible: original.path,
        rutaOriginal: original.path,
        rutaMiniatura: miniatura?.path,
        tamanoSubible: tamanoOriginal,
        tamanoOriginal: tamanoOriginal,
        ancho: dimensiones?.ancho,
        alto: dimensiones?.alto,
        seOptimizo: false,
        nota: decision.motivo,
        milisegundos: cronometro.elapsedMilliseconds,
      );
    }

    try {
      final destino = _rutaVariante(original.path, 'opt', '.jpg');

      final objetivo = _objetivo(dimensiones, perfil.ladoMayorMaximo);

      final salida = await FlutterImageCompress.compressAndGetFile(
        original.path,
        destino,
        quality: perfil.calidadJpeg,
        // OJO: en este plugin minWidth/minHeight son cotas INFERIORES sobre las
        // que se calcula la escala, no un techo. Por eso se le pasan las
        // dimensiones ya calculadas a partir de la cabecera del archivo, en vez
        // de un valor suelto: pasar 3072 en ambas escalaría el lado CORTO a
        // 3072 y dejaría el largo aún mayor.
        minWidth: objetivo.ancho,
        minHeight: objetivo.alto,
        format: CompressFormat.jpeg,
        // Aplica la rotación declarada en EXIF y deja la imagen con orientación
        // normal, para que ni el backend ni el PDF dependan de leer la etiqueta.
        autoCorrectionAngle: true,
        // Sin EXIF: la orientación ya se aplicó a los píxeles (conservar la
        // etiqueta provocaría una segunda rotación en algunos visores), y el
        // GPS y la fecha se guardan en SQLite y se envían como campos propios.
        keepExif: false,
      ).timeout(const Duration(minutes: 2));

      if (salida == null) {
        throw StateError('el compresor devolvió null');
      }

      final archivoOptimizado = File(salida.path);
      final tamanoOptimizado = await archivoOptimizado.length();

      if (tamanoOptimizado <= 0) {
        await _borrarSiExiste(archivoOptimizado);
        throw StateError('la imagen optimizada quedó vacía');
      }

      // Si el "optimizado" pesa más que el original, no aporta nada.
      if (tamanoOptimizado >= tamanoOriginal) {
        await _borrarSiExiste(archivoOptimizado);
        final miniatura = await _generarMiniatura(original);
        cronometro.stop();
        return ResultadoOptimizacion(
          rutaSubible: original.path,
          rutaOriginal: original.path,
          rutaMiniatura: miniatura?.path,
          tamanoSubible: tamanoOriginal,
          tamanoOriginal: tamanoOriginal,
          ancho: dimensiones?.ancho,
          alto: dimensiones?.alto,
          seOptimizo: false,
          nota:
              'La versión optimizada no pesaba menos; se conserva la original.',
          milisegundos: cronometro.elapsedMilliseconds,
        );
      }

      final dimsFinales =
          await LectorDimensiones.leer(archivoOptimizado) ?? dimensiones;
      final miniatura = await _generarMiniatura(archivoOptimizado);

      // Retención: el original solo se retira con la copia ya verificada.
      String? rutaOriginalConservada = original.path;
      if (politica == PoliticaRetencion.soloOptimizada) {
        await _borrarSiExiste(original);
        rutaOriginalConservada = null;
      }

      cronometro.stop();
      return ResultadoOptimizacion(
        rutaSubible: archivoOptimizado.path,
        rutaOriginal: rutaOriginalConservada,
        rutaMiniatura: miniatura?.path,
        tamanoSubible: tamanoOptimizado,
        tamanoOriginal: tamanoOriginal,
        ancho: dimsFinales?.ancho,
        alto: dimsFinales?.alto,
        seOptimizo: true,
        milisegundos: cronometro.elapsedMilliseconds,
      );
    } catch (e) {
      // Nunca se pierde la foto: se sigue con el original.
      cronometro.stop();
      final miniatura = await _generarMiniatura(original);
      return ResultadoOptimizacion(
        rutaSubible: original.path,
        rutaOriginal: original.path,
        rutaMiniatura: miniatura?.path,
        tamanoSubible: tamanoOriginal,
        tamanoOriginal: tamanoOriginal,
        ancho: dimensiones?.ancho,
        alto: dimensiones?.alto,
        seOptimizo: false,
        nota: 'No se pudo optimizar ($e); se sube la fotografía original.',
        milisegundos: cronometro.elapsedMilliseconds,
      );
    }
  }

  /// Miniatura para las listas. Si falla, se devuelve `null` y la interfaz cae
  /// en un icono: una miniatura no es motivo para bloquear nada.
  Future<File?> _generarMiniatura(File origen) async {
    try {
      final destino = _rutaVariante(origen.path, 'thumb', '.jpg');
      final dims = await LectorDimensiones.leer(origen);
      final objetivo = _objetivo(dims, perfil.ladoMiniatura);
      final salida = await FlutterImageCompress.compressAndGetFile(
        origen.path,
        destino,
        quality: 72,
        minWidth: objetivo.ancho,
        minHeight: objetivo.alto,
        format: CompressFormat.jpeg,
        autoCorrectionAngle: true,
        keepExif: false,
      ).timeout(const Duration(seconds: 45));
      if (salida == null) return null;
      final archivo = File(salida.path);
      return await archivo.length() > 0 ? archivo : null;
    } catch (e) {
      debugPrint('No se pudo generar la miniatura de ${origen.path}: $e');
      return null;
    }
  }

  _Decision _decidir({
    required int tamanoOriginal,
    required DimensionesImagen? dimensiones,
    required String extension,
  }) {
    if (_formatosQueExigenConversion.contains(extension)) {
      return const _Decision(true, null);
    }
    if (dimensiones == null) {
      // Formato no reconocido: se procesa por si acaso, para normalizarlo.
      return const _Decision(true, null);
    }
    final cabeEnLimite = dimensiones.ladoMayor <= perfil.ladoMayorMaximo;
    final pesaPoco = tamanoOriginal <= perfil.pesoObjetivo;

    if (cabeEnLimite && pesaPoco) {
      return _Decision(
        false,
        'Ya estaba optimizada (${dimensiones.toString()}, '
        '${(tamanoOriginal / 1024 / 1024).toStringAsFixed(2)} MB): '
        'recomprimirla solo perdería detalle.',
      );
    }
    return const _Decision(true, null);
  }

  /// Calcula el tamaño de salida que respeta [ladoMaximo] conservando la
  /// proporción. Si no se conocen las dimensiones de partida, se devuelve un
  /// cuadrado del lado pedido como mejor aproximación.
  DimensionesImagen _objetivo(DimensionesImagen? origen, int ladoMaximo) {
    if (origen == null || origen.ladoMayor <= 0) {
      return DimensionesImagen(ladoMaximo, ladoMaximo);
    }
    if (origen.ladoMayor <= ladoMaximo) return origen;
    final escala = ladoMaximo / origen.ladoMayor;
    final ancho = (origen.ancho * escala).round().clamp(1, 100000);
    final alto = (origen.alto * escala).round().clamp(1, 100000);
    return DimensionesImagen(ancho, alto);
  }

  /// `.../placa__uuid.jpg` + `opt` → `.../placa__uuid_opt.jpg`
  String _rutaVariante(String rutaBase, String sufijo, String extension) {
    final dir = p.dirname(rutaBase);
    var nombre = p.basenameWithoutExtension(rutaBase);
    // Evita acumular sufijos si se reoptimiza.
    for (final s in ['_opt', '_thumb']) {
      if (nombre.endsWith(s))
        nombre = nombre.substring(0, nombre.length - s.length);
    }
    return p.join(dir, '${nombre}_$sufijo$extension');
  }

  Future<void> _borrarSiExiste(File archivo) async {
    try {
      if (await archivo.exists()) await archivo.delete();
    } catch (_) {
      // Un archivo que no se puede borrar no debe romper el flujo.
    }
  }
}

class _Decision {
  final bool procesar;
  final String? motivo;
  const _Decision(this.procesar, this.motivo);
}
