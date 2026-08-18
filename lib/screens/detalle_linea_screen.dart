import 'dart:io';

import 'package:flutter/material.dart';
import 'package:path_provider/path_provider.dart';

import '../core/estados_sync.dart';
import '../core/normalizar.dart';
import '../core/preferencias_app.dart';
import '../database/database_helper.dart';
import '../presentacion/comunes/componentes.dart';
import '../presentacion/diseno/tema_ecoing.dart';
import '../repositorios/borradores_repositorio.dart';
import '../repositorios/fotos_repositorio.dart';
import '../servicios/sincronizacion/politica_reintentos.dart';
import '../servicios/sincronizacion/servicio_sincronizacion.dart';

/// Fila de la tabla de sincronización.
class _FilaSync {
  final int posteId;
  final String codigo;
  final String estructura;
  final bool formularioLocal;
  final String? estadoFormulario;
  final int intentosFormulario;
  final String? errorFormulario;
  final int fotos;
  final int fotosSincronizadas;
  final int fotosConError;
  final String? errorFoto;

  const _FilaSync({
    required this.posteId,
    required this.codigo,
    required this.estructura,
    this.formularioLocal = false,
    this.estadoFormulario,
    this.intentosFormulario = 0,
    this.errorFormulario,
    this.fotos = 0,
    this.fotosSincronizadas = 0,
    this.fotosConError = 0,
    this.errorFoto,
  });

  bool get formularioSincronizado => estadoFormulario == EstadoSync.sincronizado;
  bool get fotosPendientes => fotos > fotosSincronizadas;
  bool get todoSincronizado =>
      (!formularioLocal || formularioSincronizado) &&
      (fotos == 0 || fotosSincronizadas == fotos);
  bool get hayPendiente =>
      (formularioLocal && !formularioSincronizado) || fotosPendientes;
  bool get hayError => fotosConError > 0 || estadoFormulario == EstadoSync.fallido;
  String? get ultimoError => errorFormulario ?? errorFoto;
}

/// Detalle de sincronización de una línea.
///
/// ## Cambios respecto a la versión anterior
///
/// * **La orquestación ya no vive aquí.** Antes esta pantalla tenía 521 líneas
///   mezclando interfaz, red y acceso a disco, y era la que marcaba los
///   registros como sincronizados. Ahora eso es responsabilidad de
///   `ServicioSincronizacion` y la pantalla solo muestra y pide.
/// * **Sin paginación de 10 en 10.** Se sincroniza la línea completa, o solo lo
///   fallido, en lugar de obligar a pasar página por página.
/// * **Se muestra el motivo real de cada fallo** y cuándo se reintentará.
/// * **La exportación de imágenes funciona en Android 13+.** Antes pedía
///   `Permission.storage` (obsoleto y denegado siempre en Android 13+) y
///   escribía en una ruta fija de `Download`. Ahora usa el directorio externo
///   propio de la app, que no necesita ningún permiso.
class DetalleLineaScreen extends StatefulWidget {
  final int proyectoId;
  final String linea;

  const DetalleLineaScreen({
    super.key,
    required this.proyectoId,
    required this.linea,
  });

  @override
  State<DetalleLineaScreen> createState() => _DetalleLineaScreenState();
}

class _DetalleLineaScreenState extends State<DetalleLineaScreen> {
  final _db = DatabaseHelper();
  final _fotos = FotosRepositorio();
  final _borradores = BorradoresRepositorio();
  final _sync = ServicioSincronizacion.instancia;
  final _politica = const PoliticaReintentos();

