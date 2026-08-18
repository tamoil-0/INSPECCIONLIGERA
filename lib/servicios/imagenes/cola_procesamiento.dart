import 'dart:async';
import 'dart:collection';

/// Cola de tareas con concurrencia limitada.
///
/// ## Por qué existe
///
/// Una estructura tiene 22 fotografías obligatorias. Lanzar 22 compresiones a
/// la vez en un teléfono de gama baja agota la memoria: cada códec nativo
/// reserva su propio búfer de trabajo. Aquí se procesan de una o de dos en dos
/// según el perfil del dispositivo, sin bloquear la interfaz y sin que el
/// inspector tenga que esperar: puede seguir tomando fotos mientras la cola
/// avanza por detrás.
///
/// La cola es **en memoria**: si la app se cierra, las fotos pendientes de
/// optimizar siguen en disco con su registro en SQLite (sin optimizar, pero
/// completas y subibles). La reoptimización se puede reintentar al reabrir la
/// estructura. Nunca se pierde nada por perder la cola.
class ColaProcesamiento {
  final int concurrencia;

  final Queue<_Tarea> _pendientes = Queue<_Tarea>();
  int _activas = 0;
  bool _cancelada = false;

  ColaProcesamiento({this.concurrencia = 1})
    : assert(concurrencia >= 1, 'La concurrencia mínima es 1');

  int get pendientes => _pendientes.length;
  int get activas => _activas;
  bool get ocupada => _activas > 0 || _pendientes.isNotEmpty;

  /// Encola [tarea] y devuelve su resultado cuando le llegue el turno.
  Future<T> encolar<T>(Future<T> Function() tarea) {
    if (_cancelada) {
      return Future.error(StateError('La cola fue cancelada.'));
    }
    final completer = Completer<T>();
    _pendientes.add(_Tarea(tarea, completer));
    _intentarArrancar();
    return completer.future;
  }

  /// Encola varias tareas y espera a que todas terminen.
  ///
  /// A diferencia de `Future.wait`, aquí un fallo individual no cancela el
  /// resto: se devuelve una lista con `null` en las posiciones que fallaron.
  Future<List<T?>> encolarTodas<T>(
    Iterable<Future<T> Function()> tareas,
  ) async {
    final futuros = tareas
        .map((t) => encolar(t).then<T?>((v) => v).catchError((_) => null))
        .toList();
    return Future.wait(futuros);
  }

  /// Descarta lo que aún no empezó. Lo que ya está en marcha termina: cortar un
  /// proceso de compresión a medias dejaría un archivo parcial.
  void cancelarPendientes() {
    _cancelada = true;
    while (_pendientes.isNotEmpty) {
      final tarea = _pendientes.removeFirst();
      if (!tarea.completer.isCompleted) {
        tarea.completer.completeError(
          StateError('Tarea cancelada antes de empezar.'),
        );
      }
    }
  }

  /// Permite volver a usar la cola tras una cancelación.
  void reactivar() => _cancelada = false;

  void _intentarArrancar() {
    while (_activas < concurrencia && _pendientes.isNotEmpty) {
      final tarea = _pendientes.removeFirst();
      _activas++;
      _ejecutar(tarea);
    }
  }

  Future<void> _ejecutar(_Tarea tarea) async {
    try {
      final resultado = await tarea.accion();
      if (!tarea.completer.isCompleted) tarea.completer.complete(resultado);
    } catch (e, pila) {
      if (!tarea.completer.isCompleted) tarea.completer.completeError(e, pila);
    } finally {
      _activas--;
      _intentarArrancar();
    }
  }
}

class _Tarea {
  final Future<dynamic> Function() accion;
  final Completer<dynamic> completer;
  _Tarea(this.accion, this.completer);
}
