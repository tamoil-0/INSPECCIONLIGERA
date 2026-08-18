import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import '../../core/entorno.dart';
import '../../storage/almacen_seguro.dart';

/// Clasificación de lo que puede salir mal en una petición.
///
/// Antes todos los servicios devolvían `{'success': false, 'error': 'Error de
/// conexión: $e'}`: era imposible distinguir "no hay red" de "la sesión venció"
/// o de "el servidor devolvió HTML". Con esto la interfaz puede decirle al
/// inspector qué pasó y la cola de sincronización puede decidir si reintentar.
enum TipoErrorApi {
  sinRed,
  timeout,
  peticionInvalida, // 400
  noAutorizado, // 401
  prohibido, // 403
  noEncontrado, // 404
  conflicto, // 409
  cargaDemasiadoGrande, // 413
  datosInvalidos, // 422
  errorServidor, // 5xx
  respuestaNoJson, // el PHP devolvió HTML de error
  jsonInvalido,
  respuestaInesperada,
  desconocido,
}

extension TipoErrorApiTexto on TipoErrorApi {
  /// Mensaje para el inspector: sin jerga, y dejando claro que su trabajo sigue
  /// a salvo cuando corresponde.
  String get mensaje {
    switch (this) {
      case TipoErrorApi.sinRed:
        return 'Sin conexión con el servidor. Tu trabajo sigue guardado en el teléfono.';
      case TipoErrorApi.timeout:
        return 'El servidor tardó demasiado en responder. Se reintentará.';
      case TipoErrorApi.peticionInvalida:
        return 'El servidor rechazó los datos enviados.';
      case TipoErrorApi.noAutorizado:
        return 'Tu sesión venció. Inicia sesión otra vez; no se pierde nada.';
      case TipoErrorApi.prohibido:
        return 'No tienes permiso para esta operación.';
      case TipoErrorApi.noEncontrado:
        return 'El servidor no encontró el recurso solicitado.';
      case TipoErrorApi.conflicto:
        return 'El servidor ya tenía este registro con otros datos.';
      case TipoErrorApi.cargaDemasiadoGrande:
        return 'El envío es demasiado grande para el servidor. Se reintentará en partes.';
      case TipoErrorApi.datosInvalidos:
        return 'Algunos datos no pasaron la validación del servidor.';
      case TipoErrorApi.errorServidor:
        return 'El servidor tuvo un error interno. Se reintentará.';
      case TipoErrorApi.respuestaNoJson:
        return 'El servidor respondió algo inesperado (no JSON).';
      case TipoErrorApi.jsonInvalido:
        return 'La respuesta del servidor llegó mal formada.';
      case TipoErrorApi.respuestaInesperada:
        return 'La respuesta del servidor no tiene el formato esperado.';
      case TipoErrorApi.desconocido:
        return 'Error inesperado al comunicarse con el servidor.';
    }
  }
}

/// Error de API con su clasificación y el detalle real.
class ErrorApi implements Exception {
  final TipoErrorApi tipo;
  final int? codigoHttp;

  /// Mensaje que devolvió el servidor, o el detalle técnico. Se guarda en
  /// `ultimo_error` para poder diagnosticar desde el teléfono.
  final String detalle;

  const ErrorApi(this.tipo, {this.codigoHttp, this.detalle = ''});

  /// Merece la pena reintentar automáticamente.
  bool get esTransitorio =>
      tipo == TipoErrorApi.sinRed ||
      tipo == TipoErrorApi.timeout ||
      tipo == TipoErrorApi.errorServidor ||
      tipo == TipoErrorApi.cargaDemasiadoGrande;

  /// Requiere que el inspector vuelva a iniciar sesión.
  bool get exigeSesion =>
      tipo == TipoErrorApi.noAutorizado || tipo == TipoErrorApi.prohibido;

  String get mensajeUsuario => tipo.mensaje;

  @override
  String toString() =>
      'ErrorApi(${tipo.name}${codigoHttp == null ? '' : ' HTTP $codigoHttp'})'
      '${detalle.isEmpty ? '' : ': $detalle'}';
}

/// Respuesta correcta de la API.
class RespuestaApi {
  final int codigoHttp;
  final Map<String, dynamic> cuerpo;

  const RespuestaApi({required this.codigoHttp, required this.cuerpo});

  /// El backend PHP marca el éxito con `success: true` o `status: "success"`.
  bool get exito => cuerpo['success'] == true || cuerpo['status'] == 'success';

  dynamic get datos => cuerpo['data'];

