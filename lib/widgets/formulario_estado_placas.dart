import 'package:flutter/material.dart';

import '../models/formulario_modal.dart';
import '../presentacion/diseno/tema_ecoing.dart';
import 'formulario_dropdowns.dart';

/// Ítem 11: estado de placas y elementos de seguridad.
///
/// Seis sub-campos que antes se pasaban como doce parámetros posicionales, lo
/// que hacía trivial cruzar dos por error. Ahora van con nombre.
Widget buildSeccionEstadoPlacas({
  required String? estadoPlacasTorre,
  required ValueChanged<String?> onPlacasTorre,
  required String? estadoPlacasLinea,
  required ValueChanged<String?> onPlacasLinea,
  required String? estadoPlacasFases,
  required ValueChanged<String?> onPlacasFases,
  required String? peligroCerco,
  required ValueChanged<String?> onPeligroCerco,
  required String? peligroTorre,
  required ValueChanged<String?> onPeligroTorre,
  required String? puestaTierra,
  required ValueChanged<String?> onPuestaTierra,
}) {
  const opciones = FormularioModal.estadoPlacasOptions;

  return Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Container(
        margin: const EdgeInsets.only(bottom: Espacio.m),
        padding: const EdgeInsets.all(Espacio.m),
        decoration: BoxDecoration(
          color: ColoresEcoing.azulClaro,
          borderRadius: BorderRadius.circular(Espacio.radio),
        ),
        child: const Text(
          '11. Estado de placas y seguridad',
          style: TextStyle(
            fontWeight: FontWeight.bold,
            fontSize: 16,
            color: ColoresEcoing.azul,
          ),
        ),
      ),
      buildDropdown(
        label: 'Placas de torre',
        value: estadoPlacasTorre,
        options: opciones,
        onChanged: onPlacasTorre,
      ),
      buildDropdown(
        label: 'Placas de línea',
        value: estadoPlacasLinea,
        options: opciones,
        onChanged: onPlacasLinea,
      ),
      buildDropdown(
        label: 'Placas de fases',
        value: estadoPlacasFases,
        options: opciones,
        onChanged: onPlacasFases,
      ),
      buildDropdown(
        label: 'Señal de peligro en cerco',
        value: peligroCerco,
        options: opciones,
        onChanged: onPeligroCerco,
      ),
      buildDropdown(
        label: 'Señal de peligro en torre',
        value: peligroTorre,
        options: opciones,
        onChanged: onPeligroTorre,
      ),
      buildDropdown(
        label: 'Puesta a tierra',
        value: puestaTierra,
        options: opciones,
        onChanged: onPuestaTierra,
      ),
    ],
  );
}
