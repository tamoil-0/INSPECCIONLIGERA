import 'dart:io';

/// Gama estimada del teléfono, para adaptar el procesamiento de imágenes.
enum GamaDispositivo { baja, media, alta }

/// Parámetros de optimización adaptados al teléfono.
///
/// ## Cómo se estima la gama
///
/// Con el número de núcleos (`Platform.numberOfProcessors`) como aproximación.
/// No es exacto —lo correcto sería leer la RAM disponible— pero evita añadir
/// `device_info_plus` solo para esto, y la correlación núcleos/RAM en teléfonos
/// Android es suficiente para elegir entre 2560 y 3072 px.
///
/// El inspector puede forzar un perfil desde Ajustes si el automático no
/// acierta con su equipo.
class PerfilDispositivo {
  final GamaDispositivo gama;

  /// Lado mayor máximo de la imagen optimizada, en píxeles.
  final int ladoMayorMaximo;

  /// Calidad JPEG (0-100).
  final int calidadJpeg;

  /// Peso objetivo aproximado por fotografía, en bytes.
  final int pesoObjetivo;

  /// Cuántas imágenes se procesan a la vez.
  final int concurrencia;

  /// Lado mayor de la miniatura.
  final int ladoMiniatura;

  const PerfilDispositivo({
    required this.gama,
    required this.ladoMayorMaximo,
    required this.calidadJpeg,
    required this.pesoObjetivo,
    required this.concurrencia,
    this.ladoMiniatura = 320,
  });

  /// Gama baja: se prioriza no agotar la memoria por encima del detalle.
  static const PerfilDispositivo baja = PerfilDispositivo(
    gama: GamaDispositivo.baja,
    ladoMayorMaximo: 2560,
    calidadJpeg: 86,
    pesoObjetivo: 1600 * 1024,
    concurrencia: 1,
  );

  static const PerfilDispositivo media = PerfilDispositivo(
    gama: GamaDispositivo.media,
    ladoMayorMaximo: 3072,
    calidadJpeg: 88,
    pesoObjetivo: 2200 * 1024,
    concurrencia: 2,
  );

  static const PerfilDispositivo alta = PerfilDispositivo(
    gama: GamaDispositivo.alta,
    ladoMayorMaximo: 3072,
    calidadJpeg: 90,
    pesoObjetivo: 2600 * 1024,
    concurrencia: 2,
  );

  /// Detecta el perfil del teléfono actual.
  static PerfilDispositivo detectar() {
    final nucleos = Platform.numberOfProcessors;
    if (nucleos <= 4) return baja;
    if (nucleos <= 6) return media;
    return alta;
  }

  static PerfilDispositivo? porNombre(String? nombre) {
    switch (nombre) {
      case 'baja':
        return baja;
      case 'media':
        return media;
      case 'alta':
        return alta;
      default:
        return null;
    }
  }

  String get nombre => gama.name;

  @override
  String toString() =>
      'PerfilDispositivo(${gama.name}: ${ladoMayorMaximo}px, '
      'q$calidadJpeg, x$concurrencia)';
}