  List<_FilaSync> _filas = [];
  bool _cargando = true;
  bool _sincronizando = false;
  bool _exportando = false;
  bool _modoOffline = false;
  String _filtro = 'Todos';

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
    if (!mounted) return;
    setState(() => _modoOffline = prefs.modoOffline);
    await _cargar();
  }

  Future<void> _cargar() async {
    setState(() => _cargando = true);

    final postes = await _db.buscarPostesPorLineaLocal(
      widget.proyectoId,
      widget.linea,
    );

    final filas = <_FilaSync>[];
    for (final poste in postes) {
      final posteId = int.tryParse((poste['id'] ?? '').toString());
      if (posteId == null) continue;

      final fotos = await _fotos.fotosDePoste(posteId);
      final borrador = await _borradores.obtener(posteId);
      final conError = fotos.where((f) => f.estado == EstadoSync.fallido);

      filas.add(
        _FilaSync(
          posteId: posteId,
          codigo: (poste['codigo'] ?? '').toString(),
          estructura: (poste['estructura'] ?? '').toString(),
          formularioLocal: borrador != null && borrador.datos.isNotEmpty,
          estadoFormulario: borrador?.estado,
          intentosFormulario: borrador?.intentos ?? 0,
          errorFormulario: borrador?.ultimoError,
          fotos: fotos.length,
          fotosSincronizadas: fotos.where((f) => f.estaSincronizada).length,
          fotosConError: conError.length,
          errorFoto: conError.isEmpty ? null : conError.first.ultimoError,
        ),
      );
    }

    filas.sort((a, b) => Normalizar.compararNatural(a.estructura, b.estructura));

    if (!mounted) return;
    setState(() {
      _filas = filas;
      _cargando = false;
    });
  }

  List<_FilaSync> get _visibles {
    switch (_filtro) {
      case 'Pendientes':
        return _filas.where((f) => f.hayPendiente).toList();
      case 'Con error':
        return _filas.where((f) => f.hayError).toList();
      case 'Sincronizados':
        return _filas.where((f) => f.todoSincronizado && f.fotos > 0).toList();
      default:
        return _filas;
    }
  }

  Future<void> _sincronizar({bool soloFallidos = false}) async {
    if (_sincronizando) return;
    setState(() => _sincronizando = true);

    final resultado = soloFallidos
        ? await _sync.reintentarFallidos(
            proyectoId: widget.proyectoId,
            linea: widget.linea,
          )
        : await _sync.sincronizarLinea(widget.proyectoId, widget.linea);

    if (!mounted) return;
    setState(() => _sincronizando = false);
    await _cargar();
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

  Future<void> _sincronizarEstructura(_FilaSync fila) async {
    if (_sincronizando) return;
    setState(() => _sincronizando = true);
    final resultado = await _sync.sincronizarEstructura(fila.posteId);
    if (!mounted) return;
    setState(() => _sincronizando = false);
    await _cargar();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        backgroundColor: resultado.todoConfirmado
            ? ColoresEcoing.exito
            : ColoresEcoing.pendiente,
        content: Text('Estructura ${fila.estructura}: ${resultado.mensaje}'),
      ),
    );
  }

  /// Copia las fotografías a una carpeta accesible por USB o gestor de
  /// archivos, organizadas por proyecto y poste.
  ///
  /// Usa el directorio externo **propio de la app**
  /// (`Android/data/<paquete>/files/...`), que no requiere ningún permiso en
  /// ninguna versión de Android. La versión anterior pedía `Permission.storage`
  /// —obsoleto y denegado siempre desde Android 13— y escribía en una ruta fija
  /// de `Download`, así que en los teléfonos actuales simplemente no funcionaba.
  Future<void> _exportarImagenes() async {
    if (_exportando) return;
    setState(() => _exportando = true);

    try {
      final base = await getExternalStorageDirectory() ??
          await getApplicationDocumentsDirectory();
      final destinoBase = Directory('${base.path}/exportadas');
      if (!await destinoBase.exists()) {
        await destinoBase.create(recursive: true);
      }

      var copiadas = 0;
      var sinArchivo = 0;
      var fallidas = 0;

      for (final fila in _filas) {
        final fotos = await _fotos.fotosDePoste(fila.posteId);
        for (final foto in fotos) {
          final origen = foto.archivo;
          if (!await origen.exists()) {
            sinArchivo++;
            continue;
          }
          try {
            final carpeta = Directory(
              '${destinoBase.path}/Proyecto_${widget.proyectoId}'
              '/Linea_${_nombreSeguro(widget.linea)}'
              '/Estructura_${_nombreSeguro(fila.estructura)}',
            );
            if (!await carpeta.exists()) {
              await carpeta.create(recursive: true);
            }
            await origen.copy('${carpeta.path}/${foto.nombreFoto}.jpg');
            copiadas++;
          } catch (_) {
            fallidas++;
          }
        }
      }

      if (!mounted) return;
      setState(() => _exportando = false);
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Exportación terminada'),
          content: Text(
            'Copiadas: $copiadas fotografía(s).\n'
            '${sinArchivo > 0 ? 'Sin archivo en el teléfono: $sinArchivo.\n' : ''}'
            '${fallidas > 0 ? 'Fallidas: $fallidas.\n' : ''}'
            '\nCarpeta:\n${destinoBase.path}',
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child: const Text('Entendido'),
            ),
          ],
        ),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _exportando = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: ColoresEcoing.error,
          content: Text('No se pudo exportar: $e'),
        ),
      );
    }
  }

  String _nombreSeguro(String s) =>
      s.replaceAll(RegExp(r'[^a-zA-Z0-9_\-]'), '_');

  int get _totalPendientes => _filas.where((f) => f.hayPendiente).length;
  int get _totalConError => _filas.where((f) => f.hayError).length;

  @override
  Widget build(BuildContext context) {
    final progreso = _sync.progreso.value;
    return Stack(
      children: [
        Scaffold(
          appBar: AppBar(
            title: Text('Sincronizar · ${widget.linea}'),
            actions: [
              IconButton(
                tooltip: 'Exportar fotografías a una carpeta',
                icon: const Icon(Icons.drive_file_move_outline),
                onPressed: _exportando || _filas.isEmpty
                    ? null
                    : _exportarImagenes,
              ),
              IconButton(
                tooltip: 'Actualizar',
                icon: const Icon(Icons.refresh),
                onPressed: _cargando ? null : _cargar,
              ),
            ],
          ),
          body: _cargando
              ? const Center(child: CircularProgressIndicator())
              : Column(
                  children: [
                    if (_modoOffline)
                      const Aviso(
                        icono: Icons.cloud_off,
                        texto: 'Modo offline. Desactívalo para poder enviar.',
                      ),
                    _cabecera(),
                    _filtros(),
                    Expanded(child: _lista()),
                  ],
                ),
        ),
        if (_sincronizando)
          CapaCargando(
            mensaje: progreso.elementoActual == null
                ? 'Enviando…'
                : 'Enviando ${progreso.elementoActual}',
            progreso: progreso.totalElementos > 0 ? progreso.fraccion : null,
            alCancelar: _sync.cancelar,
          ),
        if (_exportando)
          const CapaCargando(mensaje: 'Copiando fotografías…'),
      ],
    );
  }

  Widget _cabecera() {
    return Container(
      width: double.infinity,
      color: ColoresEcoing.superficie,
      padding: const EdgeInsets.all(Espacio.l),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          BarraProgreso(
            hechas: _filas.where((f) => f.todoSincronizado).length,
            total: _filas.length,
            etiqueta: 'Estructuras confirmadas por el servidor',
          ),
          const SizedBox(height: Espacio.m),
          ResumenEstados(
            completas: _filas.where((f) => f.todoSincronizado).length,
            pendientes: _totalPendientes,
            conError: _totalConError,
          ),
          if (_totalPendientes > 0 || _totalConError > 0) ...[
            const SizedBox(height: Espacio.m),
            Row(
              children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: _modoOffline || _sincronizando
                        ? null
                        : () => _sincronizar(),
                    icon: const Icon(Icons.sync, size: 20),
                    label: const Text('Sincronizar línea'),
                  ),
                ),
                if (_totalConError > 0) ...[
                  const SizedBox(width: Espacio.m),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: _modoOffline || _sincronizando
                          ? null
                          : () => _sincronizar(soloFallidos: true),
                      icon: const Icon(Icons.replay, size: 20),
                      label: const Text('Reintentar fallidos'),
                    ),
                  ),
                ],
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _filtros() {
    return Container(
      width: double.infinity,
      color: ColoresEcoing.superficie,
      padding: const EdgeInsets.fromLTRB(
        Espacio.l,
        0,
        Espacio.l,
        Espacio.m,
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: ['Todos', 'Pendientes', 'Con error', 'Sincronizados']
              .map(
                (f) => Padding(
                  padding: const EdgeInsets.only(right: Espacio.s),
                  child: FilterChip(
                    label: Text(f),
                    selected: _filtro == f,
                    onSelected: (_) => setState(() => _filtro = f),
                    selectedColor: ColoresEcoing.azulClaro,
                    checkmarkColor: ColoresEcoing.azul,
                  ),
                ),
              )
              .toList(),
        ),
      ),
    );
  }

  Widget _lista() {
    final visibles = _visibles;
    if (_filas.isEmpty) {
      return const VistaEstado.vacio(
        titulo: 'No hay estructuras en esta línea',
        detalle: 'Descarga el proyecto o revisa la línea seleccionada.',
      );
    }
    if (visibles.isEmpty) {
      return VistaEstado.vacio(
        titulo: 'Nada en «$_filtro»',
        detalle: 'Prueba con otro filtro.',
        textoAccion: 'Ver todo',
        alPulsar: () => setState(() => _filtro = 'Todos'),
      );
    }

    return RefreshIndicator(
      onRefresh: _cargar,
      child: ListView.builder(
        padding: const EdgeInsets.only(bottom: Espacio.xl),
        itemCount: visibles.length,
        itemBuilder: (context, i) => _tarjeta(visibles[i]),
      ),
    );
  }

  Widget _tarjeta(_FilaSync fila) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(Espacio.l),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    'Estructura ${fila.estructura}'
                    '${fila.codigo.isEmpty ? '' : ' · ${fila.codigo}'}',
                    style: const TextStyle(
                      fontSize: 16.5,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
                ChipEstado(
                  compacto: true,
                  estado: fila.todoSincronizado
                      ? EstadoSync.sincronizado
                      : (fila.hayError
                            ? EstadoSync.fallido
                            : EstadoSync.pendiente),
                ),
              ],
            ),
            const SizedBox(height: Espacio.m),
            _filaDetalle(
              'Formulario',
              fila.formularioLocal
                  ? (fila.formularioSincronizado
                        ? 'Confirmado por el servidor'
                        : 'Guardado en el teléfono, por enviar')
                  : 'Sin formulario',
              fila.formularioLocal
                  ? (fila.formularioSincronizado
                        ? ColoresEcoing.exito
                        : ColoresEcoing.pendiente)
                  : ColoresEcoing.textoTenue,
            ),
            _filaDetalle(
              'Fotografías',
              fila.fotos == 0
                  ? 'Sin fotografías'
                  : '${fila.fotosSincronizadas} de ${fila.fotos} confirmadas',
              fila.fotos == 0
                  ? ColoresEcoing.textoTenue
                  : (fila.fotosSincronizadas == fila.fotos
                        ? ColoresEcoing.exito
                        : ColoresEcoing.pendiente),
            ),
            if (fila.ultimoError != null) ...[
              const SizedBox(height: Espacio.s),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(Espacio.s),
                decoration: BoxDecoration(
                  color: ColoresEcoing.errorFondo,
                  borderRadius: BorderRadius.circular(Espacio.s),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      fila.ultimoError!,
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: ColoresEcoing.error,
                      ),
                    ),
                    if (fila.intentosFormulario > 0)
                      Text(
                        _politica.describirEspera(
                          intentos: fila.intentosFormulario,
                        ),
                        style: const TextStyle(
                          fontSize: 12,
                          color: ColoresEcoing.textoSuave,
                        ),
                      ),
                  ],
                ),
              ),
            ],
            if (fila.hayPendiente) ...[
              const SizedBox(height: Espacio.m),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: _modoOffline || _sincronizando
                      ? null
                      : () => _sincronizarEstructura(fila),
                  icon: const Icon(Icons.upload, size: 18),
                  label: const Text('Enviar solo esta estructura'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _filaDetalle(String etiqueta, String valor, Color color) {
    return Padding(
      padding: const EdgeInsets.only(bottom: Espacio.xs),
      child: Row(
        children: [
          SizedBox(
            width: 104,
            child: Text(
              etiqueta,
              style: const TextStyle(
                fontSize: 13.5,
                color: ColoresEcoing.textoSuave,
              ),
            ),
          ),
          Expanded(
            child: Text(
              valor,
              style: TextStyle(
                fontSize: 13.5,
                color: color,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