  String? get mensajeServidor =>
      (cuerpo['error'] ?? cuerpo['message'])?.toString();
}

/// Cliente HTTP único de la aplicación.
///
/// ## Qué resuelve
///
/// * **Un solo sitio** donde se construyen cabeceras, se inyecta el token y se
///   interpretan las respuestas. Antes había cinco servicios repitiendo el
///   mismo bloque, cada uno con su propia forma de fallar.
/// * **`jsonDecode` a ciegas.** Todos los servicios hacían
///   `jsonDecode(response.body)` sin mirar el tipo de contenido: un error 500
///   de PHP devolviendo HTML producía `FormatException`. Aquí se comprueba
///   antes y se devuelve `respuestaNoJson` con los primeros caracteres del
///   cuerpo, que es lo único que permite diagnosticar el problema desde campo.
/// * **Timeouts.** No había ninguno: una petición podía quedarse colgada
///   indefinidamente con señal débil.
/// * **Codificación de parámetros.** `?linea=$linea` sin escapar rompía la
///   petición con líneas que llevaran `&` o espacios.
/// * **401 centralizado.** Un único punto avisa de que la sesión venció.
class ClienteApi {
  ClienteApi({http.Client? cliente, AlmacenSeguro? almacen, String? baseUrl})
    : _cliente = cliente ?? http.Client(),
      _almacen = almacen ?? AlmacenSeguro(),
      baseUrl = _normalizarBase(baseUrl ?? Entorno.apiBaseUrlNormalizada);

  final http.Client _cliente;
  final AlmacenSeguro _almacen;
  final String baseUrl;

  static String _normalizarBase(String valor) {
    final limpio = valor.trim().replaceFirst(RegExp(r'/+$'), '');
    final uri = Uri.tryParse(limpio);
    if (uri == null ||
        !uri.hasAuthority ||
        (uri.scheme != 'http' && uri.scheme != 'https')) {
      throw ArgumentError.value(valor, 'baseUrl', 'Debe ser una URL HTTP(S).');
    }
    return limpio;
  }

  /// Se invoca cuando el servidor responde 401/403. La app lo usa para llevar
  /// al login **sin borrar** nada de lo pendiente.
  static void Function()? alVencerSesion;

  Future<RespuestaApi> get(
    String ruta, {
    Map<String, dynamic>? parametros,
    bool requiereToken = true,
  }) => _enviar(
    'GET',
    ruta,
    parametros: parametros,
    requiereToken: requiereToken,
  );

  Future<RespuestaApi> post(
    String ruta, {
    Map<String, dynamic>? parametros,
    Object? cuerpo,
    bool requiereToken = true,
  }) => _enviar(
    'POST',
    ruta,
    parametros: parametros,
    cuerpo: cuerpo,
    requiereToken: requiereToken,
  );

  Future<RespuestaApi> put(
    String ruta, {
    Map<String, dynamic>? parametros,
    Object? cuerpo,
    bool requiereToken = true,
  }) => _enviar(
    'PUT',
    ruta,
    parametros: parametros,
    cuerpo: cuerpo,
    requiereToken: requiereToken,
  );

  /// Construye la URI escapando correctamente los parámetros.
  Uri uri(String ruta, [Map<String, dynamic>? parametros]) {
    final base = Uri.parse('$baseUrl$ruta');
    if (parametros == null || parametros.isEmpty) return base;
    return base.replace(
      queryParameters: {
        ...base.queryParameters,
        for (final e in parametros.entries)
          if (e.value != null) e.key: e.value.toString(),
      },
    );
  }

