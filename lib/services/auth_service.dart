import '../api/api_config.dart';
import '../data/remoto/cliente_api.dart';
import '../storage/almacen_seguro.dart';

/// Resultado de un intento de inicio de sesión.
class ResultadoLogin {
  final bool exito;
  final Map<String, dynamic>? usuario;
  final ErrorApi? error;
  final String? mensajeServidor;

  const ResultadoLogin({
    required this.exito,
    this.usuario,
    this.error,
    this.mensajeServidor,
  });

  /// Texto listo para mostrar: prioriza lo que dijo el servidor y, si no hay
  /// nada, usa el mensaje según el tipo de error.
  String get mensaje {
    if (exito) return 'Sesión iniciada.';
    if (mensajeServidor != null && mensajeServidor!.isNotEmpty) {
      return mensajeServidor!;
    }
    return error?.mensajeUsuario ?? 'No se pudo iniciar sesión.';
  }

  bool get esProblemaDeRed =>
      error != null &&
      (error!.tipo == TipoErrorApi.sinRed ||
          error!.tipo == TipoErrorApi.timeout);
}

class AuthService {
  AuthService({ClienteApi? api, AlmacenSeguro? almacen})
    : _api = api ?? ClienteApi(),
      _almacen = almacen ?? AlmacenSeguro();

  final ClienteApi _api;
  final AlmacenSeguro _almacen;

  Future<ResultadoLogin> login(String nombreUsuario, String contrasena) async {
    try {
      final respuesta = await _api.post(
        ApiConfig.login,
        requiereToken: false,
        cuerpo: {'nombre_usuario': nombreUsuario, 'contrasena': contrasena},
      );

      final token = respuesta.cuerpo['token']?.toString();
      if (token == null || token.isEmpty) {
        return ResultadoLogin(
          exito: false,
          mensajeServidor:
              respuesta.mensajeServidor ??
              'El servidor no devolvió un token de sesión.',
        );
      }

      await _almacen.guardarToken(token);

      final usuario = respuesta.cuerpo['usuario'];
      if (usuario is Map) {
        await _almacen.guardarUsuario(Map<String, dynamic>.from(usuario));
      }

      return ResultadoLogin(
        exito: true,
        usuario: usuario is Map ? Map<String, dynamic>.from(usuario) : null,
      );
    } on ErrorApi catch (e) {
      // 401 en el login significa credenciales incorrectas, no sesión vencida.
      final mensaje = e.tipo == TipoErrorApi.noAutorizado
          ? 'Usuario o contraseña incorrectos.'
          : (e.detalle.isNotEmpty && e.detalle.length < 160 ? e.detalle : null);
      return ResultadoLogin(exito: false, error: e, mensajeServidor: mensaje);
    } catch (e) {
      return ResultadoLogin(
        exito: false,
        mensajeServidor: 'Error inesperado al iniciar sesión: $e',
      );
    }
  }

  Future<Map<String, dynamic>?> getUsuarioActual() => _almacen.usuario();

  Future<bool> haySesion() => _almacen.haySesion();

  Future<bool> sesionVencida() => _almacen.tokenVencido();

  /// Cierra la sesión **sin tocar la base local**.
  ///
  /// Los formularios y las fotografías pendientes se conservan: perder el
  /// trabajo de una jornada por pulsar "Cerrar sesión" sería inaceptable.
  Future<void> logout() => _almacen.cerrarSesion();
}
