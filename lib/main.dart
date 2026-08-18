import 'package:flutter/material.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'core/entorno.dart';
import 'data/remoto/cliente_api.dart';
import 'presentacion/diseno/tema_ecoing.dart';
import 'repositorios/borradores_repositorio.dart';
import 'repositorios/fotos_repositorio.dart';
import 'screens/login_screen.dart';
import 'screens/ajustes_screen.dart';
import 'screens/proyecto_screen.dart';
import 'screens/sincronizacion.dart';
import 'servicios/conectividad/servicio_conectividad.dart';
import 'storage/almacen_seguro.dart';

/// Llave global del navegador, para poder llevar al login desde un 401 sin
/// depender de que alguna pantalla concreta esté montada.
final GlobalKey<NavigatorState> navegadorApp = GlobalKey<NavigatorState>();

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Datos regionales para formatear fechas en español (Perú).
  await initializeDateFormatting('es_PE', null);

  // Recuperación de trabajo interrumpido.
  //
  // Si la app se cerró (o el sistema la mató) durante una subida, quedaron
  // registros en estado `uploading` que nadie iba a reintentar. Aquí vuelven a
  // la cola antes de que el inspector vea la primera pantalla.
  await _recuperarTrabajoInterrumpido();

  // Un único punto de la app avisa de que la sesión venció.
  ClienteApi.alVencerSesion = _irAlLogin;

  // Escucha de conectividad centralizada: se arranca antes de la primera
  // pantalla para que el indicador no aparezca en falso.
  ServicioConectividad.instancia.iniciar();

  final almacen = AlmacenSeguro();
  final haySesion = await almacen.haySesion();
  final vencida = haySesion && await almacen.tokenVencido();

  runApp(
    MyApp(
      initialRoute: (haySesion && !vencida) ? '/proyectos' : '/login',
      sesionVencidaAlArrancar: vencida,
    ),
  );
}

Future<void> _recuperarTrabajoInterrumpido() async {
  try {
    final fotos = await FotosRepositorio().recuperarSubidasInterrumpidas();
    final formularios = await BorradoresRepositorio()
        .recuperarEnviosInterrumpidos();
    if (fotos > 0 || formularios > 0) {
      debugPrint(
        'Recuperados tras cierre inesperado: $fotos fotografía(s) y '
        '$formularios formulario(s) devueltos a la cola.',
      );
    }
  } catch (e) {
    // Un fallo aquí no debe impedir abrir la app: los datos siguen en disco y
    // la pantalla de sincronización los detectará igual.
    debugPrint('No se pudo recuperar el trabajo interrumpido: $e');
  }
}

/// Lleva al login conservando **todo** lo pendiente.
///
/// Se dispara con un 401/403 del servidor. No borra credenciales ni datos: solo
/// saca al inspector a la pantalla de inicio de sesión, donde el aviso explica
/// que su trabajo sigue guardado.
void _irAlLogin() {
  final navegador = navegadorApp.currentState;
  if (navegador == null) return;
  final contexto = navegador.context;
  final rutaActual = ModalRoute.of(contexto)?.settings.name;
  if (rutaActual == '/login') return;

  ScaffoldMessenger.maybeOf(contexto)?.showSnackBar(
    const SnackBar(
      backgroundColor: ColoresEcoing.error,
      duration: Duration(seconds: 6),
      content: Text(
        'Tu sesión venció. Vuelve a iniciar sesión; lo que tengas pendiente '
        'sigue guardado en el teléfono.',
      ),
    ),
  );
  navegador.pushNamedAndRemoveUntil('/login', (_) => false);
}

class MyApp extends StatelessWidget {
  final String initialRoute;
  final bool sesionVencidaAlArrancar;

  const MyApp({
    super.key,
    required this.initialRoute,
    this.sesionVencidaAlArrancar = false,
  });

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Ecoing IP 26',
      debugShowCheckedModeBanner: !Entorno.esProduccion,
      navigatorKey: navegadorApp,
      theme: TemaEcoing.claro(),
      initialRoute: initialRoute,
      // Se limita el escalado de texto por arriba para que la interfaz siga
      // siendo usable con la accesibilidad al máximo, sin ignorar la
      // preferencia del sistema.
      builder: (context, child) {
        final escala = MediaQuery.textScalerOf(
          context,
        ).clamp(minScaleFactor: 0.9, maxScaleFactor: 1.6);
        return MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: escala),
          child: child ?? const SizedBox.shrink(),
        );
      },
      routes: {
        '/login': (context) => const LoginScreen(),
        '/proyectos': (context) => const ProyectosScreen(),
        '/sincronizacion': (context) => const SincronizacionScreen(),
        '/ajustes': (context) => const AjustesScreen(),
      },
      // Evita la pantalla de error de Flutter si se navega a una ruta que no
      // existe: el menú anterior tenía un "Editar Postes" apuntando a
      // /editar_postes, que nunca estuvo registrada.
      onUnknownRoute: (settings) => MaterialPageRoute(
        builder: (_) => Scaffold(
          appBar: AppBar(title: const Text('Sección no disponible')),
          body: Center(
            child: Padding(
              padding: const EdgeInsets.all(Espacio.xl),
              child: Text(
                'La sección "${settings.name}" no está disponible en esta '
                'versión.',
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 16),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
