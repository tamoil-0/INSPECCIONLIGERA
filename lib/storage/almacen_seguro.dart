import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:jwt_decoder/jwt_decoder.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Almacenamiento de la sesión.
///
/// ## Qué cambia respecto a la versión anterior
///
/// El token JWT y el objeto completo del usuario se guardaban en
/// `SharedPreferences` **en texto plano**: en un teléfono con root, o a través
/// de una copia de seguridad de Android, cualquiera podía leerlos. Ahora van a
/// `flutter_secure_storage`, que usa el Keystore de Android y el Keychain de
/// iOS.
///
/// ## Migración sin cerrar sesiones
///
/// Un inspector con la app abierta y trabajo pendiente no debe verse expulsado
/// por una actualización. La primera lectura busca el token en el almacén
/// seguro; si no está, lo toma de `SharedPreferences`, lo copia al almacén
/// seguro y lo borra del antiguo. La sesión continúa.
///
/// ## Cerrar sesión no borra el trabajo
///
/// [cerrarSesion] limpia credenciales y **nada más**. La base local con
/// formularios y fotografías pendientes queda intacta: perder el trabajo de una
/// jornada por pulsar "Cerrar sesión" sería inaceptable. La separación segura
/// entre usuarios sigue pendiente: los datos heredados no guardan quién los
/// creó y asignarlos automáticamente podría ocultar trabajo a la cuenta real.
class AlmacenSeguro {
  AlmacenSeguro({FlutterSecureStorage? almacen})
    : _almacen =
          almacen ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(encryptedSharedPreferences: true),
          );

  final FlutterSecureStorage _almacen;

  static const _kToken = 'sesion_token';
  static const _kUsuario = 'sesion_usuario';

  // Claves antiguas, en texto plano.
  static const _kTokenLegado = 'token';
  static const _kUsuarioLegado = 'user';

  String? _tokenCache;

  // ===========================================================================
  // Token
  // ===========================================================================

  Future<String?> token() async {
    if (_tokenCache != null) return _tokenCache;

    try {
      final seguro = await _almacen.read(key: _kToken);
      if (seguro != null && seguro.isNotEmpty) {
        _tokenCache = seguro;
        return seguro;
      }
    } catch (e) {
      // Si el almacén seguro falla (dispositivos con Keystore roto), se sigue
      // con el valor heredado antes que dejar al inspector sin sesión.
      debugPrint('No se pudo leer el almacén seguro: $e');
    }

    return _migrarDesdePreferencias();
  }

  Future<void> guardarToken(String token) async {
    _tokenCache = token;
    try {
      await _almacen.write(key: _kToken, value: token);
    } catch (e) {
      debugPrint('No se pudo escribir en el almacén seguro: $e');
      // Respaldo: sin esto la sesión no persistiría en absoluto.
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_kTokenLegado, token);
    }
  }

  /// ¿El token está vencido?
  ///
  /// Antes nadie lo comprobaba: `main.dart` solo miraba que la cadena
  /// existiera, así que un token caducado producía sesiones zombis en las que
  /// cada subida fallaba con un error genérico.
  ///
  /// Un token que no se puede decodificar se considera **válido**: puede ser un
  /// formato que no sea JWT estándar, y expulsar al inspector por eso sería
  /// peor que dejar que el servidor responda 401.
  Future<bool> tokenVencido() async {
    final t = await token();
    if (t == null || t.isEmpty) return true;
    try {
      return JwtDecoder.isExpired(t);
    } catch (_) {
      return false;
    }
  }

  Future<DateTime?> expiracionToken() async {
    final t = await token();
    if (t == null || t.isEmpty) return null;
    try {
      return JwtDecoder.getExpirationDate(t);
    } catch (_) {
      return null;
    }
  }

  Future<bool> haySesion() async {
    final t = await token();
    return t != null && t.isNotEmpty;
  }

  // ===========================================================================
  // Usuario
  // ===========================================================================

  Future<void> guardarUsuario(Map<String, dynamic> usuario) async {
    final json = jsonEncode(usuario);
    try {
      await _almacen.write(key: _kUsuario, value: json);
    } catch (e) {
      debugPrint('No se pudo guardar el usuario en el almacén seguro: $e');
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_kUsuarioLegado, json);
    }
  }

  Future<Map<String, dynamic>?> usuario() async {
    String? json;
    try {
      json = await _almacen.read(key: _kUsuario);
    } catch (_) {
      json = null;
    }

    if (json == null || json.isEmpty) {
      final prefs = await SharedPreferences.getInstance();
      json = prefs.getString(_kUsuarioLegado);
      if (json != null && json.isNotEmpty) {
        try {
          await _almacen.write(key: _kUsuario, value: json);
          await prefs.remove(_kUsuarioLegado);
        } catch (_) {
          // Se sigue usando el valor heredado.
        }
      }
    }

    if (json == null || json.isEmpty) return null;
    try {
      return Map<String, dynamic>.from(jsonDecode(json) as Map);
    } catch (_) {
      return null;
    }
  }

  /// Identificador del usuario de la sesión, para separar datos entre cuentas.
  Future<int?> usuarioId() async {
    final u = await usuario();
    if (u == null) return null;
    final valor = u['id'] ?? u['usuario_id'];
    if (valor == null) return null;
    return valor is int ? valor : int.tryParse(valor.toString());
  }

  // ===========================================================================
  // Cierre de sesión
  // ===========================================================================

  /// Limpia credenciales. **No toca la base local.**
  Future<void> cerrarSesion() async {
    _tokenCache = null;
    try {
      await _almacen.delete(key: _kToken);
      await _almacen.delete(key: _kUsuario);
    } catch (e) {
      debugPrint('No se pudo limpiar el almacén seguro: $e');
    }
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kTokenLegado);
    await prefs.remove(_kUsuarioLegado);
  }

  // ===========================================================================
  // Interno
  // ===========================================================================

  Future<String?> _migrarDesdePreferencias() async {
    final prefs = await SharedPreferences.getInstance();
    final legado = prefs.getString(_kTokenLegado);
    if (legado == null || legado.isEmpty) return null;

    _tokenCache = legado;
    try {
      await _almacen.write(key: _kToken, value: legado);
      await prefs.remove(_kTokenLegado);
      debugPrint('Token migrado de SharedPreferences al almacén seguro.');
    } catch (e) {
      // Si no se puede migrar, la sesión sigue funcionando con el valor
      // heredado. Se reintentará en el siguiente arranque.
      debugPrint('Migración del token pospuesta: $e');
    }
    return legado;
  }

  /// Solo para pruebas.
  @visibleForTesting
  void olvidarCache() => _tokenCache = null;
}
