import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import '../api/api_config.dart';

class PosteDatosService {
  final http.Client _client;
  PosteDatosService({http.Client? client}) : _client = client ?? http.Client();

  Future<bool> actualizarDatosPoste({
    required int posteId,
    required String token,
    required Map<String, dynamic> datos,
  }) async {
    final url = Uri.parse('${ApiConfig.baseUrl}${ApiConfig.posteActualizarDatos}?poste_id=$posteId');

    try {
      final response = await _client.put(
        url,
        headers: _buildHeaders(token),
        body: jsonEncode(datos),
      );

      final data = jsonDecode(response.body);
      return data['success'] == true;
    } catch (e) {
      print('❌ Error actualizarDatosPoste: $e');
      return false;
    }
  }

  Future<bool> agregarSeccionRST({
    required int posteId,
    required String token,
    required Map<String, dynamic> datos,
  }) async {
    final url = Uri.parse('${ApiConfig.baseUrl}${ApiConfig.posteAgregarRST}?poste_id=$posteId');

    try {
      final response = await _client.post(
        url,
        headers: _buildHeaders(token),
        body: jsonEncode(datos),
      );
      print('📥 Respuesta bruta del servidor: ${response.body}');
      final data = jsonDecode(response.body);
      return data['success'] == true;
    } catch (e) {
      print('❌ Error agregarSeccionRST: $e');
      return false;
    }
  }

  Future<bool> verificarConexion() async {
    try {
      final url = Uri.parse('${ApiConfig.baseUrl}${ApiConfig.postes}');
      final response = await _client.get(url);
      return response.statusCode < 500;
    } catch (e) {
      print('❌ Error de conectividad: $e');
      return false;
    }
  }
  Future<Map<String, bool>> obtenerEstadoSincronizacion({
    required int posteId,
    required String token,
  }) async {
    final url = Uri.parse('${ApiConfig.baseUrl}/postes/sincronizacion_estado.php?poste_id=$posteId');

    try {
      final response = await _client.get(
        url,
        headers: _buildHeaders(token),
      );

      final data = jsonDecode(response.body);
      if (data['success'] == true) {
        return {
          'formulario_subido': data['formulario_subido'] == true,
          'imagenes_subidas': data['imagenes_subidas'] == true,
        };
      }
    } catch (e) {
      print("❌ Error al consultar estado servidor: $e");
    }

    return {
      'formulario_subido': false,
      'imagenes_subidas': false,
    };
  }


  Map<String, String> _buildHeaders(String token) {
    return {
      "Content-Type": "application/json",
      "Accept": "application/json",
      "Authorization": "Bearer $token",
    };
  }
}
