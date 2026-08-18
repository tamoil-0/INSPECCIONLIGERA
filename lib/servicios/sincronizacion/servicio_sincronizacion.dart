import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';

import '../../core/preferencias_app.dart';
import '../../database/database_helper.dart';
import '../../repositorios/borradores_repositorio.dart';
import '../../repositorios/fotos_repositorio.dart';
import '../../services/imagenesPoste_service.dart';
import '../../services/poste_datos_service.dart';
import '../../storage/almacen_seguro.dart';
import '../conectividad/servicio_conectividad.dart';
import '../imagenes/optimizador_imagenes.dart';
import 'politica_reintentos.dart';

/// Ámbito de una sincronización.
enum AmbitoSync { estructura, linea, proyecto, todo, soloFallidos }

/// Motivo por el que una sincronización no puede empezar.
enum ImpedimentoSync {
  ninguno,
  modoOffline,
  sinConexion,
  sinInternetReal,
  requiereWifi,
  sinSesion,
  nadaPendiente,
  yaEnMarcha,
}

extension ImpedimentoSyncTexto on ImpedimentoSync {
  String get mensaje {
    switch (this) {
      case ImpedimentoSync.ninguno:
        return '';
      case ImpedimentoSync.modoOffline:
        return 'Estás en modo offline. Desactívalo para enviar.';
      case ImpedimentoSync.sinConexion:
        return 'Sin conexión. Tu trabajo sigue guardado en el teléfono.';
      case ImpedimentoSync.sinInternetReal:
        return 'Hay red pero sin salida a internet. Se reintentará solo.';
      case ImpedimentoSync.requiereWifi:
        return 'Configuraste enviar fotografías solo con Wi-Fi.';
      case ImpedimentoSync.sinSesion:
        return 'Tu sesión venció. Inicia sesión otra vez; no se pierde nada.';
      case ImpedimentoSync.nadaPendiente:
        return 'No hay nada pendiente por enviar.';
      case ImpedimentoSync.yaEnMarcha:
        return 'Ya hay una sincronización en curso.';
    }
  }
}

/// Progreso observable de la sincronización en curso.
class ProgresoSync {
  final bool enMarcha;
  final AmbitoSync? ambito;
  final int totalElementos;
  final int procesados;
  final int confirmados;
  final int fallidos;
  final String? elementoActual;

  const ProgresoSync({
    this.enMarcha = false,
    this.ambito,
    this.totalElementos = 0,
    this.procesados = 0,
    this.confirmados = 0,
    this.fallidos = 0,
    this.elementoActual,
  });

  double get fraccion =>
      totalElementos == 0 ? 0 : (procesados / totalElementos).clamp(0.0, 1.0);

  ProgresoSync copiar({
    bool? enMarcha,
    AmbitoSync? ambito,
    int? totalElementos,
    int? procesados,
    int? confirmados,
    int? fallidos,
    String? elementoActual,
  }) => ProgresoSync(
    enMarcha: enMarcha ?? this.enMarcha,
    ambito: ambito ?? this.ambito,
    totalElementos: totalElementos ?? this.totalElementos,
    procesados: procesados ?? this.procesados,
    confirmados: confirmados ?? this.confirmados,
    fallidos: fallidos ?? this.fallidos,
    elementoActual: elementoActual ?? this.elementoActual,
  );
}

/// Resultado de una sincronización, con cuentas reales.
class ResultadoSync {
  final ImpedimentoSync impedimento;
  final int formulariosConfirmados;
  final int formulariosFallidos;
  final int fotosConfirmadas;
  final int fotosFallidas;
  final int omitidosPorEspera;
  final bool cancelada;
  final String? ultimoError;

  const ResultadoSync({
    this.impedimento = ImpedimentoSync.ninguno,
    this.formulariosConfirmados = 0,
    this.formulariosFallidos = 0,
    this.fotosConfirmadas = 0,
    this.fotosFallidas = 0,
    this.omitidosPorEspera = 0,
    this.cancelada = false,
    this.ultimoError,
  });

  bool get pudoEmpezar => impedimento == ImpedimentoSync.ninguno;
  int get totalConfirmados => formulariosConfirmados + fotosConfirmadas;
  int get totalFallidos => formulariosFallidos + fotosFallidas;
  bool get huboIntentos => totalConfirmados + totalFallidos > 0;
  bool get todoConfirmado => huboIntentos && totalFallidos == 0;

