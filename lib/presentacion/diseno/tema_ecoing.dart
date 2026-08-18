import 'package:flutter/material.dart';

/// Paleta y tema de la aplicación.
///
/// ## Criterios de campo
///
/// La app se usa a pleno sol, con guantes, a veces con una sola mano, en
/// jornadas largas. De ahí:
///
/// * **Modo claro y alto contraste.** Nada de degradados a pantalla completa
///   como antes: bajaban el contraste del contenido justo donde más falta hace.
/// * **El color significa una cosa y solo una.** El rojo institucional se
///   usaba para acciones normales ("Ir"), lo que lo hacía indistinguible de un
///   error. Aquí el rojo es error y nada más.
/// * **Objetivos táctiles de 48 px como mínimo.**
/// * **Texto de 15 sp para arriba** en contenido, y se respeta el escalado del
///   sistema (hay pruebas de widget con texto ampliado).
class ColoresEcoing {
  const ColoresEcoing._();

  // --- Identidad ----------------------------------------------------------
  /// Rojo institucional. Solo en la cabecera y la marca, nunca en acciones.
  static const Color marca = Color(0xFF8B0000);

  /// Azul institucional: acciones primarias y navegación.
  static const Color azul = Color(0xFF0D47A1);
  static const Color azulClaro = Color(0xFFE3F2FD);

  // --- Estados (semántica estricta) ---------------------------------------
  /// Solo confirmaciones reales del servidor.
  static const Color exito = Color(0xFF2E7D32);
  static const Color exitoFondo = Color(0xFFE8F5E9);

  /// Pendiente, en espera, advertencia.
  static const Color pendiente = Color(0xFFEF6C00);
  static const Color pendienteFondo = Color(0xFFFFF3E0);

  /// Error y situaciones críticas. Nada más.
  static const Color error = Color(0xFFC62828);
  static const Color errorFondo = Color(0xFFFFEBEE);

  /// En curso.
  static const Color enCurso = Color(0xFF1565C0);
  static const Color enCursoFondo = Color(0xFFE3F2FD);

  // --- Neutros ------------------------------------------------------------
  static const Color texto = Color(0xFF1C1C1E);
  static const Color textoSuave = Color(0xFF5A5A5E);
  static const Color textoTenue = Color(0xFF8A8A8E);
  static const Color borde = Color(0xFFD4D7DD);
  static const Color superficie = Colors.white;
  static const Color fondo = Color(0xFFF3F5F9);
  static const Color inactivo = Color(0xFF757575);
}

/// Espaciado en múltiplos de 4, para que todo alinee sin decidir caso a caso.
class Espacio {
  const Espacio._();
  static const double xs = 4;
  static const double s = 8;
  static const double m = 12;
  static const double l = 16;
  static const double xl = 24;
  static const double xxl = 32;

  /// Altura mínima de cualquier elemento pulsable.
  static const double objetivoTactil = 48;
  static const double radio = 12;
  static const double radioGrande = 16;
}

class TemaEcoing {
  const TemaEcoing._();

  static ThemeData claro() {
    final base = ThemeData.light(useMaterial3: true);
    final esquema = ColorScheme.fromSeed(
      seedColor: ColoresEcoing.azul,
      primary: ColoresEcoing.azul,
      error: ColoresEcoing.error,
      surface: ColoresEcoing.superficie,
    );

    return base.copyWith(
      colorScheme: esquema,
      scaffoldBackgroundColor: ColoresEcoing.fondo,
      appBarTheme: const AppBarTheme(
        backgroundColor: ColoresEcoing.azul,
        foregroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          color: Colors.white,
          fontSize: 19,
          fontWeight: FontWeight.w600,
        ),
        iconTheme: IconThemeData(color: Colors.white),
      ),
      cardTheme: CardThemeData(
        color: ColoresEcoing.superficie,
        elevation: 1,
        margin: const EdgeInsets.symmetric(
          horizontal: Espacio.m,
          vertical: Espacio.s,
        ),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Espacio.radioGrande),
          side: const BorderSide(color: ColoresEcoing.borde),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: ColoresEcoing.azul,
          foregroundColor: Colors.white,
          minimumSize: const Size(64, Espacio.objetivoTactil),
          padding: const EdgeInsets.symmetric(horizontal: Espacio.xl),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(Espacio.radio),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: ColoresEcoing.azul,
          minimumSize: const Size(64, Espacio.objetivoTactil),
          side: const BorderSide(color: ColoresEcoing.azul, width: 1.4),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(Espacio.radio),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: ColoresEcoing.azul,
          minimumSize: const Size(48, Espacio.objetivoTactil),
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: ColoresEcoing.superficie,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: Espacio.l,
          vertical: Espacio.l,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(Espacio.radio),
          borderSide: const BorderSide(color: ColoresEcoing.borde),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(Espacio.radio),
          borderSide: const BorderSide(color: ColoresEcoing.borde),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(Espacio.radio),
          borderSide: const BorderSide(color: ColoresEcoing.azul, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(Espacio.radio),
          borderSide: const BorderSide(color: ColoresEcoing.error, width: 1.6),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(Espacio.radio),
          borderSide: const BorderSide(color: ColoresEcoing.error, width: 2),
        ),
        labelStyle: const TextStyle(
          color: ColoresEcoing.textoSuave,
          fontSize: 15,
        ),
        errorStyle: const TextStyle(
          color: ColoresEcoing.error,
          fontSize: 13,
          fontWeight: FontWeight.w600,
        ),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: ColoresEcoing.superficie,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Espacio.radioGrande),
        ),
        titleTextStyle: const TextStyle(
          color: ColoresEcoing.texto,
          fontSize: 19,
          fontWeight: FontWeight.bold,
        ),
        contentTextStyle: const TextStyle(
          color: ColoresEcoing.texto,
          fontSize: 15,
          height: 1.4,
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Espacio.radio),
        ),
        contentTextStyle: const TextStyle(fontSize: 15, color: Colors.white),
      ),
      dividerTheme: const DividerThemeData(
        color: ColoresEcoing.borde,
        thickness: 1,
        space: 1,
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: ColoresEcoing.azul,
      ),
      listTileTheme: const ListTileThemeData(
        minVerticalPadding: Espacio.m,
        titleTextStyle: TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w600,
          color: ColoresEcoing.texto,
        ),
        subtitleTextStyle: TextStyle(
          fontSize: 14,
          color: ColoresEcoing.textoSuave,
        ),
      ),
      textTheme: base.textTheme
          .apply(bodyColor: ColoresEcoing.texto, displayColor: ColoresEcoing.texto)
          .copyWith(
            bodyLarge: const TextStyle(fontSize: 16, color: ColoresEcoing.texto),
            bodyMedium: const TextStyle(fontSize: 15, color: ColoresEcoing.texto),
            bodySmall: const TextStyle(
              fontSize: 13.5,
              color: ColoresEcoing.textoSuave,
            ),
            titleLarge: const TextStyle(
              fontSize: 21,
              fontWeight: FontWeight.bold,
              color: ColoresEcoing.texto,
            ),
            titleMedium: const TextStyle(
              fontSize: 17,
              fontWeight: FontWeight.w600,
              color: ColoresEcoing.texto,
            ),
          ),
    );
  }

  /// Degradado de la marca. Reservado a la cabecera: nunca detrás de contenido.
  static const LinearGradient degradadoCabecera = LinearGradient(
    colors: [ColoresEcoing.marca, ColoresEcoing.azul],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );
}
