import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/servicios/sincronizacion/politica_reintentos.dart';
import 'package:pruebaoffline/servicios/sincronizacion/servicio_sincronizacion.dart';

void main() {
  group('PoliticaReintentos · backoff exponencial', () {
    const politica = PoliticaReintentos();

    test('el primer intento es inmediato', () {
      // La mayoría de los fallos de red son transitorios: reintentar ya vale.
      expect(politica.esperaPara(0), Duration.zero);
      expect(politica.puedeIntentar(intentos: 0), isTrue);
    });

    test('las esperas crecen con cada fallo', () {
      final esperas = [
        politica.esperaPara(1),
        politica.esperaPara(2),
        politica.esperaPara(3),
        politica.esperaPara(4),
        politica.esperaPara(5),
      ];
      for (var i = 1; i < esperas.length; i++) {
        expect(
          esperas[i],
          greaterThan(esperas[i - 1]),
          reason: 'La espera del intento ${i + 1} debe ser mayor que la anterior',
        );
      }
      expect(esperas.first, const Duration(seconds: 30));
      expect(politica.esperaPara(4), const Duration(minutes: 30));
      expect(esperas.last, const Duration(hours: 2));
    });

    test('la espera tiene techo, no crece indefinidamente', () {
      expect(politica.esperaPara(50), politica.esperaPara(100));
      expect(politica.esperaPara(50), const Duration(hours: 2));
    });

    test('no se reintenta antes de que toque', () {
      final haceUnSegundo = DateTime.now().subtract(const Duration(seconds: 1));
      expect(
        politica.puedeIntentar(intentos: 3, ultimoIntento: haceUnSegundo),
        isFalse,
      );
    });

    test('se reintenta cuando ha pasado la espera', () {
      final haceUnaHora = DateTime.now().subtract(const Duration(hours: 1));
      expect(
        politica.puedeIntentar(intentos: 3, ultimoIntento: haceUnaHora),
        isTrue,
      );
    });

    test('forzar salta la espera', () {
      // Es lo que hace el botón "Reintentar": el inspector sabe algo que la app
      // no sabe, como que acaba de llegar a un sitio con cobertura.
      final ahora = DateTime.now();
      expect(
        politica.puedeIntentar(intentos: 5, ultimoIntento: ahora),
        isFalse,
      );
      expect(
        politica.puedeIntentar(intentos: 5, ultimoIntento: ahora, forzar: true),
        isTrue,
      );
    });

    test('tras el límite requiere atención manual pero NO se descarta', () {
      expect(politica.requiereAtencionManual(7), isFalse);
      expect(politica.requiereAtencionManual(8), isTrue);
      expect(politica.requiereAtencionManual(20), isTrue);

      // Y aun así se puede forzar: nada queda inaccesible para siempre.
      expect(
        politica.puedeIntentar(
          intentos: 30,
          ultimoIntento: DateTime.now(),
          forzar: true,
        ),
        isTrue,
      );
    });

    test('el texto de espera es comprensible', () {
      final ahora = DateTime.now();
      expect(
        politica.describirEspera(intentos: 2, ultimoIntento: ahora),
        contains('reintentará'),
      );
      expect(
        politica.describirEspera(intentos: 10, ultimoIntento: ahora),
        contains('a mano'),
      );
      expect(
        politica.describirEspera(intentos: 0),
        contains('próximo envío'),
      );
    });
  });

  group('ResultadoSync · mensajes honestos', () {
    test('sin intentos no dice que sincronizó nada', () {
      const r = ResultadoSync();
      expect(r.huboIntentos, isFalse);
      expect(r.todoConfirmado, isFalse);
      expect(r.mensaje, contains('No había nada pendiente'));
    });

    test('todo confirmado enumera lo que confirmó el servidor', () {
      const r = ResultadoSync(
        formulariosConfirmados: 2,
        fotosConfirmadas: 44,
      );
      expect(r.todoConfirmado, isTrue);
      expect(r.mensaje, contains('Servidor confirmó'));
      expect(r.mensaje, contains('2 formulario'));
      expect(r.mensaje, contains('44 fotografía'));
    });

    test('un envío parcial NO se anuncia como éxito', () {
      const r = ResultadoSync(fotosConfirmadas: 8, fotosFallidas: 14);
      expect(r.todoConfirmado, isFalse);
      expect(r.mensaje, contains('8 fotografía'));
      expect(r.mensaje, contains('14 pendiente'));
      expect(r.mensaje, contains('guardados en el teléfono'));
      expect(r.mensaje.toLowerCase(), isNot(contains('éxito')));
    });

    test('un fallo total lo dice claramente', () {
      const r = ResultadoSync(fotosFallidas: 22, ultimoError: 'HTTP 500');
      expect(r.todoConfirmado, isFalse);
      expect(r.totalConfirmados, 0);
      expect(r.mensaje, contains('22 pendiente'));
    });

    test('una cancelación no se presenta como fallo', () {
      const r = ResultadoSync(fotosConfirmadas: 5, cancelada: true);
      expect(r.mensaje, contains('detenida'));
      expect(r.mensaje, contains('5'));
    });

    test('los impedimentos explican qué hacer', () {
      for (final impedimento in ImpedimentoSync.values) {
        if (impedimento == ImpedimentoSync.ninguno) continue;
        final r = ResultadoSync(impedimento: impedimento);
        expect(r.pudoEmpezar, isFalse);
        expect(
          r.mensaje,
          isNotEmpty,
          reason: 'El impedimento ${impedimento.name} debe tener mensaje',
        );
      }
      expect(
        const ResultadoSync(impedimento: ImpedimentoSync.sinSesion).mensaje,
        contains('no se pierde nada'),
      );
      expect(
        const ResultadoSync(impedimento: ImpedimentoSync.sinConexion).mensaje,
        contains('guardado en el teléfono'),
      );
    });

    test('sumar resultados acumula las cuentas', () {
      const a = ResultadoSync(formulariosConfirmados: 1, fotosFallidas: 2);
      const b = ResultadoSync(fotosConfirmadas: 10, fotosFallidas: 1);
      final total = a.mas(b);

      expect(total.formulariosConfirmados, 1);
      expect(total.fotosConfirmadas, 10);
      expect(total.fotosFallidas, 3);
      expect(total.totalConfirmados, 11);
      expect(total.totalFallidos, 3);
      expect(total.todoConfirmado, isFalse);
    });
  });

  group('ProgresoSync', () {
    test('la fracción no se sale del rango', () {
      expect(const ProgresoSync(totalElementos: 0).fraccion, 0);
      expect(
        const ProgresoSync(totalElementos: 10, procesados: 5).fraccion,
        0.5,
      );
      expect(
        const ProgresoSync(totalElementos: 10, procesados: 99).fraccion,
        1.0,
      );
    });
  });
}
