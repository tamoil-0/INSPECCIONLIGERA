import 'package:flutter/material.dart';

import '../models/formulario_modal.dart';
import '../presentacion/diseno/tema_ecoing.dart';

/// Desplegable de un ítem del formulario.
///
/// ## Cambios respecto a la versión anterior
///
/// * **Marca visualmente lo que está sin revisar.** Un ítem en `no_revisado`
///   sale con borde y etiqueta naranja: el inspector ve de un golpe qué le
///   falta, en lugar de encontrarse 19 campos ya rellenos con `bueno`.
/// * **Etiquetas legibles.** Antes mostraba las claves crudas del catálogo
///   (`conductor_en_mal_estado`); ahora `Conductor en mal estado`.
/// * **El mensaje de error iba en rojo sobre fondo azul oscuro**, prácticamente
///   ilegible. Ahora usa el estilo de error del tema.
/// * Objetivo táctil de 48 px y texto de 16 sp.
Widget buildDropdown({
  required String label,
  required String? value,
  required List<String> options,
  required ValueChanged<String?> onChanged,
  bool isRequired = false,
  String? ayuda,
}) {
  final sinRevisar = value == null || value == FormularioModal.noRevisado;
  final colorEstado = sinRevisar
      ? ColoresEcoing.pendiente
      : ColoresEcoing.exito;

  return Padding(
    padding: const EdgeInsets.only(bottom: Espacio.l),
    child: FormField<String>(
      initialValue: value,
      validator: isRequired
          ? (v) {
              if (v == null || v.isEmpty || v == FormularioModal.noRevisado) {
                return 'Este campo es obligatorio';
              }
              return null;
            }
          : null,
      builder: (estado) {
        return Column(
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
                if (isRequired)
                  const Text(
                    ' *',
                    style: TextStyle(
                      color: ColoresEcoing.error,
                      fontWeight: FontWeight.bold,
                      fontSize: 16,
                    ),
                  ),
                Icon(
                  sinRevisar
                      ? Icons.radio_button_unchecked
                      : Icons.check_circle,
                  size: 17,
                  color: colorEstado,
                ),
              ],
            ),
            if (ayuda != null) ...[
              const SizedBox(height: 2),
              Text(
                ayuda,
                style: const TextStyle(
                  fontSize: 12.5,
                  color: ColoresEcoing.textoTenue,
                ),
              ),
            ],
            const SizedBox(height: Espacio.s),
            Container(
              decoration: BoxDecoration(
                color: ColoresEcoing.superficie,
                borderRadius: BorderRadius.circular(Espacio.radio),
                border: Border.all(
                  color: estado.hasError
                      ? ColoresEcoing.error
                      : (sinRevisar
                            ? ColoresEcoing.pendiente
                            : ColoresEcoing.borde),
                  width: estado.hasError || sinRevisar ? 1.6 : 1,
                ),
              ),
              padding: const EdgeInsets.symmetric(horizontal: Espacio.m),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  isExpanded: true,
                  value: value,
                  hint: const Text(
                    'Elegir…',
                    style: TextStyle(color: ColoresEcoing.textoTenue),
                  ),
                  itemHeight: Espacio.objetivoTactil,
                  icon: const Icon(
                    Icons.arrow_drop_down,
                    color: ColoresEcoing.azul,
                  ),
                  style: const TextStyle(
                    fontSize: 16,
                    color: ColoresEcoing.texto,
                    fontWeight: FontWeight.w500,
                  ),
                  items: options.map((op) {
                    final esNoRevisado = op == FormularioModal.noRevisado;
                    return DropdownMenuItem(
                      value: op,
                      child: Text(
                        FormularioModal.etiqueta(op),
                        style: TextStyle(
                          color: esNoRevisado
                              ? ColoresEcoing.pendiente
                              : ColoresEcoing.texto,
                          fontStyle: esNoRevisado
                              ? FontStyle.italic
                              : FontStyle.normal,
                          fontWeight: op == value
                              ? FontWeight.bold
                              : FontWeight.normal,
                        ),
                      ),
                    );
                  }).toList(),
                  onChanged: (nuevo) {
                    estado.didChange(nuevo);
                    onChanged(nuevo);
                  },
                ),
              ),
            ),
            if (estado.hasError) ...[
              const SizedBox(height: Espacio.xs),
              Row(
                children: [
                  const Icon(
                    Icons.error_outline,
                    size: 15,
                    color: ColoresEcoing.error,
                  ),
                  const SizedBox(width: Espacio.xs),
                  Text(
                    estado.errorText!,
                    style: const TextStyle(
                      color: ColoresEcoing.error,
                      fontWeight: FontWeight.w600,
                      fontSize: 13,
                    ),
                  ),
                ],
              ),
            ],
          ],
        );
      },
    ),
  );
}