  ResultadoSync mas(ResultadoSync otro) => ResultadoSync(
    impedimento: impedimento,
    formulariosConfirmados:
        formulariosConfirmados + otro.formulariosConfirmados,
    formulariosFallidos: formulariosFallidos + otro.formulariosFallidos,
    fotosConfirmadas: fotosConfirmadas + otro.fotosConfirmadas,
    fotosFallidas: fotosFallidas + otro.fotosFallidas,
    omitidosPorEspera: omitidosPorEspera + otro.omitidosPorEspera,
    cancelada: cancelada || otro.cancelada,
    ultimoError: otro.ultimoError ?? ultimoError,
  );

  /// Mensaje honesto: describe lo que el servidor confirmó, no el hecho de
  /// haber terminado el bucle.
  String get mensaje {
    if (!pudoEmpezar) return impedimento.mensaje;
    if (cancelada) {
      return 'Sincronización detenida. Confirmado: $totalConfirmados. '
          'El resto sigue guardado en el teléfono.';
    }
    if (!huboIntentos) {
      if (omitidosPorEspera > 0) {
        return '$omitidosPorEspera elemento(s) esperan su próximo reintento.';
      }
      return 'No había nada pendiente por enviar.';
    }
    if (todoConfirmado) {
      final partes = <String>[];
      if (formulariosConfirmados > 0) {
        partes.add('$formulariosConfirmados formulario(s)');
      }
      if (fotosConfirmadas > 0) partes.add('$fotosConfirmadas fotografía(s)');
      return 'Servidor confirmó ${partes.join(' y ')}.';
    }
    return 'Confirmado: $formulariosConfirmados formulario(s) y '
        '$fotosConfirmadas fotografía(s). Quedan $totalFallidos pendiente(s), '
        'guardados en el teléfono.';
  }
}

/// Servicio central de sincronización.
///
/// ## Por qué existe
///
/// Antes esta lógica vivía dentro de `DetalleLineaScreen` (521 líneas de UI,
/// red y disco mezclados) y solo permitía "sincronizar esta página": el
/// inspector tenía que entrar a Sincronización, elegir proyecto, elegir línea,
/// paginar de 10 en 10 y pulsar en cada página.
///
/// ## Garantías
///
/// * **Nada se marca como sincronizado sin confirmación del servidor.**
/// * **Nada se descarta.** Lo que falla vuelve a la cola con su contador de
///   intentos y su último error.
/// * **La cola es la base de datos**, no una lista en memoria: sobrevive al
///   cierre de la app.
/// * **Se puede cancelar** sin dejar nada a medias en estado `uploading`.
/// * **Respeta el modo offline y la preferencia de solo-Wi-Fi.**
///
/// ## Límite honesto
///
/// Esto sincroniza **con la app en primer plano**. No hay sincronización
/// garantizada en segundo plano porque ni Android (Doze, límites desde API 26,
/// matanza agresiva de OEM) ni iOS (BGTaskScheduler sin garantías) la permiten
/// de forma fiable. Lo que sí hace: dispararse al recuperar la conexión con la
/// app en uso, y reanudar al abrir.
class ServicioSincronizacion {
  ServicioSincronizacion({
    FotosRepositorio? fotos,
    BorradoresRepositorio? borradores,
    PosteDatosService? datosService,
    ImagenesPosteService? imagenesService,
    DatabaseHelper? db,
    AlmacenSeguro? almacenSeguro,
    ServicioConectividad? conectividad,
    this.politica = const PoliticaReintentos(),
  }) : _fotos = fotos ?? FotosRepositorio(),
       _borradores = borradores ?? BorradoresRepositorio(),
       _datos = datosService ?? PosteDatosService(),
       _imagenes = imagenesService ?? ImagenesPosteService(),
       _db = db ?? DatabaseHelper(),
       _almacenSeguro = almacenSeguro ?? AlmacenSeguro(),
       _conectividad = conectividad ?? ServicioConectividad.instancia;

  static final ServicioSincronizacion instancia = ServicioSincronizacion();

  final FotosRepositorio _fotos;
  final BorradoresRepositorio _borradores;
  final PosteDatosService _datos;
  final ImagenesPosteService _imagenes;
  final DatabaseHelper _db;
  final AlmacenSeguro _almacenSeguro;
  final ServicioConectividad _conectividad;
  final PoliticaReintentos politica;

