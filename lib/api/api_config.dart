import '../core/entorno.dart';

/// Rutas de la API.
///
/// La URL base ya no está escrita aquí: viene de [Entorno], que la toma de
/// `--dart-define` en el momento de compilar. Ver `.env.example`.
class ApiConfig {
  const ApiConfig._();

  /// URL base, sin barra final. Configurable por entorno.
  static String get baseUrl => Entorno.apiBaseUrl;

  // --- Sesión -------------------------------------------------------------
  static const String login = '/usuarios/login.php';

  // --- Proyectos ----------------------------------------------------------
  static const String proyectosLista = '/proyectos/listar.php';

  // --- Postes -------------------------------------------------------------
  static const String posteListarPorProyecto = '/postes/listar.php';
  static const String posteBuscarEstructura = '/postes/buscar_estructura.php';
  static const String buscarLinea = '/postes/buscar_por_linea.php';
  static const String posteActualizarDatos = '/postes/actualiza-datos.php';
  static const String posteAgregarRST = '/postes/agregar-seccion-rst.php';
  static const String posteImagenes = '/postes/imagenes-poste.php';
  static const String estadoSincronizacion =
      '/postes/sincronizacion_estado.php';
}
