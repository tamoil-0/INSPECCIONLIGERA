import 'package:flutter/material.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/poste_service.dart';
import 'formulario_screen.dart';
import '../database/database_helper.dart';
import 'dart:convert';
import '../utils/formatos.dart';
import 'imagenesPoste_screen.dart';

class DetalleProyectoScreen extends StatefulWidget {
  final int proyectoId;
  final String proyectoNombre;
  final String lineaSeleccionada;

  const DetalleProyectoScreen({
    Key? key,
    required this.proyectoId,
    required this.proyectoNombre,
    required this.lineaSeleccionada,
  }) : super(key: key);

  @override
  _DetalleProyectoScreenState createState() => _DetalleProyectoScreenState();
}

class _DetalleProyectoScreenState extends State<DetalleProyectoScreen> {
  final PosteService _posteService = PosteService();
  final TextEditingController _estructuraController = TextEditingController();

  bool _isLoading = false;
  String? _errorMessage;
  bool _hayInternet = false;
  bool _modoOffline = false;

  List<Map<String, dynamic>> _postesPorLinea = [];
  List<Map<String, dynamic>> _postesFiltrados = [];
  String? _estructuraMin;
  String? _estructuraMax;
  @override
  void initState() {
    super.initState();
    _inicializar();
  }

  Future<void> _inicializar() async {
    final prefs = await SharedPreferences.getInstance();
    _modoOffline = prefs.getBool('modo_offline') ?? false;

    final connectivityResult = await Connectivity().checkConnectivity();
    _hayInternet = connectivityResult != ConnectivityResult.none;

    await _cargarPostesDeLinea();
  }

  Future<void> _cargarPostesDeLinea() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final db = DatabaseHelper();

    if (!_modoOffline && _hayInternet) {
      try {
        final response = await _posteService.buscarPostesPorLinea(widget.lineaSeleccionada);
        if (response['success']) {
          _postesPorLinea = List<Map<String, dynamic>>.from(response['data']);
          await db.insertOrUpdatePostes(_postesPorLinea);
        } else {
          _errorMessage = response['error'] ?? "Error al obtener postes del servidor.";
          _postesPorLinea = await db.buscarPostesPorLineaLocal(widget.proyectoId, widget.lineaSeleccionada);
        }
      } catch (e) {
        _errorMessage = "Error de red: $e";
        _postesPorLinea = await db.buscarPostesPorLineaLocal(widget.proyectoId, widget.lineaSeleccionada);
      }
    } else {
      _postesPorLinea = await db.buscarPostesPorLineaLocal(widget.proyectoId, widget.lineaSeleccionada);
      if (_postesPorLinea.isNotEmpty) {
        final estructuras = _postesPorLinea.map((p) => int.tryParse(p['estructura'].toString()) ?? 0).toList();
        estructuras.sort();
        _estructuraMin = estructuras.first.toString();
        _estructuraMax = estructuras.last.toString();
      }

      if (_postesPorLinea.isEmpty) {
        _errorMessage = 'No se encontraron datos locales en modo offline.';
      }
    }

