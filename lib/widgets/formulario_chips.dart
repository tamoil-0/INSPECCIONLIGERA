import 'package:flutter/material.dart';

/// Construye una sección de selección múltiple usando FilterChips.
/// Ideal para listas como obstáculos en faja o elementos tipo checklist.
Widget buildDropdownMultiple({
  required String label,
  required List<String> options,
  required List<String> seleccionados,
  TextStyle? labelStyle,
  required Function(List<String>) onChanged,
}) {
  return Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(
        label,
        style: labelStyle ??
            const TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.bold,
              color: Color(0xFFFFD600), // Amarillo dorado institucional
            ),
      ),
      const SizedBox(height: 10),
      Wrap(
        spacing: 10.0,
        runSpacing: 6.0,
        children: options.map((opcion) {
          final seleccionado = seleccionados.contains(opcion);
          return FilterChip(
            label: Text(
              opcion,
              style: TextStyle(
                color: seleccionado ? Colors.white : const Color(0xFF3A3A3A),
                fontWeight: FontWeight.w500,
              ),
            ),
            selected: seleccionado,
            selectedColor: const Color(0xFF0D47A1), // Azul profundo al seleccionar
            backgroundColor: const Color(0xFFF5F5F5), // Gris claro de fondo
            checkmarkColor: Colors.white,
            onSelected: (bool selected) {
              final nuevos = List<String>.from(seleccionados);
              selected ? nuevos.add(opcion) : nuevos.remove(opcion);
              onChanged(nuevos);
            },
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(20),
              side: BorderSide(
                color: seleccionado
                    ? const Color(0xFF0D47A1)
                    : const Color(0xFFB0BEC5), // Borde gris si no está seleccionado
              ),
            ),
          );
        }).toList(),
      ),
      const SizedBox(height: 16),
    ],
  );
}
