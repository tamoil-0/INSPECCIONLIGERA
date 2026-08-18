import 'package:flutter/material.dart';

Widget buildDropdown({
  required String label,
  required String? value,
  required List<String> options,
  required Function(String?) onChanged,
  TextStyle? labelStyle,
  bool isRequired = false,
}) {
  return Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: FormField<String>(
      validator: isRequired
          ? (value) => value == null || value.isEmpty ? 'Este campo es obligatorio' : null
          : null,
      builder: (FormFieldState<String> state) {
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            InputDecorator(
              decoration: InputDecoration(
                labelText: label,
                labelStyle: labelStyle ?? const TextStyle(
                  color: Color(0xFF7986CB),
                  fontWeight: FontWeight.bold,
                ),
                floatingLabelStyle: const TextStyle(
                  backgroundColor: Color(0xFF0D47A1),
                  color: Colors.white,
                  fontWeight: FontWeight.bold,
                ),
                filled: true,
                fillColor: Colors.white,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(16),
                  borderSide: const BorderSide(color: Color(0xFFB0BEC5)),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(16),
                  borderSide: const BorderSide(color: Color(0xFFB0BEC5)),
                ),
                focusedBorder: const OutlineInputBorder(
                  borderSide: BorderSide(color: Color(0xFF0D47A1), width: 2),
                ),
                errorBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(16),
                  borderSide: const BorderSide(color: Colors.red, width: 2),
                ),
                focusedErrorBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(16),
                  borderSide: const BorderSide(color: Colors.redAccent, width: 2),
                ),
                // 👇 Ocultamos el errorStyle porque lo personalizamos abajo
                errorStyle: const TextStyle(height: 0),
              ),
              isEmpty: value == null || value.isEmpty,
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  isExpanded: true,
                  value: value,
                  items: options.map((op) {
                    final isSelected = op == value;
                    return DropdownMenuItem(
                      value: op,
                      child: Container(
                        decoration: isSelected
                            ? BoxDecoration(
                          color: const Color(0xFFE3F2FD), // fondo celeste claro para opción seleccionada
                          borderRadius: BorderRadius.circular(8),
                        )
                            : null,
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                        child: Text(
                          op,
                          style: TextStyle(
                            fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                            color: isSelected ? Colors.black : Colors.black87,
                          ),
                        ),
                      ),
                    );
                  }).toList(),

                  onChanged: (val) {
                    state.didChange(val);
                    onChanged(val);
                  },
                  icon: const Icon(Icons.arrow_drop_down, color: Color(0xFF0D47A1)),
                  style: const TextStyle(
                    color: Color(0xFF3A3A3A),
                    fontSize: 16,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
            ),
            if (state.hasError)
              Container(
                margin: const EdgeInsets.only(top: 4, left: 12),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                decoration: BoxDecoration(
                  color: const Color(0xFF0D47A1), // Fondo azul profundo
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  state.errorText!,
                  style: const TextStyle(
                    color: Colors.red,
                    fontWeight: FontWeight.bold,
                    fontSize: 13,
                  ),
                ),
              ),
          ],
        );
      },
    ),
  );

}