  /// Cuántos postes se procesan a la vez. Tres es lo que ya usaba la pantalla
  /// anterior y funciona bien en gama baja.
  static const int postesEnParalelo = 3;

  final ValueNotifier<ProgresoSync> progreso = ValueNotifier(
    const ProgresoSync(),
  );

  bool _enMarcha = false;
  bool _cancelar = false;
  StreamSubscription<EstadoRed>? _suscripcionReconexion;

  bool get enMarcha => _enMarcha;

  // ===========================================================================
  // Disparo automático
  // ===========================================================================

  /// Empieza a escuchar la recuperación de conexión para sincronizar solo.
  ///
  /// No es sincronización en segundo plano: solo funciona con la app abierta.
  void activarDisparoAutomatico() {
    _suscripcionReconexion?.cancel();
    _suscripcionReconexion = _conectividad.alRecuperarConexion.listen((
      _,
    ) async {
      if (_enMarcha) return;
      final prefs = await PreferenciasApp.instancia();
      if (prefs.modoOffline) return;
      debugPrint('Conexión recuperada: sincronizando pendientes.');
      await sincronizar(ambito: AmbitoSync.todo);
    });
  }

  Future<void> desactivarDisparoAutomatico() async {
    await _suscripcionReconexion?.cancel();
    _suscripcionReconexion = null;
  }

  /// Pide detener la sincronización en curso.
  ///
  /// No corta una subida a medias: se termina el elemento actual y no se empieza
  /// el siguiente. Cortar en seco dejaría el registro en `uploading`.
  void cancelar() => _cancelar = true;

  // ===========================================================================
  // Entradas públicas
  // ===========================================================================

  Future<ResultadoSync> sincronizarEstructura(
    int posteId, {
    bool forzar = true,
  }) => sincronizar(
    ambito: AmbitoSync.estructura,
    posteId: posteId,
    forzar: forzar,
  );

  Future<ResultadoSync> sincronizarLinea(int proyectoId, String linea) =>
      sincronizar(
        ambito: AmbitoSync.linea,
        proyectoId: proyectoId,
        linea: linea,
      );

  Future<ResultadoSync> sincronizarProyecto(int proyectoId) =>
      sincronizar(ambito: AmbitoSync.proyecto, proyectoId: proyectoId);

  Future<ResultadoSync> sincronizarTodo() =>
      sincronizar(ambito: AmbitoSync.todo);

  /// Reintenta solo lo que falló, saltándose la espera de backoff.
  Future<ResultadoSync> reintentarFallidos({int? proyectoId, String? linea}) =>
      sincronizar(
        ambito: AmbitoSync.soloFallidos,
        proyectoId: proyectoId,
        linea: linea,
        forzar: true,
      );

  // ===========================================================================
  // Motor
  // ===========================================================================

  Future<ResultadoSync> sincronizar({
    required AmbitoSync ambito,
    int? posteId,
    int? proyectoId,
    String? linea,
    bool forzar = false,
  }) async {
    if (_enMarcha) {
      return const ResultadoSync(impedimento: ImpedimentoSync.yaEnMarcha);
    }

    final impedimento = await _comprobarCondiciones();
    if (impedimento != ImpedimentoSync.ninguno) {
      return ResultadoSync(impedimento: impedimento);
    }

    final token = await _almacenSeguro.token();
    if (token == null || token.isEmpty) {
      return const ResultadoSync(impedimento: ImpedimentoSync.sinSesion);
    }

    // Qué postes tienen trabajo pendiente en el ámbito pedido.
    final postes = await _postesConPendientes(
      posteId: posteId,
      proyectoId: proyectoId,
      linea: linea,
    );
    if (postes.isEmpty) {
      return const ResultadoSync(impedimento: ImpedimentoSync.nadaPendiente);
    }

    _enMarcha = true;
    _cancelar = false;
    progreso.value = ProgresoSync(
      enMarcha: true,
      ambito: ambito,
      totalElementos: postes.length,
    );

    var resultado = const ResultadoSync();

    try {
      final soloFotosConWifi = await _soloFotosConWifi();

      for (var i = 0; i < postes.length; i += postesEnParalelo) {
        if (_cancelar) {
          resultado = resultado.mas(const ResultadoSync(cancelada: true));
          break;
        }

        final lote = postes.sublist(
          i,
          (i + postesEnParalelo).clamp(0, postes.length),
        );

        final parciales = await Future.wait(
          lote.map(
            (p) => _sincronizarPoste(
              posteId: p,
              token: token,
              forzar: forzar,
              omitirFotos: soloFotosConWifi,
            ),
          ),
        );
        for (final parcial in parciales) {
          resultado = resultado.mas(parcial);
        }

        progreso.value = progreso.value.copiar(
          procesados: (i + lote.length).clamp(0, postes.length),
          confirmados: resultado.totalConfirmados,
          fallidos: resultado.totalFallidos,
        );
      }

      if (resultado.totalConfirmados > 0) {
        final prefs = await PreferenciasApp.instancia();
        await prefs.marcarSincronizacion();
      }
      return resultado;
    } finally {
      _enMarcha = false;
      _cancelar = false;
      progreso.value = const ProgresoSync();
    }
  }

