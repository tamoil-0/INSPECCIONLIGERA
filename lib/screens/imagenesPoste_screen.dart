import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:permission_handler/permission_handler.dart';

import '../core/conversion_utm.dart';
import '../core/estados_sync.dart';
import '../core/preferencias_app.dart';
import '../repositorios/fotos_repositorio.dart';
import '../servicios/imagenes/cola_procesamiento.dart';
import '../servicios/imagenes/optimizador_imagenes.dart';
import '../servicios/imagenes/perfil_dispositivo.dart';
import '../services/imagenesPoste_service.dart';

/// Captura de las fotografías de una estructura.
///
/// ## Cambio de fondo respecto a la versión anterior
///
/// Antes, con internet, las fotos **solo** se subían: la escritura en SQLite
/// vivía en la rama `else` del caso sin conexión. Un fallo de red hacía
/// desaparecer las 22 fotos de una torre, sin registro y sin posibilidad de
/// reintentar.
///
/// Ahora cada captura se copia a almacenamiento permanente y se registra en la
/// base **en el momento de tomarla**, antes de cualquier intento de subida.
/// "Enviar" pasa a ser una operación sobre datos que ya están a salvo, y solo
/// las fotos que el servidor confirma pasan a `synced`.
class ImagenesPosteScreen extends StatefulWidget {
  final int posteId;
  final String numeroEstructura;
  final int proyectoId;
  final String proyectoNombre;
  final String? linea;

  const ImagenesPosteScreen({
    super.key,
    required this.posteId,
    required this.numeroEstructura,
    required this.proyectoId,
    required this.proyectoNombre,
    this.linea,
  });

  @override
  State<ImagenesPosteScreen> createState() => _ImagenesPosteScreenState();
}

class _ImagenesPosteScreenState extends State<ImagenesPosteScreen> {
  final ImagePicker _picker = ImagePicker();
  final ImagenesPosteService _service = ImagenesPosteService();
  final FotosRepositorio _fotos = FotosRepositorio();

  PerfilDispositivo _perfil = PerfilDispositivo.media;
  OptimizadorImagenes _optimizador = const OptimizadorImagenes();
  ColaProcesamiento _cola = ColaProcesamiento(concurrencia: 1);
  int _espacioOcupado = 0;

  /// Estado de cada vista fotográfica, indexado por `nombre_foto`.
  final Map<String, FotoLocal> _registradas = {};

  /// Vistas cuya optimización está en marcha o en cola.
  final Set<String> _optimizando = {};

  bool _cargandoInicial = true;
  bool _enviando = false;
  String? _procesando;
  int _confirmadasEnEnvio = 0;
  int _totalEnEnvio = 0;
  bool _modoOffline = false;
  bool _hayInternet = false;
  int _archivosPerdidos = 0;

  static const List<String> _fotosRequeridas = [
    'placa',
    'torre_parte_inferior',
    'torre_parte_superior',
    'base_torre',
    'mensulas',
    'crucetas',
    'perfiles_angulares',
    'atiescalamiento',
    'otros',
    'aisladores_fase_r_atras',
    'aisladores_fase_s_atras',
    'aisladores_fase_t_atras',
    'aisladores_fase_r_adelante',
    'aisladores_fase_s_adelante',
    'aisladores_fase_t_adelante',
    'ferreteria_fase_r',
    'ferreteria_fase_s',
    'ferreteria_fase_t',
    'cable_guarda',
    'ferreteria_de_cable_de_guarda',
    'conductor',
    'ferreteria_de_conductor',
    'puesta_tierra',
    'retenida',
    'faja_servidumbre',
    'ubicacion_acceso',
  ];

  static const List<String> _fotosOpcionales = [
    'otros',
    'aisladores_fase_r_adelante',
    'aisladores_fase_s_adelante',
    'aisladores_fase_t_adelante',
  ];

  List<String> get _obligatorias =>
      _fotosRequeridas.where((f) => !_fotosOpcionales.contains(f)).toList();

  int get _obligatoriasTomadas =>
      _obligatorias.where((f) => _registradas.containsKey(f)).length;

  bool get _todasLasFotosTomadas =>
      _obligatoriasTomadas == _obligatorias.length;

  int get _pendientesDeEnviar => _registradas.values
      .where((f) => f.estado != EstadoSync.sincronizado)
      .length;

  @override
  void initState() {
    super.initState();
    _inicializar();
  }

