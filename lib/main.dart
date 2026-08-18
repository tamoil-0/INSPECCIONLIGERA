import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:intl/date_symbol_data_local.dart'; // 👈 Importación añadida

import 'screens/login_screen.dart';
import 'screens/proyecto_screen.dart';
import 'database/database_helper.dart';
import 'screens/sincronizacion.dart';
import 'screens/imagenesPoste_screen.dart';
import 'screens/formulario_screen.dart';
import 'screens/1.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // 🧪 Opcional: limpiar base local
  //await DatabaseHelper().eliminarBaseDeDatos();

  // 🧠 Inicializa datos regionales para formatear fechas en español (Perú)
  await initializeDateFormatting('es_PE', null);


  final prefs = await SharedPreferences.getInstance();
  final token = prefs.getString('token');

  runApp(MyApp(
    initialRoute: token != null ? '/proyectos' : '/login',
  ));
}

class MyApp extends StatelessWidget {
  final String initialRoute;

  const MyApp({Key? key, required this.initialRoute}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      initialRoute: initialRoute,
      routes: {
        '/login': (context) => const LoginScreen(),
        '/proyectos': (context) => const ProyectosScreen(),
        '/sincronizacion': (context) => const SincronizacionScreen(),
        // '/editar_postes': (context) => const EditarPosteScreen(),
        // '/imagenes': (context) => const ImagenesPosteScreen(),
      },
    );
  }
}