  Future<ImpedimentoSync> _comprobarCondiciones() async {
    final prefs = await PreferenciasApp.instancia();
    if (prefs.modoOffline) return ImpedimentoSync.modoOffline;

    final red = await _conectividad.comprobar(forzar: true);
    if (red.tipo == TipoRed.ninguna) return ImpedimentoSync.sinConexion;
    if (!red.hayInternet) return ImpedimentoSync.sinInternetReal;

    return ImpedimentoSync.ninguno;
  }

  /// Con la preferencia "solo Wi-Fi" activa y datos móviles, los formularios
  /// sí se envían (son kilobytes) y las fotografías se dejan para el Wi-Fi.
  Future<bool> _soloFotosConWifi() async {
    final prefs = await PreferenciasApp.instancia();
    if (!prefs.sincronizarSoloWifi) return false;
    return !_conectividad.estado.value.esWifi;
  }

  Future<List<int>> _postesConPendientes({
    int? posteId,
    int? proyectoId,
    String? linea,
  }) async {
    if (posteId != null) return [posteId];

    final conFotos = await _fotos.postesConFotosPendientes(
      proyectoId: proyectoId,
      linea: linea,
    );
    final conFormulario = await _borradores.pendientes(
      proyectoId: proyectoId,
      linea: linea,
    );

    return <int>{...conFotos, ...conFormulario.map((b) => b.posteId)}.toList()
      ..sort();
  }

  Future<ResultadoSync> _sincronizarPoste({
    required int posteId,
    required String token,
    required bool forzar,
    required bool omitirFotos,
  }) async {
    var resultado = const ResultadoSync();

    resultado = resultado.mas(
      await _enviarFormulario(posteId: posteId, token: token, forzar: forzar),
    );

    if (omitirFotos) {
      final pendientes = await _fotos.pendientes(posteId: posteId);
      return resultado.mas(ResultadoSync(omitidosPorEspera: pendientes.length));
    }

    resultado = resultado.mas(
      await _enviarFotos(posteId: posteId, forzar: forzar),
    );
    return resultado;
  }

  Future<ResultadoSync> _enviarFormulario({
    required int posteId,
    required String token,
    required bool forzar,
  }) async {
    final borrador = await _borradores.obtener(posteId);
    if (borrador == null ||
        borrador.estaSincronizado ||
        borrador.datos.isEmpty) {
      return const ResultadoSync();
    }

    final ultimoIntento = await _borradores.ultimoIntentoDe(posteId);
    if (!politica.puedeIntentar(
      intentos: borrador.intentos,
      ultimoIntento: ultimoIntento,
      forzar: forzar,
    )) {
      return const ResultadoSync(omitidosPorEspera: 1);
    }

    progreso.value = progreso.value.copiar(
      elementoActual: 'Formulario del poste $posteId',
    );

    await _borradores.marcarSubiendo(posteId);
    try {
      final okDatos = await _datos.actualizarDatosPoste(
        posteId: posteId,
        token: token,
        datos: borrador.datos,
      );
      if (!okDatos) {
        await _borradores.marcarFallido(
          posteId,
          'El servidor no confirmó la actualización de datos.',
        );
        return const ResultadoSync(
          formulariosFallidos: 1,
          ultimoError: 'El servidor no confirmó la actualización de datos.',
        );
      }

      if (borrador.rst.isNotEmpty) {
        final okRst = await _datos.agregarSeccionRST(
          posteId: posteId,
          token: token,
          datos: {'registros': borrador.rst},
        );
        if (!okRst) {
          await _borradores.marcarFallido(
            posteId,
            'Los datos se enviaron pero el tablero RST no fue confirmado.',
          );
          return const ResultadoSync(
            formulariosFallidos: 1,
            ultimoError: 'El tablero RST no fue confirmado.',
          );
        }
      }

      await _borradores.marcarSincronizado(posteId);
      // Espejo local del dato ya confirmado.
      await _db.guardarFormularioCompleto(
        posteId: posteId,
        datos: borrador.datos,
      );
      return const ResultadoSync(formulariosConfirmados: 1);
    } on SocketException catch (e) {
      await _borradores.marcarFallido(posteId, 'Sin conexión: ${e.message}');
      return ResultadoSync(formulariosFallidos: 1, ultimoError: e.message);
    } catch (e) {
      await _borradores.marcarFallido(posteId, 'Error de envío: $e');
      return ResultadoSync(formulariosFallidos: 1, ultimoError: '$e');
    }
  }

