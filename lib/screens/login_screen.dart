import 'package:flutter/material.dart';
import '../services/auth_service.dart';
import '../database/database_helper.dart';
import '../services/proyecto_service.dart';
import '../services/poste_service.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({Key? key}) : super(key: key);

  @override
  _LoginScreenState createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _usernameController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();
  bool _isLoading = false;
  String? _errorMessage;

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    final authService = AuthService();
    final response = await authService.login(
      _usernameController.text.trim(),
      _passwordController.text.trim(),
    );

    if (response['success']) {
      final proyectoService = ProyectoService();
      final posteService = PosteService();
      final dbHelper = DatabaseHelper();

      final proyectosResponse = await proyectoService.listarProyectos();

      if (proyectosResponse['success']) {
        final proyectos = proyectosResponse['data'];
        await dbHelper.insertOrUpdateProyectos(proyectos);

        for (var proyecto in proyectos) {
          final int proyectoId = int.parse(proyecto['id'].toString());
          final postesResponse = await posteService.listarPostesPorProyecto(proyectoId);

          if (postesResponse['success']) {
            final postes = postesResponse['data'];
            final postesConLinea = postes
                .where((p) => p['linea'] != null && p['linea'].toString().trim().isNotEmpty)
                .toList();
            for (var poste in postesConLinea) {
              print('📦 Poste descargado: '
                  'ID=${poste['id']}, '
                  'Código=${poste['codigo']}, '
                  'Línea=${poste['linea']}, '
                  'Estructura=${poste['estructura']}, '
                  'Ubicación=${poste['ubicaciones']}');
            }
            await dbHelper.insertOrUpdatePostes(postesConLinea);
          }
        }
      }

      setState(() => _isLoading = false);
      Navigator.pushReplacementNamed(context, '/proyectos');
    } else {
      setState(() {
        _isLoading = false;
        _errorMessage = response['error'];
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        width: double.infinity,
        height: double.infinity,
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [Color(0xFFB71C1C), Color(0xFF0D47A1)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24.0),
            child: Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(24),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withOpacity(0.15),
                    blurRadius: 20,
                    offset: const Offset(0, 10),
                  ),
                ],
              ),
              child: Form(
                key: _formKey,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    // Logo + Nombre
                    Column(
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: Image.asset(
                            'assets/images/img.png',
                            height: 40,
                            width: 80,
                            fit: BoxFit.cover,
                          ),
                        ),
                        const SizedBox(height: 12),
                        const Text(
                          "App-Ecoing",
                          style: TextStyle(
                            fontSize: 26,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF0D47A1),
                          ),
                        ),
                        const Text(
                          "Contratistas-Generales S.R.L",
                          style: TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF0D47A1),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 32),
                    TextFormField(
                      controller: _usernameController,
                      decoration: InputDecoration(
                        labelText: 'Nombre de usuario',
                        prefixIcon: const Icon(Icons.person_outline),
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(16),
                        ),
                        filled: true,
                        fillColor: Colors.grey[100],
                      ),
                      validator: (value) => value!.isEmpty ? 'Campo requerido' : null,
                    ),
                    const SizedBox(height: 20),
                    TextFormField(
                      controller: _passwordController,
                      obscureText: true,
                      decoration: InputDecoration(
                        labelText: 'Contraseña',
                        prefixIcon: const Icon(Icons.lock_outline),
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(16),
                        ),
                        filled: true,
                        fillColor: Colors.grey[100],
                      ),
                      validator: (value) => value!.isEmpty ? 'Campo requerido' : null,
                    ),
                    const SizedBox(height: 30),
                    _isLoading
                        ? Column(
                      children: const [
                        CircularProgressIndicator(color: Color(0xFFFBC02D)),
                        SizedBox(height: 12),
                        Text(
                          "Cargando datos, por favor espera...",
                          style: TextStyle(color: Color(0xFF424242)),
                        ),
                      ],
                    )
                        : SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          padding: const EdgeInsets.symmetric(vertical: 16),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(20),
                          ),
                          backgroundColor: const Color(0xFFB71C1C),
                        ),
                        onPressed: _login,
                        child: const Text(
                          'Iniciar sesión',
                          style: TextStyle(fontSize: 18, color: Colors.white),
                        ),
                      ),
                    ),
                    if (_errorMessage != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 16.0),
                        child: Text(
                          _errorMessage!,
                          style: const TextStyle(color: Colors.redAccent),
                          textAlign: TextAlign.center,
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
