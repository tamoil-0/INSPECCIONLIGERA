import 'package:shared_preferences/shared_preferences.dart';

import '../servicios/imagenes/optimizador_imagenes.dart';
import '../servicios/imagenes/perfil_dispositivo.dart';

/// Preferencias del aplicativo, con nombres de clave en un solo sitio.
///
/// Antes cada pantalla leía `prefs.getBool('modo_offline')` por su cuenta con
/// la cadena escrita a mano. Un typo en cualquiera de las cinco copias habría
/// hecho que el modo offline se ignorara en esa pantalla, sin ningún aviso.
class PreferenciasApp {
  PreferenciasApp._(this._prefs);

  final SharedPreferences _prefs;

  static PreferenciasApp? _instancia;

  static Future<PreferenciasApp> instancia() async {
    if (_instancia != null) return _instancia!;
    _instancia = PreferenciasApp._(await SharedPreferences.getInstance());
    return _instancia!;
  }

  /// Solo para pruebas: olvida la instancia en memoria.
  static void reiniciarParaPruebas() => _instancia = null;

  // --- Claves -------------------------------------------------------------
  static const _kModoOffline = 'modo_offline';
  static const _kPerfilForzado = 'perfil_dispositivo';
  static const _kPoliticaRetencion = 'politica_retencion';
  static const _kSoloWifi = 'sincronizar_solo_wifi';
  static const _kUltimaSincronizacion = 'ultima_sincronizacion';
  static const _kAvisoEspacioVisto = 'aviso_espacio_visto';

  // --- Modo offline -------------------------------------------------------

  bool get modoOffline => _prefs.getBool(_kModoOffline) ?? false;

  Future<void> setModoOffline(bool valor) =>
      _prefs.setBool(_kModoOffline, valor);

  // --- Perfil de procesamiento de imágenes --------------------------------

  /// Perfil elegido a mano en Ajustes, o el detectado si no hay ninguno.
  PerfilDispositivo get perfilImagenes =>
      PerfilDispositivo.porNombre(_prefs.getString(_kPerfilForzado)) ??
      PerfilDispositivo.detectar();

  bool get perfilEsAutomatico => _prefs.getString(_kPerfilForzado) == null;

  Future<void> setPerfilImagenes(PerfilDispositivo? perfil) async {
    if (perfil == null) {
      await _prefs.remove(_kPerfilForzado);
    } else {
      await _prefs.setString(_kPerfilForzado, perfil.nombre);
    }
  }

  // --- Retención de fotografías -------------------------------------------

  /// Qué se conserva en el teléfono tras optimizar.
  ///
  /// Por defecto [PoliticaRetencion.soloOptimizada]: 22 fotos de 12 MP
  /// conservando original y optimizada son ~310 MB por estructura, lo que llena
  /// un teléfono en unas diez torres. La versión optimizada es de alta calidad
  /// y es la que se sube; nunca se borra automáticamente.
  PoliticaRetencion get politicaRetencion {
    switch (_prefs.getString(_kPoliticaRetencion)) {
      case 'conservarOriginal':
        return PoliticaRetencion.conservarOriginal;
      case 'liberarTrasSincronizar':
        return PoliticaRetencion.liberarTrasSincronizar;
      default:
        return PoliticaRetencion.soloOptimizada;
    }
  }

  Future<void> setPoliticaRetencion(PoliticaRetencion politica) =>
      _prefs.setString(_kPoliticaRetencion, politica.name);

  // --- Red ----------------------------------------------------------------

  /// Cuando está activo, las fotografías solo se suben con Wi-Fi. Los
  /// formularios se envían igual: son kilobytes.
  bool get sincronizarSoloWifi => _prefs.getBool(_kSoloWifi) ?? false;

  Future<void> setSincronizarSoloWifi(bool valor) =>
      _prefs.setBool(_kSoloWifi, valor);

  // --- Última sincronización ----------------------------------------------

  DateTime? get ultimaSincronizacion {
    final iso = _prefs.getString(_kUltimaSincronizacion);
    return iso == null ? null : DateTime.tryParse(iso);
  }

  Future<void> marcarSincronizacion([DateTime? cuando]) => _prefs.setString(
    _kUltimaSincronizacion,
    (cuando ?? DateTime.now()).toIso8601String(),
  );

  // --- Avisos -------------------------------------------------------------

  bool get avisoEspacioVisto => _prefs.getBool(_kAvisoEspacioVisto) ?? false;

  Future<void> setAvisoEspacioVisto(bool valor) =>
      _prefs.setBool(_kAvisoEspacioVisto, valor);
}
