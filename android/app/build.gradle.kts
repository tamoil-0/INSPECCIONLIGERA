import java.io.FileInputStream
import java.util.Properties

// Firma de release desde android/key.properties, que NO se versiona.
//
// Si el archivo no existe se compila con la clave de depuración, para que
// `flutter build apk --release` siga funcionando en un equipo recién clonado.
// El APK resultante NO es distribuible: solo sirve para probar.
// Ver android/key.properties.example.
val archivoFirma = rootProject.file("key.properties")
val propiedadesFirma = Properties().apply {
    if (archivoFirma.exists()) {
        FileInputStream(archivoFirma).use { load(it) }
    }
}
val hayFirmaPropia = archivoFirma.exists()

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.example.pruebaoffline"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = "27.0.12077973"

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_11.toString()
    }

    defaultConfig {
        // ATENCION: sigue siendo el identificador de ejemplo de Flutter.
        //
        // Con este ID no se puede publicar en Google Play. Debe cambiarse a algo
        // como "com.ecoing.inspecciones", PERO ese cambio NO es inocuo:
        //
        //   Android trata un applicationId distinto como una aplicacion
        //   DIFERENTE. Los telefonos que ya tienen la app instalada no
        //   recibirian la actualizacion: quedaria la version vieja con sus
        //   inspecciones pendientes y la nueva empezaria con la base vacia.
        //
        // Por eso se deja sin tocar hasta que el equipo decida el momento y el
        // procedimiento (sincronizar todo lo pendiente en todos los telefonos
        // antes de migrar). Ver README.md, seccion "Publicacion".
        applicationId = "com.example.pruebaoffline"

        minSdk = flutter.minSdkVersion  // flutter_secure_storage con encryptedSharedPreferences
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        create("release") {
            if (hayFirmaPropia) {
                keyAlias = propiedadesFirma["keyAlias"] as String?
                keyPassword = propiedadesFirma["keyPassword"] as String?
                storeFile = propiedadesFirma["storeFile"]?.let { file(it) }
                storePassword = propiedadesFirma["storePassword"] as String?
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (hayFirmaPropia) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    // Un APK por arquitectura en lugar de uno universal.
    //
    // El APK universal pesaba 52 MB porque incluia las librerias nativas de
    // todas las ABI. Los inspectores lo descargan con datos moviles en campo.
    // Con esto cada telefono baja solo lo suyo (~18-20 MB).
    //
    // Para Google Play lo correcto es `flutter build appbundle`, que hace esto
    // solo. Los splits sirven para reparto directo del APK.
    splits {
        abi {
            isEnable = project.hasProperty("splitApks")
            reset()
            include("armeabi-v7a", "arm64-v8a", "x86_64")
            isUniversalApk = true
        }
    }
}

flutter {
    source = "../.."
}
