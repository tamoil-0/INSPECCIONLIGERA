import 'package:flutter/material.dart';

import '../core/normalizar.dart';
import '../core/preferencias_app.dart';
import '../database/database_helper.dart';
import '../presentacion/comunes/componentes.dart';
import '../presentacion/diseno/tema_ecoing.dart';
import '../servicios/sincronizacion/servicio_sincronizacion.dart';
import 'detalle_linea_screen.dart';

/// Centro de sincronización: proyectos, líneas y estado global.
///
/// ## Cambios respecto a la versión anterior
///
/// * Se puede **sincronizar todo, o un proyecto completo**, sin bajar a la
///   línea y luego a la página. Antes solo existía "sincronizar esta página" de
///   10 postes.
/// * El proyecto se elige en la propia pantalla, no en un diálogo con el título
///   "Seleccionar Proyectoss".
/// * Se muestra cuánto hay pendiente por proyecto, para saber dónde ir.
class SincronizacionScreen extends StatefulWidget {
  const SincronizacionScreen({super.key});

  @override
  State<SincronizacionScreen> createState() => _SincronizacionScreenState();
}

class _SincronizacionScreenState extends State<SincronizacionScreen> {
  final _db = DatabaseHelper();
  final _sync = ServicioSincronizacion.instancia;

  List<Map<String, dynamic>> _proyectos = [];
  Map<int, int> _pendientesPorProyecto = {};
  List<Map<String, String>> _lineas = [];
  int? _proyectoId;
  String _proyectoNombre = '';

  bool _cargando = true;
  bool _sincronizando = false;
  bool _modoOffline = false;

  @override
  void initState() {
    super.initState();
    _sync.progreso.addListener(_alCambiarProgreso);
    _inicializar();
  }

  @override
  void dispose() {
    _sync.progreso.removeListener(_alCambiarProgreso);
    super.dispose();
  }

  void _alCambiarProgreso() {
    if (mounted) setState(() {});
  }

  Future<void> _inicializar() async {
    final prefs = await PreferenciasApp.instancia();
    final proyectos = await _db.getProyectos();

    final pendientes = <int, int>{};
    for (final p in proyectos) {
      final id = int.tryParse((p['id'] ?? '').toString());
      if (id == null) continue;
      final resumen = await _sync.resumenGlobal(proyectoId: id);
      pendientes[id] = resumen.entries
          .where((e) => e.key != 'synced')
          .fold(0, (a, e) => a + e.value);
    }

    if (!mounted) return;
    setState(() {
      _modoOffline = prefs.modoOffline;
      _proyectos = proyectos;
      _pendientesPorProyecto = pendientes;
      _cargando = false;
    });
  }

  Future<void> _elegirProyecto(Map<String, dynamic> proyecto) async {
    final id = int.tryParse((proyecto['id'] ?? '').toString());
    if (id == null) return;

    setState(() {
      _cargando = true;
      _proyectoId = id;
      _proyectoNombre = (proyecto['nombre_proyecto'] ?? 'Proyecto $id')
          .toString();
      _lineas = [];
    });

    final lineas = await _db.obtenerLineasConUbicacion(id);
    lineas.sort((a, b) => Normalizar.compararNatural(a['linea'], b['linea']));

    if (!mounted) return;
    setState(() {
      _lineas = lineas;
      _cargando = false;
    });
  }

