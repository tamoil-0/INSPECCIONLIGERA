import 'package:flutter/material.dart';

import '../models/formulario_modal.dart';
import '../presentacion/diseno/tema_ecoing.dart';

/// Selección múltiple con chips (ítem 1: obstáculos en faja).
///
/// Se marca como sin revisar mientras el inspector no haya elegido nada ni
/// confirmado que no aplica, en lugar de dar por bueno el silencio.
Widget buildDropdownMultiple({
  required String label,
  required List<String> options,
  required List<String> seleccionados,
  required ValueChanged<List<String>> onChanged,
  bool revisado = false,
  VoidCallback? alConfirmarVacio,
}) {
  final sinRevisar = seleccionados.isEmpty && !revisado;
  return Padding(
    padding: const EdgeInsets.only(bottom: Espacio.l),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                label,
                style: const TextStyle(
                  fontSize: 15.5,
                  fontWeight: FontWeight.w600,
                  color: ColoresEcoing.texto,
                ),
              ),
            ),
            Icon(
              sinRevisar ? Icons.radio_button_unchecked : Icons.check_circle,
              size: 17,
              color: sinRevisar ? ColoresEcoing.pendiente : ColoresEcoing.exito,
            ),
          ],
        ),
        const SizedBox(height: 2),
        Text(
          sinRevisar
              ? 'Marca lo que corresponda, o confirma que no hay obstáculos.'
              : '${seleccionados.length} seleccionado(s)',
          style: TextStyle(
            fontSize: 12.5,
            color: sinRevisar ? ColoresEcoing.pendiente : ColoresEcoing.textoTenue,
          ),
        ),
        const SizedBox(height: Espacio.s),
        Wrap(
          spacing: Espacio.s,
          runSpacing: Espacio.s,
          children: options.map((opcion) {
            final elegido = seleccionados.contains(opcion);
            return FilterChip(
              label: Text(FormularioModal.etiqueta(opcion)),
              selected: elegido,
              onSelected: (activar) {
                final nuevos = List<String>.from(seleccionados);
                if (activar) {
                  nuevos.add(opcion);
                } else {
                  nuevos.remove(opcion);
                }
                onChanged(nuevos);
              },
              selectedColor: ColoresEcoing.azulClaro,
              checkmarkColor: ColoresEcoing.azul,
              labelStyle: TextStyle(
                fontSize: 14,
                fontWeight: elegido ? FontWeight.w600 : FontWeight.normal,
                color: elegido ? ColoresEcoing.azul : ColoresEcoing.texto,
              ),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(Espacio.radio),
                side: BorderSide(
                  color: elegido ? ColoresEcoing.azul : ColoresEcoing.borde,
                ),
              ),
            );
          }).toList(),
        ),
        if (sinRevisar && alConfirmarVacio != null) ...[
          const SizedBox(height: Espacio.s),
          OutlinedButton.icon(
            onPressed: alConfirmarVacio,
            icon: const Icon(Icons.check, size: 18),
            label: const Text('No hay obstáculos'),
          ),
        ],
      ],
    ),
  );
}