  @override
  void dispose() {
    _cola.cancelarPendientes();
    super.dispose();
  }

  Future<void> _inicializar() async {
    final prefs = await PreferenciasApp.instancia();
    _perfil = prefs.perfilImagenes;
    _optimizador = OptimizadorImagenes(
      perfil: _perfil,
      politica: prefs.politicaRetencion,
    );
    // Concurrencia adaptada al teléfono: 22 compresiones a la vez agotarían la
    // memoria en gama baja.
    _cola = ColaProcesamiento(concurrencia: _perfil.concurrencia);

    await _cargarEstadoConexion();
    await _cargarFotosGuardadas();
    // El permiso se pide después del primer fotograma para no usar el context
    // dentro de initState.
    await _pedirPermisosUbicacion();
    if (mounted) setState(() => _cargandoInicial = false);

    // Reintenta la optimización de lo que quedó a medias (por ejemplo porque
    // la app se cerró antes de que la cola llegara a esas fotos).
    await _optimizarPendientes();
  }

  Future<void> _optimizarPendientes() async {
    final pendientes = await _fotos.sinOptimizar(widget.posteId);
    for (final foto in pendientes) {
      _encolarOptimizacion(foto);
    }
  }

  /// Encola la optimización de una foto ya guardada.
  ///
  /// No se espera el resultado: el inspector puede seguir tomando fotos
  /// mientras la cola avanza por detrás. La foto ya está a salvo; optimizarla
  /// solo mejora el peso de la subida.
  void _encolarOptimizacion(FotoLocal foto) {
    if (_optimizando.contains(foto.nombreFoto)) return;
    setState(() => _optimizando.add(foto.nombreFoto));

    _cola.encolar(() => _optimizador.optimizar(foto.archivo)).then(
      (resultado) async {
        try {
          final actualizada = await _fotos.aplicarOptimizacion(
            id: foto.id,
            rutaSubible: resultado.rutaSubible,
            rutaOriginal: resultado.rutaOriginal,
            rutaMiniatura: resultado.rutaMiniatura,
            tamanoSubible: resultado.tamanoSubible,
            tamanoOriginal: resultado.tamanoOriginal,
            ancho: resultado.ancho,
            alto: resultado.alto,
          );
          if (!mounted) return;
          setState(() {
            _registradas[foto.nombreFoto] = actualizada;
            _optimizando.remove(foto.nombreFoto);
          });
        } catch (e) {
          debugPrint('No se pudo registrar la optimización: $e');
          if (mounted) {
            setState(() => _optimizando.remove(foto.nombreFoto));
          }
        }
      },
      onError: (Object e) {
        // La foto sigue guardada y subible tal cual: la optimización es una
        // mejora, no un requisito.
        debugPrint('Optimización fallida de ${foto.nombreFoto}: $e');
        if (mounted) {
          setState(() => _optimizando.remove(foto.nombreFoto));
        }
      },
    );
  }

  /// Recupera de la base las fotos ya tomadas de esta estructura.
  ///
  /// Sin esto, reabrir un poste ya fotografiado mostraba la lista en blanco y
  /// obligaba a repetir las 22 capturas.
  Future<void> _cargarFotosGuardadas() async {
    final perdidos = await _fotos.verificarArchivos(posteId: widget.posteId);
    final guardadas = await _fotos.fotosDePoste(widget.posteId);
    var ocupado = 0;
    try {
      ocupado = await _fotos.almacen.espacioOcupado();
    } catch (_) {
      // Un fallo al medir el espacio no debe impedir ver las fotos.
    }
    if (!mounted) return;
    setState(() {
      _espacioOcupado = ocupado;
      _archivosPerdidos = perdidos;
      _registradas
        ..clear()
        ..addEntries(
          guardadas
              .where((f) => f.rutaArchivo.isNotEmpty)
              .map((f) => MapEntry(f.nombreFoto, f)),
        );
    });
  }

