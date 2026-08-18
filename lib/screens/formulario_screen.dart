import 'dart:async';

import 'package:flutter/material.dart';

import '../core/entorno.dart';
import '../core/estados_sync.dart';
import '../core/preferencias_app.dart';
import '../data/remoto/cliente_api.dart';
import '../database/database_helper.dart';
import '../models/formulario_modal.dart';
import '../presentacion/comunes/componentes.dart';
import '../presentacion/diseno/tema_ecoing.dart';
import '../repositorios/borradores_repositorio.dart';
import '../repositorios/fotos_repositorio.dart';
import '../servicios/conectividad/servicio_conectividad.dart';
import '../services/poste_datos_service.dart';
import '../storage/almacen_seguro.dart';
import '../widgets/formulario_chips.dart';
import '../widgets/formulario_dropdowns.dart';
import '../widgets/formulario_estado_placas.dart';
import '../widgets/tablero_rst.dart';

/// Formulario técnico de inspección, en pasos.
///
/// ## Cambios respecto a la versión anterior
///
/// * **De una pantalla interminable de 23 ítems a 5 pasos** con indicador de
///   progreso y navegación anterior/siguiente.
/// * **Autoguardado** tras cada cambio: el trabajo no depende de que el
///   inspector llegue al botón de enviar.
/// * **«No revisado» por defecto** en lugar de 19 respuestas precargadas que
///   nadie miró (ver `FormularioModal`).
/// * **Paso de resumen** antes de finalizar: fotos, campos sin revisar, estado
///   local y estado de sincronización.
/// * **Recupera el borrador anterior** al abrir.
/// * **Atrás no pierde nada**: solo pide confirmación si hay cambios sin guardar.
/// * Sin `Navigator.pop()` automáticos ni modales que se cierran solos.
class FormularioPostePage extends StatefulWidget {
  final int proyectoId;
  final String estructura;
  final String proyectoNombre;
  final int posteId;

  const FormularioPostePage({
    super.key,
    required this.proyectoId,
    required this.estructura,
    required this.proyectoNombre,
    required this.posteId,
  });

  @override
  State<FormularioPostePage> createState() => _FormularioPostePageState();
}

class _FormularioPostePageState extends State<FormularioPostePage> {
  static const List<String> _titulosPasos = [
    'Faja y vegetación',
    'Torre y accesos',
    'Placas y seguridad',
    'Estructura y herrajes',
    'Tablero RST y cierre',
  ];

  final _modelo = FormularioModal();
  final _clavesPasos = List.generate(5, (_) => GlobalKey<FormState>());
  final _comentariosCtrl = TextEditingController();
  final _borradores = BorradoresRepositorio();
  final _fotos = FotosRepositorio();
  final _datosService = PosteDatosService();

  int _paso = 0;
  bool _cargando = true;
  bool _enviando = false;
  bool _guardando = false;
  bool _hayCambiosSinGuardar = false;
  bool _modoOffline = false;
  DateTime? _ultimoGuardado;
  BorradorFormulario? _borrador;
  int _fotosTomadas = 0;
  Timer? _temporizadorAutoguardado;

  @override
  void initState() {
    super.initState();
    _inicializar();
  }

  @override
  void dispose() {
    _temporizadorAutoguardado?.cancel();
    _comentariosCtrl.dispose();
    super.dispose();
  }

  Future<void> _inicializar() async {
    final prefs = await PreferenciasApp.instancia();
    _modoOffline = prefs.modoOffline;

    try {
      final borrador = await _borradores.obtener(widget.posteId);
      if (borrador != null && borrador.datos.isNotEmpty) {
        _modelo.cargarDesdeMap(borrador.datos);
        _modelo.seleccionados
          ..clear()
          ..addAll(borrador.seleccionadosRst);
        _comentariosCtrl.text = _modelo.comentarios ?? '';
      }
      _borrador = borrador;
      _ultimoGuardado = borrador?.actualizadoEn;
    } catch (e) {
      if (mounted) {
        _avisar('No se pudo recuperar el borrador: $e', ColoresEcoing.error);
      }
    }

    final fotos = await _fotos.fotosDePoste(widget.posteId);
    if (!mounted) return;
    setState(() {
      _fotosTomadas = fotos.length;
      _cargando = false;
    });
  }

