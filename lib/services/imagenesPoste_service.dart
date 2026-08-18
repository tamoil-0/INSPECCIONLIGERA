import 'dart:io';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:exif/exif.dart';
import '../api/api_config.dart';

class ImagenesPosteService {
  final String _url = "${ApiConfig.baseUrl}${ApiConfig.PosteImagenes}";

  // === Headers ===
  Future<Map<String, String>> _getHeaders() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('token') ?? '';
    return {
      'Authorization': 'Bearer $token',
      'Accept': 'application/json',
    };
  }

  // === GPS EXIF a decimal ===
  double _parseGps(String gpsString) {
    final parts = gpsString.replaceAll('[', '').replaceAll(']', '').split(',');
    if (parts.length < 3) return 0.0;
    final d = double.parse(parts[0]);
    final m = double.parse(parts[1]);
    final s = double.parse(parts[2]);
    return d + (m / 60.0) + (s / 3600.0);
  }

  // === Extraer EXIF básico (fecha, lat/lon opcional) ===
  Future<Map<String, dynamic>> _parseExifData(File image) async {
    try {
      final bytes = await image.readAsBytes();
      final exifData = await readExifFromBytes(bytes) ?? {};

      final fecha = exifData.containsKey('DateTimeOriginal')
          ? exifData['DateTimeOriginal'].toString().replaceAll(':', '-').replaceFirst(' ', 'T')
          : DateTime.now().toIso8601String();

      double? lat, lon;
      if (exifData.containsKey('GPSLatitude') && exifData.containsKey('GPSLongitude')) {
        lat = _parseGps(exifData['GPSLatitude'].toString());
        lon = _parseGps(exifData['GPSLongitude'].toString());
      }

      return {
        'fecha': fecha,
        'lat': lat?.toString() ?? '',
        'lon': lon?.toString() ?? '',
      };
    } catch (e) {
      print('❌ Error EXIF: $e');
      return {
        'fecha': DateTime.now().toIso8601String(),
        'lat': '',
        'lon': '',
      };
    }
  }

  // === Subida principal ===
  Future<bool> subirImagenBatch(
      int posteId,
      Map<String, File> imagenes,
      Map<String, Map<String, dynamic>> metadatos,
      ) async {
    try {
      if (imagenes.length > 20) {
        return await _subirPorLotes(posteId, imagenes, metadatos);
      }
      return await _subirLote(posteId, imagenes, metadatos);
    } catch (e) {
      print('❌ ERROR subirImagenBatch: $e');
      return false;
    }
  }

  Future<bool> _subirPorLotes(int posteId, Map<String, File> imagenes, Map<String, Map<String, dynamic>> metadatos) async {
    const tamanoLote = 15;
    final entries = imagenes.entries.toList();
    bool todoBien = true;

    for (int i = 0; i < entries.length; i += tamanoLote) {
      final lote = Map.fromEntries(entries.sublist(i, (i + tamanoLote).clamp(0, entries.length)));
      final exito = await _subirLote(posteId, lote, metadatos);
      if (!exito) todoBien = false;
      await Future.delayed(const Duration(milliseconds: 500));
    }

    return todoBien;
  }

  Future<bool> _subirLote(int posteId, Map<String, File> imagenes, Map<String, Map<String, dynamic>> metadatos) async {
    final uri = Uri.parse('$_url?poste_id=$posteId');
    final request = http.MultipartRequest('POST', uri);
    request.headers.addAll(await _getHeaders());

    int i = 0;
    for (var entry in imagenes.entries) {
      final nombre = entry.key;
      final file = entry.value;
      final meta = metadatos[nombre] ?? await _parseExifData(file);

      request.fields['nombre_foto_$i'] = nombre;
      request.fields['utm_este_$i'] = meta['utm_este'].toString();
      request.fields['utm_norte_$i'] = meta['utm_norte'].toString();
      request.fields['zona_$i'] = meta['zona'] ?? '';
      request.fields['fecha_captura_$i'] = meta['fecha'].toString();

      request.files.add(await http.MultipartFile.fromPath(
        'imagen_$i',
        file.path,
        filename: '${nombre}_${DateTime.now().millisecondsSinceEpoch}.jpg',
      ));
      i++;
    }

    final res = await request.send();
    final body = await res.stream.bytesToString();

    if (res.statusCode == 200 && body.contains('{')) {
      try {
        final jsonResponse = json.decode(body);
        return jsonResponse['success'] == true || jsonResponse['status'] == 'success';
      } catch (_) {
        print('⚠️ Error al interpretar respuesta JSON');
      }
    } else {
      print('❌ Respuesta inesperada: $body');
    }

    return false;
  }

  Future<void> testConnection() async {
    try {
      final response = await http.get(Uri.parse(_url), headers: await _getHeaders());
      print('Test conexión: ${response.statusCode}, body: ${response.body}');
    } catch (e) {
      print('❌ Error test conexión: $e');
    }
  }
}
