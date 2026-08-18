/// Contrato único de fotografías compartido por captura y sincronización.
///
/// Debe mantenerse idéntico a `photoTypes()` del backend PHP. Centralizarlo
/// evita que una pantalla considere completa una inspección que el servidor
/// todavía reporta como incompleta.
abstract final class ContratoFotos {
  static const int cantidadRequerida = 28;

  static const List<String> tiposRequeridos = [
    'foto_panoramica',
    'placa',
    'torre_parte_inferior',
    'torre_parte_superior',
    'base_torre',
    'mensulas',
    'crucetas',
    'perfiles_angulares',
    'atiescalamiento',
    'otros',
    'aisladores_fase_r_atras',
    'aisladores_fase_s_atras',
    'aisladores_fase_t_atras',
    'aisladores_fase_r_adelante',
    'aisladores_fase_s_adelante',
    'aisladores_fase_t_adelante',
    'ferreteria_fase_r',
    'ferreteria_fase_s',
    'ferreteria_fase_t',
    'cable_guarda',
    'ferreteria_de_cable_de_guarda',
    'conductor',
    'ferreteria_de_conductor',
    'puesta_tierra',
    'puesta_tierra_2',
    'retenida',
    'faja_servidumbre',
    'ubicacion_acceso',
  ];

  /// Lotes pequeños toleran mejor cortes de red y quedan por debajo de los
  /// límites habituales de PHP aun con fotografías cercanas a 10 MB.
  static const int fotosPorLote = 6;
}
