import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

/// Tipo de conexión disponible.
enum TipoRed { ninguna, wifi, movil, otra }

/// Estado de red observable.
class EstadoRed {
  final TipoRed tipo;

  /// `true` solo si además de estar conectado hay **internet real**.
  ///
  /// Un Wi-Fi de hotel o un router sin salida dan `wifi` con `false`: la app
  /// no debe declararse en línea en ese caso.
  final bool hayInternet;

  final DateTime comprobadoEn;

  const EstadoRed({
    required this.tipo,
    required this.hayInternet,
    required this.comprobadoEn,
  });

  bool get conectado => tipo != TipoRed.ninguna && hayInternet;
  bool get esWifi => tipo == TipoRed.wifi;
  bool get esMovil => tipo == TipoRed.movil;

  String get descripcion {
    if (tipo == TipoRed.ninguna) return 'Sin conexión';
    if (!hayInternet) return 'Conectado sin internet';
    return esWifi ? 'Wi-Fi' : (esMovil ? 'Datos móviles' : 'Conectado');
  }

  @override
  String toString() => 'EstadoRed(${tipo.name}, internet: $hayInternet)';
}

/// Único punto de la app que observa la conectividad.
///
/// ## Por qué existe
///
/// Antes cada pantalla llamaba a `checkConnectivity()` por su cuenta, y el
/// formulario lanzaba una petición HTTP **en cada reconstrucción del AppBar**:
/// es decir, en cada cambio de cualquiera de los 22 desplegables. Eso son
/// decenas de peticiones por inspección, gastando batería y datos móviles en
/// campo, y ninguna de ellas servía para reaccionar a la recuperación de la
/// señal.
///
/// Aquí se escucha `onConnectivityChanged` **una sola vez**, el resultado se
/// expone como `ValueListenable` y la comprobación de internet real se cachea
/// unos segundos.
class ServicioConectividad {
  ServicioConectividad._();

  static final ServicioConectividad instancia = ServicioConectividad._();

  /// Cuánto se considera vigente una comprobación de internet real.
  static const Duration vigencia = Duration(seconds: 20);

  /// Sobrescribible en pruebas para no depender de la red.
  @visibleForTesting
  static Future<bool> Function()? comprobadorDeInternet;

  final ValueNotifier<EstadoRed> estado = ValueNotifier(
    EstadoRed(
      tipo: TipoRed.ninguna,
      hayInternet: false,
      comprobadoEn: DateTime.fromMillisecondsSinceEpoch(0),
    ),
  );

  final Connectivity _connectivity = Connectivity();
  StreamSubscription<ConnectivityResult>? _suscripcion;

  /// Se dispara cuando la conexión pasa de ausente a presente. Es la señal para
  /// intentar sincronizar lo pendiente.
  final _reconexiones = StreamController<EstadoRed>.broadcast();
  Stream<EstadoRed> get alRecuperarConexion => _reconexiones.stream;

  bool _iniciado = false;

  Future<void> iniciar() async {
    if (_iniciado) return;
    _iniciado = true;

    await _actualizar(await _connectivity.checkConnectivity());

    _suscripcion = _connectivity.onConnectivityChanged.listen(
      _actualizar,
      onError: (Object e) => debugPrint('Error observando conectividad: $e'),
    );
  }

  Future<void> detener() async {
    await _suscripcion?.cancel();
    _suscripcion = null;
    _iniciado = false;
  }

  /// Fuerza una comprobación. Si la última es reciente, devuelve la cacheada
  /// para no repetir la petición.
  Future<EstadoRed> comprobar({bool forzar = false}) async {
    final actual = estado.value;
    final vencida = DateTime.now().difference(actual.comprobadoEn) > vigencia;
    if (!forzar && !vencida) return actual;
    await _actualizar(await _connectivity.checkConnectivity());
    return estado.value;
  }

  Future<void> _actualizar(ConnectivityResult resultado) async {
    final tipo = _tipoDe(resultado);
    final anteriorConectado = estado.value.conectado;

    final hayInternet = tipo == TipoRed.ninguna
        ? false
        : await _verificarInternet();

    estado.value = EstadoRed(
      tipo: tipo,
      hayInternet: hayInternet,
      comprobadoEn: DateTime.now(),
    );

    if (!anteriorConectado && estado.value.conectado) {
      _reconexiones.add(estado.value);
    }
  }

  TipoRed _tipoDe(ConnectivityResult r) {
    switch (r) {
      case ConnectivityResult.wifi:
      case ConnectivityResult.ethernet:
        return TipoRed.wifi;
      case ConnectivityResult.mobile:
        return TipoRed.movil;
      case ConnectivityResult.none:
        return TipoRed.ninguna;
      default:
        return TipoRed.otra;
    }
  }

  /// Comprueba que hay salida real a internet, no solo interfaz levantada.
  Future<bool> _verificarInternet() async {
    final comprobador = comprobadorDeInternet;
    if (comprobador != null) return comprobador();
    try {
      final resultado = await InternetAddress.lookup(
        'one.one.one.one',
      ).timeout(const Duration(seconds: 5));
      return resultado.isNotEmpty && resultado.first.rawAddress.isNotEmpty;
    } catch (_) {
      return false;
    }
  }
}
