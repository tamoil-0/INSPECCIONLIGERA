import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'repositorios/borradores_repositorio.dart';
import 'repositorios/fotos_repositorio.dart';
import 'screens/login_screen.dart';
import 'screens/proyecto_screen.dart';
import 'screens/sincronizacion.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Datos regionales para formatear fechas en español (Perú).
  await initializeDateFormatting('es_PE', null);

  // Recuperación de trabajo interrumpido.
  //
  // Si la app se cerró (o el sistema la mató) durante una subida, quedaron
  // registros en estado `uploading` que nadie iba a reintentar. Aquí vuelven a
  // la cola antes de que el inspector vea la primera pantalla.
  //
  // No es opcional ni diferible: es lo que hace que "cerrar la app a mitad de
  // una sincronización" deje de ser una forma de perder trabajo.
  await _recuperarTrabajoInterrumpido();

  final prefs = await SharedPreferences.getInstance();
  final token = prefs.getString('token');

  runApp(MyApp(initialRoute: token != null ? '/proyectos' : '/login'));
}

Future<void> _recuperarTrabajoInterrumpido() async {
  try {
    final fotos = await FotosRepositorio().recuperarSubidasInterrumpidas();
    final formularios =
        await BorradoresRepositorio().recuperarEnviosInterrumpidos();
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

class MyApp extends StatelessWidget {
  final String initialRoute;

  const MyApp({super.key, required this.initialRoute});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      initialRoute: initialRoute,
      routes: {
        '/login': (context) => const LoginScreen(),
        '/proyectos': (context) => const ProyectosScreen(),
        '/sincronizacion': (context) => const SincronizacionScreen(),
      },
      // Evita la pantalla de error de Flutter cuando se navega a una ruta que
      // no existe: el menú tenía un "Editar Postes" apuntando a /editar_postes,
      // que está comentada en este mapa.
      onUnknownRoute: (settings) => MaterialPageRoute(
        builder: (_) => Scaffold(
          appBar: AppBar(title: const Text('Sección no disponible')),
          body: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Text(
                'La sección "${settings.name}" todavía no está disponible '
                'en esta versión.',
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
