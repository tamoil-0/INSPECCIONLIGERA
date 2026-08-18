 import 'dart:io';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../database/database_helper.dart';
import '../services/poste_datos_service.dart';
import '../services/imagenesPoste_service.dart';
 import 'package:permission_handler/permission_handler.dart';
 import 'package:path_provider/path_provider.dart';
class DetalleLineaScreen extends StatefulWidget {
  final int proyectoId;
  final String linea;

  const DetalleLineaScreen({super.key, required this.proyectoId, required this.linea});

  @override
  State<DetalleLineaScreen> createState() => _DetalleLineaScreenState();
}

class _DetalleLineaScreenState extends State<DetalleLineaScreen> {
  final DatabaseHelper _db = DatabaseHelper();
  final PosteDatosService _posteService = PosteDatosService();
  final ImagenesPosteService _imagenService = ImagenesPosteService();

  String? _posteActualSincronizando;

  late SharedPreferences _prefs;
  List<Map<String, dynamic>> _todosLosPostes = [];
  List<Map<String, dynamic>> _postesVisibles = [];
  int _paginaActual = 0;
  int _tamanioPagina = 10;
  bool _cargando = true;
  double _progreso = 0.0;
  bool _estadoServidorVisible = false;
  bool _sincronizando = false;
  String filtro = "Todos";

  @override
  void initState() {
    super.initState();
    _init();
  }


  Future<void> _init() async {
    _prefs = await SharedPreferences.getInstance();
    await _cargarPostesDeLinea();
  }

