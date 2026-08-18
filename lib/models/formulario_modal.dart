import '../database/database_helper.dart';
class FormularioModal {
  List<String> obstaculosFaja = [];
  String? estadoCuencas = 'n_a';

  String? marcadoArboles = 'no'; // Item 3 predeterminado

  String? criticidadTala = 'n_a';
  String? criticidadContacto = 'n_a';
  String? notificacionPropietario = 'otro';
  String? tipoTorre;
  String? ubicacion;
  String? accesoTorre;
  String? estadoAcceso = 'mal_estado';
  DateTime? fechaInspeccion;

  // 🔻 Item 11 - predeterminar "bueno"
  String? estadoPlacasTorre = 'bueno';
  String? estadoPlacasLinea = 'bueno';
  String? estadoPlacasFases = 'bueno';
  String? peligroCerco = 'no_existe';
  String? peligroTorre = 'bueno';
  String? puestaTierra = 'bueno';

  // 🔻 Resto de ítems predeterminados
  String? retenida = 'n_a'; // Item 12
  String? estadoBase = 'buen_estado'; // Item 13
  String? limpiarBase = 'no'; // Item 14
  String? crucetasMensuales = 'buen_estado'; // Item 15
  String? perfilesAngulares = 'buen_estado'; // Item 16
  String? mallaAntiescalamiento = 'buen_estado'; // Item 17
  String? oxidosBase = 'no'; // Item 18
  String? cadenaAisladores = 'en_suspension'; // Item 19
  String? tipoAislador = 'porcelana'; // Item 20
  String? conductorBajadaPat = 'n_a'; // Item 21
  String? conductorGuarda = 'n_a';
  String? comentarios;

  Map<String, bool> seleccionados = {};

  // === Opciones estáticas ===
  static const List<String> obstaculosFajaOptions = [
    'invasiones_nuevas', 'construcciones_nuevas', 'proceso_construccion',
    'cercos_vallas', 'arboles', 'arbustos', 'arboles_fuera_faja', 'otros', 'n_a'
  ];
  static const List<String> estadoCuencasOptions = ['seguimiento', 'critico', 'n_a'];
  static const List<String> marcadoArbolesOptions = ['si', 'no'];
  static const List<String> criticidadTalaOptions = ['bajo', 'seguimiento', 'critico', 'n_a'];
  static const List<String> criticidadContactoOptions = ['bajo', 'seguimiento', 'critico', 'n_a'];
  static const List<String> notificacionPropietarioOptions = ['persona_natural', 'persona_juridica', 'otro'];
  static const List<String> tipoTorreOptions = ['alineamiento', 'angulo', 'fin_linea'];
  static const List<String> ubicacionOptions = ['rural_con_vegetacion', 'urbana', 'industrial', 'rural_sin_vegetacion', 'zona_sujeta_huaycos', 'desertico'];
  static const List<String> accesoTorreOptions = ['a_pie', 'en_vehiculo'];
  static const List<String> estadoAccesoOptions = ['bueno', 'mal_estado'];
  static const List<String> estadoPlacasOptions = ['bueno', 'malo', 'no_existe'];
  static const List<String> retenidaOptions = ['buen_estado', 'cambiar_preforme', 'retemplar', 'n_a'];
  static const List<String> estadoBaseOptions = ['buen_estado', 'mal_estado'];
  static const List<String> limpiarBaseOptions = ['si', 'no'];
  static const List<String> crucetasMensualesOptions = ['buen_estado', 'mal_estado', 'falta_ajustar', 'n_a'];
  static const List<String> perfilesAngularesOptions = ['buen_estado', 'mal_estado', 'falta', 'n_a'];
  static const List<String> mallaAntiescalamientoOptions = ['buen_estado', 'mal_estado', 'falta', 'n_a'];
  static const List<String> oxidosBaseOptions = ['si', 'no', 'n_a'];
  static const List<String> cadenaAisladoresOptions = ['en_suspension', 'en_anclaje', 'en_cuello_muerto'];
  static const List<String> tipoAisladorOptions = ['vidrio', 'porcelana', 'polimero'];
  static const List<String> conductorBajadaPatOptions = ['buen_estado', 'conductor_en_mal_estado', 'grapas_en_mal_estado', 'listones_en_mal_estado', 'n_a'];
  static const List<String> conductorGuardaOptions = ['hebras_rotas', 'encanastillado', 'empalme_deformado', 'objetos_extranos', 'n_a'];

