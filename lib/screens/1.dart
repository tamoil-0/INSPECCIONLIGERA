import 'package:flutter/material.dart';
import 'formulario_screen.dart';

class EditarPosteScreen extends StatelessWidget {
  final int proyectoId;
  final String proyectoNombre;
  final String linea;
  final List<Map<String, dynamic>> estructuras;

  const EditarPosteScreen({
    super.key,
    required this.proyectoId,
    required this.proyectoNombre,
    required this.linea,
    required this.estructuras,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text("Editar Poste"),
        backgroundColor: Colors.deepPurple,
      ),
      body: ListView.builder(
        itemCount: estructuras.length,
        itemBuilder: (context, index) {
          final estructura = estructuras[index];

          final posteId = estructura['id']; // asegúrate que tu tabla tenga este campo
          final numeroEstructura = estructura['estructura'] ?? 'Sin número';

          return ListTile(
            title: Text("Estructura: $numeroEstructura"),
            subtitle: Text("ID Poste: $posteId"),
            trailing: IconButton(
              icon: const Icon(Icons.edit, color: Colors.blue),
              onPressed: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => FormularioPostePage(
                      proyectoId: proyectoId,
                      estructura: numeroEstructura,
                      proyectoNombre: proyectoNombre,
                      posteId: posteId,
                    ),
                  ),
                );
              },
            ),
          );
        },
      ),
    );
  }
}
