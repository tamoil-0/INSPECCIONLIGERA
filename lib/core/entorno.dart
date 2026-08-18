/// Configuración por entorno, sin secretos en el código.
///
/// ## Por qué no un `.env`
///
/// El proyecto tenía `flutter_dotenv` declarado (sin usar) y la URL del
/// servidor escrita como constante. Un `.env` empaquetado dentro del APK **no
/// es un secreto**: se extrae con `unzip`. Y añade una dependencia y un archivo
/// de activos para nada.
///
/// Los valores llegan por `--dart-define` en el momento de compilar, así que
/// cambiar de entorno no exige tocar código:
///
/// ```bash
/// # Producción (valores por defecto)
/// flutter build apk --release
///
/// # Preproducción
/// flutter build apk --release \
///   --dart-define=API_BASE_URL=https://pruebas.grupoecoing.com/inspeccionligera/api \
///   --dart-define=ENTORNO=pruebas
///
/// # Servidor local durante el desarrollo
/// flutter run --dart-define=API_BASE_URL=http://192.168.0.101/api \
///             --dart-define=ENTORNO=local
/// ```
///
/// Ver `.env.example` para la lista completa de claves.
class Entorno {
  const Entorno._();

  /// URL base de la API. Sin barra final.
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://grupoecoing.com/inspeccionligera/api',
  );

  /// URL validada y sin barras finales.
  static String get apiBaseUrlNormalizada {
    final valor = apiBaseUrl.trim().replaceFirst(RegExp(r'/+$'), '');
    final uri = Uri.tryParse(valor);
    if (uri == null ||
        !uri.hasAuthority ||
        (uri.scheme != 'http' && uri.scheme != 'https')) {
      throw StateError('API_BASE_URL debe ser una URL HTTP(S) válida.');
    }
    return valor;
  }

  /// Nombre del entorno: `produccion`, `pruebas`, `local`.
  static const String nombre = String.fromEnvironment(
    'ENTORNO',
    defaultValue: 'produccion',
  );

  /// Segundos de espera por petición normal.
  static const int timeoutSegundos = int.fromEnvironment(
    'TIMEOUT_SEGUNDOS',
    defaultValue: 30,
  );

  /// Minutos de espera para una subida de fotografías.
  static const int timeoutSubidaMinutos = int.fromEnvironment(
    'TIMEOUT_SUBIDA_MINUTOS',
    defaultValue: 4,
  );

  /// Registro detallado en consola. Falso en release por defecto.
  static const bool registroDetallado = bool.fromEnvironment(
    'REGISTRO_DETALLADO',
    defaultValue: false,
  );

  /// Enviar literalmente `no_revisado` en los campos que el inspector no
  /// confirmó, en lugar del valor por defecto heredado.
  ///
  /// **Por defecto `false`, a propósito.** Es un cambio de contrato en 19 campos
  /// del formulario y el backend se está actualizando. Con `false` la app es
  /// compatible con el servidor actual: la interfaz sigue mostrando
  /// «No revisado» al inspector y `campos_revisados` viaja igual, así que el
  /// servidor ya puede distinguir lo revisado de lo que no; solo el valor del
  /// campo mantiene el formato antiguo.
  ///
  /// Activar cuando el backend acepte el valor:
  /// ```bash
  /// flutter build apk --release --dart-define=ENVIAR_NO_REVISADO=true
  /// ```
  ///
  /// Ver `BACKEND_CONTRATO.md`.
  static const bool enviarNoRevisado = bool.fromEnvironment(
    'ENVIAR_NO_REVISADO',
    defaultValue: false,
  );

  static bool get esProduccion => nombre == 'produccion';

  static Duration get timeout => const Duration(seconds: timeoutSegundos);
  static Duration get timeoutSubida =>
      const Duration(minutes: timeoutSubidaMinutos);

  /// Etiqueta corta para mostrar en el login. En producción no se muestra nada:
  /// solo interesa saberlo cuando NO se está en producción.
  static String? get etiquetaVisible => esProduccion ? null : nombre;
}
