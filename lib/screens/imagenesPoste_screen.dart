    import 'package:flutter/material.dart';
    import 'package:image_picker/image_picker.dart';
    import '../services/imagenesPoste_service.dart';
    import '../database/database_helper.dart';
    import 'dart:io';
    import 'buscar_linea_screen.dart';
    import 'package:permission_handler/permission_handler.dart';
    import 'dart:math';
    import 'package:geolocator/geolocator.dart';
    import 'package:shared_preferences/shared_preferences.dart';
    import 'dart:async';
    import 'dart:io' show InternetAddress;

    class ImagenesPosteScreen extends StatefulWidget {
      final int posteId;
      final String numeroEstructura;
      final int proyectoId;
      final String proyectoNombre;
      const ImagenesPosteScreen({
        super.key,
        required this.posteId,
        required this.numeroEstructura,
        required this.proyectoId,
        required this.proyectoNombre,
      });

      @override
      State<ImagenesPosteScreen> createState() => _ImagenesPosteScreenState();
    }

    class _ImagenesPosteScreenState extends State<ImagenesPosteScreen> {
      final ImagePicker _picker = ImagePicker();
      final ImagenesPosteService _service = ImagenesPosteService();
      final Map<String, File?> _imagenesSubidas = {};
      final Map<String, Map<String, dynamic>> _metadatosImagenes = {};
      bool _enviando = false;
      double _progress = 0;
      bool _modoOffline = false;
      bool _hayInternet = false;

      final List<String> _fotosRequeridas = [
        'placa', 'torre_parte_inferior', 'torre_parte_superior', 'base_torre',
        'mensulas', 'crucetas', 'perfiles_angulares', 'atiescalamiento', 'otros',
        'aisladores_fase_r_atras', 'aisladores_fase_s_atras', 'aisladores_fase_t_atras',
        'aisladores_fase_r_adelante', 'aisladores_fase_s_adelante', 'aisladores_fase_t_adelante',
        'ferreteria_fase_r', 'ferreteria_fase_s', 'ferreteria_fase_t', 'cable_guarda',
        'ferreteria_de_cable_de_guarda', 'conductor', 'ferreteria_de_conductor', 'puesta_tierra', 'retenida',
        'faja_servidumbre', 'ubicacion_acceso'
      ];

      final List<String> _fotosOpcionales = [
        'otros',
        'aisladores_fase_r_adelante',
        'aisladores_fase_s_adelante',
        'aisladores_fase_t_adelante',
      ];

      @override
      void initState() {
        super.initState();
        _pedirPermisosUbicacion();
        _cargarEstadoConexion();
      }

      Future<void> _pedirPermisosUbicacion() async {
        final status = await Permission.location.request();
        if (!status.isGranted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Permiso de ubicación es necesario.')),
          );
        }
      }

      Future<void> _cargarEstadoConexion() async {
        final prefs = await SharedPreferences.getInstance();
        final modoOffline = prefs.getBool('modo_offline') ?? false;
        final conectado = await _verificarConexion();
        if (mounted) {
          setState(() {
            _modoOffline = modoOffline;
            _hayInternet = conectado;
          });
        }
      }

      Future<bool> _verificarConexion() async {
        try {
          final result = await InternetAddress.lookup('google.com');
          return result.isNotEmpty && result[0].rawAddress.isNotEmpty;
        } catch (_) {
          return false;
        }
      }

      Map<String, dynamic> _latLonToUTM(double lat, double lon) {
        const a = 6378137.0;
        const f = 1 / 298.257223563;
        const k0 = 0.9996;
        final e = sqrt(f * (2 - f));
        final zone = ((lon + 180) / 6).floor() + 1;
        final lonOrigin = (zone - 1) * 6 - 180 + 3;
        final latRad = lat * pi / 180;
        final lonRad = lon * pi / 180;
        final lonOriginRad = lonOrigin * pi / 180;

        final N = a / sqrt(1 - pow(e * sin(latRad), 2));
        final T = pow(tan(latRad), 2);
        final C = pow(e, 2) / (1 - pow(e, 2)) * pow(cos(latRad), 2);
        final A = cos(latRad) * (lonRad - lonOriginRad);

        final M = a * ((1 - pow(e, 2) / 4 - 3 * pow(e, 4) / 64 - 5 * pow(e, 6) / 256) * latRad -
            (3 * pow(e, 2) / 8 + 3 * pow(e, 4) / 32 + 45 * pow(e, 6) / 1024) * sin(2 * latRad) +
            (15 * pow(e, 4) / 256 + 45 * pow(e, 6) / 1024) * sin(4 * latRad) -
            (35 * pow(e, 6) / 3072) * sin(6 * latRad));

        double easting = k0 * N * (A + (1 - T + C) * pow(A, 3) / 6 +
            (5 - 18 * T + T * T + 72 * C - 58 * pow(e, 2) / (1 - pow(e, 2))) * pow(A, 5) / 120) +
            500000.0;

        double northing = k0 * (M + N * tan(latRad) *
            (pow(A, 2) / 2 + (5 - T + 9 * C + 4 * C * C) * pow(A, 4) / 24 +
                (61 - 58 * T + T * T + 600 * C - 330 * pow(e, 2) / (1 - pow(e, 2))) * pow(A, 6) / 720));

        if (lat < 0) northing += 10000000.0;

        const letters = "CDEFGHJKLMNPQRSTUVWX";
        final letra = (lat >= -80 && lat <= 84) ? letters[((lat + 80) ~/ 8)] : '?';

        return {
          'utmEste': double.parse(easting.toStringAsFixed(2)),
          'utmNorte': double.parse(northing.toStringAsFixed(2)),
          'zona': '$zone$letra'
        };
      }

      Future<Position?> _obtenerPosicionPrecisa() async {
        try {
          return await Geolocator.getCurrentPosition(
            desiredAccuracy: LocationAccuracy.high,
            timeLimit: const Duration(seconds: 10),
          );
        } catch (e) {
          final ultima = await Geolocator.getLastKnownPosition();
          return ultima;
        }
      }

      Future<void> _tomarFoto(String nombreFoto) async {
        final XFile? foto = await _picker.pickImage(source: ImageSource.camera);
        if (foto == null) return;

        final file = File(foto.path);
        final String fecha = DateTime.now().toIso8601String();
        final posicion = await _obtenerPosicionPrecisa();

        double? lat = posicion?.latitude;
        double? lon = posicion?.longitude;

        final utm = (lat != null && lon != null)
            ? _latLonToUTM(lat, lon)
            : {'utmEste': 0.0, 'utmNorte': 0.0, 'zona': 'NA'};

        _metadatosImagenes[nombreFoto] = {
          'utm_este': utm['utmEste'],
          'utm_norte': utm['utmNorte'],
          'zona': utm['zona'],
          'fecha': fecha,
        };

        setState(() => _imagenesSubidas[nombreFoto] = file);
      }
      void _mostrarModalExito(String titulo, String mensaje) {
        showDialog(
          context: context,
          barrierDismissible: false, // Evita que el usuario lo cierre tocando fuera
          builder: (BuildContext context) {
            return AlertDialog(
              backgroundColor: const Color(0xFFFAFAFA),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(20),
              ),
              title: Row(
                children: [
                  const Icon(Icons.check_circle_outline, color: Color(0xFF2E7D32)),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      titulo,
                      style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF2E7D32),
                        fontSize: 18,
                      ),
                    ),
                  ),
                ],
              ),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    mensaje,
                    style: const TextStyle(
                      fontSize: 16,
                      color: Color(0xFF3A3A3A),
                    ),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    "Puedes continuar con la siguiente estructura o volver al proyecto.",
                    style: TextStyle(
                      fontSize: 14,
                      color: Color(0xFF0D47A1),
                      fontWeight: FontWeight.w600,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
              actions: [
                TextButton.icon(
                  icon: const Icon(Icons.arrow_forward_ios, color: Colors.white, size: 18),
                  label: const Text("Continuar", style: TextStyle(color: Colors.white)),
                  style: TextButton.styleFrom(
                    backgroundColor: const Color(0xFF0D47A1),
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                  onPressed: () {
                    Navigator.of(context).pop(); // Cierra el modal
                    Navigator.pop(context); // Cierra ImagenesPosteScreen y permite la navegación en cascada
                  },


                ),
              ],
            );
          },
        );
      }


      Future<void> _enviarTodasLasFotos() async {
        if (!_todasLasFotosTomadas()) return;
        setState(() => _enviando = true);

        final imagenes = Map<String, File>.from(
          _imagenesSubidas.map((k, v) => MapEntry(k, v!)),
        );

        if (_hayInternet && !_modoOffline) {
          final success = await _service.subirImagenBatch(
            widget.posteId,
            imagenes,
            _metadatosImagenes,
          );
          setState(() => _progress = 1.0);

          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(success ? '¡Fotos subidas!' : 'Error al subir.')),
          );
        } else {
          final db = DatabaseHelper();
          for (var entry in imagenes.entries) {
            final meta = _metadatosImagenes[entry.key];
            await db.guardarImagenPosteLocal(
              posteId: widget.posteId,
              nombreFoto: entry.key,
              rutaArchivo: entry.value.path,
              utmEste: meta?['utm_este']?.toString(),
              utmNorte: meta?['utm_norte']?.toString(),
              fechaInspeccion: meta?['fecha']?.toString(),
              zona: meta?['zona']?.toString(),
            );
          }
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Sin conexión. Guardado local exitoso.')),
          );
        }

        _mostrarModalExito("¡Envío exitoso!", "Las fotos han sido registradas correctamente.");

      }


      bool _todasLasFotosTomadas() {
        final requeridas = _fotosRequeridas.where((f) => !_fotosOpcionales.contains(f));
        return requeridas.every((foto) => _imagenesSubidas[foto] != null);
      }

      Widget _buildFotoItem(String nombreFoto) {
        final fueSubida = _imagenesSubidas.containsKey(nombreFoto);
        final file = _imagenesSubidas[nombreFoto];
        final esOpcional = _fotosOpcionales.contains(nombreFoto);

        final Color fondo = fueSubida
            ? const Color(0xFFE8F5E9) // Verde claro para subidas
            : esOpcional
            ? const Color(0xFFE3F2FD) // Azul claro para opcionales
            : const Color(0xFFFFEBEE); // Rojo claro para pendientes

        final Color borde = fueSubida
            ? const Color(0xFF2E7D32) // Verde oscuro
            : esOpcional
            ? const Color(0xFF1565C0) // Azul profundo
            : const Color(0xFFC62828); // Rojo oscuro

        return Container(
          margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          decoration: BoxDecoration(
            color: fondo,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: borde, width: 1.5),
            boxShadow: [
              BoxShadow(
                color: borde.withOpacity(0.15),
                blurRadius: 6,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: ListTile(
            contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            leading: file != null
                ? ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.file(file, width: 55, height: 55, fit: BoxFit.cover),
            )
                : Icon(Icons.photo_camera, size: 50, color: borde),
            title: Text(
              nombreFoto.replaceAll('_', ' ').toUpperCase(),
              style: TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.w600,
                color: fueSubida ? Colors.black87 : borde,
              ),
            ),
            subtitle: file != null
                ? Text(
              '${(file.lengthSync() / (1024 * 1024)).toStringAsFixed(2)} MB',
              style: const TextStyle(fontSize: 13),
            )
                : esOpcional
                ? const Text('Opcional', style: TextStyle(fontSize: 13))
                : const Text('Obligatoria', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w500)),
            trailing: Icon(
              fueSubida ? Icons.check_circle_rounded : Icons.camera_alt_rounded,
              color: fueSubida ? const Color(0xFF2E7D32) : borde,
              size: 28,
            ),
            onTap: () => _tomarFoto(nombreFoto),
          ),
        );
      }


      Widget _iconoModoOffline() {
        return Tooltip(
          message: _modoOffline ? 'Modo offline ACTIVADO' : 'Modo offline DESACTIVADO',
          child: Icon(
            _modoOffline ? Icons.cloud_off : Icons.cloud_done,
            color: _modoOffline ? Colors.orange : Colors.blue,
          ),
        );
      }

      Widget _iconoInternet() {
        return Tooltip(
          message: _hayInternet ? 'Conectado a Internet' : 'Sin conexión',
          child: Icon(
            _hayInternet ? Icons.wifi : Icons.wifi_off,
            color: _hayInternet ? Colors.green : Colors.red,
          ),
        );
      }

      @override
      Widget build(BuildContext context) {
        return Scaffold(
          appBar: AppBar(
            flexibleSpace: Container(
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  colors: [Color(0xFF8B0000), Color(0xFF0D47A1)],
                  begin: Alignment.centerLeft,
                  end: Alignment.centerRight,
                ),
              ),
            ),
            title: Row(
              children: [
                Icon(Icons.photo_library_rounded, color: Colors.white),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'Fotos - Estructura ${widget.numeroEstructura}',
                    style: const TextStyle(color: Colors.white, fontSize: 18),
                  ),
                ),
              ],
            ),

            iconTheme: const IconThemeData(color: Colors.white),
            actions: [
              Padding(padding: const EdgeInsets.all(8), child: _iconoModoOffline()),
              Padding(padding: const EdgeInsets.all(8), child: _iconoInternet()),
            ],
          ),

          floatingActionButton: _todasLasFotosTomadas()
              ? Container(
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [Color(0xFF0D47A1), Color(0xFF8B0000)],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(30),
            ),
            child: FloatingActionButton.extended(
              backgroundColor: Colors.transparent,
              elevation: 0,
              icon: _enviando
                  ? const CircularProgressIndicator(color: Colors.white)
                  : const Icon(Icons.send_rounded, color: Colors.white),
              label: const Text('ENVIAR TODO', style: TextStyle(color: Colors.white)),
              onPressed: _enviando ? null : _enviarTodasLasFotos,
            ),
          )
              : null,

          body: Column(
            children: [
              if (_enviando)
                LinearProgressIndicator(
                  value: _progress,
                  minHeight: 4,
                  backgroundColor: Colors.grey[200],
                ),
              Expanded(
                child: ListView.builder(
                  itemCount: _fotosRequeridas.length,
                  itemBuilder: (context, index) => _buildFotoItem(_fotosRequeridas[index]),
                ),
              ),
            ],
          ),
        );
      }
    }
