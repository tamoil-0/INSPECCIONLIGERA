import 'package:intl/intl.dart';

/// Fecha y hora en el formato que usa el equipo en Perú.
///
/// Ejemplo: `18 de agosto de 2026 a horas 3:42:10 pm`.
///
/// ANTES: esta función imprimía **ocho líneas** por cada llamada, y se llama una
/// vez por estructura en cada reconstrucción de la lista. Con 180 estructuras
/// eso son 1440 líneas de log por repintado, también en release.
String formatearFechaHoraBonita(dynamic fechaIso) {
  if (fechaIso == null) return 'No registrada';

  final texto = fechaIso.toString().trim();
  if (texto.isEmpty || texto == 'null' || texto == 'N/A') {
    return 'No registrada';
  }

  final fecha = DateTime.tryParse(texto);
  if (fecha == null) return 'Fecha inválida';

  final formatoFecha = DateFormat("d 'de' MMMM 'de' y", 'es_PE');
  final formatoHora = DateFormat('h:mm:ss a', 'es_PE');

  final hora = formatoHora.format(fecha).toLowerCase().replaceAll('.', '');
  return '${formatoFecha.format(fecha)} a horas $hora';
}

/// Solo el día, para listas compactas: `18/08/2026`.
String formatearFechaCorta(dynamic fechaIso) {
  if (fechaIso == null) return '—';
  final fecha = DateTime.tryParse(fechaIso.toString().trim());
  if (fecha == null) return '—';
  return DateFormat('dd/MM/yyyy').format(fecha);
}

/// Tamaño legible: `2,1 MB`.
String formatearTamano(int? bytes) {
  if (bytes == null || bytes <= 0) return '—';
  if (bytes >= 1024 * 1024 * 1024) {
    return '${(bytes / (1024 * 1024 * 1024)).toStringAsFixed(2)} GB';
  }
  if (bytes >= 1024 * 1024) {
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
  return '${(bytes / 1024).toStringAsFixed(0)} KB';
}