    setState(() {
      _isLoading = false;
    });
  }

  int _idDe(Map<String, dynamic> poste) =>
      int.parse(poste['id'].toString());

  Future<void> _abrirFotos(Map<String, dynamic> poste) async {
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => ImagenesPosteScreen(
          posteId: _idDe(poste),
          numeroEstructura: poste['estructura']?.toString() ?? '',
          proyectoId: widget.proyectoId,
          proyectoNombre: widget.proyectoNombre,
          linea: widget.lineaSeleccionada,
        ),
      ),
    );
    // Al volver, la tarjeta se repinta con el estado real de la base.
    if (mounted) setState(() {});
  }

  Future<void> _abrirFormulario(Map<String, dynamic> poste) async {
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => FormularioPostePage(
          estructura: poste['estructura']?.toString() ?? '',
          proyectoNombre: widget.proyectoNombre,
          proyectoId: widget.proyectoId,
          posteId: _idDe(poste),
        ),
      ),
    );
    if (mounted) setState(() {});
  }

  void _filtrarPostes() {
    final estructuraBuscada = _estructuraController.text.trim();
    if (estructuraBuscada.isEmpty) return;

    setState(() {
      _postesFiltrados = _postesPorLinea
          .where((poste) => poste['estructura'].toString() == estructuraBuscada)
          .toList();

      _errorMessage = _postesFiltrados.isEmpty
          ? 'No se encontraron postes con estructura $estructuraBuscada'
          : null;
    });
  }

  Widget _iconoModoOffline() {
    return Tooltip(
      message: _modoOffline ? 'Modo offline ACTIVADO' : 'Modo offline DESACTIVADO',
      child: Icon(
        _modoOffline ? Icons.cloud_off : Icons.cloud_done,
        color: _modoOffline ? Colors.yellow : Colors.white,
      ),
    );
  }

  Widget _iconoInternet() {
    return Tooltip(
      message: _hayInternet ? 'Conectado a Internet' : 'Sin conexión',
      child: Icon(
        _hayInternet ? Icons.wifi : Icons.wifi_off,
        color: _hayInternet ? Colors.greenAccent : Colors.grey[300],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: Text('Proyecto: ${widget.proyectoNombre}', style: const TextStyle(color: Colors.white)),
        iconTheme: const IconThemeData(color: Colors.white),
        actions: [
          _iconoModoOffline(),
          const SizedBox(width: 12),
          _iconoInternet(),
          const SizedBox(width: 16),
        ],
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [Color(0xFFB71C1C), Color(0xFF0D47A1)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: SafeArea(
          child: _isLoading
              ? const Center(child: CircularProgressIndicator(color: Colors.white))
              : Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              children: [
                Text(
                  'Línea: ${widget.lineaSeleccionada}',
                  style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.white),
                ),
                const SizedBox(height: 16),
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: TextField(
                    controller: _estructuraController,
                    onSubmitted: (_) => _filtrarPostes(),
                    style: const TextStyle(
                      color: Color(0xFF3A3A3A),
                      fontSize: 16,
                      fontWeight: FontWeight.w500,
                    ),
                    decoration: InputDecoration(
                      labelText: 'Ingrese el numero de estructura',
                      labelStyle: const TextStyle(
                        color: Color(0xFF7986CB),
                        fontWeight: FontWeight.bold,
                      ),
                      floatingLabelStyle: const TextStyle(
                        backgroundColor: Color(0xFF0D47A1),
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                      ),
                      prefixIcon: const Icon(Icons.search, color: Color(0xFF0D47A1)),
                      filled: true,
                      fillColor: Colors.white,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(16),
                        borderSide: const BorderSide(color: Color(0xFFB0BEC5)),
                      ),
                      enabledBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(16),
                        borderSide: const BorderSide(color: Color(0xFFB0BEC5)),
                      ),
                      focusedBorder: const OutlineInputBorder(
                        borderSide: BorderSide(color: Color(0xFF0D47A1), width: 2),
                      ),
                    ),
                  ),
                ),

                if (_estructuraMin != null && _estructuraMax != null)
                  Container(
                    margin: const EdgeInsets.symmetric(vertical: 10),
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: Colors.white70),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.info_outline, color: Colors.amberAccent, size: 28),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            'Estructuras disponibles:\nDesde $_estructuraMin hasta $_estructuraMax',
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w600,
                              fontSize: 15,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),


                const SizedBox(height: 12),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFFFBC02D),
                    foregroundColor: Colors.black,
                    padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 12),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                  ),
                  onPressed: _isLoading ? null : _filtrarPostes,
                  child: const Text('Buscar estructura'),
                ),
                const SizedBox(height: 12),
                if (_errorMessage != null)
                  Text(_errorMessage!, style: const TextStyle(color: Colors.white)),
                if (_postesFiltrados.isNotEmpty)
                  Expanded(
                    child: ListView.builder(
                      itemCount: _postesFiltrados.length,
                      itemBuilder: (context, index) {
                        final poste = _postesFiltrados[index];
                        return FutureBuilder<Map<String, dynamic>?>(
                          future: DatabaseHelper().getFormularioPorPoste(poste['id']),
                          builder: (context, snapshot) {
                            String? fechaLocal;
                            if (snapshot.hasData && snapshot.data != null) {
                              final datos = jsonDecode(snapshot.data!['datos_json'] ?? '{}');
                              fechaLocal = datos['fecha_inspeccion']?.toString();
                            }
                            final fechaMostrar = fechaLocal ?? (poste['fecha_inspeccion'] ?? null);

                            // EXISTE fecha inspección
                            final inventariado = fechaMostrar != null &&
                                fechaMostrar != "" &&
                                fechaMostrar != "null" &&
                                fechaMostrar != "N/A";

                            return Card(
                              elevation: 4,
                              margin: const EdgeInsets.symmetric(vertical: 8),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(16),
                              ),
                              child: Padding(
                                padding: const EdgeInsets.all(10.0),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    // Texto arriba del ListTile
                                    Container(
                                      padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 12),
                                      decoration: BoxDecoration(
                                        color: inventariado ? Colors.green[100] : Colors.red[100],
                                        borderRadius: BorderRadius.circular(8),
                                      ),
                                      child: Text(
                                        inventariado ? 'Ya inventariado' : 'Sin inventariar',
                                        style: TextStyle(
                                          color: inventariado ? Colors.green[900] : Colors.red[800],
                                          fontWeight: FontWeight.bold,
                                          fontSize: 15,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(height: 4),
                                    ListTile(
                                      title: Text('Código: ${poste['codigo']}', style: const TextStyle(color: Colors.black87)),
                                      subtitle: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Text('Línea: ${poste['linea']}', style: const TextStyle(color: Colors.black87)),
                                          Text('Estructura: ${poste['estructura']}', style: const TextStyle(color: Colors.black87)),
                                          Text(
                                            'Fecha inspección: ${formatearFechaHoraBonita(fechaMostrar)}',
                                            style: const TextStyle(color: Colors.black87),
                                          ),
                                        ],
                                      ),
                                      // ANTES: un solo botón abría las fotos y,
                                      // en el `.then()`, empujaba el formulario
                                      // SIEMPRE — incluso si el inspector solo
                                      // había retrocedido sin tomar nada, y sin
                                      // comprobar `mounted`. Ahora cada destino
                                      // es una decisión explícita suya.
                                      trailing: Column(
                                        mainAxisAlignment: MainAxisAlignment.center,
                                        children: [
                                          TextButton.icon(
                                            style: TextButton.styleFrom(
                                              foregroundColor: const Color(0xFF0D47A1),
                                              padding: const EdgeInsets.symmetric(horizontal: 8),
                                              minimumSize: const Size(0, 44),
                                            ),
                                            icon: const Icon(Icons.photo_camera, size: 20),
                                            label: const Text('Fotos'),
                                            onPressed: () => _abrirFotos(poste),
                                          ),
                                          TextButton.icon(
                                            style: TextButton.styleFrom(
                                              foregroundColor: inventariado
                                                  ? const Color(0xFFEF6C00)
                                                  : const Color(0xFFB71C1C),
                                              padding: const EdgeInsets.symmetric(horizontal: 8),
                                              minimumSize: const Size(0, 44),
                                            ),
                                            icon: Icon(
                                              inventariado ? Icons.edit_note : Icons.assignment,
                                              size: 20,
                                            ),
                                            label: Text(
                                              inventariado ? 'Editar' : 'Formulario',
                                            ),
                                            onPressed: () => _abrirFormulario(poste),
                                          ),
                                        ],
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          },
                        );
                      },


                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