  Future<Map<String, String>> cabeceras({bool requiereToken = true}) async {
    final cabeceras = <String, String>{
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (requiereToken) {
      final token = await _almacen.token();
      if (token != null && token.isNotEmpty) {
        cabeceras['Authorization'] = 'Bearer $token';
      }
    }
    return cabeceras;
  }

  Future<RespuestaApi> _enviar(
    String metodo,
    String ruta, {
    Map<String, dynamic>? parametros,
    Object? cuerpo,
    bool requiereToken = true,
  }) async {
    final destino = uri(ruta, parametros);
    final cabecerasPeticion = await cabeceras(requiereToken: requiereToken);
    final cuerpoCodificado = cuerpo == null ? null : jsonEncode(cuerpo);

    if (Entorno.registroDetallado) {
      debugPrint('$metodo $destino');
    }

    http.Response respuesta;
    try {
      switch (metodo) {
        case 'GET':
          respuesta = await _cliente
              .get(destino, headers: cabecerasPeticion)
              .timeout(Entorno.timeout);
          break;
        case 'POST':
          respuesta = await _cliente
              .post(destino, headers: cabecerasPeticion, body: cuerpoCodificado)
              .timeout(Entorno.timeout);
          break;
        case 'PUT':
          respuesta = await _cliente
              .put(destino, headers: cabecerasPeticion, body: cuerpoCodificado)
              .timeout(Entorno.timeout);
          break;
        default:
          throw ArgumentError('Método no soportado: $metodo');
      }
    } on SocketException catch (e) {
      throw ErrorApi(TipoErrorApi.sinRed, detalle: e.message);
    } on TimeoutException {
      throw const ErrorApi(
        TipoErrorApi.timeout,
        detalle: 'sin respuesta en ${Entorno.timeoutSegundos} s',
      );
    } on http.ClientException catch (e) {
      throw ErrorApi(TipoErrorApi.sinRed, detalle: e.message);
    }

    return interpretar(respuesta.statusCode, respuesta.body);
  }

  /// Traduce código y cuerpo a `RespuestaApi` o lanza `ErrorApi`.
  ///
  /// Expuesto para que la subida multipart, que no pasa por `_enviar`, use la
  /// misma interpretación.
  RespuestaApi interpretar(int codigo, String cuerpo) {
    if (codigo == 401 || codigo == 403) {
      alVencerSesion?.call();
      throw ErrorApi(
        codigo == 401 ? TipoErrorApi.noAutorizado : TipoErrorApi.prohibido,
        codigoHttp: codigo,
        detalle: _recorte(cuerpo),
      );
    }
    if (codigo == 413) {
      throw ErrorApi(
        TipoErrorApi.cargaDemasiadoGrande,
        codigoHttp: codigo,
        detalle: _recorte(cuerpo),
      );
    }

    final recortado = cuerpo.trimLeft();
    final pareceJson = recortado.startsWith('{') || recortado.startsWith('[');
    if (!pareceJson) {
      throw ErrorApi(
        codigo >= 500
            ? TipoErrorApi.errorServidor
            : TipoErrorApi.respuestaNoJson,
        codigoHttp: codigo,
        detalle: _recorte(cuerpo),
      );
    }

    dynamic decodificado;
    try {
      decodificado = jsonDecode(cuerpo);
    } catch (e) {
      throw ErrorApi(
        TipoErrorApi.jsonInvalido,
        codigoHttp: codigo,
        detalle: _recorte(cuerpo),
      );
    }

    if (decodificado is! Map) {
      // Algunos endpoints podrían devolver un arreglo en la raíz.
      if (decodificado is List) {
        return RespuestaApi(
          codigoHttp: codigo,
          cuerpo: {'success': codigo == 200, 'data': decodificado},
        );
      }
      throw ErrorApi(
        TipoErrorApi.respuestaInesperada,
        codigoHttp: codigo,
        detalle: _recorte(cuerpo),
      );
    }

    final mapa = Map<String, dynamic>.from(decodificado);
    final respuesta = RespuestaApi(codigoHttp: codigo, cuerpo: mapa);

    if (codigo >= 500) {
      throw ErrorApi(
        TipoErrorApi.errorServidor,
        codigoHttp: codigo,
        detalle: respuesta.mensajeServidor ?? _recorte(cuerpo),
      );
    }
    if (codigo == 404) {
      throw ErrorApi(
        TipoErrorApi.noEncontrado,
        codigoHttp: codigo,
        detalle: respuesta.mensajeServidor ?? '',
      );
    }
    if (codigo == 409) {
      throw ErrorApi(
        TipoErrorApi.conflicto,
        codigoHttp: codigo,
        detalle: respuesta.mensajeServidor ?? '',
      );
    }
    if (codigo == 422) {
      throw ErrorApi(
        TipoErrorApi.datosInvalidos,
        codigoHttp: codigo,
        detalle: respuesta.mensajeServidor ?? '',
      );
    }
    if (codigo >= 400) {
      throw ErrorApi(
        TipoErrorApi.peticionInvalida,
        codigoHttp: codigo,
        detalle: respuesta.mensajeServidor ?? _recorte(cuerpo),
      );
    }

    return respuesta;
  }

  static String _recorte(String s) {
    final limpio = s.replaceAll(RegExp(r'\s+'), ' ').trim();
    if (limpio.isEmpty) return '(respuesta vacía)';
    return limpio.length <= 200 ? limpio : '${limpio.substring(0, 200)}…';
  }

  void cerrar() => _cliente.close();
}
