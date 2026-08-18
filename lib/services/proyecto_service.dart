import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../api/api_config.dart';

class ProyectoService {
  Future<Map<String, dynamic>> listarProyectos({String? estado, String? busqueda}) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('token');

    if (token == null) {
      return {'success': false, 'error': 'No hay token de autenticación'};
    }

    final url = Uri.parse('${ApiConfig.baseUrl}${ApiConfig.proyectosLista}').replace(
      queryParameters: {
        if (estado != null) 'estado': estado,
        if (busqueda != null) 'busqueda': busqueda,
      },
    );

    try {
      final response = await http.get(
        url,
        headers: {
          'Authorization': 'Bearer $token',
          'Content-Type': 'application/json',
        },
      );

      final data = jsonDecode(response.body);

      if (response.statusCode == 200) {
        return {'success': true, 'data': data['data']};
      } else {
        return {'success': false, 'error': data['error']};
      }
    } catch (e) {
      return {'success': false, 'error': 'Error de conexión: $e'};
    }
  }
}
