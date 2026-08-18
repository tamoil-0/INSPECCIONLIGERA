import 'package:flutter/material.dart';

import '../core/estados_sync.dart';
import '../core/preferencias_app.dart';
import '../data/remoto/cliente_api.dart';
import '../database/database_helper.dart';
import '../presentacion/comunes/componentes.dart';
import '../presentacion/diseno/tema_ecoing.dart';
import '../servicios/conectividad/servicio_conectividad.dart';
import '../servicios/sincronizacion/servicio_sincronizacion.dart';
import '../services/auth_service.dart';
import '../services/proyecto_service.dart';
import 'buscar_linea_screen.dart';

/// Pantalla de inicio: proyectos, estado de conexión y trabajo pendiente.
///
/// ## Cambios respecto a la versión anterior
///
/// * **El trabajo pendiente y la sincronización dejan de estar escondidos en
///   un menú lateral.** El inspector ve en la cabecera cuántos elementos le
///   faltan por enviar, cuándo fue la última sincronización, y tiene el botón
///   ahí mismo.
/// * **Búsqueda y filtro** de proyectos.
/// * **Estado de conexión real**, no un icono que solo se actualiza al pulsarlo.
/// * Las opciones del menú que no hacían nada ("Ver Perfil", "Ajustes") o que
///   llevaban a una ruta inexistente ("Editar Postes") se retiran o se
///   sustituyen por algo que sí funciona.
class ProyectosScreen extends StatefulWidget {
  const ProyectosScreen({super.key});

  @override
  State<ProyectosScreen> createState() => _ProyectosScreenState();
}

class _ProyectosScreenState extends State<ProyectosScreen> {
  final _proyectoService = ProyectoService();
  final _authService = AuthService();
  final _db = DatabaseHelper();
  final _sync = ServicioSincronizacion.instancia;
  final _conectividad = ServicioConectividad.instancia;
  final _busquedaCtrl = TextEditingController();

  List<Map<String, dynamic>> _proyectos = [];
  List<Map<String, dynamic>> _filtrados = [];
  Map<String, int> _resumen = {};

  bool _cargando = true;
  bool _sincronizando = false;
  String? _error;
  bool _desdeLocal = false;
  String? _nombreUsuario;
  String? _correoUsuario;
  bool _modoOffline = false;
  DateTime? _ultimaSync;
  String _filtroEstado = 'Todos';

  int get _pendientes => EstadoSync.noSincronizados
      .map((e) => _resumen[e] ?? 0)
      .fold(0, (a, b) => a + b);

  int get _conError => _resumen[EstadoSync.fallido] ?? 0;

  @override
  void initState() {
    super.initState();
    _conectividad.estado.addListener(_alCambiarRed);
    _sync.progreso.addListener(_alCambiarProgresoSync);
    _inicializar();
  }

  @override
  void dispose() {
    _conectividad.estado.removeListener(_alCambiarRed);
    _sync.progreso.removeListener(_alCambiarProgresoSync);
    _busquedaCtrl.dispose();
    super.dispose();
  }

  void _alCambiarRed() {
    if (mounted) setState(() {});
  }

  void _alCambiarProgresoSync() {
    if (!mounted) return;
    final enMarcha = _sync.progreso.value.enMarcha;
    if (enMarcha != _sincronizando) {
      setState(() => _sincronizando = enMarcha);
    }
  }

  Future<void> _inicializar() async {
    await _conectividad.iniciar();
    _sync.activarDisparoAutomatico();

    final prefs = await PreferenciasApp.instancia();
    final usuario = await _authService.getUsuarioActual();
    if (!mounted) return;
    setState(() {
      _modoOffline = prefs.modoOffline;
      _ultimaSync = prefs.ultimaSincronizacion;
      _nombreUsuario = usuario?['nombre_completo']?.toString() ?? 'Inspector';
      _correoUsuario = usuario?['correo_electronico']?.toString() ?? '';
    });

    await _cargarProyectos();
    await _cargarResumen();
  }

  Future<void> _cargarResumen() async {
    try {
      final resumen = await _sync.resumenGlobal();
      if (mounted) setState(() => _resumen = resumen);
    } catch (_) {
      // El resumen es informativo: si falla, no se interrumpe nada.
    }
  }

