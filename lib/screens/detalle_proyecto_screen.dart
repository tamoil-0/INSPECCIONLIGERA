import 'package:flutter/material.dart';

import '../core/estados_sync.dart';
import '../core/contrato_fotos.dart';
import '../core/normalizar.dart';
import '../core/preferencias_app.dart';
import '../data/remoto/cliente_api.dart';
import '../database/database_helper.dart';
import '../presentacion/comunes/componentes.dart';
import '../presentacion/diseno/tema_ecoing.dart';
import '../repositorios/borradores_repositorio.dart';
import '../repositorios/fotos_repositorio.dart';
import '../servicios/conectividad/servicio_conectividad.dart';
import '../servicios/sincronizacion/servicio_sincronizacion.dart';
import '../services/poste_service.dart';
import '../utils/formatos.dart';
import 'formulario_screen.dart';
import 'imagenesPoste_screen.dart';

/// Estado de una estructura, combinando lo local y lo del servidor.
enum EstadoEstructura {
  sinIniciar,
  borrador,
  completaLocal,
  pendiente,
  sincronizada,
  conError,
}

extension EstadoEstructuraTexto on EstadoEstructura {
  String get etiqueta {
    switch (this) {
      case EstadoEstructura.sinIniciar:
        return 'Sin iniciar';
      case EstadoEstructura.borrador:
        return 'Borrador';
      case EstadoEstructura.completaLocal:
        return 'Completa en el teléfono';
      case EstadoEstructura.pendiente:
        return 'Por enviar';
      case EstadoEstructura.sincronizada:
        return 'Sincronizada';
      case EstadoEstructura.conError:
        return 'Con error';
    }
  }

  String get estadoSync {
    switch (this) {
      case EstadoEstructura.sincronizada:
        return EstadoSync.sincronizado;
      case EstadoEstructura.conError:
        return EstadoSync.fallido;
      case EstadoEstructura.pendiente:
      case EstadoEstructura.completaLocal:
        return EstadoSync.pendiente;
      default:
        return EstadoSync.local;
    }
  }
}

/// Una estructura con todo lo que la pantalla necesita saber de ella.
class _Estructura {
  final int posteId;
  final String codigo;
  final String estructura;
  final String? fechaInspeccion;
  final int fotos;
  final int fotosSincronizadas;
  final int fotosConError;
  final bool tieneBorrador;
  final bool formularioSincronizado;
  final bool formularioConError;

  const _Estructura({
    required this.posteId,
    required this.codigo,
    required this.estructura,
    this.fechaInspeccion,
    this.fotos = 0,
    this.fotosSincronizadas = 0,
    this.fotosConError = 0,
    this.tieneBorrador = false,
    this.formularioSincronizado = false,
    this.formularioConError = false,
  });

  /// Las vistas obligatorias del contrato compartido con el backend.
  static const int fotosObligatorias = ContratoFotos.cantidadRequerida;

  bool get fotosCompletas => fotos >= fotosObligatorias;
  bool get todoSincronizado =>
      formularioSincronizado && fotos > 0 && fotosSincronizadas == fotos;

  EstadoEstructura get estado {
    if (formularioConError || fotosConError > 0) {
      return EstadoEstructura.conError;
    }
    if (todoSincronizado) return EstadoEstructura.sincronizada;
    if (!tieneBorrador && fotos == 0) return EstadoEstructura.sinIniciar;
    if (tieneBorrador && fotosCompletas) return EstadoEstructura.pendiente;
    if (tieneBorrador || fotos > 0) return EstadoEstructura.borrador;
    return EstadoEstructura.sinIniciar;
  }
}

/// Estructuras de una línea, con búsqueda tolerante y estado por estructura.
///
/// ## Cambios respecto a la versión anterior
///
/// * **Se listan todas las estructuras de la línea**, ordenadas de forma
///   natural. Antes había que escribir el número exacto y la lista no aparecía
///   hasta acertar.
/// * **La búsqueda tolera ceros a la izquierda, espacios, guiones y mayúsculas**
///   (`0025` = `25` = `25-A` → `25a`). Antes era `==` sobre la cadena cruda.
/// * **Estado real por estructura**, calculado a partir de los borradores y las
///   fotografías guardadas, en lugar de un "Ya inventariado / Sin inventariar"
///   deducido solo de la fecha.
/// * El aviso "Estructuras disponibles: desde X hasta Y" **solo se calculaba en
///   la rama offline**, así que con conexión nunca aparecía. Ahora el rango se
///   muestra siempre.
/// * `buscarPorLinea` traía postes de cualquier proyecto que compartiera nombre
///   de línea y los insertaba en local sin filtrar. Ahora se filtra por
///   proyecto.
class DetalleProyectoScreen extends StatefulWidget {
  final int proyectoId;
  final String proyectoNombre;
  final String lineaSeleccionada;

