import '../core/entorno.dart';

/// Modelo del formulario técnico de inspección.
///
/// ## El cambio de fondo: «No revisado»
///
/// La versión anterior precargaba **19 de los 22 ítems** con valores como
/// `bueno`, `buen_estado`, `n_a` o `no`, y solo 3 eran obligatorios. Eso
/// significaba que un inspector podía enviar una inspección completa en dos
/// toques y el servidor recibía 19 respuestas que **nadie había mirado**,
/// indistinguibles de una inspección real hecha con cuidado.
///
/// Ahora todos los ítems arrancan en [noRevisado] y el modelo lleva la cuenta
/// de qué campos confirmó el inspector de verdad ([revisados]).
///
/// ## Compatibilidad con el backend
///
/// Enviar `no_revisado` es un cambio de contrato en 19 campos. Como el backend
/// se está actualizando, el comportamiento del envío se controla con
/// [Entorno.enviarNoRevisado]:
///
/// * **`false` (por defecto)** — compatible con el backend actual: los campos
///   sin revisar viajan con el valor por defecto de siempre. La interfaz sigue
///   mostrando «No revisado» al inspector y `campos_revisados` se envía igual,
///   así que el servidor ya puede distinguir lo revisado de lo que no.
/// * **`true`** — envía literalmente `no_revisado`. Activar cuando el backend
///   acepte el valor:
///   `flutter build apk --release --dart-define=ENVIAR_NO_REVISADO=true`
///
/// Ver `BACKEND_CONTRATO.md`.
class FormularioModal {
  /// Valor de un ítem que el inspector todavía no ha confirmado.
  static const String noRevisado = 'no_revisado';

  /// Distancia de acceso en metros. Es obligatoria en el backend.
  double? distanciaAcceso;

  /// Cantidad de puestas a tierra observadas. Opcional, nunca negativa.
  int? cantidadPat;

  /// Distancias DMS en metros. Son opcionales y, cuando se informan, no
  /// pueden ser negativas.
  double? distanciaPosteAnterior;
  double? distanciaVertical;
  double? distanciaHorizontal;

  // === Ítem 1: selección múltiple ===
  List<String> obstaculosFaja = [];

  // === Ítems 2 a 23 ===
  String? estadoCuencas = noRevisado;
  String? marcadoArboles = noRevisado;
  String? criticidadTala = noRevisado;
  String? criticidadContacto = noRevisado;
  String? notificacionPropietario = noRevisado;
  String? tipoTorre;
  String? ubicacion;
  String? accesoTorre;
  String? estadoAcceso = noRevisado;
  String? estadoPlacasTorre = noRevisado;
  String? estadoPlacasLinea = noRevisado;
  String? estadoPlacasFases = noRevisado;
  String? peligroCerco = noRevisado;
  String? peligroTorre = noRevisado;
  String? puestaTierra = noRevisado;
  String? retenida = noRevisado;
  String? estadoBase = noRevisado;
  String? limpiarBase = noRevisado;
  String? crucetasMensuales = noRevisado;
  String? perfilesAngulares = noRevisado;
  String? mallaAntiescalamiento = noRevisado;
  String? oxidosBase = noRevisado;
  String? cadenaAisladores = noRevisado;
  String? tipoAislador = noRevisado;
  String? conductorBajadaPat = noRevisado;
  String? conductorGuarda = noRevisado;
  String? comentarios;

  DateTime? fechaInspeccion;

  /// Tablero RST: claves `seccion|atributo|fase`.
  Map<String, bool> seleccionados = {};

  /// Claves de los campos que el inspector confirmó explícitamente.
  final Set<String> revisados = <String>{};

  /// Marca un campo como revisado. Se llama desde la interfaz cuando el
  /// inspector cambia o confirma un valor.
  void marcarRevisado(String clave) => revisados.add(clave);

  bool estaRevisado(String clave) => revisados.contains(clave);

  /// Valores por defecto de la versión anterior, para poder seguir enviando lo
  /// que el backend actual espera mientras se actualiza.
  static const Map<String, String> valoresPorDefectoLegado = {
    'estado_cuencas': 'n_a',
    'marcado_arboles': 'no',
    'criticidad_tala': 'n_a',
    'criticidad_contacto': 'n_a',
    'notificacion_propietario': 'otro',
    'estado_acceso': 'mal_estado',
    'estado_placas_torre': 'bueno',
    'estado_placas_linea': 'bueno',
    'estado_placas_fases': 'bueno',
    'peligro_cerco': 'no_existe',
    'peligro_torre': 'bueno',
    'puesta_tierra': 'bueno',
    'retenida': 'n_a',
    'estado_base': 'buen_estado',
    'limpiar_base': 'no',
    'crucetas_mensuales': 'buen_estado',
    'perfiles_angulares': 'buen_estado',
    'malla_antiescalamiento': 'buen_estado',
    'oxidos_base': 'no',
    'cadena_aisladores': 'en_suspension',
    'tipo_aislador': 'porcelana',
    'conductor_bajada_pat': 'n_a',
    'conductor_guarda': 'n_a',
  };

