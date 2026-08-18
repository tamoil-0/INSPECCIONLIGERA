/// Estados explícitos del ciclo de vida de un dato de inspección.
///
/// Regla de oro del aplicativo: un registro **solo** puede pasar a
/// [sincronizado] cuando el servidor confirma que lo recibió. Nunca por
/// haber "intentado" enviarlo, ni por el hecho de tener internet.
///
/// Transiciones válidas:
///
///   local ──► pendiente ──► subiendo ──► sincronizado
///                 ▲             │
///                 └──── fallido ┘
///                               └──► conflicto  (el servidor ya lo tenía)
///
/// `fallido` siempre vuelve a `pendiente` al reintentar: nunca se descarta.
class EstadoSync {
  const EstadoSync._();

  /// Guardado en el teléfono, todavía sin marcar para envío
  /// (por ejemplo un borrador que el inspector sigue editando).
  static const String local = 'local';

  /// Guardado en el teléfono y en cola para enviarse.
  static const String pendiente = 'pending';

  /// Envío en curso. Si la app muere en este estado, el arranque lo
  /// devuelve a [pendiente] (ver `FotosRepositorio.recuperarSubidasInterrumpidas`).
  static const String subiendo = 'uploading';

  /// El servidor confirmó la recepción. Único estado que permite decir
  /// "Sincronizado" en la interfaz.
  static const String sincronizado = 'synced';

  /// El envío falló. El dato sigue íntegro en el teléfono y se reintentará.
  static const String fallido = 'failed';

  /// El servidor ya tenía el registro con datos distintos: requiere decisión.
  static const String conflicto = 'conflict';

  /// Estados que todavía tienen trabajo por enviar.
  static const List<String> noSincronizados = [
    local,
    pendiente,
    subiendo,
    fallido,
    conflicto,
  ];

  /// Estados elegibles para un intento de subida.
  static const List<String> enviables = [local, pendiente, fallido];

  /// Etiqueta honesta para mostrar al inspector. Nunca dice "enviado"
  /// cuando el dato solo está en el teléfono.
  static String etiqueta(String? estado) {
    switch (estado) {
      case sincronizado:
        return 'Sincronizado';
      case subiendo:
        return 'Subiendo';
      case fallido:
        return 'Error al enviar';
      case conflicto:
        return 'Requiere revisión';
      case pendiente:
        return 'Pendiente de enviar';
      case local:
      default:
        return 'Guardado en el teléfono';
    }
  }
}
