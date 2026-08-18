import 'package:intl/intl.dart';

String formatearFechaHoraBonita(dynamic fechaIso) {
  print('🔍 [formatearFechaHoraBonita] Valor recibido: $fechaIso');

  try {
    if (fechaIso == null) {
      print('⚠️ La fecha es null');
      return 'No registrada';
    }

    final fechaStr = fechaIso.toString().trim();

    if (fechaStr.isEmpty) {
      print('⚠️ La fecha está vacía después de convertir a string');
      return 'No registrada';
    }

    print('🛠 Parseando fecha...');
    final fecha = DateTime.parse(fechaStr);
    print('✅ Fecha parseada correctamente: $fecha');

    final formatoFecha = DateFormat("d 'de' MMMM 'de' y", 'es_PE');
    final formatoHora = DateFormat('h:mm:ss a', 'es_PE');

    final fechaFormateada = formatoFecha.format(fecha);
    final horaFormateada = formatoHora.format(fecha).toLowerCase().replaceAll('.', '');

    print('📅 Fecha formateada: $fechaFormateada');
    print('⏰ Hora formateada: $horaFormateada');

    return '$fechaFormateada a horas $horaFormateada';
  } catch (e) {
    print('❌ Error al parsear o formatear la fecha: $e');
    return 'Fecha inválida';
  }
}
