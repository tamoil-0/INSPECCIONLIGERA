import 'dart:io';
import 'dart:typed_data';

/// Dimensiones en píxeles de una imagen.
class DimensionesImagen {
  final int ancho;
  final int alto;

  const DimensionesImagen(this.ancho, this.alto);

  int get ladoMayor => ancho > alto ? ancho : alto;
  bool get esVertical => alto > ancho;
  int get megapixeles => (ancho * alto) ~/ 1000000;

  @override
  String toString() => '${ancho}x$alto';
}

/// Lee el ancho y el alto de una imagen **sin decodificarla**.
///
/// ## Por qué no se usa `decodeImageFromList`
///
/// Decodificar para saber el tamaño obligaría a meter la imagen completa en
/// memoria. Una foto de 108 MP son ~324 MB de bitmap descomprimido: en un
/// teléfono de gama baja es una muerte por falta de memoria antes de haber
/// hecho nada útil.
///
/// Este lector recorre solo las cabeceras del archivo (unos pocos kilobytes),
/// saltando los segmentos por su longitud declarada.
class LectorDimensiones {
  const LectorDimensiones._();

  /// Devuelve las dimensiones, o `null` si el formato no se reconoce
  /// (por ejemplo HEIC, cuyo contenedor es mucho más complejo).
  ///
  /// Un `null` no es un error: significa "no lo sé, procésala por si acaso".
  static Future<DimensionesImagen?> leer(File archivo) async {
    RandomAccessFile? lector;
    try {
      lector = await archivo.open();
      final firma = await lector.read(8);
      if (firma.length < 8) return null;

      if (_esPng(firma)) {
        return await _leerPng(lector);
      }
      if (_esJpeg(firma)) {
        return await _leerJpeg(lector);
      }
      if (_esWebp(firma)) {
        return await _leerWebp(lector);
      }
      return null;
    } catch (_) {
      // Un archivo truncado o corrupto no debe tumbar la captura.
      return null;
    } finally {
      await lector?.close();
    }
  }

  static bool _esPng(Uint8List f) =>
      f[0] == 0x89 && f[1] == 0x50 && f[2] == 0x4E && f[3] == 0x47;

  static bool _esJpeg(Uint8List f) => f[0] == 0xFF && f[1] == 0xD8;

  static bool _esWebp(Uint8List f) =>
      f[0] == 0x52 && f[1] == 0x49 && f[2] == 0x46 && f[3] == 0x46;

  /// PNG: el bloque IHDR es siempre el primero. Ancho y alto son dos enteros
  /// de 32 bits big-endian en el desplazamiento 16.
  static Future<DimensionesImagen?> _leerPng(RandomAccessFile lector) async {
    await lector.setPosition(16);
    final datos = await lector.read(8);
    if (datos.length < 8) return null;
    final vista = ByteData.sublistView(datos);
    return DimensionesImagen(vista.getUint32(0), vista.getUint32(4));
  }

  /// JPEG: se recorren los segmentos `FF xx` hasta encontrar un marcador SOF,
  /// cuyo payload trae precisión (1 byte), alto (2) y ancho (2).
  ///
  /// Se salta cada segmento por su longitud declarada, así que no importa que
  /// el archivo lleve un EXIF enorme con miniatura incrustada delante.
  static Future<DimensionesImagen?> _leerJpeg(RandomAccessFile lector) async {
    await lector.setPosition(2); // tras FFD8
    const sofValidos = {
      0xC0,
      0xC1,
      0xC2,
      0xC3,
      0xC5,
      0xC6,
      0xC7,
      0xC9,
      0xCA,
      0xCB,
      0xCD,
      0xCE,
      0xCF,
    };

    // Tope de seguridad: un archivo malformado no debe provocar un bucle largo.
    for (var segmentos = 0; segmentos < 512; segmentos++) {
      final marcador = await _siguienteMarcador(lector);
      if (marcador == null) return null;

      // Marcadores sin payload.
      if (marcador == 0xD8 ||
          marcador == 0x01 ||
          (marcador >= 0xD0 && marcador <= 0xD7)) {
        continue;
      }
      // Inicio de los datos comprimidos: ya no habrá cabeceras.
      if (marcador == 0xDA || marcador == 0xD9) return null;

      final longitud = await _uint16(lector);
      if (longitud == null || longitud < 2) return null;

      if (sofValidos.contains(marcador)) {
        final payload = await lector.read(5);
        if (payload.length < 5) return null;
        final vista = ByteData.sublistView(payload);
        final alto = vista.getUint16(1);
        final ancho = vista.getUint16(3);
        if (ancho <= 0 || alto <= 0) return null;
        return DimensionesImagen(ancho, alto);
      }

      await lector.setPosition(await lector.position() + longitud - 2);
    }
    return null;
  }

  /// WebP en su variante VP8X (la que usan las cámaras): ancho y alto menos uno,
  /// en 24 bits little-endian a partir del desplazamiento 24.
  static Future<DimensionesImagen?> _leerWebp(RandomAccessFile lector) async {
    await lector.setPosition(12);
    final tipo = await lector.read(4);
    if (tipo.length < 4) return null;
    final etiqueta = String.fromCharCodes(tipo);
    if (etiqueta != 'VP8X') return null;

    await lector.setPosition(24);
    final datos = await lector.read(6);
    if (datos.length < 6) return null;
    final ancho = (datos[0] | (datos[1] << 8) | (datos[2] << 16)) + 1;
    final alto = (datos[3] | (datos[4] << 8) | (datos[5] << 16)) + 1;
    return DimensionesImagen(ancho, alto);
  }

  /// Lee el siguiente byte de marcador, descartando el relleno `0xFF` que
  /// algunas cámaras insertan entre segmentos.
  static Future<int?> _siguienteMarcador(RandomAccessFile lector) async {
    for (var i = 0; i < 64; i++) {
      final b = await lector.read(1);
      if (b.isEmpty) return null;
      if (b[0] != 0xFF) return b[0];
    }
    return null;
  }

  static Future<int?> _uint16(RandomAccessFile lector) async {
    final b = await lector.read(2);
    if (b.length < 2) return null;
    return (b[0] << 8) | b[1];
  }
}
