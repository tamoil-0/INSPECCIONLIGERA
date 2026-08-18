import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/core/entorno.dart';
import 'package:pruebaoffline/models/formulario_modal.dart';

void main() {
  group('«No revisado» por defecto', () {
    test('un formulario nuevo no trae ninguna respuesta precargada', () {
      final m = FormularioModal();

      // El problema original: 19 de 22 ítems llegaban con 'bueno',
      // 'buen_estado', 'n_a' o 'no' ya puestos, y el servidor recibía
      // respuestas que nadie había mirado.
      expect(m.estadoCuencas, FormularioModal.noRevisado);
      expect(m.estadoPlacasTorre, FormularioModal.noRevisado);
      expect(m.estadoBase, FormularioModal.noRevisado);
      expect(m.cadenaAisladores, FormularioModal.noRevisado);
      expect(m.tipoAislador, FormularioModal.noRevisado);
      expect(m.oxidosBase, FormularioModal.noRevisado);

      // Los tres obligatorios arrancan vacíos, no en 'no_revisado'.
      expect(m.tipoTorre, isNull);
      expect(m.ubicacion, isNull);
      expect(m.accesoTorre, isNull);
    });

    test('todos los campos cuentan como sin revisar al empezar', () {
      final m = FormularioModal();
      expect(m.camposRevisados, 0);
      expect(m.todoRevisado, isFalse);
      expect(m.sinRevisar.length, m.totalCampos);
      expect(m.totalCampos, 27); // 26 desplegables + obstáculos
    });

    test('marcar un campo lo saca de la lista de pendientes', () {
      final m = FormularioModal()
        ..estadoBase = 'mal_estado'
        ..marcarRevisado('estado_base');

      expect(m.sinRevisar, isNot(contains('estado_base')));
      expect(m.camposRevisados, 1);
      expect(m.estaRevisado('estado_base'), isTrue);
    });

    test('confirmar «no hay obstáculos» cuenta como revisado', () {
      final m = FormularioModal();
      expect(m.sinRevisar, contains('obstaculos_faja'));

      m.marcarRevisado('obstaculos_faja');
      expect(m.sinRevisar, isNot(contains('obstaculos_faja')));
    });

    test('elegir «no aplica» es una respuesta, no una omisión', () {
      final m = FormularioModal()
        ..retenida = 'n_a'
        ..marcarRevisado('retenida');
      expect(m.sinRevisar, isNot(contains('retenida')));
    });
  });

  group('toMap · qué se envía al servidor', () {
    test('incluye siempre los metadatos de calidad del dato', () {
      final m = FormularioModal()
        ..tipoTorre = 'angulo'
        ..marcarRevisado('tipo_torre');
      final mapa = m.toMap();

      expect(mapa['campos_revisados'], contains('tipo_torre'));
      expect(mapa['campos_sin_revisar'], isA<List<String>>());
      expect(mapa['total_campos'], 27);
      expect(mapa['fecha_inspeccion'], isNotEmpty);
    });

    test('los campos revisados viajan con su valor real', () {
      final m = FormularioModal()
        ..estadoBase = 'mal_estado'
        ..marcarRevisado('estado_base');
      expect(m.toMap()['estado_base'], 'mal_estado');
    });

    test(
      'con el interruptor apagado los campos sin revisar mantienen el formato '
      'antiguo, para no romper el backend actual',
      () {
        // Entorno.enviarNoRevisado es false por defecto: el backend se está
        // actualizando y enviar 'no_revisado' es un cambio de contrato.
        expect(Entorno.enviarNoRevisado, isFalse);

        final mapa = FormularioModal().toMap();
        expect(mapa['estado_base'], 'buen_estado');
        expect(mapa['estado_placas_torre'], 'bueno');
        expect(mapa['retenida'], 'n_a');
        expect(mapa['marcado_arboles'], 'no');

        // Pero el servidor SÍ puede distinguirlos: la lista de revisados va
        // vacía y la de sin revisar los enumera.
        expect(mapa['campos_revisados'], isEmpty);
        expect((mapa['campos_sin_revisar'] as List).length, 27);
      },
    );

    test('cada valor por defecto heredado corresponde a un campo real', () {
      final claves = FormularioModal().camposSimples.keys.toSet();
      for (final clave in FormularioModal.valoresPorDefectoLegado.keys) {
        expect(
          claves,
          contains(clave),
          reason: 'El valor heredado de "$clave" no corresponde a ningún campo',
        );
      }
    });
  });

  group('Tablero RST', () {
    test('solo se envían las casillas marcadas', () {
      final m = FormularioModal()
        ..seleccionados = {
          'conductores_fase|hebras_rotas|R': true,
          'conductores_fase|encanastillado|S': false,
          'estado_aisladores|buen_estado|T': true,
        };

      final servidor = m.toRSTServidor();
      expect(servidor, hasLength(2));
      expect(
        servidor.map((r) => r['fase']),
        containsAll(['R', 'T']),
      );
      expect(m.toRSTLocal(), hasLength(2));
    });

    test('se descartan las claves con formato inválido', () {
      final m = FormularioModal()
        ..seleccionados = {'clave_mal_formada': true, 'a|b|c': true};
      expect(m.toRSTServidor(), hasLength(1));
    });
  });

  group('cargarDesdeMap · recuperar el borrador', () {
    test('restaura los valores guardados', () {
      final original = FormularioModal()
        ..tipoTorre = 'fin_linea'
        ..ubicacion = 'urbana'
        ..accesoTorre = 'en_vehiculo'
        ..estadoBase = 'mal_estado'
        ..obstaculosFaja = ['arboles', 'cercos_vallas']
        ..comentarios = 'Óxido severo en la base'
        ..marcarRevisado('tipo_torre')
        ..marcarRevisado('estado_base');

      final recuperado = FormularioModal()..cargarDesdeMap(original.toMap());

      expect(recuperado.tipoTorre, 'fin_linea');
      expect(recuperado.ubicacion, 'urbana');
      expect(recuperado.accesoTorre, 'en_vehiculo');
      expect(recuperado.estadoBase, 'mal_estado');
      expect(recuperado.obstaculosFaja, ['arboles', 'cercos_vallas']);
      expect(recuperado.comentarios, contains('Óxido'));
      expect(recuperado.estaRevisado('tipo_torre'), isTrue);
      expect(recuperado.estaRevisado('estado_base'), isTrue);
    });

    test('los obstáculos guardados como texto se vuelven a leer', () {
      // La versión anterior los serializaba de varias formas según la ruta.
      final m = FormularioModal()
        ..cargarDesdeMap({'obstaculos_faja': '["arboles","arbustos"]'});
      expect(m.obstaculosFaja, ['arboles', 'arbustos']);

      final m2 = FormularioModal()
        ..cargarDesdeMap({'obstaculos_faja': 'arboles, arbustos'});
      expect(m2.obstaculosFaja, ['arboles', 'arbustos']);
    });

    test('un borrador anterior a la v3 deduce qué estaba revisado', () {
      // Los borradores viejos no tienen `campos_revisados`. Un campo con valor
      // distinto de 'no_revisado' se considera confirmado.
      final m = FormularioModal()
        ..cargarDesdeMap({
          'estado_base': 'mal_estado',
          'tipo_aislador': 'vidrio',
        });

      expect(m.estaRevisado('estado_base'), isTrue);
      expect(m.estaRevisado('tipo_aislador'), isTrue);
      expect(m.estaRevisado('oxidos_base'), isFalse);
      expect(m.sinRevisar, contains('oxidos_base'));
    });

    test('un mapa vacío deja todo en «no revisado» sin lanzar', () {
      final m = FormularioModal();
      expect(() => m.cargarDesdeMap({}), returnsNormally);
      expect(m.estadoBase, FormularioModal.noRevisado);
      expect(m.tipoTorre, isNull);
      expect(m.camposRevisados, 0);
    });

    test('valores nulos o "null" no se toman como respuesta', () {
      final m = FormularioModal()
        ..cargarDesdeMap({'estado_base': null, 'oxidos_base': 'null'});
      expect(m.estadoBase, FormularioModal.noRevisado);
      expect(m.oxidosBase, FormularioModal.noRevisado);
    });
  });

  group('Catálogos de opciones', () {
    test('los ítems opcionales ofrecen «no revisado» como primera opción', () {
      final catalogos = {
        'estadoCuencas': FormularioModal.estadoCuencasOptions,
        'estadoPlacas': FormularioModal.estadoPlacasOptions,
        'estadoBase': FormularioModal.estadoBaseOptions,
        'retenida': FormularioModal.retenidaOptions,
        'oxidosBase': FormularioModal.oxidosBaseOptions,
        'tipoAislador': FormularioModal.tipoAisladorOptions,
        'conductorGuarda': FormularioModal.conductorGuardaOptions,
      };
      catalogos.forEach((nombre, opciones) {
        expect(
          opciones.first,
          FormularioModal.noRevisado,
          reason: '$nombre debe empezar por no_revisado',
        );
      });
    });

    test('los tres obligatorios NO ofrecen «no revisado»', () {
      // Si lo ofrecieran, el inspector podría dejarlos sin responder y la
      // validación de obligatorio no tendría sentido.
      for (final opciones in [
        FormularioModal.tipoTorreOptions,
        FormularioModal.ubicacionOptions,
        FormularioModal.accesoTorreOptions,
      ]) {
        expect(opciones, isNot(contains(FormularioModal.noRevisado)));
      }
    });
  });

  group('etiqueta · texto legible', () {
    test('convierte las claves del catálogo en algo que se pueda leer', () {
      expect(FormularioModal.etiqueta('buen_estado'), 'Buen estado');
      expect(
        FormularioModal.etiqueta('conductor_en_mal_estado'),
        'Conductor en mal estado',
      );
      expect(FormularioModal.etiqueta('n_a'), 'No aplica');
      expect(FormularioModal.etiqueta(FormularioModal.noRevisado), 'No revisado');
      expect(FormularioModal.etiqueta(null), 'Sin elegir');
      expect(FormularioModal.etiqueta(''), 'Sin elegir');
    });
  });
}