  const DetalleProyectoScreen({
    super.key,
    required this.proyectoId,
    required this.proyectoNombre,
    required this.lineaSeleccionada,
  });

  @override
  State<DetalleProyectoScreen> createState() => _DetalleProyectoScreenState();
}

class _DetalleProyectoScreenState extends State<DetalleProyectoScreen> {
  final _busquedaCtrl = TextEditingController();
  final _posteService = PosteService();
  final _db = DatabaseHelper();
  final _fotos = FotosRepositorio();
  final _borradores = BorradoresRepositorio();
  final _sync = ServicioSincronizacion.instancia;
  final _conectividad = ServicioConectividad.instancia;

  List<_Estructura> _todas = [];
  List<_Estructura> _filtradas = [];
  bool _cargando = true;
  bool _sincronizando = false;
  bool _modoOffline = false;
  String? _error;
  EstadoEstructura? _filtroEstado;

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
    await _cargar(actualizarDesdeServidor: true);
  }

  Future<void> _cargar({bool actualizarDesdeServidor = false}) async {
    setState(() {
      _cargando = true;
      _error = null;
    });

    if (actualizarDesdeServidor && !_modoOffline) {
      final red = await _conectividad.comprobar();
      if (red.conectado) {
        try {
          final remotos = await _posteService.buscarPorLinea(
            widget.lineaSeleccionada,
            proyectoId: widget.proyectoId,
          );
          await _db.insertOrUpdatePostes(remotos);
        } on ErrorApi catch (e) {
          _error =
              'No se pudo actualizar desde el servidor: '
              '${e.mensajeUsuario}';
        } catch (e) {
          _error = 'No se pudo actualizar desde el servidor: $e';
        }
      }
    }

    final estructuras = await _leerEstructuras();
    if (!mounted) return;
    setState(() {
      _todas = estructuras;
      _cargando = false;
    });
    _filtrar();
  }

  Future<List<_Estructura>> _leerEstructuras() async {
    final postes = await _db.buscarPostesPorLineaLocal(
      widget.proyectoId,
      widget.lineaSeleccionada,
    );

    final resultado = <_Estructura>[];
    for (final poste in postes) {
      final posteId = int.tryParse((poste['id'] ?? '').toString());
      if (posteId == null) continue;

      final fotos = await _fotos.fotosDePoste(posteId);
      final borrador = await _borradores.obtener(posteId);

      resultado.add(
        _Estructura(
          posteId: posteId,
          codigo: (poste['codigo'] ?? '').toString(),
          estructura: (poste['estructura'] ?? '').toString(),
          fechaInspeccion:
              borrador?.datos['fecha_inspeccion']?.toString() ??
              poste['fecha_inspeccion']?.toString(),
          fotos: fotos.length,
          fotosSincronizadas: fotos.where((f) => f.estaSincronizada).length,
          fotosConError: fotos
              .where((f) => f.estado == EstadoSync.fallido)
              .length,
          tieneBorrador: borrador != null && borrador.datos.isNotEmpty,
          formularioSincronizado: borrador?.estaSincronizado ?? false,
          formularioConError: borrador?.estado == EstadoSync.fallido,
        ),
      );
    }

    resultado.sort(
      (a, b) => Normalizar.compararNatural(a.estructura, b.estructura),
    );
    return resultado;
  }

  void _filtrar() {
    final texto = _busquedaCtrl.text.trim();
    setState(() {
      _filtradas = _todas.where((e) {
        if (_filtroEstado != null && e.estado != _filtroEstado) return false;
        if (texto.isEmpty) return true;
        // Coincidencia exacta normalizada, o parcial por si escribe de más.
        return Normalizar.mismaEstructura(e.estructura, texto) ||
            Normalizar.estructura(
              e.estructura,
            ).contains(Normalizar.estructura(texto)) ||
            Normalizar.contiene(e.codigo, texto);
      }).toList();
    });
  }

  Future<void> _abrirFotos(_Estructura e) async {
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => ImagenesPosteScreen(
          posteId: e.posteId,
          numeroEstructura: e.estructura,
          proyectoId: widget.proyectoId,
          proyectoNombre: widget.proyectoNombre,
          linea: widget.lineaSeleccionada,
        ),
      ),
    );
    if (mounted) await _cargar();
  }

  Future<void> _abrirFormulario(_Estructura e) async {
    // Editar algo ya sincronizado se confirma: es la única vía por la que el
    // inspector podría sobrescribir una inspección cerrada.
    if (e.formularioSincronizado) {
      final seguir = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Esta inspección ya está sincronizada'),
          content: Text(
            'La estructura ${e.estructura} ya fue enviada y confirmada por el '
            'servidor.\n\nSe abrirá con los datos anteriores cargados. Si la '
            'guardas, se volverá a enviar y reemplazará lo que hay en el '
            'servidor.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Cancelar'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Abrir para editar'),
            ),
          ],
        ),
      );
      if (seguir != true) return;
    }

    if (!mounted) return;
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => FormularioPostePage(
          estructura: e.estructura,
          proyectoNombre: widget.proyectoNombre,
          proyectoId: widget.proyectoId,
          posteId: e.posteId,
        ),
      ),
    );
    if (mounted) await _cargar();
  }

  Future<void> _sincronizarLinea() async {
    if (_sincronizando) return;
    setState(() => _sincronizando = true);
    final resultado = await _sync.sincronizarLinea(
      widget.proyectoId,
      widget.lineaSeleccionada,
    );
    if (!mounted) return;
    setState(() => _sincronizando = false);
    await _cargar();
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

  int _contar(EstadoEstructura estado) =>
      _todas.where((e) => e.estado == estado).length;

  @override
  Widget build(BuildContext context) {
    final red = _conectividad.estado.value;
    final pendientes =
        _contar(EstadoEstructura.pendiente) +
        _contar(EstadoEstructura.borrador);

    return Stack(
      children: [
        Scaffold(
          appBar: AppBar(
            title: Text(
              'Línea ${widget.lineaSeleccionada}',
              overflow: TextOverflow.ellipsis,
            ),
            actions: [
              IndicadorConexion(
                hayInternet: red.conectado,
                modoOffline: _modoOffline,
                descripcionRed: red.descripcion,
              ),
            ],
          ),
          // El buscador y los filtros quedan fijos (una fila cada uno); las
          // estadísticas van DENTRO de la lista. Como cabecera fija ocupaban
          // altura garantizada y en una pantalla de 320x568 con el texto del
          // sistema ampliado desbordaban.
          body: Column(
            children: [
              _buscador(),
              Expanded(child: _cuerpo(pendientes)),
            ],
          ),
        ),
        if (_sincronizando)
          CapaCargando(
            mensaje:
                'Enviando lo pendiente de la línea…\n'
                'Puedes detenerlo: nada se pierde.',
            alCancelar: _sync.cancelar,
          ),
      ],
    );
  }

  Widget _cabecera(int pendientes) {
    final rango = _rangoEstructuras();
    return Container(
      width: double.infinity,
      color: ColoresEcoing.superficie,
      padding: const EdgeInsets.all(Espacio.l),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            widget.proyectoNombre,
            style: const TextStyle(
              fontSize: 14,
              color: ColoresEcoing.textoSuave,
            ),
          ),
          const SizedBox(height: Espacio.s),
          BarraProgreso(
            hechas: _contar(EstadoEstructura.sincronizada),
            total: _todas.length,
            etiqueta: 'Estructuras sincronizadas',
          ),
          const SizedBox(height: Espacio.m),
          ResumenEstados(
            completas: _contar(EstadoEstructura.sincronizada),
            pendientes: pendientes,
            conError: _contar(EstadoEstructura.conError),
            sinIniciar: _contar(EstadoEstructura.sinIniciar),
          ),
          if (rango != null) ...[
            const SizedBox(height: Espacio.s),
            Text(
              'Estructuras disponibles: $rango',
              style: const TextStyle(
                fontSize: 13,
                color: ColoresEcoing.textoTenue,
              ),
            ),
          ],
          if (pendientes > 0 || _contar(EstadoEstructura.conError) > 0) ...[
            const SizedBox(height: Espacio.m),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _modoOffline ? null : _sincronizarLinea,
                icon: const Icon(Icons.sync),
                label: Text(
                  _modoOffline
                      ? 'Modo offline activado'
                      : 'Sincronizar esta línea',
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  String? _rangoEstructuras() {
    if (_todas.isEmpty) return null;
    final ordenadas = _todas.map((e) => e.estructura).toList()
      ..sort(Normalizar.compararNatural);
    if (ordenadas.length == 1) return ordenadas.first;
    return 'de ${ordenadas.first} a ${ordenadas.last}';
  }

  Widget _buscador() {
    return Container(
      color: ColoresEcoing.superficie,
      padding: const EdgeInsets.fromLTRB(Espacio.l, 0, Espacio.l, Espacio.m),
      child: Column(
        children: [
          TextField(
            controller: _busquedaCtrl,
            keyboardType: TextInputType.text,
            onChanged: (_) => _filtrar(),
            decoration: InputDecoration(
              hintText: 'Nº de estructura o código (ej. 25, 0025, 25-A)',
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
          const SizedBox(height: Espacio.s),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                _chipFiltro(null, 'Todas', _todas.length),
                ...EstadoEstructura.values.map(
                  (e) => _chipFiltro(e, e.etiqueta, _contar(e)),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _chipFiltro(EstadoEstructura? estado, String etiqueta, int cuantas) {
    if (cuantas == 0 && estado != null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(right: Espacio.s),
      child: FilterChip(
        label: Text('$etiqueta ($cuantas)'),
        selected: _filtroEstado == estado,
        onSelected: (_) {
          setState(() => _filtroEstado = estado);
          _filtrar();
        },
        selectedColor: ColoresEcoing.azulClaro,
        checkmarkColor: ColoresEcoing.azul,
      ),
    );
  }

  Widget _cuerpo(int pendientes) {
    if (_cargando) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_todas.isEmpty) {
      return VistaEstado.error(
        titulo: 'No hay estructuras en esta línea',
        detalle: _error ?? 'No se encontraron datos locales para esta línea.',
        alPulsar: () => _cargar(actualizarDesdeServidor: true),
      );
    }
    if (_filtradas.isEmpty) {
      return VistaEstado.vacio(
        titulo: 'Ninguna estructura coincide',
        detalle: 'La búsqueda admite ceros a la izquierda, espacios y guiones.',
        textoAccion: 'Ver todas',
        alPulsar: () {
          _busquedaCtrl.clear();
          setState(() => _filtroEstado = null);
          _filtrar();
        },
      );
    }

    return RefreshIndicator(
      onRefresh: () => _cargar(actualizarDesdeServidor: true),
      child: ListView(
        padding: const EdgeInsets.only(bottom: Espacio.xl),
        children: [
          if (_error != null) Aviso(icono: Icons.cloud_off, texto: _error!),
          _cabecera(pendientes),
          ..._filtradas.map(_tarjeta),
        ],
      ),
    );
  }

  Widget _tarjeta(_Estructura e) {
    final estado = e.estado;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(Espacio.l),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Estructura ${e.estructura}',
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      if (e.codigo.isNotEmpty)
                        Text(
                          'Código ${e.codigo}',
                          style: const TextStyle(
                            fontSize: 13,
                            color: ColoresEcoing.textoSuave,
                          ),
                        ),
                    ],
                  ),
                ),
                ChipEstado(
                  estado: estado.estadoSync,
                  textoPersonalizado: estado.etiqueta,
                ),
              ],
            ),
            const SizedBox(height: Espacio.m),
            Row(
              children: [
                _dato(
                  Icons.photo_camera_outlined,
                  '${e.fotos}/${_Estructura.fotosObligatorias} fotos',
                  e.fotosCompletas
                      ? ColoresEcoing.exito
                      : (e.fotos == 0
                            ? ColoresEcoing.textoTenue
                            : ColoresEcoing.pendiente),
                ),
                const SizedBox(width: Espacio.l),
                _dato(
                  Icons.assignment_outlined,
                  e.tieneBorrador ? 'Formulario' : 'Sin formulario',
                  e.formularioSincronizado
                      ? ColoresEcoing.exito
                      : (e.tieneBorrador
                            ? ColoresEcoing.pendiente
                            : ColoresEcoing.textoTenue),
                ),
              ],
            ),
            if (e.fechaInspeccion != null &&
                e.fechaInspeccion!.isNotEmpty &&
                e.fechaInspeccion != 'null') ...[
              const SizedBox(height: Espacio.s),
              Text(
                'Inspección: ${formatearFechaHoraBonita(e.fechaInspeccion)}',
                style: const TextStyle(
                  fontSize: 12.5,
                  color: ColoresEcoing.textoSuave,
                ),
              ),
            ],
            if (e.fotosConError > 0 || e.formularioConError) ...[
              const SizedBox(height: Espacio.s),
              Text(
                e.formularioConError
                    ? 'El formulario no se pudo enviar. Sigue guardado.'
                    : '${e.fotosConError} fotografía(s) no se pudieron enviar. '
                          'Siguen guardadas.',
                style: const TextStyle(
                  fontSize: 12.5,
                  color: ColoresEcoing.error,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
            const SizedBox(height: Espacio.m),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _abrirFotos(e),
                    icon: const Icon(Icons.photo_camera, size: 20),
                    label: Text(e.fotos == 0 ? 'Tomar fotos' : 'Ver fotos'),
                  ),
                ),
                const SizedBox(width: Espacio.m),
                Expanded(
                  child: FilledButton.icon(
                    onPressed: () => _abrirFormulario(e),
                    icon: Icon(
                      e.tieneBorrador ? Icons.edit_note : Icons.assignment,
                      size: 20,
                    ),
                    label: Text(e.tieneBorrador ? 'Editar' : 'Formulario'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _dato(IconData icono, String texto, Color color) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icono, size: 16, color: color),
        const SizedBox(width: Espacio.xs),
        Text(
          texto,
          style: TextStyle(
            fontSize: 13.5,
            color: color,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }
}
