import 'package:flutter/material.dart';

import '../core/normalizar.dart';
import '../core/preferencias_app.dart';
import '../data/remoto/cliente_api.dart';
import '../database/database_helper.dart';
import '../presentacion/comunes/componentes.dart';
import '../presentacion/diseno/tema_ecoing.dart';
import '../servicios/conectividad/servicio_conectividad.dart';
import '../services/poste_service.dart';
import 'detalle_proyecto_screen.dart';

/// Líneas eléctricas de un proyecto.
///
/// ## Cambios respecto a la versión anterior
///
/// * `_ubicacionesPorLinea` estaba declarada **a nivel de archivo**, fuera de
///   la clase `State`: se compartía entre instancias y entre proyectos, de modo
///   que las ubicaciones de un proyecto aparecían en otro. Ahora es un campo de
///   estado.
/// * **Búsqueda tolerante** a acentos y mayúsculas, y **orden natural** de las
///   líneas (antes salían en el orden arbitrario que devolvía SQLite).
/// * Se muestra **cuántas estructuras** tiene cada línea.
/// * El texto del buscador decía "Buscar líssea".
class BuscarLineaScreen extends StatefulWidget {
  final int proyectoId;
  final String proyectoNombre;

  const BuscarLineaScreen({
    super.key,
    required this.proyectoId,
    required this.proyectoNombre,
  });

  @override
  State<BuscarLineaScreen> createState() => _BuscarLineaScreenState();
}

/// Una línea con lo que se sabe de ella en local.
class _Linea {
  final String nombre;
  final String ubicacion;
  final int estructuras;

  const _Linea({
    required this.nombre,
    required this.ubicacion,
    required this.estructuras,
  });
}

class _BuscarLineaScreenState extends State<BuscarLineaScreen> {
  final _busquedaCtrl = TextEditingController();
  final _posteService = PosteService();
  final _db = DatabaseHelper();
  final _conectividad = ServicioConectividad.instancia;

