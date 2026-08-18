import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../api/api_config.dart';

class PosteService {
  /// 🔍 Buscar postes por estructura
  Future<Map<String, dynamic>> buscarPostesPorEstructura(String estructura) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('token');

    if (token == null) {
      return {
        'success': false,
        'error': 'Token no encontrado. Por favor inicia sesión nuevamente.',
        'token': null,
      };
    }

    final url = Uri.parse('${ApiConfig.baseUrl}${ApiConfig.posteBuscarEstructura}?estructura=$estructura');

    try {
      final response = await http.get(
        url,
        headers: {
          'Authorization': 'Bearer $token',
          'Content-Type': 'application/json',
        },
      );

      if (response.statusCode == 200) {
        return jsonDecode(response.body);
      } else {
        return {
          'success': false,
          'error': jsonDecode(response.body)['error'] ?? 'Error desconocido',
          'token': token,
        };
      }
    } catch (e) {
      return {
        'success': false,
        'error': 'Error al conectar con el servidor: $e',
        'token': token,
      };
    }
  }

  /// 📥 Descargar todos los postes de un proyecto
  Future<Map<String, dynamic>> listarPostesPorProyecto(int proyectoId) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('token');

    if (token == null) {
      return {
        'success': false,
        'error': 'Token no encontrado. Por favor inicia sesión nuevamente.',
      };
    }

    final url = Uri.parse('${ApiConfig.baseUrl}${ApiConfig.posteListarPorProyecto}?proyecto_id=$proyectoId');

    try {
      final response = await http.get(
        url,
        headers: {
          'Authorization': 'Bearer $token',
          'Content-Type': 'application/json',
        },
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return {'success': true, 'data': data['data']};
      } else {
        final error = jsonDecode(response.body)['error'] ?? 'Error desconocido';
        return {'success': false, 'error': error};
      }
    } catch (e) {
      return {
        'success': false,
        'error': 'Error de conexión: $e',
      };
    }
  }

  /// 🔍 Buscar postes por línea (nuevo método)
  Future<Map<String, dynamic>> buscarPostesPorLinea(String linea) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('token');

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
