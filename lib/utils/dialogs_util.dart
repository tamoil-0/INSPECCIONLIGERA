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
    return Container(
      margin: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF9FAFB),
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.08),
            blurRadius: 8,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: ExpansionTile(
        tilePadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        collapsedBackgroundColor: const Color(0xFFECEFF1),
        backgroundColor: const Color(0xFFF9FAFB),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        collapsedShape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text(
          "🔧 Información Técnica",
          style: TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.bold,
            color: Color(0xFF0D47A1), // azul profundo
          ),
        ),
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _buildLinea("🪵 ID del Poste", "$posteId"),
                _buildLinea("🌿 Obstáculos", "${obstaculos.length} seleccionados"),
                _buildLinea("🌊 Estado de Cuencas", estadoCuencas ?? 'N/A'),
                _buildLinea("📌 RST Marcados", "$totalRSTSeleccionados"),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildLinea(String titulo, String valor) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            "$titulo: ",
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: Color(0xFF3A3A3A),
            ),
          ),
          Expanded(
            child: Text(
              valor,
              style: const TextStyle(
                fontSize: 14,
                color: Color(0xFF3A3A3A),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
