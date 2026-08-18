import 'package:flutter/material.dart';
import '../services/poste_service.dart';
import 'detalle_proyecto_screen.dart';
import '../database/database_helper.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

class BuscarLineaScreen extends StatefulWidget {
  final int proyectoId;
  final String proyectoNombre;

  const BuscarLineaScreen({
    Key? key,
    required this.proyectoId,
    required this.proyectoNombre,
  }) : super(key: key);

  @override
  State<BuscarLineaScreen> createState() => _BuscarLineaScreenState();
}
Map<String, String> _ubicacionesPorLinea = {};


class _BuscarLineaScreenState extends State<BuscarLineaScreen> {
  final TextEditingController _lineaController = TextEditingController();
  final PosteService _posteService = PosteService();

  List<String> _todasLasLineas = [];
  List<String> _lineasFiltradas = [];

  bool _isLoading = false;
  bool _modoOffline = false;
  bool _hayInternet = false;

  @override
  void initState() {
    super.initState();
    _inicializarPantalla();
  }

  Future<void> _inicializarPantalla() async {
    setState(() => _isLoading = true);

    final prefs = await SharedPreferences.getInstance();
    _modoOffline = prefs.getBool('modo_offline') ?? false;

    await _verificarInternet();
    await _cargarLineas();

    setState(() => _isLoading = false);
  }

  Future<void> _verificarInternet() async {
    final result = await Connectivity().checkConnectivity();
    _hayInternet = result != ConnectivityResult.none;
  }

  Future<void> _cargarLineas() async {
    final db = DatabaseHelper();
    final lineasLocales = await db.obtenerLineasPorProyectoLocal(widget.proyectoId);

    if (lineasLocales.isNotEmpty) {
      final postesLocales = await db.obtenerPostesPorProyecto(widget.proyectoId);

      final Set<String> lineasSet = {};
      final Map<String, String> ubicaciones = {};

      for (final poste in postesLocales) {
        final linea = poste['linea']?.toString() ?? '';
        final ubicacion = poste['ubicaciones']?.toString() ?? '';

        if (linea.isNotEmpty) {
          lineasSet.add(linea);
          if (!ubicaciones.containsKey(linea)) {
            ubicaciones[linea] = ubicacion;
          }

          print(
              '📦 Poste local: ID=${poste['id']}, '
                  'Código=${poste['codigo']}, '
                  'Línea=$linea, '
                  'Estructura=${poste['estructura']}, '
                  'Ubicación=$ubicacion'
          );
        }
      }

      setState(() {
        _todasLasLineas = lineasSet.toList();
        _lineasFiltradas = lineasSet.toList();
        _ubicacionesPorLinea = ubicaciones;
      });

      return;
    }

    // Si no hay datos locales y se puede, intenta servidor
    if (!_modoOffline && _hayInternet) {
      await _actualizarLineasDesdeServidor();
    } else {
      setState(() {
        _todasLasLineas = [];
        _lineasFiltradas = [];
      });
    }
  }


  Future<void> _actualizarLineasDesdeServidor() async {
    try {
      final response = await _posteService.listarPostesPorProyecto(widget.proyectoId);
      if (response['success']) {
        final postes = response['data'];
        final Set<String> lineasSet = {};
        final Map<String, String> ubicaciones = {};

        for (final poste in postes) {
          print(
              '📥 Poste recibido: '
                  'ID=${poste['id']}, '
                  'Código=${poste['codigo']}, '
                  'Línea=${poste['linea']}, '
                  'Estructura=${poste['estructura']}, '
                  'Ubicación=${poste['ubicaciones']}, '
                  'Fecha Inspección=${poste['fecha_inspeccion']}, '
                  'Coordenadas UTM=${poste['coordenadas_utm']}, '
                  'Formulario=${poste['formulario_subido']}, '
                  'Imágenes=${poste['imagenes_subidas']}'
          );
// 👈 Agrega esto
          final linea = poste['linea']?.toString() ?? '';
          final ubicacion = poste['ubicaciones']?.toString() ?? '';

          if (linea.isNotEmpty) {
            lineasSet.add(linea);
            if (!ubicaciones.containsKey(linea)) {
              ubicaciones[linea] = ubicacion;
            }
          }
        }

        final db = DatabaseHelper();
        await db.insertOrUpdatePostes(postes);

        setState(() {
          _todasLasLineas = lineasSet.toList();
          _lineasFiltradas = lineasSet.toList();
          _ubicacionesPorLinea = ubicaciones;
        });
      }
    } catch (e) {
      print("❌ Error al cargar desde servidor: $e");
    }
  }


  void _filtrarLineas(String query) {
    final resultado = _todasLasLineas.where(
          (linea) => linea.toLowerCase().contains(query.toLowerCase()),
    ).toList();

    setState(() => _lineasFiltradas = resultado);
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
        title: Text(
          'Buscar Línea - ${widget.proyectoNombre}',
          style: const TextStyle(color: Colors.white),
        ),
        iconTheme: const IconThemeData(color: Colors.white),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back),
          onPressed: () => Navigator.pushReplacementNamed(context, '/proyectos'),
        ),
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
                Container(
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: TextField(
                    controller: _lineaController,
                    decoration: InputDecoration(
                      labelText: 'Buscar líssea',
                      labelStyle: const TextStyle(color: Colors.black87),
                      prefixIcon: const Icon(Icons.search, color: Colors.black54),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(16),
                        borderSide: BorderSide.none,
                      ),
                    ),
                    style: const TextStyle(color: Colors.black87),
                    onChanged: _filtrarLineas,
                  ),
                ),
                const SizedBox(height: 16),
                Expanded(
                  child: _lineasFiltradas.isEmpty
                      ? const Center(
                    child: Text('No se encontraron líneas', style: TextStyle(color: Colors.white)),
                  )
                      : ListView.builder(
                    itemCount: _lineasFiltradas.length,
                    itemBuilder: (context, index) {
                      final linea = _lineasFiltradas[index];
                      return Card(
                          elevation: 4,
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16),
                          ),
                          margin: const EdgeInsets.symmetric(vertical: 6),
                          child: ListTile(
                            title: Text(
                              linea,
                              style: const TextStyle(color: Colors.black87, fontWeight: FontWeight.bold),
                            ),
                            subtitle: Text(
                              'Ubicación: ${_ubicacionesPorLinea[linea] ?? "No disponible"}',
                              style: const TextStyle(color: Colors.black54),
                            ),
                            trailing: const Icon(Icons.arrow_forward, color: Colors.black54),
                            onTap: () {
                              Navigator.push(
                                context,
                                MaterialPageRoute(
                                  builder: (_) => DetalleProyectoScreen(
                                    proyectoId: widget.proyectoId,
                                    proyectoNombre: widget.proyectoNombre,
                                    lineaSeleccionada: linea,
                                  ),
                                ),
                              );
                            },
                          )

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