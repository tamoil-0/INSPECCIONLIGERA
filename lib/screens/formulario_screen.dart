import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../database/database_helper.dart';
import '../models/formulario_modal.dart';
import '../repositorios/borradores_repositorio.dart';
import '../services/poste_datos_service.dart';
import '../widgets/debug_info_widget.dart';
import '../widgets/formulario_chips.dart';
import '../widgets/formulario_dropdowns.dart';
import '../widgets/formulario_estado_placas.dart';
import '../widgets/tablero_rst.dart';

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
  final _formKey = GlobalKey<FormState>();
  final _modelo = FormularioModal();
  final PosteDatosService _posteService = PosteDatosService();
  final BorradoresRepositorio _borradores = BorradoresRepositorio();

  bool _isLoading = false;
  bool _cargandoBorrador = true;
  BorradorFormulario? _borrador;

  @override
  void initState() {
    super.initState();
    _recuperarBorrador();
  }

  /// Carga el borrador guardado del poste, si existe.
  ///
  /// ANTES: `FormularioModal.cargarDesdeMap` existía pero no se llamaba desde
  /// ningún sitio. Pulsar "Editar" en una estructura ya inventariada abría el
  /// formulario en blanco y, al enviarlo, sobrescribía `poste_datos` (que usa
  /// ConflictAlgorithm.replace sobre la PK poste_id) con los valores por
  /// defecto. La inspección original se perdía.
  Future<void> _recuperarBorrador() async {
    try {
      final borrador = await _borradores.obtener(widget.posteId);
      if (borrador != null && borrador.datos.isNotEmpty) {
        _modelo.cargarDesdeMap(borrador.datos);
        _modelo.seleccionados
          ..clear()
          ..addAll(borrador.seleccionadosRst);
      }
      if (!mounted) return;
      setState(() {
        _borrador = borrador;
        _cargandoBorrador = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _cargandoBorrador = false);
      _showErrorDialog(
        context,
        'No se pudo recuperar el borrador',
        'El formulario se abre en blanco para no perder nada de lo anterior.\n\n'
            'Detalle: $e',
      );
    }
  }

  /// Aviso visible de que se recuperó trabajo previo, y de en qué estado está.
  ///
  /// Es la señal de que "Editar" ya no parte de cero: si el inspector ve este
  /// banner, sabe que lo que tiene en pantalla es su inspección anterior.
  Widget _avisoBorrador() {
    if (_cargandoBorrador) {
      return Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.white24,
          borderRadius: BorderRadius.circular(12),
        ),
        child: const Row(
          children: [
            SizedBox(
              width: 16,
              height: 16,
              child: CircularProgressIndicator(
                strokeWidth: 2,
                color: Colors.white,
              ),
            ),
            SizedBox(width: 12),
            Text(
              'Buscando trabajo guardado…',
              style: TextStyle(color: Colors.white),
            ),
          ],
        ),
      );
    }

    final borrador = _borrador;
    if (borrador == null) return const SizedBox.shrink();

    final sincronizado = borrador.estaSincronizado;
    final fecha = borrador.actualizadoEn ?? borrador.creadoEn;
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: sincronizado ? const Color(0xFFE8F5E9) : const Color(0xFFFFF3E0),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: sincronizado
              ? const Color(0xFF2E7D32)
              : const Color(0xFFEF6C00),
        ),
      ),
      child: Row(
        children: [
          Icon(
            sincronizado ? Icons.cloud_done : Icons.save_alt,
            color: sincronizado
                ? const Color(0xFF2E7D32)
                : const Color(0xFFEF6C00),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  sincronizado
                      ? 'Inspección ya sincronizada — la estás editando'
                      : 'Borrador recuperado de este teléfono',
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
                Text(
                  [
                    if (fecha != null)
                      'Guardado el ${fecha.day}/${fecha.month}/${fecha.year} '
                          '${fecha.hour.toString().padLeft(2, '0')}:'
                          '${fecha.minute.toString().padLeft(2, '0')}',
                    if (borrador.rst.isNotEmpty)
                      '${borrador.rst.length} marca(s) RST',
                    if (borrador.intentos > 0)
                      '${borrador.intentos} intento(s) de envío',
                  ].join(' · '),
                  style: const TextStyle(fontSize: 12.5, color: Colors.black54),
                ),
                if (borrador.ultimoError != null)
                  Text(
                    borrador.ultimoError!,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 11.5,
                      color: Color(0xFFC62828),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Future<bool> _estaModoOffline() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool('modo_offline') ?? false;
  }
  bool _validarEstadoPlacas() {
    return (_modelo.estadoPlacasTorre?.isNotEmpty ?? false) &&
        (_modelo.estadoPlacasLinea?.isNotEmpty ?? false) &&
        (_modelo.estadoPlacasFases?.isNotEmpty ?? false) &&
        (_modelo.peligroCerco?.isNotEmpty ?? false) &&
        (_modelo.peligroTorre?.isNotEmpty ?? false) &&
        (_modelo.puestaTierra?.isNotEmpty ?? false);
  }


  bool _validarSoloUnAtributoPorFase(Map<String, bool> seleccionados) {
    final Map<String, Set<String>> fasePorSeccion = {
      "R": {},
      "S": {},
      "T": {},
    };

    for (var entrada in seleccionados.entries) {
      if (!entrada.value) continue;

      final partes = entrada.key.split('|');
      if (partes.length != 3) continue;

      final seccion = partes[0]; // ej. conductores_fase
      final fase = partes[2]; // R, S, T

      final clave = '$seccion|$fase';

      fasePorSeccion.putIfAbsent(fase, () => <String>{});
      fasePorSeccion[fase]!.add(clave);
    }

    // Validar que en cada fase, por cada sección, solo se haya marcado un atributo
    for (var fase in ['R', 'S', 'T']) {
      final usados = <String, int>{};
      for (var entrada in seleccionados.entries) {
        if (!entrada.value) continue;

        final partes = entrada.key.split('|');
        if (partes.length != 3) continue;

        final seccion = partes[0];
        final f = partes[2];

        if (f != fase) continue;

        final clave = '$seccion|$fase';
        usados[clave] = (usados[clave] ?? 0) + 1;
      }

      for (var conteo in usados.values) {
        if (conteo > 1) return false;
      }
    }

    return true;
  }


  /// Guarda el formulario y, si es posible, lo envía.
  ///
  /// ## Cambios respecto a la versión anterior
  ///
  /// 1. **Toda la validación ocurre ANTES de activar `_isLoading`.** Antes, si
  ///    `_validarEstadoPlacas()` fallaba, el `return` salía sin restaurar el
  ///    indicador (el `finally` estaba en un `try` posterior) y el botón
  ///    "ENVIAR FORMULARIO" quedaba deshabilitado con spinner permanente:
  ///    había que salir de la pantalla y perder lo escrito.
  /// 2. **El guardado local ocurre siempre y primero**, incluso sin token.
  ///    Antes, si el token faltaba, se salía sin guardar nada.
  /// 3. **No hay `Navigator.pop()` automáticos.** Antes se ejecutaban dos, sin
  ///    comprobar `mounted` ni el resultado: cerraban el diálogo de error en
  ///    lugar de la pantalla, o pantallas que no correspondían.
  /// 4. **Los mensajes distinguen "guardado en el teléfono" de "confirmado por
  ///    el servidor".**
  Future<void> _enviarFormulario() async {
    if (_isLoading) return;

    // ---- 1. Validaciones (todavía sin bloquear el botón) ----
    if (!(_formKey.currentState?.validate() ?? false)) {
      await _showErrorDialog(
        context,
        "Campos incompletos",
        "Faltan campos obligatorios. Revisa los campos resaltados en rojo.",
      );
      return;
    }

    if (!_validarEstadoPlacas()) {
      await _showErrorDialog(
        context,
        "Campos obligatorios faltantes",
        "Debes completar todos los campos en la sección de Estado de Placas.",
      );
      return;
    }

    if (!_validarSoloUnAtributoPorFase(_modelo.seleccionados)) {
      await _showErrorDialog(
        context,
        "Error en Tablero RST",
        "Solo puedes seleccionar una opción por fase (R, S o T) dentro de cada "
            "grupo.\n\nEjemplo: no puedes marcar hebras rotas y encanastillado "
            "a la vez en R.",
      );
      return;
    }

    setState(() => _isLoading = true);

    try {
      final datos = _modelo.toMap();
      datos['fecha_inspeccion'] = DateTime.now().toIso8601String();
      final rst = _modelo.toRSTLocal();

      // ---- 2. Guardado local garantizado ----
      // Ocurre antes de mirar la red y antes de mirar el token: si algo va mal
      // después, el trabajo del inspector ya está a salvo.
      try {
        await _borradores.guardar(
          posteId: widget.posteId,
          datos: datos,
          rst: rst,
        );
      } catch (e) {
        await _showErrorDialog(
          context,
          "No se pudo guardar en el teléfono",
          "El formulario NO quedó guardado. No cierres la pantalla: vuelve a "
              "intentarlo.\n\nDetalle: $e",
        );
        return;
      }

      // ---- 3. Intento de envío ----
      final prefs = await SharedPreferences.getInstance();
      final token = prefs.getString('token');
      final offline = await _estaModoOffline();

      if (token == null || token.isEmpty) {
        await _mostrarGuardadoLocal(
          motivo: 'Tu sesión venció, así que no se pudo enviar ahora.',
        );
        return;
      }
      if (offline) {
        await _mostrarGuardadoLocal(motivo: 'Estás en modo offline.');
        return;
      }
      if (!await _posteService.verificarConexion()) {
        await _mostrarGuardadoLocal(motivo: 'No hay conexión en este momento.');
        return;
      }

      await _borradores.marcarSubiendo(widget.posteId);

      final okDatos = await _posteService.actualizarDatosPoste(
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
          motivo: 'El servidor no confirmó la recepción.',
          esError: true,
        );
        return;
      }

      var okRst = true;
      if (rst.isNotEmpty) {
        okRst = await _posteService.agregarSeccionRST(
          posteId: widget.posteId,
          token: token,
          datos: {"registros": _modelo.toRSTServidor()},
        );
      }

      if (!okRst) {
        await _borradores.marcarFallido(
          widget.posteId,
          'Los datos se enviaron pero el tablero RST no fue confirmado.',
        );
        await _mostrarGuardadoLocal(
          motivo: 'El tablero RST no fue confirmado por el servidor.',
          esError: true,
        );
        return;
      }

      // ---- 4. Solo aquí hubo confirmación real ----
      await _borradores.marcarSincronizado(widget.posteId);
      await DatabaseHelper().guardarFormularioCompleto(
        posteId: widget.posteId,
        datos: datos,
      );
      await _mostrarConfirmadoPorServidor();
    } catch (e) {
      await _borradores.marcarFallido(widget.posteId, 'Error de envío: $e');
      await _showErrorDialog(
        context,
        "Error al enviar",
        "Tu formulario quedó guardado en el teléfono y se reintentará desde "
            "Sincronización.\n\nDetalle: $e",
      );
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  /// Mensaje para cuando el dato está a salvo en el teléfono pero no enviado.
  Future<void> _mostrarGuardadoLocal({
    required String motivo,
    bool esError = false,
  }) {
    if (!mounted) return Future.value();
    return showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            Icon(
              esError ? Icons.cloud_off : Icons.save_alt,
              color: esError ? const Color(0xFFEF6C00) : const Color(0xFF0D47A1),
            ),
            const SizedBox(width: 8),
            const Expanded(
              child: Text(
                'Guardado en este teléfono',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
              ),
            ),
          ],
        ),
        content: Text(
          '$motivo\n\n'
          'El formulario quedó guardado y pendiente de enviar. '
          'Podrás sincronizarlo desde la pantalla de Sincronización cuando '
          'tengas señal. Tu información está segura.',
          style: const TextStyle(fontSize: 15),
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

  Future<void> _mostrarConfirmadoPorServidor() {
    if (!mounted) return Future.value();
    return showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: const [
            Icon(Icons.cloud_done, color: Color(0xFF2E7D32)),
            SizedBox(width: 8),
            Expanded(
              child: Text(
                'Sincronización confirmada',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
              ),
            ),
          ],
        ),
        content: const Text(
          'El servidor confirmó que recibió el formulario y el tablero RST.',
          style: TextStyle(fontSize: 15),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Seguir aquí'),
          ),
          FilledButton(
            onPressed: () {
              Navigator.of(ctx).pop();
              // Cierre explícito, decidido por el inspector.
              if (mounted) Navigator.of(context).pop(true);
            },
            child: const Text('Volver a la lista'),
          ),
        ],
      ),
    );
  }




  Future<void> _showErrorDialog(BuildContext context, String titulo, String mensaje, {bool mostrarInfoLocal = false}) {
    if (!mounted) return Future.value();
    return showDialog<void>(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          backgroundColor: const Color(0xFFFFF8E1),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(20),
          ),
          title: Row(
            children: [
              const Icon(Icons.offline_pin, color: Color(0xFFD32F2F)),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  titulo,
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    color: Color(0xFFD32F2F),
                    fontSize: 18,
                  ),
                ),
              ),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                mensaje,
                style: const TextStyle(
                  fontSize: 16,
                  color: Color(0xFF3A3A3A),
                ),
                textAlign: TextAlign.center,
              ),
              if (mostrarInfoLocal) ...[
                const SizedBox(height: 12),
                const Text(
                  "Tus datos fueron guardados de forma segura en el dispositivo.\n\nNo te preocupes, al final lo sincronizarás cuando tengas buena conexión",
                  style: TextStyle(
                    fontSize: 14,
                    color: Color(0xFF0D47A1),
                    fontWeight: FontWeight.w600,
                  ),
                  textAlign: TextAlign.center,
                ),
              ]
            ],
          ),
          actions: [
            TextButton.icon(
              icon: const Icon(Icons.check_circle, color: Colors.white),
              label: const Text("OK", style: TextStyle(color: Colors.white)),
              style: TextButton.styleFrom(
                backgroundColor: const Color(0xFF0D47A1),
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
              ),
              onPressed: () => Navigator.of(context).pop(),
            ),
          ],
        );
      },
    );
  }



  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text("Formulario del Poste", style: TextStyle(color: Colors.white)),
        iconTheme: const IconThemeData(color: Colors.white),
        actions: [
          FutureBuilder<bool>(
            future: _posteService.verificarConexion(),
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.done) {
                final conectado = snapshot.data ?? false;
                return Icon(
                  conectado ? Icons.wifi : Icons.wifi_off,
                  color: conectado ? Colors.greenAccent : Colors.grey[300],
                );
              }
              return const SizedBox();
            },
          ),
          const SizedBox(width: 12),
          FutureBuilder<bool>(
            future: _estaModoOffline(),
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.done) {
                final offline = snapshot.data ?? false;
                return Icon(
                  offline ? Icons.cloud_off : Icons.cloud_done,
                  color: offline ? Colors.yellow : Colors.white,
                );
              }
              return const SizedBox();
            },
          ),
          const SizedBox(width: 12),
          IconButton(
            icon: const Icon(Icons.bug_report, color: Colors.white),
            onPressed: () {
              showDialog(
                context: context,
                builder: (_) => DebugInfoWidget(
                  posteId: widget.posteId,
                  obstaculos: _modelo.obstaculosFaja,
                  estadoCuencas: _modelo.estadoCuencas,
                  totalRSTSeleccionados: _modelo.seleccionados.values.where((v) => v).length,
                ),
              );
            },
          ),
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
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text("Estructura: ${widget.estructura}",
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: Colors.white)),
                  Text("Proyecto: ${widget.proyectoNombre}", style: const TextStyle(color: Colors.white70)),
                  const SizedBox(height: 16),
                  _avisoBorrador(),
                  // Aquí volveremos a insertar tus 22 campos completos uno por uno
                  // (lo haremos en el siguiente paso de edición para mantener claridad y orden)
                  buildDropdownMultiple(
                    label: '1. Obstáculos en Faja',
                    options: FormularioModal.obstaculosFajaOptions,
                    seleccionados: _modelo.obstaculosFaja,
                    onChanged: (val) => setState(() => _modelo.obstaculosFaja = val),

                  ),
                  buildDropdown(
                    label: '2. Estado de Cuencas',
                    value: _modelo.estadoCuencas,
                    options: FormularioModal.estadoCuencasOptions,
                    onChanged: (val) => setState(() => _modelo.estadoCuencas = val),

                  ),
                  buildDropdown(
                    label: '3. Marcado de Árboles',
                    value: _modelo.marcadoArboles,
                    options: FormularioModal.marcadoArbolesOptions,
                    onChanged: (val) => setState(() => _modelo.marcadoArboles = val),

                  ),
                  buildDropdown(
                    label: '4. Criticidad de Tala',
                    value: _modelo.criticidadTala,
                    options: FormularioModal.criticidadTalaOptions,
                    onChanged: (val) => setState(() => _modelo.criticidadTala = val),


                  ),
                  buildDropdown(
                    label: '5. Criticidad de Contacto',
                    value: _modelo.criticidadContacto,
                    options: FormularioModal.criticidadContactoOptions,
                    onChanged: (val) => setState(() => _modelo.criticidadContacto = val),

                  ),
                  buildDropdown(
                    label: '6. Notificación de Propietario',
                    value: _modelo.notificacionPropietario,
                    options: FormularioModal.notificacionPropietarioOptions,
                    onChanged: (val) => setState(() => _modelo.notificacionPropietario = val),

                  ),
                  buildDropdown(
                    label: '7. Tipo de Torre',
                    value: _modelo.tipoTorre,
                    options: FormularioModal.tipoTorreOptions,
                    onChanged: (val) => setState(() => _modelo.tipoTorre = val),
                    isRequired: true,
                  ),
                  buildDropdown(
                    label: '8. Ubicación',
                    value: _modelo.ubicacion,
                    options: FormularioModal.ubicacionOptions,
                    onChanged: (val) => setState(() => _modelo.ubicacion = val),
                    isRequired: true,
                  ),
                  buildDropdown(
                    label: '9. Acceso Torre',
                    value: _modelo.accesoTorre,
                    options: FormularioModal.accesoTorreOptions,
                    onChanged: (val) => setState(() => _modelo.accesoTorre = val),
                    isRequired: true,
                  ),
                  buildDropdown(
                    label: '10. Estado de Acceso',
                    value: _modelo.estadoAcceso,
                    options: FormularioModal.estadoAccesoOptions,
                    onChanged: (val) => setState(() => _modelo.estadoAcceso = val),

                  ),
                  buildSeccionEstadoPlacas(
                    context,
                    _modelo.estadoPlacasTorre, (val) => setState(() => _modelo.estadoPlacasTorre = val),
                    _modelo.estadoPlacasLinea, (val) => setState(() => _modelo.estadoPlacasLinea = val),
                    _modelo.estadoPlacasFases, (val) => setState(() => _modelo.estadoPlacasFases = val),
                    _modelo.peligroCerco, (val) => setState(() => _modelo.peligroCerco = val),
                    _modelo.peligroTorre, (val) => setState(() => _modelo.peligroTorre = val),
                    _modelo.puestaTierra, (val) => setState(() => _modelo.puestaTierra = val),
                  ),
                  buildDropdown(
                    label: '12. Retenida',
                    value: _modelo.retenida,
                    options: FormularioModal.retenidaOptions,
                    onChanged: (val) => setState(() => _modelo.retenida = val),

                  ),
                  buildDropdown(
                    label: '13. Estado de Base',
                    value: _modelo.estadoBase,
                    options: FormularioModal.estadoBaseOptions,
                    onChanged: (val) => setState(() => _modelo.estadoBase = val),

                  ),
                  buildDropdown(
                    label: '14. Limpiar Base',
                    value: _modelo.limpiarBase,
                    options: FormularioModal.limpiarBaseOptions,
                    onChanged: (val) => setState(() => _modelo.limpiarBase = val),

                  ),
                  buildDropdown(
                    label: '15. Crucetas Mensuales',
                    value: _modelo.crucetasMensuales,
                    options: FormularioModal.crucetasMensualesOptions,
                    onChanged: (val) => setState(() => _modelo.crucetasMensuales = val),

                  ),
                  buildDropdown(
                    label: '16. Perfiles Angulares',
                    value: _modelo.perfilesAngulares,
                    options: FormularioModal.perfilesAngularesOptions,
                    onChanged: (val) => setState(() => _modelo.perfilesAngulares = val),

                  ),
                  buildDropdown(
                    label: '17. Malla Antiescalamiento',
                    value: _modelo.mallaAntiescalamiento,
                    options: FormularioModal.mallaAntiescalamientoOptions,
                    onChanged: (val) => setState(() => _modelo.mallaAntiescalamiento = val),

                  ),
                  buildDropdown(
                    label: '18. Óxidos Base',
                    value: _modelo.oxidosBase,
                    options: FormularioModal.oxidosBaseOptions,
                    onChanged: (val) => setState(() => _modelo.oxidosBase = val),

                  ),
                  buildDropdown(
                    label: '19. Cadena Aisladores',
                    value: _modelo.cadenaAisladores,
                    options: FormularioModal.cadenaAisladoresOptions,
                    onChanged: (val) => setState(() => _modelo.cadenaAisladores = val),

                  ),
                  buildDropdown(
                    label: '20. Tipo Aislador',
                    value: _modelo.tipoAislador,
                    options: FormularioModal.tipoAisladorOptions,
                    onChanged: (val) => setState(() => _modelo.tipoAislador = val),

                  ),
                  buildDropdown(
                    label: '21. Conductor Bajada PAT',
                    value: _modelo.conductorBajadaPat,
                    options: FormularioModal.conductorBajadaPatOptions,
                    onChanged: (val) => setState(() => _modelo.conductorBajadaPat = val),

                  ),
                  buildDropdown(
                    label: '22. Conductor Guarda',
                    value: _modelo.conductorGuarda,
                    options: FormularioModal.conductorGuardaOptions,
                    onChanged: (val) => setState(() => _modelo.conductorGuarda = val),

                  ),
                  buildTableroRST(
                    seleccionados: _modelo.seleccionados,
                    onChanged: (clave, valor) {
                      setState(() => _modelo.seleccionados[clave] = valor);
                    },
                  ),

                  // Campo de comentarios
                  const SizedBox(height: 16),
                  Text(
                    '23. Comentarios (opcional)',
                    style: TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.bold,
                      color: Colors.yellow,
                    ),
                  ),
                  const SizedBox(height: 8),
                  TextFormField(
                    decoration: InputDecoration(
                      hintText: 'Escribe tus comentarios aquí...',
                      filled: true,
                      fillColor: Colors.white,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    maxLines: 4,
                    style: const TextStyle(fontSize: 16),
                    initialValue: _modelo.comentarios,
                    onChanged: (value) => _modelo.comentarios = value.trim(),
                  ),

                  const SizedBox(height: 16),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFFFBC02D),
                        foregroundColor: Colors.black,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(20),
                        ),
                      ),
                      onPressed: _isLoading ? null : _enviarFormulario,
                      child: _isLoading
                          ? const CircularProgressIndicator(color: Colors.white)
                          : const Text("ENVIAR FORMULARIO"),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
