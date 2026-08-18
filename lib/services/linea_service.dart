import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../api/api_config.dart';

class LineaService {
  Future<Map<String, dynamic>> buscarPostesPorLinea(String linea) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('token');

    // Validar token
    if (token == null) {
      return {
        'success': false,
        'error': 'Token no encontrado. Por favor inicia sesión nuevamente.',
      };
    }

    final url = Uri.parse('${ApiConfig.baseUrl}${ApiConfig.BuscarLinea}?linea=$linea');

    try {
      final response = await http.get(
        url,
        headers: {
          'Authorization': 'Bearer $token',
          'Content-Type': 'application/json',
        },
      );

      final data = jsonDecode(response.body);

      if (response.statusCode == 200 && data['success'] == true) {
        return {
          'success': true,
          'data': data['data'],
        };
      } else {
        return {
          'success': false,
          'error': data['error'] ?? 'Error al consultar línea',
        };
      }
    } catch (e) {
      return {
        'success': false,
        'error': 'Error de conexión: $e',
      };
    }
  }
}
