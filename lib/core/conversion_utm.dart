import 'dart:math';

/// Coordenada UTM resultante de una posición geográfica.
class CoordenadaUtm {
  final double este;
  final double norte;
  final int zona;
  final String banda;

  const CoordenadaUtm({
    required this.este,
    required this.norte,
    required this.zona,
    required this.banda,
  });

  /// Designación de zona tal como la espera el backend: `19K`, `18L`…
  String get zonaCompleta => '$zona$banda';

  /// Forma que consumen los servicios y la base local.
  Map<String, dynamic> aMapa() => {
    'utmEste': este,
    'utmNorte': norte,
    'zona': zonaCompleta,
  };

  @override
  String toString() =>
      'UTM $zonaCompleta E=${este.toStringAsFixed(2)} N=${norte.toStringAsFixed(2)}';
}

/// Conversión de latitud/longitud (WGS-84) a UTM.
///
/// La implementación viene del código original de la app —era correcta y está
/// validada en campo— extraída aquí para poder probarla sin emulador. La
/// aritmética se conserva idéntica: mismas fórmulas, mismo redondeo a dos
/// decimales, misma corrección de 10 000 000 m en el hemisferio sur.
///
/// ## Corrección respecto al original
///
/// La tabla de bandas tenía un desbordamiento: `letters[((lat + 80) ~/ 8)]`
/// da índice 20 para cualquier latitud entre 80° y 84°, y la cadena solo tiene
/// 20 caracteres (índices 0-19). Es decir, convertir una foto tomada a más de
/// 80° de latitud norte lanzaba `RangeError`. La banda X abarca de 72° a 84°
/// (12° en lugar de 8°), así que el índice se limita al último válido.
/// No afecta a Perú, pero era un fallo real de una función pura.
class ConversionUtm {
  const ConversionUtm._();

  static const double _a = 6378137.0; // semieje mayor WGS-84
  static const double _f = 1 / 298.257223563; // achatamiento
  static const double _k0 = 0.9996; // factor de escala UTM
  static const String _bandas = 'CDEFGHJKLMNPQRSTUVWX';

  /// Zona UTM (1-60) que corresponde a una longitud.
  static int zonaDe(double lon) => ((lon + 180) / 6).floor() + 1;

  /// Banda de latitud UTM. Devuelve `?` fuera del rango cubierto (-80°..84°).
  static String bandaDe(double lat) {
    if (lat < -80 || lat > 84) return '?';
    final indice = ((lat + 80) ~/ 8).clamp(0, _bandas.length - 1);
    return _bandas[indice];
  }

  static CoordenadaUtm desdeLatLon(double lat, double lon) {
    final e = sqrt(_f * (2 - _f));
    final zone = zonaDe(lon);
    final lonOrigin = (zone - 1) * 6 - 180 + 3;
    final latRad = lat * pi / 180;
    final lonRad = lon * pi / 180;
    final lonOriginRad = lonOrigin * pi / 180;

    final n = _a / sqrt(1 - pow(e * sin(latRad), 2));
    final t = pow(tan(latRad), 2);
    final c = pow(e, 2) / (1 - pow(e, 2)) * pow(cos(latRad), 2);
    final aa = cos(latRad) * (lonRad - lonOriginRad);

    final m = _a *
        ((1 - pow(e, 2) / 4 - 3 * pow(e, 4) / 64 - 5 * pow(e, 6) / 256) *
                latRad -
            (3 * pow(e, 2) / 8 +
                    3 * pow(e, 4) / 32 +
                    45 * pow(e, 6) / 1024) *
                sin(2 * latRad) +
            (15 * pow(e, 4) / 256 + 45 * pow(e, 6) / 1024) * sin(4 * latRad) -
            (35 * pow(e, 6) / 3072) * sin(6 * latRad));

    final easting = _k0 *
            n *
            (aa +
                (1 - t + c) * pow(aa, 3) / 6 +
                (5 - 18 * t + t * t + 72 * c - 58 * pow(e, 2) / (1 - pow(e, 2))) *
                    pow(aa, 5) /
                    120) +
        500000.0;

    var northing = _k0 *
        (m +
            n *
                tan(latRad) *
                (pow(aa, 2) / 2 +
                    (5 - t + 9 * c + 4 * c * c) * pow(aa, 4) / 24 +
                    (61 -
                            58 * t +
                            t * t +
                            600 * c -
                            330 * pow(e, 2) / (1 - pow(e, 2))) *
                        pow(aa, 6) /
                        720));

    if (lat < 0) northing += 10000000.0;

    return CoordenadaUtm(
      este: double.parse(easting.toStringAsFixed(2)),
      norte: double.parse(northing.toStringAsFixed(2)),
      zona: zone,
      banda: bandaDe(lat),
    );
  }
}
