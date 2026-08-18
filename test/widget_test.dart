import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/core/estados_sync.dart';
import 'package:pruebaoffline/main.dart';
import 'package:pruebaoffline/presentacion/comunes/componentes.dart';
import 'package:pruebaoffline/presentacion/diseno/tema_ecoing.dart';

/// Tamaños de pantalla reales que hay que soportar.
const _pantallaPequena = Size(320, 568); // gama baja antigua
const _pantallaNormal = Size(412, 915); // Galaxy Note 10 y similares

Future<void> _conPantalla(
  WidgetTester tester,
  Size tamano,
  Widget widget, {
  double escalaTexto = 1.0,
}) async {
  tester.view.physicalSize = tamano * 3;
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: TemaEcoing.claro(),
      home: MediaQuery(
        data: MediaQueryData(textScaler: TextScaler.linear(escalaTexto)),
        child: widget,
      ),
    ),
  );
  await tester.pump();
}

void main() {
  group('Arranque de la app', () {
    testWidgets('arranca en el login cuando no hay sesión', (tester) async {
      await tester.pumpWidget(const MyApp(initialRoute: '/login'));
      await tester.pump();

      expect(find.text('App-Ecoing'), findsOneWidget);
      expect(find.text('Iniciar sesión'), findsOneWidget);
      expect(find.byType(TextFormField), findsNWidgets(2));
    });

    testWidgets('el login exige usuario y contraseña', (tester) async {
      await tester.pumpWidget(const MyApp(initialRoute: '/login'));
      await tester.pump();

      await tester.tap(find.text('Iniciar sesión'));
      await tester.pump();

      expect(find.text('Campo requerido'), findsNWidgets(2));
    });

    testWidgets('la contraseña se puede mostrar y ocultar', (tester) async {
      await tester.pumpWidget(const MyApp(initialRoute: '/login'));
      await tester.pump();

      expect(find.byIcon(Icons.visibility_outlined), findsOneWidget);
      await tester.tap(find.byIcon(Icons.visibility_outlined));
      await tester.pump();
      expect(find.byIcon(Icons.visibility_off_outlined), findsOneWidget);
    });

    testWidgets('navegar a una ruta inexistente muestra un aviso legible', (
      tester,
    ) async {
      // El escenario del bug: el menú tenía "Editar Postes" apuntando a
      // /editar_postes con pushNamed, y esa ruta estaba comentada en el mapa.
      // El resultado era la pantalla de error de Flutter.
      await tester.pumpWidget(const MyApp(initialRoute: '/login'));
      await tester.pump();

      navegadorApp.currentState!.pushNamed('/editar_postes');
      await tester.pumpAndSettle();

      expect(find.text('Sección no disponible'), findsOneWidget);
      expect(find.byType(ErrorWidget), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });

  group('Pantalla pequeña (320×568)', () {
    testWidgets('el login entra sin desbordar', (tester) async {
      await _conPantalla(
        tester,
        _pantallaPequena,
        const MyApp(initialRoute: '/login'),
      );
      expect(tester.takeException(), isNull);
      expect(find.text('Iniciar sesión'), findsOneWidget);
    });

    testWidgets('los componentes de estado no desbordan', (tester) async {
      await _conPantalla(
        tester,
        _pantallaPequena,
        Scaffold(
          body: ListView(
            children: [
              const ChipEstado(estado: EstadoSync.fallido),
              const Aviso(
                texto: 'Modo offline. Todo se guarda en el teléfono y nada se '
                    'envía hasta que lo desactives.',
              ),
              const BarraProgreso(
                hechas: 18,
                total: 22,
                etiqueta: 'Estructuras sincronizadas de esta línea',
              ),
              const ResumenEstados(
                completas: 118,
                pendientes: 34,
                conError: 2,
                sinIniciar: 26,
              ),
              SizedBox(
                height: 400,
                child: VistaEstado.error(
                  titulo: 'No se pudo conectar con el servidor',
                  detalle: 'Tu trabajo sigue guardado en el teléfono.',
                  alPulsar: () {},
                ),
              ),
            ],
          ),
        ),
      );
      expect(tester.takeException(), isNull);
    });
  });

  group('Texto ampliado por accesibilidad', () {
    testWidgets('el login sigue usable a 1.6x', (tester) async {
      await _conPantalla(
        tester,
        _pantallaNormal,
        const MyApp(initialRoute: '/login'),
        escalaTexto: 1.6,
      );
      expect(tester.takeException(), isNull);
      expect(find.text('Iniciar sesión'), findsOneWidget);
    });

    testWidgets('los componentes de estado aguantan 2x sin romperse', (
      tester,
    ) async {
      await _conPantalla(
        tester,
        _pantallaNormal,
        const Scaffold(
          body: SingleChildScrollView(
            child: Column(
              children: [
                ChipEstado(estado: EstadoSync.sincronizado),
                ChipEstado(estado: EstadoSync.pendiente),
                Aviso.error(
                  texto: '3 fotografías no pudieron enviarse. Tu información '
                      'sigue segura en el teléfono.',
                ),
                BarraProgreso(hechas: 5, total: 22, etiqueta: 'Fotografías'),
              ],
            ),
          ),
        ),
        escalaTexto: 2.0,
      );
      expect(tester.takeException(), isNull);
    });
  });

  group('Semántica de estados', () {
    testWidgets('cada estado muestra su etiqueta honesta', (tester) async {
      await _conPantalla(
        tester,
        _pantallaNormal,
        const Scaffold(
          body: Column(
            children: [
              ChipEstado(estado: EstadoSync.local),
              ChipEstado(estado: EstadoSync.pendiente),
              ChipEstado(estado: EstadoSync.subiendo),
              ChipEstado(estado: EstadoSync.sincronizado),
              ChipEstado(estado: EstadoSync.fallido),
            ],
          ),
        ),
      );

      expect(find.text('Guardado en el teléfono'), findsOneWidget);
      expect(find.text('Pendiente de enviar'), findsOneWidget);
      expect(find.text('Subiendo'), findsOneWidget);
      expect(find.text('Sincronizado'), findsOneWidget);
      expect(find.text('Error al enviar'), findsOneWidget);
    });

    testWidgets('el indicador de conexión distingue offline de sin señal', (
      tester,
    ) async {
      await _conPantalla(
        tester,
        _pantallaNormal,
        Scaffold(
          appBar: AppBar(
            actions: const [
              IndicadorConexion(hayInternet: false, modoOffline: true),
            ],
          ),
        ),
      );
      expect(find.text('Offline'), findsOneWidget);

      await _conPantalla(
        tester,
        _pantallaNormal,
        Scaffold(
          appBar: AppBar(
            actions: const [
              IndicadorConexion(hayInternet: false, modoOffline: false),
            ],
          ),
        ),
      );
      expect(find.text('Sin conexión'), findsOneWidget);
    });
  });

  group('CapaCargando · nunca deja al usuario atrapado', () {
    testWidgets('ofrece detener cuando se le pasa la acción', (tester) async {
      var detenido = false;
      await _conPantalla(
        tester,
        _pantallaNormal,
        Scaffold(
          body: CapaCargando(
            mensaje: 'Enviando…',
            progreso: 0.4,
            alCancelar: () => detenido = true,
          ),
        ),
      );

      expect(find.text('Detener'), findsOneWidget);
      await tester.tap(find.text('Detener'));
      expect(detenido, isTrue);
    });
  });
}