  // === Catálogos de opciones ===
  //
  // `noRevisado` va primero en cada lista, de modo que es lo que el inspector
  // ve mientras no elija.
  static const List<String> obstaculosFajaOptions = [
    'invasiones_nuevas',
    'construcciones_nuevas',
    'proceso_construccion',
    'cercos_vallas',
    'arboles',
    'arbustos',
    'arboles_fuera_faja',
    'otros',
    'n_a',
  ];

  static const List<String> estadoCuencasOptions = [
    noRevisado,
    'seguimiento',
    'critico',
    'n_a',
  ];
  static const List<String> marcadoArbolesOptions = [noRevisado, 'si', 'no'];
  static const List<String> criticidadTalaOptions = [
    noRevisado,
    'bajo',
    'seguimiento',
    'critico',
    'n_a',
  ];
  static const List<String> criticidadContactoOptions = [
    noRevisado,
    'bajo',
    'seguimiento',
    'critico',
    'n_a',
  ];
  static const List<String> notificacionPropietarioOptions = [
    noRevisado,
    'persona_natural',
    'persona_juridica',
    'otro',
  ];
  static const List<String> tipoTorreOptions = [
    'alineamiento',
    'angulo',
    'fin_linea',
  ];
  static const List<String> ubicacionOptions = [
    'rural_con_vegetacion',
    'urbana',
    'industrial',
    'rural_sin_vegetacion',
    'zona_sujeta_huaycos',
    'desertico',
  ];
  static const List<String> accesoTorreOptions = ['a_pie', 'en_vehiculo'];
  static const List<String> estadoAccesoOptions = [
    noRevisado,
    'bueno',
    'mal_estado',
  ];
  static const List<String> estadoPlacasOptions = [
    noRevisado,
    'bueno',
    'malo',
    'no_existe',
  ];
  static const List<String> retenidaOptions = [
    noRevisado,
    'buen_estado',
    'cambiar_preforme',
    'retemplar',
    'n_a',
  ];
  static const List<String> estadoBaseOptions = [
    noRevisado,
    'buen_estado',
    'mal_estado',
  ];
  static const List<String> limpiarBaseOptions = [noRevisado, 'si', 'no'];
  static const List<String> crucetasMensualesOptions = [
    noRevisado,
    'buen_estado',
    'mal_estado',
    'falta_ajustar',
    'n_a',
  ];
  static const List<String> perfilesAngularesOptions = [
    noRevisado,
    'buen_estado',
    'mal_estado',
    'falta',
    'n_a',
  ];
  static const List<String> mallaAntiescalamientoOptions = [
    noRevisado,
    'buen_estado',
    'mal_estado',
    'falta',
    'n_a',
  ];
  static const List<String> oxidosBaseOptions = [noRevisado, 'si', 'no', 'n_a'];
  static const List<String> cadenaAisladoresOptions = [
    noRevisado,
    'en_suspension',
    'en_anclaje',
    'en_cuello_muerto',
  ];
  static const List<String> tipoAisladorOptions = [
    noRevisado,
    'vidrio',
    'porcelana',
    'polimero',
  ];
  static const List<String> conductorBajadaPatOptions = [
    noRevisado,
    'buen_estado',
    'conductor_en_mal_estado',
    'grapas_en_mal_estado',
    'listones_en_mal_estado',
    'n_a',
  ];
  static const List<String> conductorGuardaOptions = [
    noRevisado,
    'hebras_rotas',
    'encanastillado',
    'empalme_deformado',
    'objetos_extranos',
    'n_a',
  ];