  // === Serializar para guardar en SQLite ===
  Map<String, dynamic> toMap() {
    return {
      'obstaculos_faja': obstaculosFaja,
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
      'comentarios': comentarios,
      'fecha_inspeccion': fechaInspeccion?.toIso8601String() ?? DateTime.now().toIso8601String(),

    };
  }

  // === Para imprimir en consola ===
  Map<String, dynamic> toJson() {
    return {
      "marcadoArboles": marcadoArboles,
      "criticidadTala": criticidadTala,
      "criticidadContacto": criticidadContacto,
      "notificacionPropietario": notificacionPropietario,
      "tipoTorre": tipoTorre,
      "ubicacion": ubicacion,
      "accesoTorre": accesoTorre,
      "estadoAcceso": estadoAcceso,
      "estadoPlacasTorre": estadoPlacasTorre,
      "estadoPlacasLinea": estadoPlacasLinea,
      "estadoPlacasFases": estadoPlacasFases,
      "peligroCerco": peligroCerco,
      "peligroTorre": peligroTorre,
      "puestaTierra": puestaTierra,
      "retenida": retenida,
      "estadoBase": estadoBase,
      "limpiarBase": limpiarBase,
      "crucetasMensuales": crucetasMensuales,
      "perfilesAngulares": perfilesAngulares,
      "mallaAntiescalamiento": mallaAntiescalamiento,
      "oxidosBase": oxidosBase,
      "cadenaAisladores": cadenaAisladores,
      "tipoAislador": tipoAislador,
      "conductorBajadaPat": conductorBajadaPat,
      "conductorGuarda": conductorGuarda,
      "comentarios": comentarios,
      "seleccionados": seleccionados,
    };
  }

  // === Para servidor ===
  List<Map<String, String>> toRSTServidor() {
    final List<Map<String, String>> registros = [];
    for (final entry in seleccionados.entries) {
      if (entry.value) {
        final parts = entry.key.split('|');
        if (parts.length == 3) {
          registros.add({
            'seccion': parts[0],
            'atributo': parts[1],
            'fase': parts[2],
          });
        }
      }
    }
    return registros;
  }

  // === Para SQLite local ===
  List<Map<String, dynamic>> toRSTLocal() {
    final List<Map<String, dynamic>> registros = [];
    for (final entry in seleccionados.entries) {
      if (entry.value) {
        final partes = entry.key.split('|');
        if (partes.length == 3) {
          registros.add({
            'seccion': partes[0],
            'atributo': partes[1],
            'fase': partes[2],
          });
        }
      }
    }
    return registros;
  }
  void cargarDesdeMap(Map<String, dynamic> map) {
    final obstaculoRaw = map['obstaculos_faja'];
    if (obstaculoRaw is String) {
      obstaculosFaja = obstaculoRaw.split(',').map((e) => e.trim()).toList();
    } else if (obstaculoRaw is List) {
      obstaculosFaja = List<String>.from(obstaculoRaw);
    } else {
      obstaculosFaja = [];
    }

    estadoCuencas = map['estado_cuencas'];
    marcadoArboles = map['marcado_arboles'];
    criticidadTala = map['criticidad_tala'];
    criticidadContacto = map['criticidad_contacto'];
    notificacionPropietario = map['notificacion_propietario'];
    tipoTorre = map['tipo_torre'];
    ubicacion = map['ubicacion'];
    accesoTorre = map['acceso_torre'];
    estadoAcceso = map['estado_acceso'];
    estadoPlacasTorre = map['estado_placas_torre'];
    estadoPlacasLinea = map['estado_placas_linea'];
    estadoPlacasFases = map['estado_placas_fases'];
    peligroCerco = map['peligro_cerco'];
    peligroTorre = map['peligro_torre'];
    puestaTierra = map['puesta_tierra'];
    retenida = map['retenida'];
    estadoBase = map['estado_base'];
    limpiarBase = map['limpiar_base'];
    crucetasMensuales = map['crucetas_mensuales'];
    perfilesAngulares = map['perfiles_angulares'];
    mallaAntiescalamiento = map['malla_antiescalamiento'];
    oxidosBase = map['oxidos_base'];
    cadenaAisladores = map['cadena_aisladores'];
    tipoAislador = map['tipo_aislador'];
    conductorBajadaPat = map['conductor_bajada_pat'];
    conductorGuarda = map['conductor_guarda'];
    comentarios = map['comentarios'];
    fechaInspeccion = map['fecha_inspeccion'] != null
        ? DateTime.tryParse(map['fecha_inspeccion'])
        : null;
    // Validación adicional para prevenir errores de parsing en entero





  }



}
