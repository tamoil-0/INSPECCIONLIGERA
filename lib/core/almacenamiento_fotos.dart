import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

/// Resultado de persistir una fotografía en almacenamiento durable.
class FotoPersistida {
  final String ruta;
  final int tamanoBytes;
  final String checksum;

  const FotoPersistida({
    required this.ruta,
    required this.tamanoBytes,
    required this.checksum,
  });
}

/// Error al copiar una fotografía a almacenamiento permanente.
///
/// Se lanza *antes* de registrar nada en SQLite, para que nunca exista una
/// fila que apunte a un archivo inexistente.
class AlmacenamientoFotoException implements Exception {
  final String mensaje;
  const AlmacenamientoFotoException(this.mensaje);
  @override
  String toString() => 'AlmacenamientoFotoException: $mensaje';
}

/// Almacenamiento permanente de las fotografías de inspección.
///
/// ## Por qué existe
///
/// `image_picker` devuelve la foto en el **directorio de caché** de la
/// aplicación. Android puede vaciar esa caché en cualquier momento (poco
/// espacio, limpieza del sistema, "borrar caché" desde Ajustes). Guardar esa
/// ruta en SQLite significaba que una foto pendiente de subir podía
/// desaparecer y dejar una fila huérfana.
///
/// Esta clase copia cada captura a
/// `<documentos de la app>/inspecciones/proyecto_<id>/poste_<id>/` — un
/// directorio privado, permanente, que sobrevive al reinicio del teléfono y
/// que solo se borra si el usuario desinstala la app o nosotros lo borramos
/// explícitamente.
///
/// El nombre incluye el UUID del registro, de modo que dos capturas de la
/// misma vista (por ejemplo repetir "placa") nunca se pisan entre sí y el
/// archivo puede rastrearse hasta su fila en SQLite.
class AlmacenamientoFotos {
  static const String carpetaRaiz = 'inspecciones';

  /// Sobrescribible en pruebas para no depender de `path_provider`.
  static Directory? baseDePruebas;

  Directory? _raiz;

  Future<Directory> raiz() async {
    if (_raiz != null) return _raiz!;
    final base = baseDePruebas ?? await getApplicationDocumentsDirectory();
    final dir = Directory(p.join(base.path, carpetaRaiz));
    if (!await dir.exists()) {
      await dir.create(recursive: true);
    }
    _raiz = dir;
    return dir;
  }

  Future<Directory> carpetaDePoste({
    required int posteId,
    int? proyectoId,
  }) async {
    final base = await raiz();
    final dir = Directory(
      p.join(base.path, 'proyecto_${proyectoId ?? 0}', 'poste_$posteId'),
    );
    if (!await dir.exists()) {
      await dir.create(recursive: true);
    }
    return dir;
  }