  List<_Linea> _todas = [];
  List<_Linea> _filtradas = [];
  bool _cargando = true;
  bool _modoOffline = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _inicializar();
  }

  @override
  void dispose() {
    _busquedaCtrl.dispose();
    super.dispose();
  }

  Future<void> _inicializar() async {
    final prefs = await PreferenciasApp.instancia();
    if (!mounted) return;
    setState(() => _modoOffline = prefs.modoOffline);
    await _cargarLineas();
  }

  Future<void> _cargarLineas() async {
    setState(() {
      _cargando = true;
      _error = null;
    });

    var lineas = await _leerLineasLocales();

    // Si no hay nada en local, se intenta traer del servidor.
    if (lineas.isEmpty && !_modoOffline) {
      final red = await _conectividad.comprobar();
      if (red.conectado) {
        try {
          final postes = await _posteService.listarPorProyecto(widget.proyectoId);
          await _db.insertOrUpdatePostes(postes);
          lineas = await _leerLineasLocales();
        } on ErrorApi catch (e) {
          _error = e.mensajeUsuario;
        } catch (e) {
          _error = 'No se pudieron descargar las líneas: $e';
        }
      } else {
        _error = 'Sin conexión y sin líneas guardadas en este teléfono.';
      }
    }

    if (!mounted) return;
    setState(() {
      _todas = lineas;
      _cargando = false;
    });
    _filtrar();
  }

  /// Lee las líneas de la base local, con su ubicación y su número de
  /// estructuras, en una sola consulta.
  Future<List<_Linea>> _leerLineasLocales() async {
    final postes = await _db.obtenerPostesPorProyecto(widget.proyectoId);

    final conteo = <String, int>{};
    final ubicaciones = <String, String>{};

    for (final poste in postes) {
      final linea = (poste['linea'] ?? '').toString().trim();
      if (linea.isEmpty) continue;
      conteo[linea] = (conteo[linea] ?? 0) + 1;
      final ubicacion = (poste['ubicaciones'] ?? '').toString().trim();
      if (ubicacion.isNotEmpty && !ubicaciones.containsKey(linea)) {
        ubicaciones[linea] = ubicacion;
      }
    }

    final lista = conteo.entries
        .map(
          (e) => _Linea(
            nombre: e.key,
            ubicacion: ubicaciones[e.key] ?? 'Ubicación no registrada',
            estructuras: e.value,
          ),
        )
        .toList();

    // Orden natural: L-2 antes de L-10.
    lista.sort((a, b) => Normalizar.compararNatural(a.nombre, b.nombre));
    return lista;
  }

  void _filtrar() {
    final texto = _busquedaCtrl.text;
    setState(() {
      _filtradas = _todas
          .where(
            (l) =>
                Normalizar.contiene(l.nombre, texto) ||
                Normalizar.contiene(l.ubicacion, texto),
          )
          .toList();
    });
  }

  @override
  Widget build(BuildContext context) {
    final red = _conectividad.estado.value;
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.proyectoNombre, overflow: TextOverflow.ellipsis),
        actions: [
          IndicadorConexion(
            hayInternet: red.conectado,
            modoOffline: _modoOffline,
            descripcionRed: red.descripcion,
          ),
        ],
      ),
      body: Column(
        children: [
          Container(
            color: ColoresEcoing.superficie,
            padding: const EdgeInsets.all(Espacio.l),
            child: TextField(
              controller: _busquedaCtrl,
              onChanged: (_) => _filtrar(),
              decoration: InputDecoration(
                hintText: 'Buscar línea o ubicación',
                prefixIcon: const Icon(Icons.search),
                isDense: true,
                suffixIcon: _busquedaCtrl.text.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.close),
                        onPressed: () {
                          _busquedaCtrl.clear();
                          _filtrar();
                        },
                      ),
              ),
            ),
          ),
          if (_todas.isNotEmpty)
            Container(
              width: double.infinity,
              color: ColoresEcoing.superficie,
              padding: const EdgeInsets.fromLTRB(
                Espacio.l,
                0,
                Espacio.l,
                Espacio.m,
              ),
              child: Text(
                '${_todas.length} línea(s) · '
                '${_todas.fold<int>(0, (a, l) => a + l.estructuras)} estructuras',
                style: const TextStyle(
                  fontSize: 13,
                  color: ColoresEcoing.textoSuave,
                ),
              ),
            ),
          Expanded(child: _cuerpo()),
        ],
      ),
    );
  }

  Widget _cuerpo() {
    if (_cargando) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_todas.isEmpty) {
      return VistaEstado.error(
        titulo: 'No hay líneas para este proyecto',
        detalle: _error ??
            'Puede que el proyecto no tenga estructuras con línea asignada.',
        alPulsar: _cargarLineas,
      );
    }
    if (_filtradas.isEmpty) {
      return VistaEstado.vacio(
        titulo: 'Ninguna línea coincide',
        detalle: 'Prueba con otro texto.',
        textoAccion: 'Limpiar búsqueda',
        alPulsar: () {
          _busquedaCtrl.clear();
          _filtrar();
        },
      );
    }

    return RefreshIndicator(
      onRefresh: _cargarLineas,
      child: ListView.builder(
        padding: const EdgeInsets.only(bottom: Espacio.xl),
        itemCount: _filtradas.length,
        itemBuilder: (context, i) {
          final linea = _filtradas[i];
          return Card(
            child: InkWell(
              borderRadius: BorderRadius.circular(Espacio.radioGrande),
              onTap: () => Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (_) => DetalleProyectoScreen(
                    proyectoId: widget.proyectoId,
                    proyectoNombre: widget.proyectoNombre,
                    lineaSeleccionada: linea.nombre,
                  ),
                ),
              ),
              child: Padding(
                padding: const EdgeInsets.all(Espacio.l),
                child: Row(
                  children: [
                    const Icon(
                      Icons.alt_route_rounded,
                      color: ColoresEcoing.azul,
                      size: 26,
                    ),
                    const SizedBox(width: Espacio.m),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            linea.nombre,
                            style: const TextStyle(
                              fontSize: 17,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const SizedBox(height: Espacio.xs),
                          Row(
                            children: [
                              const Icon(
                                Icons.place_outlined,
                                size: 14,
                                color: ColoresEcoing.textoTenue,
                              ),
                              const SizedBox(width: Espacio.xs),
                              Expanded(
                                child: Text(
                                  linea.ubicacion,
                                  style: const TextStyle(
                                    fontSize: 13.5,
                                    color: ColoresEcoing.textoSuave,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: Espacio.xs),
                          Text(
                            '${linea.estructuras} estructura(s)',
                            style: const TextStyle(
                              fontSize: 13,
                              color: ColoresEcoing.azul,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const Icon(
                      Icons.chevron_right,
                      color: ColoresEcoing.textoTenue,
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
