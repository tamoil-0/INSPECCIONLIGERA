import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/data/remoto/cliente_api.dart';

void main() {
  late ClienteApi api;

  setUp(() {
    api = ClienteApi(baseUrl: 'https://ejemplo.test/api');
    ClienteApi.alVencerSesion = null;
  });

  group('ClienteApi.uri · codificación de parámetros', () {
    test('escapa los caracteres que rompían la petición', () {
      // ANTES: '?linea=$linea' sin escapar. Una línea con & o espacios generaba
      // una URL inválida y la búsqueda fallaba sin explicación.
      final uri = api.uri('/postes/buscar.php', {'linea': 'LT 220 & 60kV'});
      expect(uri.queryParameters['linea'], 'LT 220 & 60kV');
      expect(uri.toString(), contains('LT+220+%26+60kV'));
    });

    test('convierte números a texto', () {
      final uri = api.uri('/postes/listar.php', {'proyecto_id': 7});
      expect(uri.queryParameters['proyecto_id'], '7');
    });

    test('omite los parámetros nulos', () {
      final uri = api.uri('/x.php', {'a': '1', 'b': null});
      expect(uri.queryParameters.containsKey('b'), isFalse);
      expect(uri.queryParameters['a'], '1');
    });

    test('sin parámetros devuelve la ruta tal cual', () {
      expect(api.uri('/x.php').toString(), 'https://ejemplo.test/api/x.php');
    });
  });

  group('ClienteApi.interpretar · clasificación de errores', () {
    test('200 con success:true es una respuesta válida', () {
      final r = api.interpretar(200, '{"success":true,"data":[1,2]}');
      expect(r.exito, isTrue);
      expect(r.datos, [1, 2]);
    });

    test('acepta status:"success" además de success:true', () {
      expect(api.interpretar(200, '{"status":"success"}').exito, isTrue);
    });

    test('HTML de error de PHP no produce FormatException', () {
      // El fallo real: jsonDecode a ciegas sobre un 500 con HTML.
      try {
        api.interpretar(
          500,
          '<br /><b>Fatal error</b>: Uncaught PDOException in /var/www/x.php',
        );
        fail('debía lanzar ErrorApi');
      } on ErrorApi catch (e) {
        expect(e.tipo, TipoErrorApi.errorServidor);
        expect(e.detalle, contains('Fatal error'));
        expect(e.esTransitorio, isTrue);
      }
    });

    test('HTML con código 200 se detecta como respuesta no JSON', () {
      try {
        api.interpretar(200, '<html><body>Mantenimiento</body></html>');
        fail('debía lanzar ErrorApi');
      } on ErrorApi catch (e) {
        expect(e.tipo, TipoErrorApi.respuestaNoJson);
        expect(e.detalle, contains('Mantenimiento'));
      }
    });

    test('JSON truncado se clasifica como JSON inválido', () {
      try {
        api.interpretar(200, '{"success":true, "data": [1,');
        fail('debía lanzar ErrorApi');
      } on ErrorApi catch (e) {
        expect(e.tipo, TipoErrorApi.jsonInvalido);
      }
    });

    test('401 exige volver a iniciar sesión y avisa', () {
      var aviso = 0;
      ClienteApi.alVencerSesion = () => aviso++;

      try {
        api.interpretar(401, '{"error":"token expirado"}');
        fail('debía lanzar ErrorApi');
      } on ErrorApi catch (e) {
        expect(e.tipo, TipoErrorApi.noAutorizado);
        expect(e.exigeSesion, isTrue);
        expect(e.esTransitorio, isFalse);
        expect(e.mensajeUsuario, contains('no se pierde nada'));
      }
      expect(aviso, 1, reason: 'El aviso de sesión vencida debe dispararse');
    });

    test('403 también exige sesión', () {
      try {
        api.interpretar(403, '{"error":"sin permiso"}');
        fail('debía lanzar');
      } on ErrorApi catch (e) {
        expect(e.tipo, TipoErrorApi.prohibido);
        expect(e.exigeSesion, isTrue);
      }
    });

    test('413 se marca transitorio para reintentar en lotes menores', () {
      try {
        api.interpretar(413, 'Request Entity Too Large');
        fail('debía lanzar');
      } on ErrorApi catch (e) {
        expect(e.tipo, TipoErrorApi.cargaDemasiadoGrande);
        expect(e.esTransitorio, isTrue);
        expect(e.mensajeUsuario, contains('demasiado grande'));
      }
    });

    test('409 y 422 se distinguen del resto de errores 4xx', () {
      try {
        api.interpretar(409, '{"error":"ya existe"}');
        fail('debía lanzar');
      } on ErrorApi catch (e) {
        expect(e.tipo, TipoErrorApi.conflicto);
        expect(e.esTransitorio, isFalse);
      }
      try {
        api.interpretar(422, '{"error":"falta tipo_torre"}');
        fail('debía lanzar');
      } on ErrorApi catch (e) {
        expect(e.tipo, TipoErrorApi.datosInvalidos);
        expect(e.detalle, contains('tipo_torre'));
      }
    });

    test('404 se clasifica aparte', () {
      try {
        api.interpretar(404, '{"error":"no existe"}');
        fail('debía lanzar');
      } on ErrorApi catch (e) {
        expect(e.tipo, TipoErrorApi.noEncontrado);
      }
    });

    test('un arreglo en la raíz se envuelve en data', () {
      final r = api.interpretar(200, '[{"id":1}]');
      expect(r.exito, isTrue);
      expect(r.datos, isA<List<dynamic>>());
    });

    test('una respuesta vacía no rompe el mensaje de error', () {
      try {
        api.interpretar(500, '');
        fail('debía lanzar');
      } on ErrorApi catch (e) {
        expect(e.detalle, contains('vacía'));
      }
    });

    test('el detalle se recorta para no llenar la base de datos', () {
      final larguisimo = 'x' * 5000;
      try {
        api.interpretar(500, larguisimo);
        fail('debía lanzar');
      } on ErrorApi catch (e) {
        expect(e.detalle.length, lessThanOrEqualTo(210));
      }
    });

    test('todos los tipos de error tienen mensaje para el inspector', () {
      for (final tipo in TipoErrorApi.values) {
        expect(
          tipo.mensaje,
          isNotEmpty,
          reason: 'El tipo ${tipo.name} necesita un mensaje',
        );
      }
    });
  });
}