  Future<void> _sincronizar({int? proyectoId}) async {
    if (_sincronizando) return;
    setState(() => _sincronizando = true);

    final resultado = proyectoId == null
        ? await _sync.sincronizarTodo()
        : await _sync.sincronizarProyecto(proyectoId);

    if (!mounted) return;
    setState(() => _sincronizando = false);
    await _inicializar();
    if (_proyectoId != null) {
      final p = _proyectos.firstWhere(
        (e) => int.tryParse((e['id'] ?? '').toString()) == _proyectoId,
        orElse: () => <String, dynamic>{},
      );
      if (p.isNotEmpty) await _elegirProyecto(p);
    }
    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        duration: const Duration(seconds: 7),
        backgroundColor: resultado.todoConfirmado
            ? ColoresEcoing.exito
            : (resultado.pudoEmpezar
                  ? ColoresEcoing.pendiente
                  : ColoresEcoing.inactivo),
        content: Text(resultado.mensaje),
      ),
    );
  }

  int get _totalPendiente =>
      _pendientesPorProyecto.values.fold(0, (a, b) => a + b);

  @override
  Widget build(BuildContext context) {
    final progreso = _sync.progreso.value;
    return Stack(
      children: [
        Scaffold(
          appBar: AppBar(title: const Text('Sincronización')),
          body: _cargando
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.only(bottom: Espacio.xl),
                  children: [
                    if (_modoOffline)
                      const Aviso(
                        icono: Icons.cloud_off,
                        texto:
                            'Modo offline activado. Desactívalo desde la '
                            'pantalla de proyectos para poder enviar.',
                      ),
                    _resumenGlobal(),
                    _seccion('Proyectos'),
                    ..._proyectos.map(_tarjetaProyecto),
                    if (_proyectoId != null) ...[
                      _seccion('Líneas de $_proyectoNombre'),
                      if (_lineas.isEmpty)
                        const Padding(
                          padding: EdgeInsets.all(Espacio.l),
                          child: Text(
                            'Este proyecto no tiene líneas guardadas en el '
                            'teléfono.',
                            style: TextStyle(color: ColoresEcoing.textoSuave),
                          ),
                        )
                      else
                        ..._lineas.map(_tarjetaLinea),
                    ],
                  ],
                ),
        ),
        if (_sincronizando)
          CapaCargando(
            mensaje: progreso.elementoActual == null
                ? 'Sincronizando…\nPuedes detenerlo: nada se pierde.'
                : 'Enviando ${progreso.elementoActual}',
            progreso: progreso.totalElementos > 0 ? progreso.fraccion : null,
            alCancelar: _sync.cancelar,
          ),
      ],
    );
  }

  Widget _resumenGlobal() {
    return Container(
      margin: const EdgeInsets.all(Espacio.l),
      padding: const EdgeInsets.all(Espacio.l),
      decoration: BoxDecoration(
        color: _totalPendiente > 0
            ? ColoresEcoing.pendienteFondo
            : ColoresEcoing.exitoFondo,
        borderRadius: BorderRadius.circular(Espacio.radioGrande),
        border: Border.all(
          color: _totalPendiente > 0
              ? ColoresEcoing.pendiente
              : ColoresEcoing.exito,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                _totalPendiente > 0 ? Icons.cloud_upload : Icons.cloud_done,
                color: _totalPendiente > 0
                    ? ColoresEcoing.pendiente
                    : ColoresEcoing.exito,
              ),
              const SizedBox(width: Espacio.s),
              Expanded(
                child: Text(
                  _totalPendiente > 0
                      ? '$_totalPendiente elemento(s) por enviar en total'
                      : 'Todo confirmado por el servidor',
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
          if (_totalPendiente > 0) ...[
            const SizedBox(height: Espacio.m),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _modoOffline || _sincronizando
                    ? null
                    : () => _sincronizar(),
                icon: const Icon(Icons.sync),
                label: const Text('Sincronizar todo'),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _seccion(String titulo) => Padding(
    padding: const EdgeInsets.fromLTRB(
      Espacio.l,
      Espacio.m,
      Espacio.l,
      Espacio.s,
    ),
    child: Text(
      titulo,
      style: const TextStyle(
        fontSize: 15,
        fontWeight: FontWeight.bold,
        color: ColoresEcoing.textoSuave,
      ),
    ),
  );

  Widget _tarjetaProyecto(Map<String, dynamic> proyecto) {
    final id = int.tryParse((proyecto['id'] ?? '').toString());
    final pendientes = id == null ? 0 : (_pendientesPorProyecto[id] ?? 0);
    final seleccionado = id != null && id == _proyectoId;

    return Card(
      color: seleccionado ? ColoresEcoing.azulClaro : null,
      child: InkWell(
        borderRadius: BorderRadius.circular(Espacio.radioGrande),
        onTap: () => _elegirProyecto(proyecto),
        child: Padding(
          padding: const EdgeInsets.all(Espacio.l),
          child: Row(
            children: [
              Icon(
                seleccionado ? Icons.folder_open : Icons.folder_outlined,
                color: ColoresEcoing.azul,
              ),
              const SizedBox(width: Espacio.m),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      (proyecto['nombre_proyecto'] ?? 'Sin nombre').toString(),
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      pendientes > 0
                          ? '$pendientes por enviar'
                          : 'Sin pendientes',
                      style: TextStyle(
                        fontSize: 13,
                        color: pendientes > 0
                            ? ColoresEcoing.pendiente
                            : ColoresEcoing.textoTenue,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              if (pendientes > 0)
                IconButton(
                  tooltip: 'Sincronizar este proyecto',
                  icon: const Icon(Icons.sync, color: ColoresEcoing.azul),
                  onPressed: _modoOffline || _sincronizando || id == null
                      ? null
                      : () => _sincronizar(proyectoId: id),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _tarjetaLinea(Map<String, String> linea) {
    return Card(
      child: ListTile(
        leading: const Icon(Icons.alt_route_rounded, color: ColoresEcoing.azul),
        title: Text(linea['linea'] ?? ''),
        subtitle: Text(
          (linea['ubicacion'] ?? '').isEmpty
              ? 'Ubicación no registrada'
              : linea['ubicacion']!,
        ),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => DetalleLineaScreen(
              proyectoId: _proyectoId!,
              linea: linea['linea']!,
            ),
          ),
        ).then((_) => _inicializar()),
      ),
    );
  }
}
