import 'dart:io';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../api/api_config.dart';

/// Resultado honesto de un intento de subida de fotografías.
///
/// Reemplaza el `bool` anterior, que no permitía distinguir "todo bien" de
/// "subieron 8 de 22". Quien llama debe marcar como sincronizada **solo** lo
/// que aparezca en [confirmadas].
class ResultadoSubida {
  /// `nombre_foto` que el servidor confirmó haber recibido.
  final Set<String> confirmadas;

  /// `nombre_foto` que el servidor rechazó o que no llegaron.
  final Set<String> rechazadas;

  /// Mensaje real del último error, para guardarlo en `ultimo_error`.
  final String? error;

  /// Código HTTP del último intento, cuando hubo respuesta.
  final int? codigoHttp;

  const ResultadoSubida({
    this.confirmadas = const {},
    this.rechazadas = const {},
    this.error,
    this.codigoHttp,
  });

  bool get todoConfirmado => rechazadas.isEmpty && error == null;
  bool get algoConfirmado => confirmadas.isNotEmpty;

  ResultadoSubida fusionar(ResultadoSubida otro) => ResultadoSubida(
    confirmadas: {...confirmadas, ...otro.confirmadas},
    rechazadas: {...rechazadas, ...otro.rechazadas}
      ..removeWhere((n) => confirmadas.contains(n) || otro.confirmadas.contains(n)),
    error: otro.error ?? error,
    codigoHttp: otro.codigoHttp ?? codigoHttp,
  );

  @override
  String toString() =>
      'ResultadoSubida(confirmadas: ${confirmadas.length}, '
      'rechazadas: ${rechazadas.length}, error: $error)';
}

class ImagenesPosteService {
  final String _url = "${ApiConfig.baseUrl}${ApiConfig.PosteImagenes}";
  final http.Client _client;

  ImagenesPosteService({http.Client? client}) : _client = client ?? http.Client();

  /// Tiempo máximo por lote. En zonas con señal débil una petición sin timeout
  /// puede quedar colgada indefinidamente y bloquear la cola.
  static const Duration timeoutLote = Duration(minutes: 4);

