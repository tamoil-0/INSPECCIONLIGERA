import 'package:flutter/material.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../services/proyecto_service.dart';
import '../services/auth_service.dart';
import '../database/database_helper.dart';
import 'buscar_linea_screen.dart';

class ProyectosScreen extends StatefulWidget {
  const ProyectosScreen({Key? key}) : super(key: key);

  @override
  _ProyectosScreenState createState() => _ProyectosScreenState();
}

class _ProyectosScreenState extends State<ProyectosScreen> {
  final ProyectoService _proyectoService = ProyectoService();
  final AuthService _authService = AuthService();
  final DatabaseHelper _dbHelper = DatabaseHelper();

  List<dynamic> _proyectos = [];
  bool _isLoading = true;
  String? _errorMessage;

  String? _nombreUsuario;
  String? _correoUsuario;
  bool _isConnected = true;
  bool _modoOffline = false;

  @override
  void initState() {
    super.initState();
    _inicializar();
  }

  Future<void> _verificarConexion() async {
    final connectivityResult = await Connectivity().checkConnectivity();
    setState(() {
      _isConnected = connectivityResult != ConnectivityResult.none;
    });
  }

  Future<void> _inicializar() async {
    final prefs = await SharedPreferences.getInstance();
    _modoOffline = prefs.getBool('modo_offline') ?? false;

    final usuario = await _authService.getUsuarioActual();
    setState(() {
      _nombreUsuario = usuario?['nombre_completo'] ?? 'Usuario';
      _correoUsuario = usuario?['correo_electronico'] ?? 'Correo';
    });

    await _verificarConexion();
    await _cargarProyectos();
  }

  Future<void> _cargarProyectos() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    if (_isConnected && !_modoOffline) {
      final response = await _proyectoService.listarProyectos();

      if (response['success']) {
        _proyectos = response['data'];
        await _dbHelper.insertOrUpdateProyectos(_proyectos);
      } else {
        _errorMessage = response['error'];
      }
    } else {
      _proyectos = await _dbHelper.getProyectos();
      if (_proyectos.isEmpty) {
        _errorMessage = 'Sin conexión y sin datos locales';
      }
    }