  Future<ResultadoSync> _enviarFotos({
    required int posteId,
    required bool forzar,
  }) async {
    final pendientes = await _fotos.pendientes(posteId: posteId);
    if (pendientes.isEmpty) return const ResultadoSync();

    // Backoff por foto, y descarte de las que ya no tienen archivo.
    final enviables = <FotoLocal>[];
    var omitidas = 0;
    var fallidasPorArchivo = 0;

    for (final foto in pendientes) {
      if (!politica.puedeIntentar(
        intentos: foto.intentos,
        ultimoIntento: await _fotos.ultimoIntentoDe(foto.id),
        forzar: forzar,
      )) {
        omitidas++;
        continue;
      }
      if (!await foto.archivo.exists()) {
        await _fotos.marcarFallida(
          foto.id,
          'El archivo de la fotografía ya no está en el teléfono. '
          'Hay que volver a tomarla.',
        );
        fallidasPorArchivo++;
        continue;
      }
      enviables.add(foto);
    }

    if (enviables.isEmpty) {
      return ResultadoSync(
        fotosFallidas: fallidasPorArchivo,
        omitidosPorEspera: omitidas,
      );
    }

    progreso.value = progreso.value.copiar(
      elementoActual: '${enviables.length} fotografía(s) del poste $posteId',
    );

    final archivos = <String, File>{};
    final metadatos = <String, Map<String, dynamic>>{};
    for (final f in enviables) {
      archivos[f.nombreFoto] = f.archivo;
      metadatos[f.nombreFoto] = f.metadatosParaSubida;
    }

    await _fotos.marcarSubiendo(enviables.map((f) => f.id));

    try {
      final respuesta = await _imagenes.subirImagenBatch(
        posteId,
        archivos,
        metadatos,
      );

      var confirmadas = 0;
      var fallidas = fallidasPorArchivo;
      final liberarOriginal =
          (await PreferenciasApp.instancia()).politicaRetencion ==
          PoliticaRetencion.liberarTrasSincronizar;
      for (final f in enviables) {
        if (respuesta.confirmadas.contains(f.nombreFoto)) {
          await _fotos.marcarSincronizada(f.id);
          if (liberarOriginal) {
            await _fotos.liberarOriginalSincronizado(f.id);
          }
          confirmadas++;
        } else {
          await _fotos.marcarFallida(
            f.id,
            respuesta.error ?? 'El servidor no confirmó la recepción.',
          );
          fallidas++;
        }
      }

      return ResultadoSync(
        fotosConfirmadas: confirmadas,
        fotosFallidas: fallidas,
        omitidosPorEspera: omitidas,
        ultimoError: respuesta.error,
      );
    } catch (e) {
      for (final f in enviables) {
        await _fotos.marcarFallida(f.id, 'Error de envío: $e');
      }
      return ResultadoSync(
        fotosFallidas: fallidasPorArchivo + enviables.length,
        omitidosPorEspera: omitidas,
        ultimoError: '$e',
      );
    }
  }

  // ===========================================================================
  // Resumen para la interfaz
  // ===========================================================================

  /// Cuentas globales por estado, para la cabecera de inicio.
  Future<Map<String, int>> resumenGlobal({int? proyectoId}) async {
    final fotos = await _fotos.resumenPorEstado(proyectoId: proyectoId);
    final formularios = await _borradores.resumenPorEstado(
      proyectoId: proyectoId,
    );
    final total = <String, int>{};
    for (final mapa in [fotos, formularios]) {
      mapa.forEach((estado, n) => total[estado] = (total[estado] ?? 0) + n);
    }
    return total;
  }
}
