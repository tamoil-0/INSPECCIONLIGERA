import 'package:flutter/material.dart';

const _fases = ['R', 'S', 'T'];

Widget buildSeccionRST({
  required String titulo,
  required String seccion,
  required List<String> atributos,
  required Map<String, bool> seleccionados,
  required Function(String clave, bool valor) onChanged,
}) {
  return Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      const SizedBox(height: 16),
      Text(
        titulo,
        style: const TextStyle(
          fontWeight: FontWeight.bold,
          fontSize: 16,
          color: Color(0xFFFFF176), // Azul profundo
        ),
      ),
      const SizedBox(height: 8),
      Table(
        columnWidths: const {
          0: FlexColumnWidth(2),
          1: FlexColumnWidth(1),
          2: FlexColumnWidth(1),
          3: FlexColumnWidth(1),
        },
        border: TableBorder.all(color: const Color(0xFFB0BEC5)), // Gris azulado
        children: [
          const TableRow(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [Color(0xFF0D47A1), Color(0xFFB71C1C)],
              ),
            ),
            children: [
              Padding(
                padding: EdgeInsets.all(8),
                child: Text(
                  "Atributo",
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
              Center(
                child: Text(
                  "R",
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
              Center(
                child: Text(
                  "S",
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
              Center(
                child: Text(
                  "T",
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
          ...atributos.map((atributo) {
            return TableRow(
              decoration: const BoxDecoration(color: Colors.white),
              children: [
                Padding(
                  padding: const EdgeInsets.all(8),
                  child: Text(
                    atributo.replaceAll('_', ' '),
                    style: const TextStyle(
                      color: Color(0xFF3A3A3A),
                      fontSize: 14,
                    ),
                  ),
                ),
                ..._fases.map((fase) {
                  final clave = '$seccion|$atributo|$fase';
                  final valor = seleccionados[clave] ?? false;
                  return Center(
                    child: Container(
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: valor
                            ? const Color(0xFFB71C1C).withValues(alpha: 0.15)
                            : Colors.transparent,
                      ),
                      child: Checkbox(
                        value: valor,
                        activeColor: const Color(0xFFB71C1C), // Rojo oscuro
                        onChanged: (nuevoValor) {
                          onChanged(clave, nuevoValor ?? false);
                        },
                      ),
                    ),
                  );
                }),
              ],
            );
          }),
        ],
      ),
    ],
  );
}

Widget buildTableroRST({
  required Map<String, bool> seleccionados,
  required Function(String clave, bool valor) onChanged,
}) {
  const atributosPorSeccion = {
    "conductores_fase": [
      "hebras_rotas",
      "encanastillado",
      "empalme_deformado",
      "objetos_extranos",
    ],
    "conductores_cuellos": [
      "hebras_rotas",
      "encanastillado",
      "empalme_deformado",
      "objetos_extranos",
    ],
    "conductores_guarda": [
      "hebras_rotas",
      "encanastillado",
      "empalme_deformado",
      "objetos_extranos",
    ],
    "estado_aisladores": [
      "buen_estado",
      "rotos_suspension",
      "rotos_anclaje_adelante",
      "rotos_anclaje_atras",
      "mal_estado",
    ],
  };

  return Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      const SizedBox(height: 24),
      const Text(
        "Tablero RST",
        style: TextStyle(
          fontWeight: FontWeight.bold,
          fontSize: 18,
          color: Color(0xFFFFF176),
        ),
      ),
      ...atributosPorSeccion.entries.map((entry) {
        return buildSeccionRST(
          titulo: _tituloSeccion(entry.key),
          seccion: entry.key,
          atributos: entry.value,
          seleccionados: seleccionados,
          onChanged: onChanged,
        );
      }),
    ],
  );
}

String _tituloSeccion(String clave) {
  switch (clave) {
    case "conductores_fase":
      return "Conductores de Fase";
    case "conductores_cuellos":
      return "Conductores Cuellos";
    case "conductores_guarda":
      return "Conductores de Guarda";
    case "estado_aisladores":
      return "Estado de Aisladores";
    default:
      return clave;
  }
}
