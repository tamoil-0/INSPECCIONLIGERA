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
///   --dart-define=API_BASE_URL=https://pruebas.virrgoecoing.com/api \
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
    defaultValue: 'https://virrgoecoing.com/api',
  );

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

  static bool get esProduccion => nombre == 'produccion';

  static Duration get timeout => Duration(seconds: timeoutSegundos);
  static Duration get timeoutSubida => Duration(minutes: timeoutSubidaMinutos);

  /// Etiqueta corta para mostrar en el login. En producción no se muestra nada:
  /// solo interesa saberlo cuando NO se está en producción.
  static String? get etiquetaVisible => esProduccion ? null : nombre;
}
