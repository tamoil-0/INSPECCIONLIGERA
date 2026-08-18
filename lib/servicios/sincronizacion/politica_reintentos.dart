/// Cuándo se puede volver a intentar enviar algo que falló.
///
/// ## Por qué hay backoff
///
/// Sin espera entre intentos, un servidor caído o una señal intermitente
/// producen cientos de peticiones fallidas que gastan batería y datos sin
/// ninguna posibilidad de éxito. Con backoff, el primer reintento es inmediato
/// (la mayoría de fallos son transitorios) y los siguientes se separan cada vez
/// más.
///
/// ## Nada se descarta nunca
///
/// Pasado el límite de intentos, el elemento **no se borra ni se ignora**: pasa
/// a requerir atención manual y sigue apareciendo en la lista de pendientes con
/// su último error. El inspector puede forzarlo desde la pantalla de
/// sincronización con [forzar].
class PoliticaReintentos {
  const PoliticaReintentos({
    this.esperas = const [
      Duration.zero,
      Duration(seconds: 30),
      Duration(minutes: 2),
      Duration(minutes: 8),
      Duration(minutes: 30),
      Duration(hours: 2),
    ],
    this.limiteIntentos = 8,
  });

  /// Espera antes del intento número N (índice = intentos ya realizados).
  final List<Duration> esperas;

  /// Tras este número de intentos, el elemento requiere atención manual.
  final int limiteIntentos;

  Duration esperaPara(int intentos) {
    if (intentos <= 0) return Duration.zero;
    if (intentos >= esperas.length) return esperas.last;
    return esperas[intentos];
  }

  /// Momento a partir del cual se puede reintentar.
  DateTime proximoIntento({required int intentos, DateTime? ultimoIntento}) {
    final base = ultimoIntento ?? DateTime.fromMillisecondsSinceEpoch(0);
    return base.add(esperaPara(intentos));
  }

  /// ¿Toca reintentar ahora?
  ///
  /// [forzar] salta la espera: es lo que hace el botón "Reintentar" del
  /// inspector, que tiene información que la app no tiene (por ejemplo que
  /// acaba de llegar a un sitio con cobertura).
  bool puedeIntentar({
    required int intentos,
    DateTime? ultimoIntento,
    bool forzar = false,
    DateTime? ahora,
  }) {
    if (forzar) return true;
    if (intentos <= 0) return true;
    final momento = ahora ?? DateTime.now();
    return !momento.isBefore(
      proximoIntento(intentos: intentos, ultimoIntento: ultimoIntento),
    );
  }

  bool requiereAtencionManual(int intentos) => intentos >= limiteIntentos;

  /// Texto para explicarle al inspector por qué algo no se está reintentando.
  String describirEspera({required int intentos, DateTime? ultimoIntento}) {
    if (requiereAtencionManual(intentos)) {
      return 'Falló $intentos veces. Revísalo y reintenta a mano.';
    }
    final momento = proximoIntento(
      intentos: intentos,
      ultimoIntento: ultimoIntento,
    );
    final restante = momento.difference(DateTime.now());
    if (restante.isNegative || restante.inSeconds < 5) {
      return 'Se reintentará en el próximo envío.';
    }
    if (restante.inMinutes < 1) {
      return 'Se reintentará en ${restante.inSeconds} s.';
    }
    if (restante.inHours < 1) {
      return 'Se reintentará en ${restante.inMinutes} min.';
    }
    return 'Se reintentará en ${restante.inHours} h.';
  }
}
