import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../database/database_helper.dart';
import '../models/formulario_modal.dart';
import '../services/poste_datos_service.dart';

import '../widgets/formulario_dropdowns.dart';
import '../widgets/formulario_estado_placas.dart';
import '../widgets/tablero_rst.dart';
import '../widgets/debug_info_widget.dart';
import '../widgets/formulario_chips.dart';
import 'imagenesPoste_screen.dart';
import '../models/formulario_modal.dart';
import 'package:pruebaoffline/widgets/debug_info_widget.dart' as debug_widget;
import 'package:pruebaoffline/utils/dialogs_util.dart' as dialogs;

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


  bool _isLoading = false;

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

      final seccion = partes[0];      // ej. conductores_fase
      final atributo = partes[1];     // ej. hebras_rotas
      final fase = partes[2];         // R, S, T

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
        final atributo = partes[1];
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


  Future<void> _enviarFormulario() async {
    if (_isLoading) return;

    final isValid = _formKey.currentState?.validate() ?? false;
    if (!isValid) {
      _showErrorDialog(
        context,
        "Campos incompletos",
        "Faltan campos obligatorios. Revisa los campos resaltados en rojo.",
      );
      return;
    }

    setState(() => _isLoading = true);
    if (!_validarEstadoPlacas()) {
      _showErrorDialog(
        context,
        "Campos obligatorios faltantes",
        "Debes completar todos los campos en la sección de Estado de Placas.",
      );
      return;
    }

    // 👇🏻 Aquí validamos primero que no haya errores RST
    if (!_validarSoloUnAtributoPorFase(_modelo.seleccionados)) {
      _showErrorDialog(
        context,
        "Error en Tablero RST",
        "Solo puedes seleccionar **una opción por fase (R, S o T)** dentro de cada grupo.\n\nEjemplo: No puedes marcar hebras rotas y encanastillado a la vez en R.",
      );
      setState(() => _isLoading = false);
      return;
    }


    try {
      final prefs = await SharedPreferences.getInstance();
      final token = prefs.getString('token');
      if (token == null || token.isEmpty) {
        _showErrorDialog(context, "Error", "Token no encontrado.");
        return;
      }

      final datos = _modelo.toMap();
      datos['fecha_inspeccion'] = DateTime.now().toIso8601String();

      await DatabaseHelper().guardarFormularioPendiente(posteId: widget.posteId, datos: datos);
      await DatabaseHelper().guardarRSTLocal(widget.posteId, _modelo.toRSTLocal());

      final offline = await _estaModoOffline();
      if (!offline && await _posteService.verificarConexion()) {
        final ok = await _posteService.actualizarDatosPoste(
          posteId: widget.posteId,
          token: token,
          datos: datos,
        );
        if (ok) {
          await _posteService.agregarSeccionRST(
            posteId: widget.posteId,
            token: token,
            datos: {
              "registros": _modelo.toRSTServidor(),
            },
          );
          _mostrarPantallaExito();
        } else {
          _showErrorDialog(context, "Guardado local", "Error al enviar. Datos guardados localmente.");
        }
      } else {
        _showErrorDialog(context, "Sin conexión", "Guardado local sin conexión.");
      }

      Future.delayed(const Duration(seconds: 3), () {
        Navigator.pop(context); // Cierra el formulario
        Navigator.pop(context); // Cierra las imágenes
      });


    } catch (e) {
      _showErrorDialog(context, "Error", e.toString());
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }




  void _mostrarPantallaExito() {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text("✅ Enviado correctamente"),
        content: const Text("El formulario fue enviado con éxito."),
        actions: [
          TextButton(
            child: const Text("OK"),
            onPressed: () => Navigator.of(context).pop(),
          ),
        ],
      ),
    );
  }

  void _showErrorDialog(BuildContext context, String titulo, String mensaje, {bool mostrarInfoLocal = false}) {
    showDialog(
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