    setState(() {
      _isLoading = false;
    });
  }

  Future<void> _toggleModoOffline() async {
    final prefs = await SharedPreferences.getInstance();
    setState(() {
      _modoOffline = !_modoOffline;
      prefs.setBool('modo_offline', _modoOffline);
    });
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(_modoOffline
            ? '🔌 Modo Offline activado. No se usarán datos móviles.'
            : '🌐 Modo Online activado. Se usarán datos del servidor.'),
      ),
    );
    await _cargarProyectos();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        title: const Text('Proyectos', style: TextStyle(color: Colors.white)),
        centerTitle: true,
        actions: [
          IconButton(
            icon: Icon(_modoOffline ? Icons.cloud_off : Icons.cloud_queue,
                color: _modoOffline ? Colors.yellow : Colors.white),
            onPressed: _toggleModoOffline,
          ),
          IconButton(
            icon: Icon(Icons.wifi,
                color: _isConnected ? Colors.greenAccent : Colors.grey[300]),
            onPressed: _verificarConexion,
          ),
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: () async {
              await _verificarConexion();
              await _cargarProyectos();
            },
          ),
        ],
      ),
      drawer: _buildDrawer(),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [Color(0xFFB71C1C), Color(0xFF0D47A1)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: SafeArea(
          child: Column(
            children: [
              if (_modoOffline)
                Container(
                  width: double.infinity,
                  color: Colors.yellow[100],
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: const [
                      Icon(Icons.cloud_off, color: Colors.red),
                      SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Estás en MODO OFFLINE. Todos los datos se guardarán localmente.',
                          style: TextStyle(fontSize: 14, color: Colors.black87),
                        ),
                      ),
                    ],
                  ),
                ),
              Expanded(
                child: _isLoading
                    ? const Center(
                  child:
                  CircularProgressIndicator(color: Colors.white),
                )
                    : _errorMessage != null
                    ? Center(
                  child: Text(_errorMessage!,
                      style: const TextStyle(color: Colors.redAccent)),
                )
                    : _proyectos.isEmpty
                    ? const Center(
                  child: Text('No hay proyectos disponibles',
                      style: TextStyle(color: Colors.white)),
                )
                    : ListView.builder(
                  padding: const EdgeInsets.all(12),
                  itemCount: _proyectos.length,
                  itemBuilder: (context, index) {
                    final proyecto = _proyectos[index];
                    return Card(
                      color: Colors.white,
                      elevation: 6,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(16),
                      ),
                      margin: const EdgeInsets.symmetric(
                          vertical: 8, horizontal: 4),
                      child: ListTile(
                        title: Text(
                          proyecto['nombre_proyecto'],
                          style: const TextStyle(
                              fontWeight: FontWeight.bold),
                        ),
                        subtitle: Column(
                          crossAxisAlignment:
                          CrossAxisAlignment.start,
                          children: [
                            Text(
                                'Contratista: ${proyecto['contratista']}'),
                            Text(
                                'Ubicación: ${proyecto['ubicacion']}'),
                            Text('Estado: ${proyecto['estado']}'),
                          ],
                        ),
                        trailing: Chip(
                          label: Text(
                            proyecto['estado'],
                            style: const TextStyle(
                                color: Colors.white),
                          ),
                          backgroundColor: _getColorByEstado(
                              proyecto['estado'] ?? ''),
                        ),
                        onTap: () {
                          final proyectoId = proyecto['id'] is int
                              ? proyecto['id']
                              : int.tryParse(
                              proyecto['id'].toString()) ??
                              0;

                          Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (context) =>
                                  BuscarLineaScreen(
                                    proyectoId: proyectoId,
                                    proyectoNombre:
                                    proyecto['nombre_proyecto'],
                                  ),
                            ),
                          );
                        },
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildDrawer() {
    return Drawer(
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          UserAccountsDrawerHeader(
            accountName: Text(_nombreUsuario ?? 'Usuario'),
            accountEmail: Text(_correoUsuario ?? 'Correo'),
            currentAccountPicture: const CircleAvatar(
              backgroundColor: Colors.white,
              child: Icon(Icons.person, color: Colors.red),
            ),
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                colors: [Color(0xFFB71C1C), Color(0xFF0D47A1)],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
            ),
          ),
          ListTile(
            leading: const Icon(Icons.person),
            title: const Text('Ver Perfil'),
            onTap: () => Navigator.pop(context),
          ),
          ListTile(
            leading: const Icon(Icons.sync),
            title: const Text('Sincronización'),
            onTap: () {
              Navigator.pop(context);
              Navigator.pushNamed(context, '/sincronizacion');
            },
          ),
          ListTile(
            leading: const Icon(Icons.edit_location_alt),
            title: const Text('Editar Postes'),
            onTap: () {
              Navigator.pop(context);
              Navigator.pushNamed(context, '/editar_postes');
            },
          ),
          ListTile(
            leading: const Icon(Icons.settings),
            title: const Text('Ajustes'),
            onTap: () => Navigator.pop(context),
          ),
          const Divider(),
          ListTile(
            leading: const Icon(Icons.logout),
            title: const Text('Cerrar Sesión'),
            onTap: () async {
              await _authService.logout();
              Navigator.pop(context);
              Navigator.pushReplacementNamed(context, '/login');
            },
          ),
        ],
      ),
    );
  }

  Color _getColorByEstado(String estado) {
    switch (estado.toLowerCase()) {
      case 'activo':
        return const Color(0xFF81C784);
      case 'completado':
        return const Color(0xFF64B5F6);
      case 'cancelado':
        return const Color(0xFFE57373);
      default:
        return const Color(0xFFBDBDBD);
    }
  }
}