  /// Copia [origen] a almacenamiento permanente y verifica la copia.
  ///
  /// Verifica tres cosas antes de darla por buena:
  ///   1. el archivo de origen existe y no está vacío;
  ///   2. el destino quedó con exactamente el mismo número de bytes;
  ///   3. el checksum del destino coincide con el del origen.
  ///
  /// Si algo falla, borra el destino a medio escribir y lanza
  /// [AlmacenamientoFotoException]. Quien llama no debe registrar la foto en
  /// SQLite si esto lanza.
  Future<FotoPersistida> persistir({
    required File origen,
    required int posteId,
    required String nombreFoto,
    required String uuid,
    int? proyectoId,
  }) async {
    if (!await origen.exists()) {
      throw AlmacenamientoFotoException(
        'El archivo capturado ya no existe: ${origen.path}',
      );
    }
    final bytesOrigen = await origen.length();
    if (bytesOrigen <= 0) {
      throw const AlmacenamientoFotoException(
        'El archivo capturado está vacío (0 bytes).',
      );
    }

    final carpeta = await carpetaDePoste(
      posteId: posteId,
      proyectoId: proyectoId,
    );
    final extension = _extensionSegura(origen.path);
    final destino = File(
      p.join(carpeta.path, '${_nombreSeguro(nombreFoto)}__$uuid$extension'),
    );

    // Se escribe primero a un temporal y solo se pone en su sitio cuando la
    // copia está verificada.
    //
    // Importa al repetir una foto: el nombre del archivo se deriva del UUID,
    // que se conserva, así que la ruta de destino es la misma que la de la foto
    // anterior. Copiar directamente encima significaría destruir una foto buena
    // antes de saber si la nueva se copió bien; si el proceso muere a mitad, el
    // inspector se queda sin ninguna de las dos.
    final temporal = File('${destino.path}.tmp');

    try {
      if (await temporal.exists()) {
        await temporal.delete();
      }
      await origen.copy(temporal.path);

      final bytesDestino = await temporal.length();
      if (bytesDestino != bytesOrigen) {
        throw AlmacenamientoFotoException(
          'Copia incompleta: $bytesDestino de $bytesOrigen bytes.',
        );
      }

      final checksumOrigen = await calcularChecksum(origen);
      final checksumDestino = await calcularChecksum(temporal);
      if (checksumOrigen != checksumDestino) {
        throw const AlmacenamientoFotoException(
          'El checksum de la copia no coincide con el original.',
        );
      }

      // La copia está verificada: recién ahora se reemplaza la anterior.
      await temporal.rename(destino.path);

      return FotoPersistida(
        ruta: destino.path,
        tamanoBytes: bytesDestino,
        checksum: checksumDestino,
      );
    } catch (e) {
      // No dejar basura a medio escribir. El destino definitivo no se toca:
      // si había una foto anterior válida, sigue intacta.
      if (await temporal.exists()) {
        try {
          await temporal.delete();
        } catch (_) {
          // Un temporal huérfano es preferible a enmascarar el error real.
        }
      }
      if (e is AlmacenamientoFotoException) rethrow;
      throw AlmacenamientoFotoException('No se pudo guardar la foto: $e');
    }
  }

  /// SHA-256 leyendo por bloques: no carga la imagen completa en memoria,
  /// lo que importa con fotos de 48 o 108 MP en teléfonos de gama baja.
  Future<String> calcularChecksum(File archivo) async {
    final digest = await sha256.bind(archivo.openRead()).first;
    return digest.toString();
  }

  Future<bool> existe(String? ruta) async {
    if (ruta == null || ruta.trim().isEmpty) return false;
    return File(ruta).exists();
  }

  /// Borra el archivo de una foto. Solo debe llamarse por decisión explícita
  /// (el inspector eliminó la foto, o una limpieza autorizada).
  Future<bool> eliminar(String? ruta) async {
    if (!await existe(ruta)) return false;
    try {
      await File(ruta!).delete();
      return true;
    } catch (_) {
      return false;
    }
  }

  /// Suma en bytes de todas las fotos guardadas, para mostrar cuánto ocupa
  /// el trabajo pendiente en el teléfono.
  Future<int> espacioOcupado() async {
    final dir = await raiz();
    var total = 0;
    await for (final entidad in dir.list(recursive: true, followLinks: false)) {
      if (entidad is File) {
        try {
          total += await entidad.length();
        } catch (_) {
          // Archivo desaparecido a mitad del recorrido: no interrumpe el conteo.
        }
      }
    }
    return total;
  }

  String _extensionSegura(String rutaOrigen) {
    final ext = p.extension(rutaOrigen).toLowerCase();
    const permitidas = {'.jpg', '.jpeg', '.png', '.heic', '.heif', '.webp'};
    return permitidas.contains(ext) ? ext : '.jpg';
  }

  String _nombreSeguro(String nombre) {
    return nombre.replaceAll(RegExp(r'[^a-zA-Z0-9_\-]'), '_');
  }
}