  /// Todos los campos de un solo valor, en orden, con su clave de base de datos.
  ///
  /// Tener la lista en un solo sitio evita que la interfaz, el guardado y el
  /// recuento de revisados se desincronicen.
  Map<String, String?> get camposSimples => {
    'estado_cuencas': estadoCuencas,
    'marcado_arboles': marcadoArboles,
    'criticidad_tala': criticidadTala,
    'criticidad_contacto': criticidadContacto,
    'notificacion_propietario': notificacionPropietario,
    'tipo_torre': tipoTorre,
    'ubicacion': ubicacion,
    'acceso_torre': accesoTorre,
    'estado_acceso': estadoAcceso,
    'estado_placas_torre': estadoPlacasTorre,
    'estado_placas_linea': estadoPlacasLinea,
    'estado_placas_fases': estadoPlacasFases,
    'peligro_cerco': peligroCerco,
    'peligro_torre': peligroTorre,
    'puesta_tierra': puestaTierra,
    'retenida': retenida,
    'estado_base': estadoBase,
    'limpiar_base': limpiarBase,
    'crucetas_mensuales': crucetasMensuales,
    'perfiles_angulares': perfilesAngulares,
    'malla_antiescalamiento': mallaAntiescalamiento,
    'oxidos_base': oxidosBase,
    'cadena_aisladores': cadenaAisladores,
    'tipo_aislador': tipoAislador,
    'conductor_bajada_pat': conductorBajadaPat,
    'conductor_guarda': conductorGuarda,
  };

  /// Claves que siguen sin revisar.
  List<String> get sinRevisar {
    final pendientes = <String>[];
    if (distanciaAcceso == null) pendientes.add('distancia_acceso');
    camposSimples.forEach((clave, valor) {
      if (valor == null || valor == noRevisado) pendientes.add(clave);
    });
    if (obstaculosFaja.isEmpty && !revisados.contains('obstaculos_faja')) {
      pendientes.add('obstaculos_faja');
    }
    return pendientes;
  }

  int get totalCampos =>
      camposSimples.length + 2; // obstáculos + distancia de acceso
  int get camposRevisados => totalCampos - sinRevisar.length;
  bool get todoRevisado => sinRevisar.isEmpty;

  // === Serialización ======================================================

  /// Datos para guardar en SQLite y enviar al servidor.
  ///
  /// Los campos sin revisar salen como `no_revisado` o con su valor por defecto
  /// heredado, según [Entorno.enviarNoRevisado]. En ambos casos se incluye
  /// `campos_revisados`, para que el servidor pueda distinguir siempre lo que
  /// alguien miró de lo que no.
  Map<String, dynamic> toMap() {
    final mapa = <String, dynamic>{
      'distancia_acceso': distanciaAcceso,
      if (cantidadPat != null) 'cantidad_pat': cantidadPat,
      'distancia_poste_anterior': distanciaPosteAnterior,
      'distancia_vertical': distanciaVertical,
      'distancia_horizontal': distanciaHorizontal,
      'obstaculos_faja': obstaculosFaja,
      'comentarios': comentarios,
      'fecha_inspeccion': _fechaSql(fechaInspeccion ?? DateTime.now()),
      // Metadatos de calidad del dato.
      'campos_revisados': revisados.toList()..sort(),
      'campos_sin_revisar': sinRevisar,
      'total_campos': totalCampos,
    };

    camposSimples.forEach((clave, valor) {
      mapa[clave] = _valorParaEnviar(clave, valor);
    });

    return mapa;
  }

  static String _fechaSql(DateTime fecha) {
    String dos(int valor) => valor.toString().padLeft(2, '0');
    return '${fecha.year.toString().padLeft(4, '0')}-'
        '${dos(fecha.month)}-${dos(fecha.day)} '
        '${dos(fecha.hour)}:${dos(fecha.minute)}:${dos(fecha.second)}';
  }

  String? _valorParaEnviar(String clave, String? valor) {
    final sinConfirmar = valor == null || valor == noRevisado;
    if (!sinConfirmar) return valor;
    if (Entorno.enviarNoRevisado) return noRevisado;
    // Compatibilidad con el backend actual.
    return valoresPorDefectoLegado[clave];
  }

  /// Registros del tablero RST para el servidor.
  List<Map<String, String>> toRSTServidor() => _rst()
      .map(
        (r) => {
          'seccion': r['seccion']!,
          'atributo': r['atributo']!,
          'fase': r['fase']!,
        },
      )
      .toList();

  /// Registros del tablero RST para SQLite.
  List<Map<String, dynamic>> toRSTLocal() =>
      _rst().map((r) => Map<String, dynamic>.from(r)).toList();

  List<Map<String, String>> _rst() {
    final registros = <Map<String, String>>[];
    for (final entrada in seleccionados.entries) {
      if (!entrada.value) continue;
      final partes = entrada.key.split('|');
      if (partes.length != 3) continue;
      registros.add({
        'seccion': partes[0],
        'atributo': partes[1],
        'fase': partes[2],
      });
    }
    return registros;
  }

