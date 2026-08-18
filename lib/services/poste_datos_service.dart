import '../api/api_config.dart';
import '../data/remoto/cliente_api.dart';

/// Envío de los datos del formulario técnico y del tablero RST.
///
/// Los métodos devuelven `true` solo cuando el servidor **confirma**, y lanzan
/// [ErrorApi] con el motivo clasificado cuando algo falla. La versión anterior
/// devolvía `false` para todo (sin red, sesión vencida, error del servidor) y
/// se comía la causa en un `print`.
class PosteDatosService {
  PosteDatosService({ClienteApi? api}) : _api = api ?? ClienteApi();

  final ClienteApi _api;

  /// Actualiza los datos del formulario de un poste.
  Future<bool> actualizarDatosPoste({
    required int posteId,
    required String token,
    required Map<String, dynamic> datos,
  }) async {
    final respuesta = await _api.put(
      ApiConfig.posteActualizarDatos,
      parametros: {'poste_id': posteId},
      cuerpo: datos,
    );
    return respuesta.exito;
  }

  /// Envía las marcas del tablero RST.
  Future<bool> agregarSeccionRST({
    required int posteId,
    required String token,
    required Map<String, dynamic> datos,
  }) async {
    final respuesta = await _api.post(
      ApiConfig.posteAgregarRST,
      parametros: {'poste_id': posteId},
      cuerpo: datos,
    );
    return respuesta.exito;
  }

  /// Consulta si el servidor ya tiene formulario e imágenes de un poste.
  ///
  /// Se usa para la tabla comparativa local/servidor. Si la consulta falla se
  /// propaga el error: antes devolvía `false, false` en silencio, lo que se
  /// mostraba como "el servidor no lo tiene" cuando en realidad no se había
  /// podido preguntar.
  Future<({bool formulario, bool imagenes})> obtenerEstadoSincronizacion({
    required int posteId,
    required String token,
  }) async {
    final respuesta = await _api.get(
      ApiConfig.estadoSincronizacion,
      parametros: {'poste_id': posteId},
    );
    if (!respuesta.exito) {
      throw ErrorApi(
        TipoErrorApi.respuestaInesperada,
        codigoHttp: respuesta.codigoHttp,
        detalle: respuesta.mensajeServidor ?? 'Sin éxito en la respuesta.',
      );
    }
    return (
      formulario: respuesta.cuerpo['formulario_subido'] == true,
      imagenes: respuesta.cuerpo['imagenes_subidas'] == true,
    );
  }
}