  Future<void> _cargarProyectos() async {
    setState(() {
      _cargando = true;
      _error = null;
    });

    final red = await _conectividad.comprobar();
    final puedeConsultar = red.conectado && !_modoOffline;

    if (puedeConsultar) {
      try {
        final remotos = await _proyectoService.listar();
        await _db.insertOrUpdateProyectos(remotos);
        if (!mounted) return;
        setState(() {
          _proyectos = remotos;
          _desdeLocal = false;
          _cargando = false;
        });
        _aplicarFiltros();
        return;
      } on ErrorApi catch (e) {
        // Se cae a local, pero se dice por qué.
        final locales = await _db.getProyectos();
        if (!mounted) return;
        setState(() {
          _proyectos = locales;
          _desdeLocal = true;
          _cargando = false;
          _error = locales.isEmpty
              ? e.mensajeUsuario
              : 'Mostrando datos del teléfono: ${e.mensajeUsuario}';
        });
        _aplicarFiltros();
        return;
      }
    }

    final locales = await _db.getProyectos();
    if (!mounted) return;
    setState(() {
      _proyectos = locales;
      _desdeLocal = true;
      _cargando = false;
      _error = locales.isEmpty
          ? 'No hay proyectos guardados en este teléfono. Conéctate una vez '
                'para descargarlos.'
          : null;
    });
    _aplicarFiltros();
  }

  void _aplicarFiltros() {
    final texto = _busquedaCtrl.text.trim().toLowerCase();
    setState(() {
      _filtrados = _proyectos.where((p) {
        final estado = (p['estado'] ?? '').toString().toLowerCase();
        if (_filtroEstado != 'Todos' && estado != _filtroEstado.toLowerCase()) {
          return false;
        }
        if (texto.isEmpty) return true;
        final campos = [
          p['nombre_proyecto'],
          p['contratista'],
          p['ubicacion'],
        ].map((c) => (c ?? '').toString().toLowerCase());
        return campos.any((c) => c.contains(texto));
      }).toList();
    });
  }