  /// Restaura el modelo desde un borrador guardado.
  ///
  /// Es el método que hace que «Editar» recupere el trabajo anterior en lugar de
  /// abrir el formulario en blanco. Existía completo desde el principio pero
  /// **nunca se llamaba desde ningún sitio**.
  void cargarDesdeMap(Map<String, dynamic> map) {
    distanciaAcceso = _leerDouble(map['distancia_acceso']);
    cantidadPat = _leerInt(map['cantidad_pat']);
    distanciaPosteAnterior = _leerDouble(map['distancia_poste_anterior']);
    distanciaVertical = _leerDouble(map['distancia_vertical']);
    distanciaHorizontal = _leerDouble(map['distancia_horizontal']);
    final obstaculoRaw = map['obstaculos_faja'];
    if (obstaculoRaw is String) {
      obstaculosFaja = obstaculoRaw
          .replaceAll('[', '')
          .replaceAll(']', '')
          .replaceAll('"', '')
          .split(',')
          .map((e) => e.trim())
          .where((e) => e.isNotEmpty)
          .toList();
    } else if (obstaculoRaw is List) {
      obstaculosFaja = obstaculoRaw.map((e) => e.toString()).toList();
    } else {
      obstaculosFaja = [];
    }

    estadoCuencas = _leer(map, 'estado_cuencas');
    marcadoArboles = _leer(map, 'marcado_arboles');
    criticidadTala = _leer(map, 'criticidad_tala');
    criticidadContacto = _leer(map, 'criticidad_contacto');
    notificacionPropietario = _leer(map, 'notificacion_propietario');
    tipoTorre = _leer(map, 'tipo_torre', conDefecto: false);
    ubicacion = _leer(map, 'ubicacion', conDefecto: false);
    accesoTorre = _leer(map, 'acceso_torre', conDefecto: false);
    estadoAcceso = _leer(map, 'estado_acceso');
    estadoPlacasTorre = _leer(map, 'estado_placas_torre');
    estadoPlacasLinea = _leer(map, 'estado_placas_linea');
    estadoPlacasFases = _leer(map, 'estado_placas_fases');
    peligroCerco = _leer(map, 'peligro_cerco');
    peligroTorre = _leer(map, 'peligro_torre');
    puestaTierra = _leer(map, 'puesta_tierra');
    retenida = _leer(map, 'retenida');
    estadoBase = _leer(map, 'estado_base');
    limpiarBase = _leer(map, 'limpiar_base');
    crucetasMensuales = _leer(map, 'crucetas_mensuales');
    perfilesAngulares = _leer(map, 'perfiles_angulares');
    mallaAntiescalamiento = _leer(map, 'malla_antiescalamiento');
    oxidosBase = _leer(map, 'oxidos_base');
    cadenaAisladores = _leer(map, 'cadena_aisladores');
    tipoAislador = _leer(map, 'tipo_aislador');
    conductorBajadaPat = _leer(map, 'conductor_bajada_pat');
    conductorGuarda = _leer(map, 'conductor_guarda');
    comentarios = map['comentarios']?.toString();

    final fecha = map['fecha_inspeccion'];
    fechaInspeccion = fecha == null
        ? null
        : DateTime.tryParse(fecha.toString());

    // Campos que ya estaban confirmados.
    revisados.clear();
    final revisadosRaw = map['campos_revisados'];
    if (revisadosRaw is List) {
      revisados.addAll(revisadosRaw.map((e) => e.toString()));
    } else {
      // Borrador anterior a la v3: se deduce de los valores presentes. Un campo
      // con valor distinto de `no_revisado` se considera confirmado.
      camposSimples.forEach((clave, valor) {
        if (valor != null && valor != noRevisado) revisados.add(clave);
      });
      if (obstaculosFaja.isNotEmpty) revisados.add('obstaculos_faja');
      if (distanciaAcceso != null) revisados.add('distancia_acceso');
    }
  }

  double? _leerDouble(dynamic valor) {
    if (valor is num) return valor.toDouble();
    return double.tryParse((valor ?? '').toString().replaceAll(',', '.'));
  }

  int? _leerInt(dynamic valor) {
    if (valor is int) return valor;
    if (valor is num) return valor.toInt();
    return int.tryParse((valor ?? '').toString());
  }

  String? _leer(
    Map<String, dynamic> map,
    String clave, {
    bool conDefecto = true,
  }) {
    final valor = map[clave];
    if (valor == null) return conDefecto ? noRevisado : null;
    final texto = valor.toString();
    if (texto.isEmpty || texto == 'null') {
      return conDefecto ? noRevisado : null;
    }
    return texto;
  }

  /// Etiqueta legible de un valor de catálogo: `buen_estado` → `Buen estado`.
  static String etiqueta(String? valor) {
    if (valor == null || valor.isEmpty) return 'Sin elegir';
    if (valor == noRevisado) return 'No revisado';
    if (valor == 'n_a') return 'No aplica';
    final texto = valor.replaceAll('_', ' ');
    return texto[0].toUpperCase() + texto.substring(1);
  }
}
