import 'package:flutter/foundation.dart';

import 'entorno.dart';

/// Registro con niveles.
///
/// ## Por qué existe
///
/// El código tenía 31 llamadas a `print()` repartidas en siete archivos:
/// volcaba cada poste descargado con su código y ubicación, y ocho líneas por
/// cada formateo de fecha. En release eso sigue ejecutándose: ruido en logcat y
/// datos operativos de la empresa saliendo por el registro del sistema.
///
/// `debugPrint` ya se anula en release, pero además:
///   · los mensajes de depuración solo salen si REGISTRO_DETALLADO está activo;
///   · nunca se registran token, contraseñas ni cuerpos completos de respuesta.
class Log {
  const Log._();

  /// Detalle fino. Solo con `--dart-define=REGISTRO_DETALLADO=true`.
  static void detalle(String mensaje, [Object? contexto]) {
    if (!Entorno.registroDetallado) return;
    debugPrint('· $mensaje${contexto == null ? '' : ' | $contexto'}');
  }

  /// Hecho relevante del funcionamiento normal.
  static void info(String mensaje) => debugPrint('› $mensaje');

  /// Algo salió mal pero la app continúa.
  static void aviso(String mensaje, [Object? causa]) =>
      debugPrint('⚠ $mensaje${causa == null ? '' : ' | $causa'}');

  /// Fallo que el usuario nota.
  static void error(String mensaje, [Object? causa, StackTrace? pila]) {
    debugPrint('✖ $mensaje${causa == null ? '' : ' | $causa'}');
    if (pila != null && Entorno.registroDetallado) debugPrint('$pila');
  }
}
