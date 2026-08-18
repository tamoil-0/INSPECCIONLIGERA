import '../api/api_config.dart';
import '../data/remoto/cliente_api.dart';

/// Consultas de proyectos contra el servidor.
class ProyectoService {
  ProyectoService({ClienteApi? api}) : _api = api ?? ClienteApi();

  final ClienteApi _api;

  /// Devuelve la lista de proyectos, o lanza [ErrorApi] con el motivo.
  Future<List<Map<String, dynamic>>> listar({
    String? estado,
    String? busqueda,
  }) async {
    final respuesta = await _api.get(
      ApiConfig.proyectosLista,
      parametros: {
        if (estado != null) 'estado': estado,
        if (busqueda != null) 'busqueda': busqueda,
      },
    );

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
