import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:pruebaoffline/main.dart';

void main() {
  testWidgets('la app arranca en el login cuando no hay sesión', (tester) async {
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
}