  // ===========================================================================
  // Autoguardado
  // ===========================================================================

  /// Registra un cambio y programa el guardado.
  ///
  /// Se agrupa con un temporizador de 800 ms para no escribir en SQLite en cada
  /// pulsación mientras el inspector escribe comentarios.
  void _cambio(String claveCampo, VoidCallback aplicar) {
    setState(() {
      aplicar();
      if (claveCampo.isNotEmpty) _modelo.marcarRevisado(claveCampo);
      _hayCambiosSinGuardar = true;
    });
    _programarAutoguardado();
  }

  void _programarAutoguardado() {
    _temporizadorAutoguardado?.cancel();
    _temporizadorAutoguardado = Timer(
      const Duration(milliseconds: 800),
      () => _guardarBorrador(silencioso: true),
    );
  }

  Future<bool> _guardarBorrador({bool silencioso = false}) async {
    if (_guardando) return false;
    setState(() => _guardando = true);
    try {
      _modelo.comentarios = _comentariosCtrl.text.trim();
      final borrador = await _borradores.guardar(
        posteId: widget.posteId,
        datos: _modelo.toMap(),
        rst: _modelo.toRSTLocal(),
      );
      if (!mounted) return true;
      setState(() {
        _borrador = borrador;
        _ultimoGuardado = borrador.actualizadoEn ?? DateTime.now();
        _hayCambiosSinGuardar = false;
        _guardando = false;
      });
      return true;
    } catch (e) {
      if (!mounted) return false;
      setState(() => _guardando = false);
      if (!silencioso) {
        await _mostrarDialogo(
          titulo: 'No se pudo guardar en el teléfono',
          mensaje: 'El formulario NO quedó guardado. No cierres la pantalla: '
              'vuelve a intentarlo.\n\nDetalle: $e',
          esError: true,
        );
      } else {
        _avisar('No se pudo autoguardar: $e', ColoresEcoing.error);
      }
      return false;
    }
  }

  // ===========================================================================
  // Validación
  // ===========================================================================

  /// En el tablero RST no puede haber más de un atributo por sección y fase.
  bool _rstValido() {
    final conteo = <String, int>{};
    for (final entrada in _modelo.seleccionados.entries) {
      if (!entrada.value) continue;
      final partes = entrada.key.split('|');
      if (partes.length != 3) continue;
      final clave = '${partes[0]}|${partes[2]}';
      conteo[clave] = (conteo[clave] ?? 0) + 1;
    }
    return !conteo.values.any((n) => n > 1);
  }

  Future<void> _siguiente() async {
    if (!(_clavesPasos[_paso].currentState?.validate() ?? true)) {
      _avisar('Faltan campos obligatorios en este paso.', ColoresEcoing.error);
      return;
    }
    await _guardarBorrador(silencioso: true);
    if (!mounted) return;
    if (_paso < _titulosPasos.length - 1) setState(() => _paso++);
  }

  void _anterior() {
    if (_paso > 0) setState(() => _paso--);
  }

  // ===========================================================================
  // Envío
  // ===========================================================================

