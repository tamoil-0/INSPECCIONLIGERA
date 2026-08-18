import 'package:flutter/material.dart';

class DebugInfoWidget extends StatelessWidget {
  final int posteId;
  final List<String> obstaculos;
  final String? estadoCuencas;
  final int totalRSTSeleccionados;

  const DebugInfoWidget({
    Key? key,
    required this.posteId,
    required this.obstaculos,
    required this.estadoCuencas,
    required this.totalRSTSeleccionados,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return ExpansionTile(
      title: const Text(
        "Debug Info",
        style: TextStyle(
          fontSize: 13,
          fontWeight: FontWeight.bold,
          color: Color(0xFF3A3A3A), // Gris carbón
        ),
      ),
      collapsedBackgroundColor: Color(0xFFE0E0E0),
      children: [
        Container(
          width: double.infinity,
          color: Color(0xFFF9FAFB), // Gris hielo
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _buildItem("Poste ID", "$posteId"),
              _buildItem("Obstáculos", "${obstaculos.length} seleccionados"),
              _buildItem("Estado cuencas", estadoCuencas ?? 'N/A'),
              _buildItem("RST marcados", "$totalRSTSeleccionados"),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildItem(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Text(
        "$label: $value",
        style: const TextStyle(
          fontSize: 13,
          color: Color(0xFF3A3A3A), // Gris carbón
        ),
      ),
    );
  }
}