  // === Headers ===
  Future<Map<String, String>> _getHeaders() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('token') ?? '';
    return {
      'Authorization': 'Bearer $token',
      'Accept': 'application/json',
    };
  }

  // === Subida principal ===
  /// Sube las [imagenes] del poste y devuelve exactamente qué confirmó el
  /// servidor.
  ///
  /// Los lotes se mantienen como en la versión anterior (15 por petición
  /// cuando hay más de 20 fotos) porque el backend PHP tiene límites de
  /// `max_file_uploads` y `post_max_size`.
  Future<ResultadoSubida> subirImagenBatch(
    int posteId,
    Map<String, File> imagenes,
    Map<String, Map<String, dynamic>> metadatos,
  ) async {
    if (imagenes.isEmpty) {
      return const ResultadoSubida();
    }
    try {
      if (imagenes.length > 20) {
        return await _subirPorLotes(posteId, imagenes, metadatos);
      }
      return await _subirLote(posteId, imagenes, metadatos);
    } catch (e) {
      return ResultadoSubida(
        rechazadas: imagenes.keys.toSet(),
        error: 'Error inesperado al subir: $e',
      );
    }
  }

  Future<ResultadoSubida> _subirPorLotes(
    int posteId,
    Map<String, File> imagenes,
    Map<String, Map<String, dynamic>> metadatos,
  ) async {
    const tamanoLote = 15;
    final entries = imagenes.entries.toList();
    var acumulado = const ResultadoSubida();

    for (int i = 0; i < entries.length; i += tamanoLote) {
      final lote = Map.fromEntries(
        entries.sublist(i, (i + tamanoLote).clamp(0, entries.length)),
      );
      acumulado = acumulado.fusionar(
        await _subirLote(posteId, lote, metadatos),
      );
      await Future.delayed(const Duration(milliseconds: 500));
    }

    return acumulado;
  }

  Future<ResultadoSubida> _subirLote(
    int posteId,
    Map<String, File> imagenes,
    Map<String, Map<String, dynamic>> metadatos,
  ) async {
    final nombres = imagenes.keys.toSet();
    final uri = Uri.parse('$_url?poste_id=$posteId');
    final request = http.MultipartRequest('POST', uri);
    request.headers.addAll(await _getHeaders());

    int i = 0;
    for (var entry in imagenes.entries) {
      final nombre = entry.key;
      final file = entry.value;

      if (!await file.exists()) {
        // No se puede subir lo que no está en disco: se informa como rechazo
        // explícito en lugar de enviar un campo vacío.
        return ResultadoSubida(
          rechazadas: nombres,
          error: 'El archivo local de "$nombre" no existe: ${file.path}',
        );
      }

      final meta = _normalizarMetadatos(metadatos[nombre]);

      request.fields['nombre_foto_$i'] = nombre;
      request.fields['utm_este_$i'] = meta['utm_este']!;
      request.fields['utm_norte_$i'] = meta['utm_norte']!;
      request.fields['zona_$i'] = meta['zona']!;
      request.fields['fecha_captura_$i'] = meta['fecha']!;
      // Identificadores para que el backend pueda descartar reenvíos del mismo
      // archivo (idempotencia). Si los ignora, no cambia nada.
      request.fields['uuid_$i'] = meta['uuid']!;
      request.fields['checksum_$i'] = meta['checksum']!;

      request.files.add(await http.MultipartFile.fromPath(
        'imagen_$i',
        file.path,
        filename: '${nombre}_${DateTime.now().millisecondsSinceEpoch}.jpg',
      ));
      i++;
    }

    http.StreamedResponse res;
    String body;
    try {
      res = await _client.send(request).timeout(timeoutLote);
      body = await res.stream.bytesToString();
    } on SocketException catch (e) {
      return ResultadoSubida(
        rechazadas: nombres,
        error: 'Sin conexión con el servidor: ${e.message}',
      );
    } catch (e) {
      return ResultadoSubida(
        rechazadas: nombres,
        error: 'La subida no completó: $e',
      );
    }

    return _interpretarRespuesta(res.statusCode, body, nombres);
  }

  /// Traduce la respuesta del backend a confirmaciones concretas.
  ///
  /// ## Contrato asumido (pendiente de verificar contra el PHP)
  ///
  /// Se acepta cualquiera de estas formas como confirmación:
  ///   `{"success": true}` · `{"status": "success"}`
  ///
  /// Si además viene un arreglo con los nombres recibidos —`fotos`, `imagenes`
  /// o `data`, con `nombre_foto` en cada elemento— se usa para confirmar foto
  /// por foto. Mientras el backend no lo devuelva, la confirmación es por lote
  /// completo, que es la granularidad máxima disponible.
  ///
  /// **Regla:** ante cualquier ambigüedad se asume NO confirmado. Es preferible
  /// reintentar una foto ya subida (el backend deduplica por `uuid`/`checksum`)
  /// que dar por enviada una que no llegó.
  ResultadoSubida _interpretarRespuesta(
    int codigo,
    String body,
    Set<String> nombresEnviados,
  ) {
    if (codigo == 401 || codigo == 403) {
      return ResultadoSubida(
        rechazadas: nombresEnviados,
        codigoHttp: codigo,
        error: 'Sesión vencida o sin permiso (HTTP $codigo). '
            'Vuelve a iniciar sesión; tus fotos siguen guardadas.',
      );
    }
    if (codigo == 413) {
      return ResultadoSubida(
        rechazadas: nombresEnviados,
        codigoHttp: codigo,
        error: 'El servidor rechazó el envío por tamaño (HTTP 413). '
            'Se reintentará en lotes más pequeños.',
      );
    }

    final pareceJson = body.trimLeft().startsWith('{') ||
        body.trimLeft().startsWith('[');
    if (!pareceJson) {
      return ResultadoSubida(
        rechazadas: nombresEnviados,
        codigoHttp: codigo,
        error: 'El servidor respondió algo que no es JSON (HTTP $codigo): '
            '${_recorte(body)}',
      );
    }

    dynamic json;
    try {
      json = jsonDecode(body);
    } catch (e) {
      return ResultadoSubida(
        rechazadas: nombresEnviados,
        codigoHttp: codigo,
        error: 'JSON inválido del servidor (HTTP $codigo): ${_recorte(body)}',
      );
    }

    if (json is! Map) {
      return ResultadoSubida(
        rechazadas: nombresEnviados,
        codigoHttp: codigo,
        error: 'Respuesta con forma inesperada (HTTP $codigo).',
      );
    }

    final exito = json['success'] == true || json['status'] == 'success';
    if (codigo != 200 || !exito) {
      return ResultadoSubida(
        rechazadas: nombresEnviados,
        codigoHttp: codigo,
        error: (json['error'] ?? json['message'] ?? 'HTTP $codigo').toString(),
      );
    }

    // Confirmación foto por foto, si el backend la ofrece.
    final detalladas = _nombresConfirmados(json);
    if (detalladas != null && detalladas.isNotEmpty) {
      final confirmadas = detalladas.intersection(nombresEnviados);
      return ResultadoSubida(
        confirmadas: confirmadas,
        rechazadas: nombresEnviados.difference(confirmadas),
        codigoHttp: codigo,
        error: confirmadas.length == nombresEnviados.length
            ? null
            : 'El servidor confirmó ${confirmadas.length} de '
                '${nombresEnviados.length} fotografías.',
      );
    }

    // Confirmación por lote completo.
    return ResultadoSubida(confirmadas: nombresEnviados, codigoHttp: codigo);
  }

  Set<String>? _nombresConfirmados(Map json) {
    for (final clave in ['fotos', 'imagenes', 'data']) {
      final valor = json[clave];
      if (valor is List) {
        final nombres = <String>{};
        for (final e in valor) {
          if (e is Map && e['nombre_foto'] != null) {
            nombres.add(e['nombre_foto'].toString());
          } else if (e is String) {
            nombres.add(e);
          }
        }
        if (nombres.isNotEmpty) return nombres;
      }
    }
    return null;
  }

  /// Normaliza los metadatos al único formato que se envía al backend.
  ///
  /// Antes había dos formas distintas circulando: la de la pantalla de fotos
  /// (`utm_este`/`utm_norte`/`zona`) y la del lector EXIF (`lat`/`lon`), y el
  /// envío leía siempre las primeras. Cuando llegaban las segundas se enviaba
  /// la cadena literal `"null"` como coordenada.
  Map<String, String> _normalizarMetadatos(Map<String, dynamic>? meta) {
    final m = meta ?? const <String, dynamic>{};
    return {
      'utm_este': _texto(m['utm_este']),
      'utm_norte': _texto(m['utm_norte']),
      'zona': _texto(m['zona']),
      'fecha': _texto(m['fecha']).isEmpty
          ? DateTime.now().toIso8601String()
          : _texto(m['fecha']),
      'uuid': _texto(m['uuid']),
      'checksum': _texto(m['checksum']),
    };
  }

  /// Nunca produce la cadena `"null"`: un campo ausente viaja vacío.
  String _texto(dynamic v) {
    if (v == null) return '';
    final s = v.toString().trim();
    return (s == 'null' || s == 'NaN') ? '' : s;
  }

  String _recorte(String s) {
    final limpio = s.replaceAll(RegExp(r'\s+'), ' ').trim();
    return limpio.length <= 160 ? limpio : '${limpio.substring(0, 160)}…';
  }
}
