import 'package:flutter/material.dart';
import '../database/database_helper.dart';
import 'detalle_linea_screen.dart';

class SincronizacionScreen extends StatefulWidget {
  const SincronizacionScreen({Key? key}) : super(key: key);

  @override
  State<SincronizacionScreen> createState() => _SincronizacionScreenState();
}

class _SincronizacionScreenState extends State<SincronizacionScreen> {
  final DatabaseHelper _db = DatabaseHelper();

  List<Map<String, dynamic>> _proyectos = [];
  int? _proyectoSeleccionadoId;
  String _proyectoSeleccionadoNombre = '';
  List<Map<String, String>> _lineasDelProyecto = [];

  bool _cargando = true;

  @override
  void initState() {
    super.initState();
    _cargarProyectos();
  }

  Future<void> _cargarProyectos() async {
    setState(() => _cargando = true);
    final proyectos = await _db.getProyectos();
    setState(() {
      _proyectos = proyectos;
      _cargando = false;
    });
  }

  Future<void> _cargarLineasDeProyecto(int proyectoId, String nombreProyecto) async {
    setState(() {
      _cargando = true;
      _proyectoSeleccionadoId = proyectoId;
      _proyectoSeleccionadoNombre = nombreProyecto;
      _lineasDelProyecto = [];
    });

    // ✅ Usamos directamente la nueva función optimizada en la base de datos
    final lineas = await _db.obtenerLineasConUbicacion(proyectoId);

    setState(() {
      _lineasDelProyecto = List<Map<String, String>>.from(lineas);
      _cargando = false;
    });
  }



  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text("Sincronización", style: TextStyle(color: Colors.white)),
        iconTheme: const IconThemeData(color: Colors.white),
        flexibleSpace: Container(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              colors: [Color(0xFF8B0000), Color(0xFF0D47A1)],
              begin: Alignment.centerLeft,
              end: Alignment.centerRight,
            ),
          ),
        ),
      ),
      backgroundColor: const Color(0xFFF3F5F9),
      body: _cargando
          ? const Center(child: CircularProgressIndicator())
          : Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  "Seleccionar Proyecto",
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 8),
                ElevatedButton.icon(
                  icon: const Icon(Icons.folder_open),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.white,
                    foregroundColor: Colors.black87,
                    elevation: 2,
                    padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 12),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  label: Align(
                    alignment: Alignment.centerLeft,
                    child: Text(
                      _proyectoSeleccionadoNombre.isNotEmpty
                          ? _proyectoSeleccionadoNombre
                          : "Toca para elegir un proyecto",
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w500,
                        overflow: TextOverflow.visible,
                      ),
                    ),
                  ),
                  onPressed: () async {
                    final seleccionado = await showDialog<Map<String, dynamic>>(
                      context: context,
                      builder: (BuildContext context) {
                        return Dialog(
                          backgroundColor: const Color(0xFFF3F5F9),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                          insetPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 40),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              // Header
                              Container(
                                width: double.infinity,
                                padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 20),
                                decoration: const BoxDecoration(
                                  color: Color(0xFF0D47A1),
                                  borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
                                ),
                                child: const Text(
                                  "Seleccionar Proyectoss",
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: 18,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),

                              // Lista de proyectos
                              ConstrainedBox(
                                constraints: BoxConstraints(
                                  maxHeight: MediaQuery.of(context).size.height * 0.6,
                                  minWidth: MediaQuery.of(context).size.width * 0.8,
                                ),
                                child: ListView.separated(
                                  padding: const EdgeInsets.all(16),
                                  itemCount: _proyectos.length,
                                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                                  itemBuilder: (context, index) {
                                    final proyecto = _proyectos[index];
                                    return Material(
                                      color: Colors.white,
                                      borderRadius: BorderRadius.circular(14),
                                      elevation: 1,
                                      child: InkWell(
                                        onTap: () => Navigator.of(context).pop(proyecto),
                                        borderRadius: BorderRadius.circular(14),
                                        child: Padding(
                                          padding: const EdgeInsets.all(12),
                                          child: Row(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              const Icon(Icons.work_outline, color: Color(0xFF8B0000)),
                                              const SizedBox(width: 12),
                                              Expanded(
                                                child: Column(
                                                  crossAxisAlignment: CrossAxisAlignment.start,
                                                  children: [
                                                    Text(
                                                      proyecto['nombre_proyecto'],
                                                      style: const TextStyle(
                                                        fontSize: 16,
                                                        fontWeight: FontWeight.bold,
                                                        color: Color(0xFF0D47A1),
                                                      ),
                                                    ),
                                                    const SizedBox(height: 4),
                                                    Text(
                                                      "ID: ${proyecto['id']}",
                                                      style: const TextStyle(fontSize: 13, color: Colors.black54),
                                                    ),
                                                  ],
                                                ),
                                              ),
                                            ],
                                          ),
                                        ),
                                      ),
                                    );
                                  },
                                ),
                              ),

                              const SizedBox(height: 12),
                              Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: Text(
                                  "${_proyectos.length} proyectos disponibles",
                                  style: const TextStyle(color: Colors.black45, fontSize: 13),
                                ),
                              ),
                            ],
                          ),
                        );
                      },
                    );


                    if (seleccionado != null) {
                      final id = seleccionado['id'];
                      final nombre = seleccionado['nombre_proyecto'] ?? 'Proyecto $id';
                      await _cargarLineasDeProyecto(id, nombre);
                    }
                  },
                ),
              ],
            ),
          ),



          if (_proyectoSeleccionadoId != null)
            Card(
              margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              elevation: 2,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              child: ListTile(
                leading: const Icon(Icons.folder_special, color: Colors.indigo),
                title: Text(
                  _proyectoSeleccionadoNombre,
                  style: const TextStyle(fontWeight: FontWeight.bold),
                ),
                subtitle: Text("ID: $_proyectoSeleccionadoId - ${_lineasDelProyecto.length} líneas encontradas"),
              ),
            ),

          if (_lineasDelProyecto.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  "Proyecto: $_proyectoSeleccionadoNombre",
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Color(0xFF0D47A1)),
                ),
              ),
            ),
          if (_lineasDelProyecto.isEmpty)
            const Padding(
              padding: EdgeInsets.only(top: 40),
              child: Text("Selecciona un proyecto para ver sus líneas"),
            ),
          Expanded(
            child: ListView.builder(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              itemCount: _lineasDelProyecto.length,
              itemBuilder: (context, index) {
                final linea = _lineasDelProyecto[index];
                return Card(
                  color: const Color(0xFFF8F9FF), // Azul muy claro de fondo
                  elevation: 3,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  margin: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.alt_route_rounded, color: Color(0xFF0D47A1)),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                "Línea: ${linea['linea']}",
                                style: const TextStyle(
                                  fontSize: 17,
                                  fontWeight: FontWeight.bold,
                                  color: Color(0xFF0D47A1),
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Row(
                          children: [
                            const Icon(Icons.location_on, size: 16, color: Colors.grey),
                            const SizedBox(width: 4),
                            Expanded(
                              child: Text(
                                linea['ubicacion'] ?? '',
                                style: const TextStyle(fontSize: 14, color: Colors.black54),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        Align(
                          alignment: Alignment.centerRight,
                          child: ElevatedButton.icon(
                            onPressed: () {
                              Navigator.push(
                                context,
                                MaterialPageRoute(
                                  builder: (_) => DetalleLineaScreen(
                                    proyectoId: _proyectoSeleccionadoId!,
                                    linea: linea['linea']!,
                                  ),
                                ),
                              );
                            },
                            icon: const Icon(Icons.open_in_new, size: 18),
                            label: const Text(
                              "Ver postes",
                              style: TextStyle(fontWeight: FontWeight.bold),
                            ),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xFF0D47A1),
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              elevation: 1,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                );


              },
            ),
          ),
        ],
      ),
    );
  }
}
