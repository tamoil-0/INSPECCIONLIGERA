# Reglas de ProGuard/R8 para la compilación de release.
#
# Se activó isMinifyEnabled para reducir el tamaño del APK, y estas reglas
# protegen lo que la ofuscación rompería.

# Flutter y sus plugins
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }
-dontwarn io.flutter.embedding.**

# sqflite
-keep class com.tekartik.sqflite.** { *; }

# flutter_secure_storage (Keystore)
-keep class com.it_nomads.fluttersecurestorage.** { *; }

# flutter_image_compress
-keep class com.example.flutterimagecompress.** { *; }

# geolocator / permission_handler
-keep class com.baseflow.** { *; }

# Play Core: R8 lo busca por las clases de diferimiento de Flutter, que esta app
# no usa. Sin esto el build de release falla con "missing classes".
-dontwarn com.google.android.play.core.**