  Future<void> _cargarPostesDeLinea() async {
    setState(() {
      _cargando = true;
      _progreso = 0.0;
    });

    final postes = await _db.obtenerPostesConEstadoPorLinea(widget.proyectoId, widget.linea);
    _todosLosPostes = postes.map((p) => Map<String, dynamic>.from(p)).toList();
    await _cargarPagina(_paginaActual, consultarServidor: false);

    setState(() => _cargando = false);
  }
  Future<void> _exportarImagenesOrganizadas() async {
    final permiso = await Permission.storage.request();
    if (!permiso.isGranted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('❌ Permiso de almacenamiento denegado')),
      );
      return;
    }

    final appId = 'ecoing'; // o usa: const String.fromEnvironment('APP_NAME');
    final carpetaBase = Directory('/storage/emulated/0/Download/imagenes_poste_$appId');
    if (!await carpetaBase.exists()) {
      await carpetaBase.create(recursive: true);
    }

    final imagenes = await _db.obtenerTodasLasImagenes(); // Asegúrate que tenga: proyecto_id, poste_id, nombre_foto, ruta_archivo

    int nuevas = 0;
    int errores = 0;

    for (final img in imagenes) {
      final proyectoId = img['proyecto_id'].toString();
      final posteId = img['poste_id'].toString();
      final nombreOriginal = img['nombre_foto'] ?? 'foto_${img['id']}';
      final nombre = nombreOriginal.endsWith('.jpg') ? nombreOriginal : '$nombreOriginal.jpg';

      final origen = File(img['ruta_archivo'].toString());
      print('🔍 Revisando imagen: ${img['nombre_foto']}');
      print('📍 Ruta archivo: ${img['ruta_archivo']}');

      if (!await origen.exists()) {
        print('❌ No existe: ${img['ruta_archivo']}');
      } else {
        print('✅ Existe, tamaño: ${await origen.length()}');
      }

      try {
        if (!await origen.exists()) continue;
        if (await origen.length() < 1024) {
          errores++;
          continue; // evitar archivos vacíos o corruptos
        }

        final carpetaDestino = Directory('${carpetaBase.path}/Proyecto_$proyectoId/Poste_$posteId');
        if (!await carpetaDestino.exists()) {
          await carpetaDestino.create(recursive: true);
        }

        final destino = File('${carpetaDestino.path}/$nombre');

        await origen.copy(destino.path);
        nuevas++;

      } catch (e) {
        errores++;
        debugPrint('❌ Error copiando imagen $nombre: $e');
      }
    }

    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text('✅ Exportadas: $nuevas imágenes. ❌ Fallidas: $errores'),
      backgroundColor: Colors.green,
    ));
  }



  Future<void> _cargarPagina(int pagina, {bool consultarServidor = true}) async {
    setState(() {
      _cargando = true;
      _progreso = 0.0;
    });

    final inicioIndex = pagina * _tamanioPagina;
    final finIndex = (inicioIndex + _tamanioPagina).clamp(0, _todosLosPostes.length);
    final posteLote = _todosLosPostes.sublist(inicioIndex, finIndex);
    final token = _prefs.getString('token') ?? '';

    if (consultarServidor) {
      await Future.wait(posteLote.map((poste) async {
        try {
          setState(() => _posteActualSincronizando = poste['codigo'] ?? 'ID ${poste['poste_id']}');


        final estado = await _posteService.obtenerEstadoSincronizacion(
              posteId: poste['poste_id'], token: token);
          poste['formulario_servidor'] = estado['formulario_subido'];
          poste['imagenes_servidor'] = estado['imagenes_subidas'];
        } catch (_) {
          poste['formulario_servidor'] = false;
          poste['imagenes_servidor'] = false;
        }
      }));
    }

    if (mounted) {
      setState(() {
        _postesVisibles = posteLote;
        _paginaActual = pagina;
        _cargando = false;
        _progreso = 1.0;
      });
    }
  }

  void _toggleEstadoServidor() {
    setState(() => _estadoServidorVisible = !_estadoServidorVisible);
    _cargarPagina(_paginaActual, consultarServidor: _estadoServidorVisible);
  }

  void _paginaAnterior() {
    if (_paginaActual > 0) {
      _cargarPagina(_paginaActual - 1, consultarServidor: _estadoServidorVisible);
    }
  }

  void _paginaSiguiente() {
    final total = (_todosLosPostes.length / _tamanioPagina).ceil();
    if (_paginaActual + 1 < total) {
      _cargarPagina(_paginaActual + 1, consultarServidor: _estadoServidorVisible);
    }
  }

  Widget _icono(bool valor) {
    return Icon(
      valor ? Icons.check_circle : Icons.cancel,
      color: valor ? Colors.green : Colors.red,
    );
  }

  List<Map<String, dynamic>> aplicarFiltro() {
    if (filtro == "Locales") {
      return _postesVisibles.where((p) => (p['formulario_local'] == 1 || (p['imagenes_local'] ?? 0) > 0)).toList();
    } else if (filtro == "Diferencias") {
      return _postesVisibles.where((p) =>
      (p['formulario_local'] == 1) != (p['formulario_servidor'] == true) ||
          ((p['imagenes_local'] ?? 0) > 0) != (p['imagenes_servidor'] == true)).toList();
    } else if (filtro == "Sincronizados") {
      return _postesVisibles.where((p) =>
      p['formulario_local'] == 1 &&
          p['formulario_servidor'] == true &&
          (p['imagenes_local'] ?? 0) > 0 &&
          p['imagenes_servidor'] == true).toList();
    }
    return _postesVisibles;
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        Scaffold(
          appBar: AppBar(
            title: Text("📡 Línea: ${widget.linea}", style: const TextStyle(color: Colors.white)),
            iconTheme: const IconThemeData(color: Colors.white),
            actions: [
              IconButton(
                icon: const Icon(Icons.download),
                tooltip: 'Exportar imágenes',
                onPressed: _exportarImagenesOrganizadas,
              ),
            ],
            flexibleSpace: Container(
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  colors: [Color(0xFF8B0000), Color(0xFF0D47A1)],
                  begin: Alignment.centerLeft,
                  end: Alignment.centerRight,
                ),
              ),
            ),
            elevation: 2,
          ),

          backgroundColor: const Color(0xFFF3F5F9),
          body: _cuerpoPantalla(),



        ),
        _buildCargandoOverlay(), // overlay flotante mientras carga/sincroniza
      ],
    );
  }


  Widget _cuerpoPantalla() {
    if (_cargando) {
      return const Center(child: SizedBox()); // overlay se encarga del loading
    }

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          child: Wrap(
            spacing: 12,
            runSpacing: 12,
            alignment: WrapAlignment.spaceAround,
            children: [
              ElevatedButton.icon(
                icon: const Icon(Icons.sync, color: Colors.white),
                label: const Text(
                  "Sincronizar esta página",
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                    shadows: [
                      Shadow(color: Colors.black45, offset: Offset(0.5, 0.5), blurRadius: 1),
                    ],
                  ),
                ),
                onPressed: _sincronizando ? null : _sincronizarPaginaActual,
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF0D47A1),
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  elevation: 4,
                ),
              ),
              DropdownButton<String>(
                value: filtro,
                items: ["Todos", "Locales", "Diferencias", "Sincronizados"]
                    .map((f) => DropdownMenuItem(value: f, child: Text(f)))
                    .toList(),
                onChanged: (f) => setState(() => filtro = f!),
              ),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text("Servidor"),
                  Switch(
                    value: _estadoServidorVisible,
                    onChanged: (_) => _toggleEstadoServidor(),
                  ),
                ],
              ),
            ],
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              columns: const [
                DataColumn(label: Text("Código")),
                DataColumn(label: Text("Formulario Local")),
                DataColumn(label: Text("Formulario Servidor")),
                DataColumn(label: Text("Imágenes Local")),
                DataColumn(label: Text("Imágenes Servidor")),
              ],
              rows: aplicarFiltro().map((p) {
                return DataRow(cells: [
                  DataCell(Text(p['codigo'] ?? '')),
                  DataCell(_icono((p['formulario_local'] ?? 0) == 1)),
                  DataCell(_icono(p['formulario_servidor'] == true)),
                  DataCell(_icono((p['imagenes_local'] ?? 0) > 0)),
                  DataCell(_icono(p['imagenes_servidor'] == true)),
                ]);
              }).toList(),
            ),
          ),
        ),
        const SizedBox(height: 10),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceEvenly,
          children: [
            ElevatedButton.icon(
              onPressed: _paginaAnterior,
              icon: const Icon(Icons.arrow_back, color: Colors.white),
              label: const Text("Anterior",
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.bold,
                  fontSize: 15,
                ),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF8B0000),
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
            ),
            Text("Página ${_paginaActual + 1}",
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF0D47A1))),
            ElevatedButton.icon(
              onPressed: _paginaSiguiente,
              icon: const Icon(Icons.arrow_forward, color: Colors.white),
              label: const Text("Siguiente",
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.bold,
                  fontSize: 15,
                ),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF8B0000),
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
      ],
    );
  }

  Widget _buildCargandoOverlay() {
    if (!_cargando && !_sincronizando) return const SizedBox.shrink();

    final mensaje = _cargando
        ? "Cargando postes de la línea..."
        : "Sincronizando datos e imágenes...\nPor favor, no cierre la aplicación.";

    return Stack(
      children: [
        ModalBarrier(
          dismissible: false,
          color: Colors.black.withOpacity(0.4),
        ),
        Center(
          child: Container(
            width: 280,
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(0.25),
                  blurRadius: 8,
                  offset: const Offset(0, 4),
                )
              ],
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const CircularProgressIndicator(
                  valueColor: AlwaysStoppedAnimation<Color>(Color(0xFF0D47A1)),
                ),
                const SizedBox(height: 16),
                Text(
                  mensaje,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 16,
                    color: Colors.black87,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                if (_sincronizando)
                  Padding(
                    padding: const EdgeInsets.only(top: 12),
                    child: LinearProgressIndicator(
                      value: _progreso,
                      backgroundColor: Colors.grey[300],
                      color: const Color(0xFF8B0000),
                      minHeight: 6,
                    ),
                  ),
              ],
            ),
          ),
        ),
      ],
    );
  }



  Future<void> _sincronizarPaginaActual() async {
    setState(() {
      _sincronizando = true;
      _progreso = 0.0;
    });

    final String? token = _prefs.getString('token');
    if (token == null) return;

    final aEnviar = _postesVisibles.where((p) {
      final fLocal = (p['formulario_local'] ?? 0) == 1;
      final fServ = (p['formulario_servidor'] ?? false) == true;
      final iLocal = (p['imagenes_local'] ?? 0) > 0;
      final iServ = (p['imagenes_servidor'] ?? false) == true;
      return (fLocal && !fServ) || (iLocal && !iServ);
    }).toList();

    const int batchSize = 3; // ejecuta 3 en paralelo
    for (int i = 0; i < aEnviar.length; i += batchSize) {
      final lote = aEnviar.sublist(i, (i + batchSize).clamp(0, aEnviar.length));
      await Future.wait(lote.map((poste) => _sincronizarPoste(poste, token)));
      final nuevoProgreso = (i + batchSize) / aEnviar.length;
      if (mounted && nuevoProgreso > _progreso + 0.05) {
        setState(() => _progreso = nuevoProgreso.clamp(0.0, 1.0));
      }

    }

    if (mounted) {
      setState(() => _sincronizando = false);
    }
    if (aEnviar.isNotEmpty && mounted) {
      await _cargarPagina(_paginaActual, consultarServidor: _estadoServidorVisible);
    }
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text("✅ Sincronización completada con éxito."),
          backgroundColor: Colors.green,
        ),
      );
    }

  }

  Future<void> _sincronizarPoste(Map<String, dynamic> poste, String token) async {
    final posteId = poste['poste_id'];
    final formulario = await _db.getFormularioPorPoste(posteId);
    final datosJson = formulario?['datos_json'];

    if (datosJson != null && datosJson.toString().trim().isNotEmpty) {
      final datos = Map<String, dynamic>.from(await _db.decodeJson(datosJson));
      await _posteService.actualizarDatosPoste(posteId: posteId, token: token, datos: datos);
      await _db.guardarFormularioCompleto(posteId: posteId, datos: datos);
    }

    final rst = await _db.getRSTPorPoste(posteId);
    if (rst.isNotEmpty) {
      await _posteService.agregarSeccionRST(posteId: posteId, token: token, datos: {"registros": rst});
    }

    final imagenes = await _db.obtenerImagenesDePoste(posteId);
    Map<String, File> archivos = {};
    Map<String, Map<String, dynamic>> metadatos = {};

    for (final img in imagenes) {
      final file = File(img['ruta_archivo']);
      if (await file.exists()) {
        archivos[img['nombre_foto']] = file;
        metadatos[img['nombre_foto']] = {
          'utm_este': img['utm_este'],
          'utm_norte': img['utm_norte'],
          'zona': img['zona'],
          'fecha': img['fecha_inspeccion'],
        };
      }
    }

    if (archivos.isNotEmpty) {
      await _imagenService.subirImagenBatch(posteId, archivos, metadatos);
      for (final img in imagenes) {
        await _db.marcarImagenComoSincronizada(img['id']);
      }
    }
  }

}