  Future<void> _finalizar() async {
    if (_enviando) return;

    // Todas las validaciones ANTES de bloquear el botón.
    //
    // En la versión anterior `_isLoading` se activaba antes de validar el estado
    // de placas; si esa validación fallaba, el `return` salía sin restaurarlo y
    // el botón quedaba deshabilitado con spinner permanente.
    for (var i = 0; i < _clavesPasos.length; i++) {
      final formulario = _clavesPasos[i].currentState;
      if (formulario != null && !formulario.validate()) {
        setState(() => _paso = i);
        _avisar(
          'Revisa los campos marcados del paso ${i + 1}.',
          ColoresEcoing.error,
        );
        return;
      }
    }

    if (!_rstValido()) {
      setState(() => _paso = 4);
      await _mostrarDialogo(
        titulo: 'Error en el tablero RST',
        mensaje: 'Solo puedes marcar una opción por fase (R, S o T) en cada '
            'grupo.\n\nEjemplo: no se puede marcar «hebras rotas» y '
            '«encanastillado» a la vez en la fase R.',
        esError: true,
      );
      return;
    }

    // El guardado local ocurre siempre y primero.
    if (!await _guardarBorrador()) return;
    if (!mounted) return;

    setState(() => _enviando = true);
    try {
      final token = await AlmacenSeguro().token();
      if (token == null || token.isEmpty) {
        await _mostrarGuardadoLocal(
          'Tu sesión venció, así que no se pudo enviar ahora.',
        );
        return;
      }
      if (_modoOffline) {
        await _mostrarGuardadoLocal('Estás en modo offline.');
        return;
      }

      final red = await ServicioConectividad.instancia.comprobar(forzar: true);
      if (!red.conectado) {
        await _mostrarGuardadoLocal(
          red.tipo == TipoRed.ninguna
              ? 'No hay conexión en este momento.'
              : 'Hay red pero sin salida a internet.',
        );
        return;
      }

      final datos = _modelo.toMap();
      await _borradores.marcarSubiendo(widget.posteId);

      final okDatos = await _datosService.actualizarDatosPoste(
        posteId: widget.posteId,
        token: token,
        datos: datos,
      );
      if (!okDatos) {
        await _borradores.marcarFallido(
          widget.posteId,
          'El servidor no confirmó la actualización de datos.',
        );
        await _mostrarGuardadoLocal(
          'El servidor no confirmó la recepción.',
          esError: true,
        );
        return;
      }

      final rst = _modelo.toRSTServidor();
      if (rst.isNotEmpty) {
        final okRst = await _datosService.agregarSeccionRST(
          posteId: widget.posteId,
          token: token,
          datos: {'registros': rst},
        );
        if (!okRst) {
          await _borradores.marcarFallido(
            widget.posteId,
            'Los datos se enviaron pero el tablero RST no fue confirmado.',
          );
          await _mostrarGuardadoLocal(
            'El tablero RST no fue confirmado por el servidor.',
            esError: true,
          );
          return;
        }
      }

      // Solo aquí hubo confirmación real.
      await _borradores.marcarSincronizado(widget.posteId);
      await DatabaseHelper().guardarFormularioCompleto(
        posteId: widget.posteId,
        datos: datos,
      );
      await _recargarBorrador();
      await _mostrarConfirmado();
    } on ErrorApi catch (e) {
      await _borradores.marcarFallido(widget.posteId, e.toString());
      await _mostrarGuardadoLocal(e.mensajeUsuario, esError: true);
    } catch (e) {
      await _borradores.marcarFallido(widget.posteId, 'Error de envío: $e');
      await _mostrarGuardadoLocal('Error al enviar: $e', esError: true);
    } finally {
      if (mounted) setState(() => _enviando = false);
    }
  }

  Future<void> _recargarBorrador() async {
    final borrador = await _borradores.obtener(widget.posteId);
    if (!mounted) return;
    setState(() => _borrador = borrador);
  }

  // ===========================================================================
  // Mensajes
  // ===========================================================================

