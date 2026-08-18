import '../api/api_config.dart';
import '../data/remoto/cliente_api.dart';

/// Consultas de postes contra el servidor.
///
/// Cada método devuelve la lista, o lanza [ErrorApi] con el motivo clasificado.
/// Antes devolvían `Map<String, dynamic>` con `success` y `error` como cadenas,
/// y quien llamaba no podía distinguir "sin red" de "sesión vencida".
class PosteService {
  PosteService({ClienteApi? api}) : _api = api ?? ClienteApi();

  final ClienteApi _api;

  /// 📥 Todos los postes de un proyecto.
  Future<List<Map<String, dynamic>>> listarPorProyecto(int proyectoId) async {
    final respuesta = await _api.get(
      ApiConfig.posteListarPorProyecto,
      parametros: {'proyecto_id': proyectoId},
    );
    return _lista(respuesta);
  }

  /// 🔍 Postes de una línea.
  Future<List<Map<String, dynamic>>> buscarPorLinea(
    String linea, {
    required int proyectoId,
  }) async {
    final respuesta = await _api.get(
      ApiConfig.buscarLinea,
      parametros: {'linea': linea, 'proyecto_id': proyectoId},
    );
    return _lista(respuesta);
  }

  /// 🔍 Postes por número de estructura.
  Future<List<Map<String, dynamic>>> buscarPorEstructura(
    String estructura, {
    required String linea,
    required int proyectoId,
  }) async {
    final respuesta = await _api.get(
      ApiConfig.posteBuscarEstructura,
      parametros: {
        'estructura': estructura,
        'linea': linea,
        'proyecto_id': proyectoId,
      },
    );
    return _lista(respuesta);
  }

  List<Map<String, dynamic>> _lista(RespuestaApi respuesta) {
    if (!respuesta.exito) {
      throw ErrorApi(
        TipoErrorApi.respuestaInesperada,
        codigoHttp: respuesta.codigoHttp,
        detalle: respuesta.mensajeServidor ?? 'El servidor no devolvió éxito.',
      );
    }
    final datos = respuesta.datos;
    if (datos == null) return const [];
    if (datos is! List) {
      throw ErrorApi(
        TipoErrorApi.respuestaInesperada,
        codigoHttp: respuesta.codigoHttp,
        detalle: 'Se esperaba una lista en "data".',
      );
    }
    return datos
        .whereType<Map>()
        .map((e) => Map<String, dynamic>.from(e))
        .toList();
  }
}