  Future<void> _alternarModoOffline() async {
    final prefs = await PreferenciasApp.instancia();
    final nuevo = !_modoOffline;
    await prefs.setModoOffline(nuevo);
    if (!mounted) return;
    setState(() => _modoOffline = nuevo);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        backgroundColor: nuevo ? ColoresEcoing.pendiente : ColoresEcoing.exito,
        content: Text(
          nuevo
              ? 'Modo offline activado. Todo se guarda en el teléfono y no se '
                    'usan datos móviles.'
              : 'Modo online activado. Ya se puede sincronizar.',
        ),
      ),
    );
    await _cargarProyectos();
  }

  Future<void> _sincronizarTodo() async {
    if (_sincronizando) return;
    setState(() => _sincronizando = true);
    final resultado = await _sync.sincronizarTodo();
    if (!mounted) return;
    setState(() => _sincronizando = false);

    final prefs = await PreferenciasApp.instancia();
    if (!mounted) return;
    setState(() => _ultimaSync = prefs.ultimaSincronizacion);
    await _cargarResumen();

    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        duration: const Duration(seconds: 6),
        backgroundColor: resultado.todoConfirmado
            ? ColoresEcoing.exito
            : (resultado.pudoEmpezar
                  ? ColoresEcoing.pendiente
                  : ColoresEcoing.inactivo),
        content: Text(resultado.mensaje),
      ),
    );
  }

  Future<void> _cerrarSesion() async {
    final confirmado = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('¿Cerrar sesión?'),
        content: Text(
          _pendientes > 0
              ? 'Tienes $_pendientes elemento(s) sin enviar.\n\n'
                    'No se van a borrar: quedan guardados en el teléfono y '
                    'podrás enviarlos al volver a entrar.'
              : 'Se cerrará la sesión en este teléfono.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Cerrar sesión'),
          ),
        ],
      ),
    );
    if (confirmado != true) return;

    await _authService.logout();
    if (!mounted) return;
    Navigator.pushReplacementNamed(context, '/login');
  }

  Future<void> _accionMenu(String accion) async {
    switch (accion) {
      case 'sincronizacion':
        await Navigator.pushNamed(context, '/sincronizacion');
        if (mounted) await _cargarResumen();
        break;
      case 'ajustes':
        await Navigator.pushNamed(context, '/ajustes');
        if (!mounted) return;
        final prefs = await PreferenciasApp.instancia();
        setState(() => _modoOffline = prefs.modoOffline);
        await _cargarProyectos();
        break;
      case 'salir':
        await _cerrarSesion();
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    final red = _conectividad.estado.value;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Proyectos'),
        actions: [
          IndicadorConexion(
            hayInternet: red.conectado,
            modoOffline: _modoOffline,
            descripcionRed: red.descripcion,
            alPulsar: _alternarModoOffline,
          ),
          IconButton(
            tooltip: 'Actualizar',
            icon: const Icon(Icons.refresh),
            onPressed: _cargando
                ? null
                : () async {
                    await _cargarProyectos();
                    await _cargarResumen();
                  },
          ),
          PopupMenuButton<String>(
            tooltip: 'Más opciones',
            onSelected: _accionMenu,
            itemBuilder: (_) => const [
              PopupMenuItem(
                value: 'sincronizacion',
                child: ListTile(
                  leading: Icon(Icons.sync),
                  title: Text('Sincronización'),
                ),
              ),
              PopupMenuItem(
                value: 'ajustes',
                child: ListTile(
                  leading: Icon(Icons.settings_outlined),
                  title: Text('Ajustes'),
                ),
              ),
              PopupMenuDivider(),
              PopupMenuItem(
                value: 'salir',
                child: ListTile(
                  leading: Icon(Icons.logout),
                  title: Text('Cerrar sesión'),
                ),
              ),
            ],
          ),
        ],
      ),
      body: Column(
        children: [
          if (_modoOffline)
            const Aviso(
              icono: Icons.cloud_off,
              texto:
                  'Modo offline. Todo se guarda en el teléfono; nada se '
                  'envía hasta que lo desactives.',
            ),
          _cabecera(),
          _buscador(),
          Expanded(child: _cuerpo()),
        ],
      ),
    );
  }

  Widget _cabecera() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(
        Espacio.l,
        Espacio.m,
        Espacio.l,
        Espacio.m,
      ),
      color: ColoresEcoing.superficie,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const CircleAvatar(
                backgroundColor: ColoresEcoing.azulClaro,
                child: Icon(Icons.person, color: ColoresEcoing.azul),
              ),
              const SizedBox(width: Espacio.m),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _nombreUsuario ?? 'Inspector',
                      style: const TextStyle(
                        fontSize: 16.5,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    if ((_correoUsuario ?? '').isNotEmpty)
                      Text(
                        _correoUsuario!,
                        style: const TextStyle(
                          fontSize: 13,
                          color: ColoresEcoing.textoSuave,
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: Espacio.m),
          Container(
            padding: const EdgeInsets.all(Espacio.m),
            decoration: BoxDecoration(
              color: _pendientes > 0
                  ? ColoresEcoing.pendienteFondo
                  : ColoresEcoing.exitoFondo,
              borderRadius: BorderRadius.circular(Espacio.radio),
              border: Border.all(
                color: _pendientes > 0
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
                      _pendientes > 0 ? Icons.cloud_upload : Icons.cloud_done,
                      color: _pendientes > 0
                          ? ColoresEcoing.pendiente
                          : ColoresEcoing.exito,
                    ),
                    const SizedBox(width: Espacio.s),
                    Expanded(
                      child: Text(
                        _pendientes > 0
                            ? '$_pendientes elemento(s) por enviar'
                            : 'Todo sincronizado',
                        style: const TextStyle(
                          fontSize: 15.5,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: Espacio.xs),
                Text(
                  _ultimaSync == null
                      ? 'Sin sincronizaciones registradas'
                      : 'Última sincronización: ${_fechaCorta(_ultimaSync!)}',
                  style: const TextStyle(
                    fontSize: 13,
                    color: ColoresEcoing.textoSuave,
                  ),
                ),
                if (_conError > 0) ...[
                  const SizedBox(height: Espacio.xs),
                  Text(
                    '$_conError con error: se reintentarán.',
                    style: const TextStyle(
                      fontSize: 13,
                      color: ColoresEcoing.error,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
                if (_pendientes > 0) ...[
                  const SizedBox(height: Espacio.m),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton.icon(
                      onPressed: _sincronizando || _modoOffline
                          ? null
                          : _sincronizarTodo,
                      icon: _sincronizando
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            )
                          : const Icon(Icons.sync),
                      label: Text(
                        _sincronizando ? 'Sincronizando…' : 'Sincronizar todo',
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buscador() {
    return Container(
      color: ColoresEcoing.superficie,
      padding: const EdgeInsets.fromLTRB(Espacio.l, 0, Espacio.l, Espacio.m),
      child: Column(
        children: [
          TextField(
            controller: _busquedaCtrl,
            onChanged: (_) => _aplicarFiltros(),
            decoration: InputDecoration(
              hintText: 'Buscar proyecto, contratista o ubicación',
              prefixIcon: const Icon(Icons.search),
              isDense: true,
              suffixIcon: _busquedaCtrl.text.isEmpty
                  ? null
                  : IconButton(
                      icon: const Icon(Icons.close),
                      onPressed: () {
                        _busquedaCtrl.clear();
                        _aplicarFiltros();
                      },
                    ),
            ),
          ),
          const SizedBox(height: Espacio.s),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: ['Todos', 'Activo', 'Completado', 'Cancelado']
                  .map(
                    (f) => Padding(
                      padding: const EdgeInsets.only(right: Espacio.s),
                      child: FilterChip(
                        label: Text(f),
                        selected: _filtroEstado == f,
                        onSelected: (_) {
                          setState(() => _filtroEstado = f);
                          _aplicarFiltros();
                        },
                        selectedColor: ColoresEcoing.azulClaro,
                        checkmarkColor: ColoresEcoing.azul,
                      ),
                    ),
                  )
                  .toList(),
            ),
          ),
        ],
      ),
    );
  }

  Widget _cuerpo() {
    if (_cargando) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_proyectos.isEmpty) {
      return VistaEstado.error(
        titulo: 'No hay proyectos disponibles',
        detalle: _error,
        alPulsar: _cargarProyectos,
      );
    }
    if (_filtrados.isEmpty) {
      return VistaEstado.vacio(
        titulo: 'Ningún proyecto coincide',
        detalle: 'Prueba con otro texto o quita los filtros.',
        textoAccion: 'Quitar filtros',
        alPulsar: () {
          _busquedaCtrl.clear();
          setState(() => _filtroEstado = 'Todos');
          _aplicarFiltros();
        },
      );
    }

    return RefreshIndicator(
      onRefresh: () async {
        await _cargarProyectos();
        await _cargarResumen();
      },
      child: ListView(
        padding: const EdgeInsets.only(bottom: Espacio.xl),
        children: [
          if (_error != null) Aviso(icono: Icons.cloud_off, texto: _error!),
          if (_desdeLocal && _error == null)
            const Aviso(
              icono: Icons.smartphone,
              texto: 'Datos guardados en el teléfono.',
            ),
          ..._filtrados.map(_tarjetaProyecto),
        ],
      ),
    );
  }

  Widget _tarjetaProyecto(Map<String, dynamic> proyecto) {
    final estado = (proyecto['estado'] ?? '').toString();
    final id = int.tryParse(proyecto['id'].toString()) ?? 0;

    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(Espacio.radioGrande),
        onTap: () => Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => BuscarLineaScreen(
              proyectoId: id,
              proyectoNombre: (proyecto['nombre_proyecto'] ?? '').toString(),
            ),
          ),
        ).then((_) => _cargarResumen()),
        child: Padding(
          padding: const EdgeInsets.all(Espacio.l),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      (proyecto['nombre_proyecto'] ?? 'Sin nombre').toString(),
                      style: const TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                  const SizedBox(width: Espacio.s),
                  ChipEstado(
                    textoPersonalizado: estado.isEmpty ? 'Sin estado' : estado,
                    estado: _mapearEstadoProyecto(estado),
                  ),
                ],
              ),
              const SizedBox(height: Espacio.s),
              _linea(Icons.engineering, proyecto['contratista']),
              _linea(Icons.place_outlined, proyecto['ubicacion']),
              const SizedBox(height: Espacio.m),
              const Row(
                children: [
                  Icon(
                    Icons.arrow_forward,
                    size: 18,
                    color: ColoresEcoing.azul,
                  ),
                  SizedBox(width: Espacio.xs),
                  Text(
                    'Ver líneas y estructuras',
                    style: TextStyle(
                      color: ColoresEcoing.azul,
                      fontWeight: FontWeight.w600,
                      fontSize: 14,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _linea(IconData icono, Object? valor) {
    final texto = (valor ?? '').toString();
    if (texto.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: Espacio.xs),
      child: Row(
        children: [
          Icon(icono, size: 15, color: ColoresEcoing.textoTenue),
          const SizedBox(width: Espacio.s - 2),
          Expanded(
            child: Text(
              texto,
              style: const TextStyle(
                fontSize: 14,
                color: ColoresEcoing.textoSuave,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Traduce el estado del proyecto al color semántico correspondiente.
  String? _mapearEstadoProyecto(String estado) {
    switch (estado.toLowerCase()) {
      case 'activo':
        return EstadoSync.subiendo; // azul: en curso
      case 'completado':
        return EstadoSync.sincronizado; // verde
      case 'cancelado':
        return EstadoSync.fallido; // rojo
      default:
        return EstadoSync.local;
    }
  }

  String _fechaCorta(DateTime f) {
    final ahora = DateTime.now();
    final hoy = DateTime(ahora.year, ahora.month, ahora.day);
    final dia = DateTime(f.year, f.month, f.day);
    final hora =
        '${f.hour.toString().padLeft(2, '0')}:${f.minute.toString().padLeft(2, '0')}';
    if (dia == hoy) return 'hoy $hora';
    if (dia == hoy.subtract(const Duration(days: 1))) return 'ayer $hora';
    return '${f.day}/${f.month}/${f.year} $hora';
  }
}
