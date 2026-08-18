import 'package:flutter/material.dart';
import 'formulario_dropdowns.dart';

Widget buildSeccionEstadoPlacas(
    BuildContext context,
    String? estadoPlacasTorre,
    ValueChanged<String?> onPlacasTorreChanged,
    String? estadoPlacasLinea,
    ValueChanged<String?> onPlacasLineaChanged,
    String? estadoPlacasFases,
    ValueChanged<String?> onPlacasFasesChanged,
    String? peligroCerco,
    ValueChanged<String?> onPeligroCercoChanged,
    String? peligroTorre,
    ValueChanged<String?> onPeligroTorreChanged,
    String? puestaTierra,
    ValueChanged<String?> onPuestaTierraChanged,
    ) {
  const opciones = ['bueno', 'malo', 'no_existe'];

  return Padding(
    padding: const EdgeInsets.only(bottom: 16),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          "11. Estado de Placas",
          style: TextStyle(
            fontWeight: FontWeight.bold,
            fontSize: 16,
            color: Color(0xFFFFF176), // Gris carbón suave
          ),
        ),

        const SizedBox(height: 8),
        buildDropdown(
          label: 'Placas Torre',
          value: estadoPlacasTorre,
          options: opciones,
          onChanged: onPlacasTorreChanged,
        ),
        buildDropdown(
          label: 'Placas Línea',
          value: estadoPlacasLinea,
          options: opciones,
          onChanged: onPlacasLineaChanged,
        ),
        buildDropdown(
          label: 'Placas Fases',
          value: estadoPlacasFases,
          options: opciones,
          onChanged: onPlacasFasesChanged,
        ),
        buildDropdown(
          label: 'Peligro Cerco',
          value: peligroCerco,
          options: opciones,
          onChanged: onPeligroCercoChanged,
        ),
        buildDropdown(
          label: 'Peligro Torre',
          value: peligroTorre,
          options: opciones,
          onChanged: onPeligroTorreChanged,
        ),
        buildDropdown(
          label: 'Puesta a Tierra',
          value: puestaTierra,
          options: opciones,
          onChanged: onPuestaTierraChanged,
        ),
      ],
    ),
  );
}
