import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/core/conversion_utm.dart';

void main() {
  group('ConversionUtm.zonaDe', () {
    test('calcula la zona de longitudes peruanas', () {
      expect(ConversionUtm.zonaDe(-71.537), 19); // Arequipa
      expect(ConversionUtm.zonaDe(-77.043), 18); // Lima
      expect(ConversionUtm.zonaDe(-69.181), 19); // Puno
    });

    test('cubre los extremos del rango', () {
      expect(ConversionUtm.zonaDe(-180), 1);
      expect(ConversionUtm.zonaDe(179.9), 60);
      expect(ConversionUtm.zonaDe(0), 31);
    });
  });

  group('ConversionUtm.bandaDe', () {
    test('devuelve las bandas conocidas del Perú', () {
      expect(ConversionUtm.bandaDe(-16.409), 'K'); // Arequipa -> 19K
      expect(ConversionUtm.bandaDe(-12.046), 'L'); // Lima     -> 18L
      expect(ConversionUtm.bandaDe(-5.19), 'M'); // Piura      -> 17M
    });

    test('el ecuador cae en la banda N', () {
      expect(ConversionUtm.bandaDe(0), 'N');
    });

    test('no desborda entre 80 y 84 grados (fallo del código original)', () {
      // El original hacía letters[(lat+80) ~/ 8] -> índice 20 sobre una cadena
      // de 20 caracteres: RangeError. Debe devolver la banda X.
      expect(ConversionUtm.bandaDe(80), 'X');
      expect(ConversionUtm.bandaDe(83.9), 'X');
      expect(ConversionUtm.bandaDe(84), 'X');
      expect(() => ConversionUtm.bandaDe(84), returnsNormally);
    });

    test('marca como desconocida la latitud fuera de cobertura UTM', () {
      expect(ConversionUtm.bandaDe(85), '?');
      expect(ConversionUtm.bandaDe(-80.1), '?');
    });

    test('el límite inferior -80 es válido', () {
      expect(ConversionUtm.bandaDe(-80), 'C');
    });
  });

  group('ConversionUtm.desdeLatLon', () {
    test('en el meridiano central del ecuador da E=500000 y N=0', () {
      // Identidad matemática exacta de la proyección UTM: sobre el meridiano
      // central (zona 18 -> -75°) el falso este es 500 000 y el norte 0.
      final utm = ConversionUtm.desdeLatLon(0, -75);
      expect(utm.este, closeTo(500000.0, 0.01));
      expect(utm.norte, closeTo(0.0, 0.01));
      expect(utm.zonaCompleta, '18N');
    });

    test('aplica el desplazamiento de 10 000 000 m en el hemisferio sur', () {
      final sur = ConversionUtm.desdeLatLon(-0.0001, -75);
      expect(sur.norte, greaterThan(9999000));
      expect(sur.norte, lessThan(10000001));
    });

    test('convierte una estructura en Arequipa a 19K con este plausible', () {
      final utm = ConversionUtm.desdeLatLon(-16.409047, -71.537451);
      expect(utm.zonaCompleta, '19K');
      // Dentro del ancho útil de una zona UTM.
      expect(utm.este, inInclusiveRange(100000, 900000));
      // Hemisferio sur a 16° del ecuador: ~8 185 km desde el falso origen.
      expect(utm.norte, inInclusiveRange(8100000, 8250000));
    });

    test('el norte crece al alejarse del ecuador hacia el norte', () {
      final cerca = ConversionUtm.desdeLatLon(5, -75);
      final lejos = ConversionUtm.desdeLatLon(15, -75);
      expect(lejos.norte, greaterThan(cerca.norte));
    });

    test('el norte decrece al alejarse del ecuador hacia el sur', () {
      // Con el desplazamiento sur, más al sur significa un norte menor.
      final cerca = ConversionUtm.desdeLatLon(-5, -75);
      final lejos = ConversionUtm.desdeLatLon(-15, -75);
      expect(lejos.norte, lessThan(cerca.norte));
    });

    test('redondea a dos decimales, como el código original', () {
      final utm = ConversionUtm.desdeLatLon(-16.409047, -71.537451);
      expect(utm.este, equals(double.parse(utm.este.toStringAsFixed(2))));
      expect(utm.norte, equals(double.parse(utm.norte.toStringAsFixed(2))));
    });

    test('aMapa entrega las claves que consumen los servicios', () {
      final mapa = ConversionUtm.desdeLatLon(-16.4, -71.5).aMapa();
      expect(mapa.keys, containsAll(['utmEste', 'utmNorte', 'zona']));
      expect(mapa['zona'], '19K');
    });
  });
}