  void _avisar(String mensaje, [Color? color]) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(mensaje), backgroundColor: color),
    );
  }

  Future<void> _mostrarDialogo({
    required String titulo,
    required String mensaje,
    bool esError = false,
  }) {
    if (!mounted) return Future.value();
    return showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Row(
          children: [
            Icon(
              esError ? Icons.error_outline : Icons.info_outline,
              color: esError ? ColoresEcoing.error : ColoresEcoing.azul,
            ),
            const SizedBox(width: Espacio.s),
            Expanded(child: Text(titulo)),
          ],
        ),
        content: SingleChildScrollView(child: Text(mensaje)),
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Entendido'),
          ),
        ],
      ),
    );
  }

  Future<void> _mostrarGuardadoLocal(String motivo, {bool esError = false}) {
    return _mostrarDialogo(
      titulo: 'Guardado en este teléfono',
      mensaje: '$motivo\n\nEl formulario quedó guardado y pendiente de enviar. '
          'Se sincronizará desde la pantalla de Sincronización cuando tengas '
          'señal. Tu información está segura.',
      esError: esError,
    );
  }

  Future<void> _mostrarConfirmado() {
    if (!mounted) return Future.value();
    return showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Row(
          children: [
            Icon(Icons.cloud_done, color: ColoresEcoing.exito),
            SizedBox(width: Espacio.s),
            Expanded(child: Text('Sincronización confirmada')),
          ],
        ),
        content: const Text(
          'El servidor confirmó que recibió el formulario y el tablero RST.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Seguir aquí'),
          ),
          FilledButton(
            onPressed: () {
              Navigator.of(ctx).pop();
              if (mounted) Navigator.of(context).pop(true);
            },
            child: const Text('Volver a la lista'),
          ),
        ],
      ),
    );
  }

  Future<bool> _confirmarSalida() async {
    if (!_hayCambiosSinGuardar) return true;
    final decision = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Tienes cambios sin guardar'),
        content: const Text(
          'Puedes guardarlos como borrador y seguir después, o salir '
          'descartando solo lo último que cambiaste.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop('cancelar'),
            child: const Text('Seguir editando'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop('salir'),
            style: TextButton.styleFrom(foregroundColor: ColoresEcoing.error),
            child: const Text('Salir sin guardar'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop('guardar'),
            child: const Text('Guardar y salir'),
          ),
        ],
      ),
    );

    if (decision == 'guardar') return _guardarBorrador();
    return decision == 'salir';
  }

  // ===========================================================================
  // Interfaz
  // ===========================================================================

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: !_hayCambiosSinGuardar,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (await _confirmarSalida() && mounted) {
          Navigator.of(context).pop();
        }
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text('Estructura ${widget.estructura}'),
          actions: [
            // ANTES: dos FutureBuilder en el AppBar, uno lanzando una petición
            // HTTP en CADA reconstrucción. Como el formulario se reconstruye al
            // tocar cualquiera de los 22 desplegables, eran decenas de
            // peticiones por inspección gastando batería y datos en campo.
            ValueListenableBuilder<EstadoRed>(
              valueListenable: ServicioConectividad.instancia.estado,
              builder: (context, red, _) => IndicadorConexion(
                hayInternet: red.conectado,
                modoOffline: _modoOffline,
                descripcionRed: red.descripcion,
              ),
            ),
          ],
        ),
        body: _cargando
            ? const Center(child: CircularProgressIndicator())
            : Column(
                children: [
                  _cabeceraPasos(),
                  if (_borrador != null) _avisoBorrador(),
                  Expanded(child: _contenidoPaso()),
                  _barraNavegacion(),
                ],
              ),
      ),
    );
  }

  Widget _cabeceraPasos() {
    return Container(
      color: ColoresEcoing.superficie,
      padding: const EdgeInsets.fromLTRB(
        Espacio.l,
        Espacio.m,
        Espacio.l,
        Espacio.m,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${_paso + 1}. ${_titulosPasos[_paso]}',
                  style: const TextStyle(
                    fontSize: 16.5,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
              Text(
                'Paso ${_paso + 1} de ${_titulosPasos.length}',
                style: const TextStyle(
                  fontSize: 13,
                  color: ColoresEcoing.textoSuave,
                ),
              ),
            ],
          ),
          const SizedBox(height: Espacio.s),
          Row(
            children: List.generate(_titulosPasos.length, (i) {
              final activo = i <= _paso;
              return Expanded(
                child: Semantics(
                  label: 'Ir al paso ${i + 1}: ${_titulosPasos[i]}',
                  button: true,
                  child: GestureDetector(
                    onTap: () => setState(() => _paso = i),
                    child: Container(
                      height: 8,
                      margin: EdgeInsets.only(
                        right: i == _titulosPasos.length - 1 ? 0 : Espacio.xs,
                      ),
                      decoration: BoxDecoration(
                        color: activo
                            ? ColoresEcoing.azul
                            : ColoresEcoing.borde,
                        borderRadius: BorderRadius.circular(4),
                      ),
                    ),
                  ),
                ),
              );
            }),
          ),
          const SizedBox(height: Espacio.s),
          Row(
            children: [
              Icon(
                _guardando
                    ? Icons.sync
                    : (_hayCambiosSinGuardar ? Icons.edit : Icons.check_circle),
                size: 15,
                color: _hayCambiosSinGuardar
                    ? ColoresEcoing.pendiente
                    : ColoresEcoing.exito,
              ),
              const SizedBox(width: Espacio.xs),
              Expanded(
                child: Text(
                  _guardando
                      ? 'Guardando…'
                      : (_hayCambiosSinGuardar
                            ? 'Cambios sin guardar'
                            : _ultimoGuardado == null
                                  ? 'Sin guardar todavía'
                                  : 'Guardado ${_haceCuanto(_ultimoGuardado!)}'),
                  style: const TextStyle(
                    fontSize: 12.5,
                    color: ColoresEcoing.textoSuave,
                  ),
                ),
              ),
              Text(
                '${_modelo.camposRevisados}/${_modelo.totalCampos} revisados',
                style: TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w600,
                  color: _modelo.todoRevisado
                      ? ColoresEcoing.exito
                      : ColoresEcoing.pendiente,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _avisoBorrador() {
    final b = _borrador!;
    if (b.estaSincronizado) {
      return const Aviso.exito(
        texto: 'Esta inspección ya está confirmada por el servidor. Si guardas '
            'cambios, se volverá a enviar.',
      );
    }
    return Aviso(
      icono: Icons.save_alt,
      texto: b.ultimoError != null
          ? 'Guardado en el teléfono. Último intento: ${b.ultimoError}'
          : 'Borrador guardado en el teléfono, pendiente de enviar.',
    );
  }

  Widget _contenidoPaso() {
    return Form(
      key: _clavesPasos[_paso],
      child: ListView(
        padding: const EdgeInsets.all(Espacio.l),
        children: [
          ..._camposDelPaso(_paso),
          const SizedBox(height: Espacio.xxl),
        ],
      ),
    );
  }

  List<Widget> _camposDelPaso(int paso) {
    switch (paso) {
      case 0:
        return [
          buildDropdownMultiple(
            label: '1. Obstáculos en la faja de servidumbre',
            options: FormularioModal.obstaculosFajaOptions,
            seleccionados: _modelo.obstaculosFaja,
            revisado: _modelo.estaRevisado('obstaculos_faja'),
            onChanged: (v) =>
                _cambio('obstaculos_faja', () => _modelo.obstaculosFaja = v),
            alConfirmarVacio: () => _cambio('obstaculos_faja', () {}),
          ),
          buildDropdown(
            label: '2. Estado de cuencas',
            value: _modelo.estadoCuencas,
            options: FormularioModal.estadoCuencasOptions,
            onChanged: (v) =>
                _cambio('estado_cuencas', () => _modelo.estadoCuencas = v),
          ),
          buildDropdown(
            label: '3. Marcado de árboles',
            value: _modelo.marcadoArboles,
            options: FormularioModal.marcadoArbolesOptions,
            onChanged: (v) =>
                _cambio('marcado_arboles', () => _modelo.marcadoArboles = v),
          ),
          buildDropdown(
            label: '4. Criticidad de tala',
            value: _modelo.criticidadTala,
            options: FormularioModal.criticidadTalaOptions,
            onChanged: (v) =>
                _cambio('criticidad_tala', () => _modelo.criticidadTala = v),
          ),
          buildDropdown(
            label: '5. Criticidad de contacto',
            value: _modelo.criticidadContacto,
            options: FormularioModal.criticidadContactoOptions,
            onChanged: (v) => _cambio(
              'criticidad_contacto',
              () => _modelo.criticidadContacto = v,
            ),
          ),
          buildDropdown(
            label: '6. Notificación al propietario',
            value: _modelo.notificacionPropietario,
            options: FormularioModal.notificacionPropietarioOptions,
            onChanged: (v) => _cambio(
              'notificacion_propietario',
              () => _modelo.notificacionPropietario = v,
            ),
          ),
        ];

      case 1:
        return [
          buildDropdown(
            label: '7. Tipo de torre',
            value: _modelo.tipoTorre,
            options: FormularioModal.tipoTorreOptions,
            isRequired: true,
            onChanged: (v) =>
                _cambio('tipo_torre', () => _modelo.tipoTorre = v),
          ),
          buildDropdown(
            label: '8. Ubicación',
            value: _modelo.ubicacion,
            options: FormularioModal.ubicacionOptions,
            isRequired: true,
            onChanged: (v) => _cambio('ubicacion', () => _modelo.ubicacion = v),
          ),
          buildDropdown(
            label: '9. Acceso a la torre',
            value: _modelo.accesoTorre,
            options: FormularioModal.accesoTorreOptions,
            isRequired: true,
            onChanged: (v) =>
                _cambio('acceso_torre', () => _modelo.accesoTorre = v),
          ),
          buildDropdown(
            label: '10. Estado del acceso',
            value: _modelo.estadoAcceso,
            options: FormularioModal.estadoAccesoOptions,
            onChanged: (v) =>
                _cambio('estado_acceso', () => _modelo.estadoAcceso = v),
          ),
        ];

      case 2:
        return [
          buildSeccionEstadoPlacas(
            estadoPlacasTorre: _modelo.estadoPlacasTorre,
            onPlacasTorre: (v) => _cambio(
              'estado_placas_torre',
              () => _modelo.estadoPlacasTorre = v,
            ),
            estadoPlacasLinea: _modelo.estadoPlacasLinea,
            onPlacasLinea: (v) => _cambio(
              'estado_placas_linea',
              () => _modelo.estadoPlacasLinea = v,
            ),
            estadoPlacasFases: _modelo.estadoPlacasFases,
            onPlacasFases: (v) => _cambio(
              'estado_placas_fases',
              () => _modelo.estadoPlacasFases = v,
            ),
            peligroCerco: _modelo.peligroCerco,
            onPeligroCerco: (v) =>
                _cambio('peligro_cerco', () => _modelo.peligroCerco = v),
            peligroTorre: _modelo.peligroTorre,
            onPeligroTorre: (v) =>
                _cambio('peligro_torre', () => _modelo.peligroTorre = v),
            puestaTierra: _modelo.puestaTierra,
            onPuestaTierra: (v) =>
                _cambio('puesta_tierra', () => _modelo.puestaTierra = v),
          ),
          buildDropdown(
            label: '12. Retenida',
            value: _modelo.retenida,
            options: FormularioModal.retenidaOptions,
            onChanged: (v) => _cambio('retenida', () => _modelo.retenida = v),
          ),
        ];

      case 3:
        return [
          buildDropdown(
            label: '13. Estado de la base',
            value: _modelo.estadoBase,
            options: FormularioModal.estadoBaseOptions,
            onChanged: (v) =>
                _cambio('estado_base', () => _modelo.estadoBase = v),
          ),
          buildDropdown(
            label: '14. Limpiar base',
            value: _modelo.limpiarBase,
            options: FormularioModal.limpiarBaseOptions,
            onChanged: (v) =>
                _cambio('limpiar_base', () => _modelo.limpiarBase = v),
          ),
          buildDropdown(
            label: '15. Crucetas y ménsulas',
            value: _modelo.crucetasMensuales,
            options: FormularioModal.crucetasMensualesOptions,
            onChanged: (v) => _cambio(
              'crucetas_mensuales',
              () => _modelo.crucetasMensuales = v,
            ),
          ),
          buildDropdown(
            label: '16. Perfiles angulares',
            value: _modelo.perfilesAngulares,
            options: FormularioModal.perfilesAngularesOptions,
            onChanged: (v) => _cambio(
              'perfiles_angulares',
              () => _modelo.perfilesAngulares = v,
            ),
          ),
          buildDropdown(
            label: '17. Malla antiescalamiento',
            value: _modelo.mallaAntiescalamiento,
            options: FormularioModal.mallaAntiescalamientoOptions,
            onChanged: (v) => _cambio(
              'malla_antiescalamiento',
              () => _modelo.mallaAntiescalamiento = v,
            ),
          ),
          buildDropdown(
            label: '18. Óxidos en la base',
            value: _modelo.oxidosBase,
            options: FormularioModal.oxidosBaseOptions,
            onChanged: (v) =>
                _cambio('oxidos_base', () => _modelo.oxidosBase = v),
          ),
          buildDropdown(
            label: '19. Cadena de aisladores',
            value: _modelo.cadenaAisladores,
            options: FormularioModal.cadenaAisladoresOptions,
            onChanged: (v) => _cambio(
              'cadena_aisladores',
              () => _modelo.cadenaAisladores = v,
            ),
          ),
          buildDropdown(
            label: '20. Tipo de aislador',
            value: _modelo.tipoAislador,
            options: FormularioModal.tipoAisladorOptions,
            onChanged: (v) =>
                _cambio('tipo_aislador', () => _modelo.tipoAislador = v),
          ),
          buildDropdown(
            label: '21. Conductor de bajada a PAT',
            value: _modelo.conductorBajadaPat,
            options: FormularioModal.conductorBajadaPatOptions,
            onChanged: (v) => _cambio(
              'conductor_bajada_pat',
              () => _modelo.conductorBajadaPat = v,
            ),
          ),
          buildDropdown(
            label: '22. Conductor de guarda',
            value: _modelo.conductorGuarda,
            options: FormularioModal.conductorGuardaOptions,
            onChanged: (v) =>
                _cambio('conductor_guarda', () => _modelo.conductorGuarda = v),
          ),
        ];

      default:
        return [
          buildTableroRST(
            seleccionados: _modelo.seleccionados,
            onChanged: (clave, valor) =>
                _cambio('', () => _modelo.seleccionados[clave] = valor),
          ),
          const SizedBox(height: Espacio.xl),
          const Text(
            '23. Comentarios (opcional)',
            style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: Espacio.s),
          TextFormField(
            controller: _comentariosCtrl,
            maxLines: 4,
            decoration: const InputDecoration(
              hintText: 'Observaciones que no encajen en los ítems anteriores…',
            ),
            onChanged: (_) {
              _hayCambiosSinGuardar = true;
              _programarAutoguardado();
            },
          ),
          const SizedBox(height: Espacio.xl),
          _resumenFinal(),
        ];
    }
  }

  Widget _resumenFinal() {
    final sinRevisar = _modelo.sinRevisar;
    final fotosCompletas = _fotosTomadas >= 22;

    return Container(
      padding: const EdgeInsets.all(Espacio.l),
      decoration: BoxDecoration(
        color: ColoresEcoing.superficie,
        borderRadius: BorderRadius.circular(Espacio.radioGrande),
        border: Border.all(color: ColoresEcoing.borde),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Antes de finalizar',
            style: TextStyle(fontSize: 16.5, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: Espacio.m),
          _filaResumen(
            fotosCompletas ? Icons.check_circle : Icons.warning_amber_rounded,
            'Fotografías',
            '$_fotosTomadas de 22 obligatorias',
            fotosCompletas ? ColoresEcoing.exito : ColoresEcoing.pendiente,
          ),
          _filaResumen(
            sinRevisar.isEmpty ? Icons.check_circle : Icons.info_outline,
            'Campos revisados',
            '${_modelo.camposRevisados} de ${_modelo.totalCampos}',
            sinRevisar.isEmpty ? ColoresEcoing.exito : ColoresEcoing.pendiente,
          ),
          _filaResumen(
            Icons.grid_on,
            'Tablero RST',
            '${_modelo.seleccionados.values.where((v) => v).length} marca(s)',
            ColoresEcoing.textoSuave,
          ),
          _filaResumen(
            _borrador?.estaSincronizado == true
                ? Icons.cloud_done
                : Icons.save_alt,
            'Estado',
            EstadoSync.etiqueta(_borrador?.estado),
            _borrador?.estaSincronizado == true
                ? ColoresEcoing.exito
                : ColoresEcoing.pendiente,
          ),
          if (sinRevisar.isNotEmpty) ...[
            const SizedBox(height: Espacio.m),
            Container(
              padding: const EdgeInsets.all(Espacio.m),
              decoration: BoxDecoration(
                color: ColoresEcoing.pendienteFondo,
                borderRadius: BorderRadius.circular(Espacio.radio),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${sinRevisar.length} campo(s) sin revisar',
                    style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      color: ColoresEcoing.pendiente,
                    ),
                  ),
                  const SizedBox(height: Espacio.xs),
                  Text(
                    sinRevisar.map((c) => c.replaceAll('_', ' ')).join(' · '),
                    style: const TextStyle(
                      fontSize: 12.5,
                      color: ColoresEcoing.texto,
                    ),
                  ),
                  const SizedBox(height: Espacio.xs),
                  Text(
                    Entorno.enviarNoRevisado
                        ? 'Se enviarán como «no revisado».'
                        : 'Se enviarán con su valor por defecto. Revísalos si '
                              'los inspeccionaste.',
                    style: const TextStyle(
                      fontSize: 12,
                      color: ColoresEcoing.textoSuave,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _filaResumen(
    IconData icono,
    String etiqueta,
    String valor,
    Color color,
  ) {
    return Padding(
      padding: const EdgeInsets.only(bottom: Espacio.s),
      child: Row(
        children: [
          Icon(icono, size: 18, color: color),
          const SizedBox(width: Espacio.s),
          SizedBox(
            width: 130,
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
                fontWeight: FontWeight.w600,
                color: color,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _barraNavegacion() {
    final esUltimo = _paso == _titulosPasos.length - 1;
    return Container(
      padding: const EdgeInsets.all(Espacio.m),
      decoration: const BoxDecoration(
        color: ColoresEcoing.superficie,
        border: Border(top: BorderSide(color: ColoresEcoing.borde)),
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            if (_paso > 0)
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _enviando ? null : _anterior,
                  icon: const Icon(Icons.arrow_back, size: 20),
                  label: const Text('Anterior'),
                ),
              ),
            if (_paso > 0) const SizedBox(width: Espacio.m),
            Expanded(
              flex: 2,
              child: FilledButton.icon(
                onPressed: _enviando
                    ? null
                    : (esUltimo ? _finalizar : _siguiente),
                icon: _enviando
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : Icon(
                        esUltimo ? Icons.cloud_upload : Icons.arrow_forward,
                        size: 20,
                      ),
                label: Text(
                  _enviando
                      ? 'Enviando…'
                      : (esUltimo ? 'Guardar y enviar' : 'Siguiente'),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _haceCuanto(DateTime cuando) {
    final diferencia = DateTime.now().difference(cuando);
    if (diferencia.inSeconds < 10) return 'ahora mismo';
    if (diferencia.inMinutes < 1) return 'hace ${diferencia.inSeconds} s';
    if (diferencia.inHours < 1) return 'hace ${diferencia.inMinutes} min';
    return 'a las ${cuando.hour.toString().padLeft(2, '0')}:'
        '${cuando.minute.toString().padLeft(2, '0')}';
  }
}
