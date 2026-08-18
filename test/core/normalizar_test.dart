import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/core/normalizar.dart';

void main() {
  group('Normalizar.estructura · búsqueda tolerante', () {
    test('ignora ceros a la izquierda', () {
      // El caso que hacía que el inspector no encontrara su torre.
      expect(Normalizar.estructura('0025'), '25');
      expect(Normalizar.estructura('00025'), '25');
      expect(Normalizar.estructura('25'), '25');
    });

    test('ignora espacios, guiones y puntos', () {
      expect(Normalizar.estructura(' 25 '), '25');
      expect(Normalizar.estructura('25-A'), '25a');
      expect(Normalizar.estructura('T-025'), 't25');
      expect(Normalizar.estructura('25_B'), '25b');
      expect(Normalizar.estructura('25.1'), '251');
    });

    test('no destruye números legítimos con ceros interiores', () {
      expect(Normalizar.estructura('100'), '100');
      expect(Normalizar.estructura('0100'), '100');
      expect(Normalizar.estructura('1005'), '1005');
    });

    test('mismaEstructura reconoce todas las variantes', () {
      expect(Normalizar.mismaEstructura('0025', '25'), isTrue);
      expect(Normalizar.mismaEstructura('25', ' 25'), isTrue);
      expect(Normalizar.mismaEstructura('25-a', '25A'), isTrue);
      expect(Normalizar.mismaEstructura('25', '26'), isFalse);
      expect(Normalizar.mismaEstructura('25', '250'), isFalse);
    });

    test('una estructura vacía no coincide con nada', () {
      expect(Normalizar.mismaEstructura('', ''), isFalse);
      expect(Normalizar.mismaEstructura(null, null), isFalse);
      expect(Normalizar.mismaEstructura('25', null), isFalse);
    });
  });

  group('Normalizar.texto · acentos y mayúsculas', () {
    test('quita acentos', () {
      expect(Normalizar.texto('Línea Sur'), 'linea sur');
      expect(Normalizar.texto('AREQUIPA'), 'arequipa');
      expect(Normalizar.texto('Ñuñoa'), 'nunoa');
      expect(Normalizar.texto('Camión'), 'camion');
    });

    test('colapsa espacios', () {
      expect(Normalizar.texto('  LT   220   kV  '), 'lt 220 kv');
    });

    test('contiene busca sin importar acentos ni mayúsculas', () {
      expect(Normalizar.contiene('Línea Sur 220kV', 'linea'), isTrue);
      expect(Normalizar.contiene('Línea Sur 220kV', 'SUR'), isTrue);
      expect(Normalizar.contiene('Línea Sur', 'norte'), isFalse);
      // Una búsqueda vacía coincide con todo.
      expect(Normalizar.contiene('cualquier cosa', ''), isTrue);
    });
  });

  group('Normalizar.compararNatural · orden que espera una persona', () {
    test('ordena los números por valor, no por carácter', () {
      final estructuras = ['10', '2', '1', '100', '20', '3'];
      estructuras.sort(Normalizar.compararNatural);
      expect(estructuras, ['1', '2', '3', '10', '20', '100']);
    });

    test('el orden alfabético habría sido el de la app anterior', () {
      final alfabetico = ['10', '2', '1', '100', '20', '3']..sort();
      // Esto es lo que veía el inspector: 1, 10, 100, 2, 20, 3.
      expect(alfabetico, ['1', '10', '100', '2', '20', '3']);
    });

    test('ordena líneas con prefijo', () {
      final lineas = ['L-10', 'L-2', 'L-1', 'L-21'];
      lineas.sort(Normalizar.compararNatural);
      expect(lineas, ['L-1', 'L-2', 'L-10', 'L-21']);
    });

    test('ordena estructuras con sufijo de letra', () {
      final estructuras = ['25B', '25A', '3', '25'];
      estructuras.sort(Normalizar.compararNatural);
      expect(estructuras.first, '3');
      expect(estructuras.indexOf('25'), lessThan(estructuras.indexOf('25A')));
      expect(estructuras.indexOf('25A'), lessThan(estructuras.indexOf('25B')));
    });

    test('no falla con nulos ni cadenas vacías', () {
      final valores = <String?>['2', null, '', '1'];
      expect(() => valores.sort(Normalizar.compararNatural), returnsNormally);
    });
  });
}