  Future<void> _pedirPermisosUbicacion() async {
    final status = await Permission.location.request();
    if (!status.isGranted && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Sin permiso de ubicación las fotos se guardarán sin coordenadas.',
          ),
        ),
      );
    }
  }

  Future<void> _cargarEstadoConexion() async {
    final prefs = await PreferenciasApp.instancia();
    final conectado = await _verificarConexion();
    if (!mounted) return;
    setState(() {
      _modoOffline = prefs.modoOffline;
      _hayInternet = conectado;
    });
  }

  Future<bool> _verificarConexion() async {
    try {
      final result = await InternetAddress.lookup(
        'google.com',
      ).timeout(const Duration(seconds: 5));
      return result.isNotEmpty && result[0].rawAddress.isNotEmpty;
    } catch (_) {
      return false;
    }
  }

  Future<Position?> _obtenerPosicionPrecisa() async {
    try {
      return await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 10),
        ),
      );
    } catch (_) {
      try {
        return await Geolocator.getLastKnownPosition();
      } catch (_) {
        return null;
      }
    }
  }

  // ===========================================================================
  // Captura
  // ===========================================================================

  Future<void> _tomarFoto(String nombreFoto) async {
    if (_enviando) return;

    final XFile? foto = await _picker.pickImage(source: ImageSource.camera);
    if (foto == null) return;
    if (!mounted) return;

    setState(() => _procesando = nombreFoto);

    try {
      final posicion = await _obtenerPosicionPrecisa();
      final lat = posicion?.latitude;
      final lon = posicion?.longitude;
      final utm = (lat != null && lon != null)
          ? ConversionUtm.desdeLatLon(lat, lon)
          : null;

      // Copia durable + registro en SQLite. Si algo de esto falla, se avisa y
      // NO se da la foto por buena.
      final registrada = await _fotos.registrarCaptura(
        archivoTemporal: File(foto.path),
        posteId: widget.posteId,
        nombreFoto: nombreFoto,
        proyectoId: widget.proyectoId,
        linea: widget.linea,
        latitud: lat,
        longitud: lon,
        precisionGps: posicion?.accuracy,
        utmEste: utm?.este.toString(),
        utmNorte: utm?.norte.toString(),
        zona: utm?.zonaCompleta,
        fechaCaptura: DateTime.now(),
      );

      if (!mounted) return;
      setState(() {
        _registradas[nombreFoto] = registrada;
        _procesando = null;
      });

      // La foto ya está a salvo. La optimización va por detrás, sin bloquear.
      _encolarOptimizacion(registrada);

      if (posicion == null) {
        _avisar(
          'Foto guardada en el teléfono, pero sin coordenadas GPS. '
          'Puedes repetirla en un punto con mejor señal.',
          color: const Color(0xFFEF6C00),
        );
      }
    } catch (e) {
      if (!mounted) return;
      setState(() => _procesando = null);
      await _mostrarError(
        'No se pudo guardar la fotografía',
        'La foto NO quedó registrada, así que vuelve a tomarla.\n\n'
            'Detalle: $e',
      );
    }
  }

  Future<void> _eliminarFoto(String nombreFoto) async {
    final foto = _registradas[nombreFoto];
    if (foto == null) return;

    final confirmado = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('¿Eliminar esta fotografía?'),
        content: Text(
          '${_titulo(nombreFoto)}\n\n'
          'Se borrará del teléfono y tendrás que tomarla de nuevo.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancelar'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: TextButton.styleFrom(foregroundColor: const Color(0xFFC62828)),
            child: const Text('Eliminar'),
          ),
        ],
      ),
    );

    if (confirmado != true) return;
    await _fotos.eliminar(foto.id);
    if (!mounted) return;
    setState(() => _registradas.remove(nombreFoto));
  }

  // ===========================================================================
  // Envío
  // ===========================================================================

  /// Sube lo pendiente. Las fotos ya están a salvo en el teléfono: aquí solo se
  /// gana o se pierde el intento de envío.
  Future<void> _enviarPendientes() async {
    if (_enviando) return;

    final pendientes = await _fotos.pendientesDePoste(widget.posteId);
    if (pendientes.isEmpty) {
      _avisar('No hay fotografías pendientes de enviar.');
      return;
    }

    await _cargarEstadoConexion();
    if (!mounted) return;

    if (_modoOffline || !_hayInternet) {
      await _mostrarInfo(
        'Guardado en el teléfono',
        '${pendientes.length} fotografía(s) quedaron guardadas y en cola.\n\n'
            '${_modoOffline ? 'Estás en modo offline.' : 'No hay conexión ahora mismo.'} '
            'Se enviarán desde la pantalla de Sincronización cuando tengas señal. '
            'Tu trabajo está seguro.',
      );
      return;
    }

    setState(() {
      _enviando = true;
      _confirmadasEnEnvio = 0;
      _totalEnEnvio = pendientes.length;
    });

    try {
      final archivos = <String, File>{};
      final metadatos = <String, Map<String, dynamic>>{};
      for (final f in pendientes) {
        archivos[f.nombreFoto] = f.archivo;
        metadatos[f.nombreFoto] = f.metadatosParaSubida;
      }

      await _fotos.marcarSubiendo(pendientes.map((f) => f.id));

      final resultado = await _service.subirImagenBatch(
        widget.posteId,
        archivos,
        metadatos,
      );

      // Solo lo confirmado por el servidor pasa a sincronizado.
      for (final f in pendientes) {
        if (resultado.confirmadas.contains(f.nombreFoto)) {
          await _fotos.marcarSincronizada(f.id);
        } else {
          await _fotos.marcarFallida(
            f.id,
            resultado.error ?? 'El servidor no confirmó la recepción.',
          );
        }
      }

      await _cargarFotosGuardadas();
      if (!mounted) return;
      setState(() {
        _enviando = false;
        _confirmadasEnEnvio = resultado.confirmadas.length;
      });

      if (resultado.confirmadas.length == pendientes.length) {
        await _mostrarInfo(
          'Sincronización confirmada',
          'El servidor confirmó las ${resultado.confirmadas.length} '
              'fotografía(s) enviadas.',
          exito: true,
        );
      } else if (resultado.confirmadas.isEmpty) {
        await _mostrarError(
          'No se pudo enviar',
          'Ninguna fotografía llegó al servidor.\n\n'
              'Tu información sigue guardada en el teléfono y se reintentará.\n\n'
              'Detalle: ${resultado.error ?? 'sin respuesta del servidor'}',
        );
      } else {
        await _mostrarError(
          'Envío parcial',
          'Se confirmaron ${resultado.confirmadas.length} de '
              '${pendientes.length} fotografías.\n\n'
              'Las ${pendientes.length - resultado.confirmadas.length} restantes '
              'siguen guardadas en el teléfono y se reintentarán.\n\n'
              'Detalle: ${resultado.error ?? 'confirmación incompleta'}',
        );
      }
    } catch (e) {
      // Ante cualquier excepción, devolver las fotos a la cola: nunca quedan
      // colgadas en "uploading".
      for (final f in pendientes) {
        await _fotos.marcarFallida(f.id, 'Error durante el envío: $e');
      }
      await _cargarFotosGuardadas();
      if (!mounted) return;
      setState(() => _enviando = false);
      await _mostrarError(
        'Error durante el envío',
        'Tus fotografías siguen guardadas en el teléfono.\n\nDetalle: $e',
      );
    } finally {
      // Garantiza que el indicador nunca se quede encendido.
      if (mounted && _enviando) setState(() => _enviando = false);
    }
  }

  // ===========================================================================
  // Mensajes
  // ===========================================================================

  void _avisar(String mensaje, {Color? color}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(mensaje), backgroundColor: color),
    );
  }

  Future<void> _mostrarInfo(
    String titulo,
    String mensaje, {
    bool exito = false,
  }) {
    return showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            Icon(
              exito ? Icons.cloud_done : Icons.save_alt,
              color: exito ? const Color(0xFF2E7D32) : const Color(0xFF0D47A1),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                titulo,
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
              ),
            ),
          ],
        ),
        content: Text(mensaje, style: const TextStyle(fontSize: 15)),
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Entendido'),
          ),
        ],
      ),
    );
  }

  Future<void> _mostrarError(String titulo, String mensaje) {
    return showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            const Icon(Icons.error_outline, color: Color(0xFFC62828)),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                titulo,
                style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 18,
                  color: Color(0xFFC62828),
                ),
              ),
            ),
          ],
        ),
        content: SingleChildScrollView(
          child: Text(mensaje, style: const TextStyle(fontSize: 15)),
        ),
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Entendido'),
          ),
        ],
      ),
    );
  }

  // ===========================================================================
  // Interfaz
  // ===========================================================================

  String _titulo(String nombreFoto) =>
      nombreFoto.replaceAll('_', ' ').toUpperCase();

  String _formatoTamano(int bytes) {
    if (bytes >= 1024 * 1024 * 1024) {
      return '${(bytes / (1024 * 1024 * 1024)).toStringAsFixed(2)} GB';
    }
    if (bytes >= 1024 * 1024) {
      return '${(bytes / (1024 * 1024)).toStringAsFixed(0)} MB';
    }
    return '${(bytes / 1024).toStringAsFixed(0)} KB';
  }

  Widget _iconoModoOffline() => Tooltip(
    message: _modoOffline ? 'Modo offline ACTIVADO' : 'Modo offline DESACTIVADO',
    child: Icon(
      _modoOffline ? Icons.cloud_off : Icons.cloud_done,
      color: _modoOffline ? Colors.orange : Colors.white,
    ),
  );

  Widget _iconoInternet() => Tooltip(
    message: _hayInternet ? 'Conectado a Internet' : 'Sin conexión',
    child: Icon(
      _hayInternet ? Icons.wifi : Icons.wifi_off,
      color: _hayInternet ? Colors.greenAccent : Colors.white70,
    ),
  );

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
            const Icon(Icons.photo_library_rounded, color: Colors.white),
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
      floatingActionButton: _pendientesDeEnviar == 0
          ? null
          : FloatingActionButton.extended(
              backgroundColor: const Color(0xFF0D47A1),
              icon: _enviando
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        color: Colors.white,
                        strokeWidth: 2,
                      ),
                    )
                  : const Icon(Icons.cloud_upload, color: Colors.white),
              label: Text(
                _enviando
                    ? 'Enviando…'
                    : 'Enviar $_pendientesDeEnviar pendiente'
                          '${_pendientesDeEnviar == 1 ? '' : 's'}',
                style: const TextStyle(color: Colors.white),
              ),
              onPressed: _enviando ? null : _enviarPendientes,
            ),
      body: _cargandoInicial
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                _barraProgreso(),
                if (_archivosPerdidos > 0) _avisoArchivosPerdidos(),
                Expanded(
                  child: ListView.builder(
                    padding: const EdgeInsets.only(bottom: 88),
                    itemCount: _fotosRequeridas.length,
                    itemBuilder: (context, index) =>
                        _buildFotoItem(_fotosRequeridas[index]),
                  ),
                ),
              ],
            ),
    );
  }

  Widget _barraProgreso() {
    final total = _obligatorias.length;
    final hechas = _obligatoriasTomadas;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      color: const Color(0xFFF3F5F9),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                _todasLasFotosTomadas
                    ? Icons.check_circle
                    : Icons.camera_alt_outlined,
                color: _todasLasFotosTomadas
                    ? const Color(0xFF2E7D32)
                    : const Color(0xFF0D47A1),
              ),
              const SizedBox(width: 8),
              Text(
                '$hechas de $total obligatorias',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
              ),
              const Spacer(),
              if (_pendientesDeEnviar > 0)
                Text(
                  '$_pendientesDeEnviar por enviar',
                  style: const TextStyle(
                    color: Color(0xFFEF6C00),
                    fontWeight: FontWeight.w600,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: total == 0 ? 0 : hechas / total,
              minHeight: 8,
              backgroundColor: const Color(0xFFE0E0E0),
              color: _todasLasFotosTomadas
                  ? const Color(0xFF2E7D32)
                  : const Color(0xFF0D47A1),
            ),
          ),
          if (_enviando && _totalEnEnvio > 0) ...[
            const SizedBox(height: 8),
            Text(
              'Sincronizando $_confirmadasEnEnvio de $_totalEnEnvio fotografías…',
              style: const TextStyle(fontSize: 13, color: Color(0xFF0D47A1)),
            ),
          ],
          if (_espacioOcupado > 0) ...[
            const SizedBox(height: 6),
            Text(
              'Fotos en el teléfono: ${_formatoTamano(_espacioOcupado)}'
              ' · calidad ${_perfil.nombre}'
              '${_cola.ocupada ? ' · optimizando ${_cola.pendientes + _cola.activas}' : ''}',
              style: const TextStyle(fontSize: 12, color: Colors.black54),
            ),
          ],
        ],
      ),
    );
  }

  Widget _avisoArchivosPerdidos() {
    return Container(
      width: double.infinity,
      color: const Color(0xFFFFF3E0),
      padding: const EdgeInsets.all(12),
      child: Row(
        children: [
          const Icon(Icons.warning_amber_rounded, color: Color(0xFFEF6C00)),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              '$_archivosPerdidos fotografía(s) registradas ya no tienen su '
              'archivo en el teléfono. Hay que volver a tomarlas.',
              style: const TextStyle(fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFotoItem(String nombreFoto) {
    final foto = _registradas[nombreFoto];
    final esOpcional = _fotosOpcionales.contains(nombreFoto);
    final estaProcesando =
        _procesando == nombreFoto || _optimizando.contains(nombreFoto);
    final archivoValido = foto != null && foto.archivo.existsSync();

    final (Color fondo, Color borde, String etiqueta, IconData icono) =
        switch (foto?.estado) {
          EstadoSync.sincronizado => (
            const Color(0xFFE8F5E9),
            const Color(0xFF2E7D32),
            'Sincronizada',
            Icons.cloud_done,
          ),
          EstadoSync.fallido => (
            const Color(0xFFFFEBEE),
            const Color(0xFFC62828),
            'Error al enviar — sigue guardada',
            Icons.error_outline,
          ),
          EstadoSync.subiendo => (
            const Color(0xFFE3F2FD),
            const Color(0xFF1565C0),
            'Subiendo…',
            Icons.cloud_upload,
          ),
          null =>
            esOpcional
                ? (
                    const Color(0xFFF5F5F5),
                    const Color(0xFF757575),
                    'Opcional',
                    Icons.camera_alt_rounded,
                  )
                : (
                    const Color(0xFFFFF8E1),
                    const Color(0xFFEF6C00),
                    'Obligatoria — pendiente',
                    Icons.camera_alt_rounded,
                  ),
          _ => (
            const Color(0xFFFFF3E0),
            const Color(0xFFEF6C00),
            'Guardada — por enviar',
            Icons.save_alt,
          ),
        };

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: fondo,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: borde, width: 1.4),
      ),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        leading: estaProcesando
            ? const SizedBox(
                width: 55,
                height: 55,
                child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
              )
            : archivoValido
            ? ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: Image.file(
                  // Miniatura generada en la optimización si existe; si no, la
                  // propia foto decodificada a tamaño reducido.
                  foto.archivoParaMiniatura,
                  width: 55,
                  height: 55,
                  fit: BoxFit.cover,
                  cacheWidth: 165,
                  errorBuilder: (_, __, ___) =>
                      Icon(Icons.broken_image, size: 44, color: borde),
                ),
              )
            : Icon(icono, size: 44, color: borde),
        title: Text(
          _titulo(nombreFoto),
          style: TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.w600,
            color: foto == null ? borde : Colors.black87,
          ),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              _optimizando.contains(nombreFoto)
                  ? 'Guardada — optimizando…'
                  : etiqueta,
              style: TextStyle(
                fontSize: 12.5,
                color: borde,
                fontWeight: FontWeight.w600,
              ),
            ),
            if (foto != null) ...[
              Text(
                _detalleFoto(foto),
                style: const TextStyle(fontSize: 12, color: Colors.black54),
              ),
              if (foto.ultimoError != null)
                Text(
                  foto.ultimoError!,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 11, color: Color(0xFFC62828)),
                ),
            ],
          ],
        ),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            IconButton(
              tooltip: foto == null ? 'Tomar foto' : 'Repetir foto',
              onPressed: _enviando ? null : () => _tomarFoto(nombreFoto),
              icon: Icon(
                foto == null ? Icons.camera_alt : Icons.refresh,
                color: borde,
              ),
              iconSize: 26,
            ),
            if (foto != null)
              IconButton(
                tooltip: 'Eliminar foto',
                onPressed: _enviando ? null : () => _eliminarFoto(nombreFoto),
                icon: const Icon(Icons.delete_outline, color: Color(0xFF757575)),
                iconSize: 24,
              ),
          ],
        ),
        onTap: _enviando ? null : () => _tomarFoto(nombreFoto),
      ),
    );
  }

  String _detalleFoto(FotoLocal foto) {
    final partes = <String>[];
    if (foto.tamanoBytes != null) {
      partes.add('${(foto.tamanoBytes! / (1024 * 1024)).toStringAsFixed(1)} MB');
    }
    if (foto.ancho != null && foto.alto != null) {
      partes.add('${foto.ancho}×${foto.alto}');
    }
    if (foto.zona != null && foto.zona!.isNotEmpty) {
      partes.add('UTM ${foto.zona}');
    } else {
      partes.add('sin GPS');
    }
    if (foto.precisionGps != null) {
      partes.add('±${foto.precisionGps!.toStringAsFixed(0)} m');
    }
    if (foto.intentos > 0) {
      partes.add('${foto.intentos} intento(s)');
    }
    return partes.join(' · ');
  }
}
