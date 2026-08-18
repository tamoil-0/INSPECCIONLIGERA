import 'package:flutter/material.dart';

import '../core/entorno.dart';
import '../data/remoto/cliente_api.dart';
import '../database/database_helper.dart';
import '../presentacion/comunes/componentes.dart';
import '../presentacion/diseno/tema_ecoing.dart';
import '../services/auth_service.dart';
import '../services/poste_service.dart';
import '../services/proyecto_service.dart';

/// Inicio de sesión.
///
/// ## Cambios respecto a la versión anterior
///
/// * **La descarga masiva ya no bloquea el login a ciegas.** Antes se
///   descargaban todos los proyectos y, en serie, todos los postes de cada uno,
///   sin progreso y sin reintento: si fallaba a mitad, la base quedaba a medias
///   y el inspector no se enteraba. Ahora hay progreso real por proyecto y, si
///   algo falla, se entra igual y se avisa de qué faltó.
/// * **Mostrar/ocultar contraseña.**
/// * **Comportamiento explícito sin conexión**: si ya hay datos locales se
///   puede seguir trabajando.
/// * **Versión y entorno visibles**, para saber contra qué servidor se está.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _usuarioCtrl = TextEditingController();
  final _contrasenaCtrl = TextEditingController();
  final _authService = AuthService();

  bool _cargando = false;
  bool _verContrasena = false;
  String? _error;
  String? _progreso;
  double? _fraccion;

  @override
  void dispose() {
    _usuarioCtrl.dispose();
    _contrasenaCtrl.dispose();
    super.dispose();
  }

  Future<void> _iniciarSesion() async {
    if (_cargando) return;
    if (!(_formKey.currentState?.validate() ?? false)) return;

    FocusScope.of(context).unfocus();
    setState(() {
      _cargando = true;
      _error = null;
      _progreso = 'Verificando credenciales…';
      _fraccion = null;
    });

    final resultado = await _authService.login(
      _usuarioCtrl.text.trim(),
      _contrasenaCtrl.text,
    );

    if (!mounted) return;

    if (!resultado.exito) {
      setState(() {
        _cargando = false;
        _progreso = null;
        _error = resultado.mensaje;
      });
      return;
    }

    final aviso = await _descargarDatosIniciales();
    if (!mounted) return;

    setState(() {
      _cargando = false;
      _progreso = null;
    });

    if (aviso != null) {
      await _mostrarAvisoDescarga(aviso);
      if (!mounted) return;
    }
    Navigator.pushReplacementNamed(context, '/proyectos');
  }

  /// Descarga proyectos y postes con progreso.
  ///
  /// Devuelve un aviso si algo quedó sin descargar, o `null` si todo fue bien.
  /// **Nunca impide entrar**: es mejor entrar con datos parciales y saberlo que
  /// quedarse fuera.
  Future<String?> _descargarDatosIniciales() async {
    final db = DatabaseHelper();
    final proyectoService = ProyectoService();
    final posteService = PosteService();

    List<Map<String, dynamic>> proyectos;
    try {
      setState(() => _progreso = 'Descargando proyectos…');
      proyectos = await proyectoService.listar();
      await db.insertOrUpdateProyectos(proyectos);
    } on ErrorApi catch (e) {
      final locales = await db.getProyectos();
      if (locales.isEmpty) {
        return 'No se pudieron descargar los proyectos y no hay datos '
            'guardados en este teléfono.\n\n${e.mensajeUsuario}';
      }
      return 'No se pudieron actualizar los proyectos, así que se usarán los '
          '${locales.length} que ya están en el teléfono.\n\n${e.mensajeUsuario}';
    }

    final fallidos = <String>[];
    var descargados = 0;

    for (var i = 0; i < proyectos.length; i++) {
      final proyecto = proyectos[i];
      final nombre = (proyecto['nombre_proyecto'] ?? 'proyecto').toString();
      if (!mounted) return null;
      setState(() {
        _progreso = 'Descargando estructuras de $nombre…';
        _fraccion = proyectos.isEmpty ? null : (i / proyectos.length);
      });

      final id = int.tryParse(proyecto['id'].toString());
      if (id == null) continue;

      try {
        final postes = await posteService.listarPorProyecto(id);
        // Se guardan TODOS, también los que no tienen línea.
        //
        // Antes se filtraba `postes.where(linea != null)` antes de insertar, de
        // modo que un poste sin línea en el servidor era invisible en la app
        // para siempre y sin ningún aviso.
        await db.insertOrUpdatePostes(postes);
        descargados += postes.length;
      } on ErrorApi catch (e) {
        fallidos.add('$nombre (${e.mensajeUsuario})');
      } catch (e) {
        fallidos.add('$nombre ($e)');
      }
    }

    if (!mounted) return null;
    setState(() {
      _progreso = 'Listo: $descargados estructuras disponibles.';
      _fraccion = 1;
    });

    if (fallidos.isEmpty) return null;
    return 'No se pudieron descargar las estructuras de '
        '${fallidos.length} proyecto(s):\n\n• ${fallidos.join('\n• ')}\n\n'
        'Puedes entrar y reintentar desde la lista de proyectos.';
  }

  Future<void> _mostrarAvisoDescarga(String mensaje) {
    return showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Descarga incompleta'),
        content: SingleChildScrollView(child: Text(mensaje)),
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Continuar'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ColoresEcoing.fondo,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(
              horizontal: Espacio.xl,
              vertical: Espacio.xxl,
            ),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 460),
              child: Column(
                children: [
                  _cabecera(),
                  const SizedBox(height: Espacio.xxl),
                  _tarjetaFormulario(),
                  const SizedBox(height: Espacio.l),
                  _pie(),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _cabecera() {
    return Column(
      children: [
        Container(
          padding: const EdgeInsets.all(Espacio.m),
          decoration: BoxDecoration(
            color: ColoresEcoing.superficie,
            borderRadius: BorderRadius.circular(Espacio.radioGrande),
            border: Border.all(color: ColoresEcoing.borde),
          ),
          child: Image.asset(
            'assets/images/img.png',
            height: 52,
            fit: BoxFit.contain,
            errorBuilder: (_, __, ___) => const Icon(
              Icons.electrical_services,
              size: 44,
              color: ColoresEcoing.azul,
            ),
          ),
        ),
        const SizedBox(height: Espacio.l),
        const Text(
          'App-Ecoing',
          style: TextStyle(
            fontSize: 28,
            fontWeight: FontWeight.bold,
            color: ColoresEcoing.azul,
          ),
        ),
        const Text(
          'Contratistas Generales S.R.L.',
          style: TextStyle(fontSize: 15, color: ColoresEcoing.textoSuave),
        ),
        const SizedBox(height: Espacio.xs),
        const Text(
          'Inspección de líneas de alta tensión',
          style: TextStyle(fontSize: 13.5, color: ColoresEcoing.textoTenue),
        ),
      ],
    );
  }

  Widget _tarjetaFormulario() {
    return Container(
      padding: const EdgeInsets.all(Espacio.xl),
      decoration: BoxDecoration(
        color: ColoresEcoing.superficie,
        borderRadius: BorderRadius.circular(Espacio.radioGrande),
        border: Border.all(color: ColoresEcoing.borde),
      ),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextFormField(
              controller: _usuarioCtrl,
              enabled: !_cargando,
              autocorrect: false,
              textInputAction: TextInputAction.next,
              decoration: const InputDecoration(
                labelText: 'Nombre de usuario',
                prefixIcon: Icon(Icons.person_outline),
              ),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'Campo requerido' : null,
            ),
            const SizedBox(height: Espacio.l),
            TextFormField(
              controller: _contrasenaCtrl,
              enabled: !_cargando,
              obscureText: !_verContrasena,
              textInputAction: TextInputAction.done,
              onFieldSubmitted: (_) => _iniciarSesion(),
              decoration: InputDecoration(
                labelText: 'Contraseña',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  tooltip: _verContrasena
                      ? 'Ocultar contraseña'
                      : 'Mostrar contraseña',
                  icon: Icon(
                    _verContrasena
                        ? Icons.visibility_off_outlined
                        : Icons.visibility_outlined,
                  ),
                  onPressed: () =>
                      setState(() => _verContrasena = !_verContrasena),
                ),
              ),
              validator: (v) =>
                  (v == null || v.isEmpty) ? 'Campo requerido' : null,
            ),
            const SizedBox(height: Espacio.xl),
            if (_cargando)
              Column(
                children: [
                  if (_fraccion != null)
                    ClipRRect(
                      borderRadius: BorderRadius.circular(Espacio.xs),
                      child: LinearProgressIndicator(
                        value: _fraccion,
                        minHeight: 8,
                        backgroundColor: ColoresEcoing.borde,
                      ),
                    )
                  else
                    const LinearProgressIndicator(minHeight: 8),
                  const SizedBox(height: Espacio.m),
                  Text(
                    _progreso ?? 'Cargando…',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontSize: 14,
                      color: ColoresEcoing.textoSuave,
                    ),
                  ),
                ],
              )
            else
              FilledButton.icon(
                onPressed: _iniciarSesion,
                icon: const Icon(Icons.login),
                label: const Text('Iniciar sesión'),
              ),
            if (_error != null) ...[
              const SizedBox(height: Espacio.l),
              Aviso.error(texto: _error!),
            ],
          ],
        ),
      ),
    );
  }

  Widget _pie() {
    final entorno = Entorno.etiquetaVisible;
    return Column(
      children: [
        Text(
          'Versión 1.1.0',
          style: const TextStyle(
            fontSize: 12.5,
            color: ColoresEcoing.textoTenue,
          ),
        ),
        if (entorno != null) ...[
          const SizedBox(height: Espacio.xs),
          Container(
            padding: const EdgeInsets.symmetric(
              horizontal: Espacio.s,
              vertical: 2,
            ),
            decoration: BoxDecoration(
              color: ColoresEcoing.pendienteFondo,
              borderRadius: BorderRadius.circular(Espacio.xs),
              border: Border.all(color: ColoresEcoing.pendiente),
            ),
            child: Text(
              'Entorno: $entorno',
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.bold,
                color: ColoresEcoing.pendiente,
              ),
            ),
          ),
        ],
      ],
    );
  }
}
