import 'package:flutter/material.dart';

import '../../core/estados_sync.dart';
import '../diseno/tema_ecoing.dart';

/// Colores que corresponden a un estado de sincronización.
class ParColor {
  final Color frente;
  final Color fondo;
  final IconData icono;
  const ParColor(this.frente, this.fondo, this.icono);

  static ParColor de(String? estado) {
    switch (estado) {
      case EstadoSync.sincronizado:
        return const ParColor(
          ColoresEcoing.exito,
          ColoresEcoing.exitoFondo,
          Icons.cloud_done,
        );
      case EstadoSync.subiendo:
        return const ParColor(
          ColoresEcoing.enCurso,
          ColoresEcoing.enCursoFondo,
          Icons.cloud_upload,
        );
      case EstadoSync.fallido:
        return const ParColor(
          ColoresEcoing.error,
          ColoresEcoing.errorFondo,
          Icons.error_outline,
        );
      case EstadoSync.conflicto:
        return const ParColor(
          ColoresEcoing.pendiente,
          ColoresEcoing.pendienteFondo,
          Icons.help_outline,
        );
      default:
        return const ParColor(
          ColoresEcoing.pendiente,
          ColoresEcoing.pendienteFondo,
          Icons.save_alt,
        );
    }
  }
}

/// Insignia de estado, legible de un vistazo y sin ambigüedad.
class ChipEstado extends StatelessWidget {
  final String? estado;
  final String? textoPersonalizado;
  final bool compacto;

  const ChipEstado({
    super.key,
    this.estado,
    this.textoPersonalizado,
    this.compacto = false,
  });

  @override
  Widget build(BuildContext context) {
    final par = ParColor.de(estado);
    final texto = textoPersonalizado ?? EstadoSync.etiqueta(estado);
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compacto ? Espacio.s : Espacio.m,
        vertical: compacto ? 2 : Espacio.xs + 2,
      ),
      decoration: BoxDecoration(
        color: par.fondo,
        borderRadius: BorderRadius.circular(Espacio.s),
        border: Border.all(color: par.frente.withValues(alpha: 0.4)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(par.icono, size: compacto ? 13 : 16, color: par.frente),
          SizedBox(width: compacto ? Espacio.xs : Espacio.s - 2),
          // Flexible + ellipsis: con el texto del sistema al 200% una etiqueta
          // como "Guardado en el teléfono" desbordaba la pantalla.
          Flexible(
            child: Text(
              texto,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: par.frente,
                fontSize: compacto ? 12 : 13.5,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Indicador de conexión y modo offline, siempre en el mismo sitio.
class IndicadorConexion extends StatelessWidget {
  final bool hayInternet;
  final bool modoOffline;
  final String? descripcionRed;
  final VoidCallback? alPulsar;

  const IndicadorConexion({
    super.key,
    required this.hayInternet,
    required this.modoOffline,
    this.descripcionRed,
    this.alPulsar,
  });

  @override
  Widget build(BuildContext context) {
    final (IconData icono, String texto) = modoOffline
        ? (Icons.cloud_off, 'Offline')
        : hayInternet
        ? (Icons.cloud_done, descripcionRed ?? 'En línea')
        : (Icons.wifi_off, 'Sin conexión');

    return Semantics(
      label: modoOffline
          ? 'Modo offline activado'
          : (hayInternet ? 'Con conexión' : 'Sin conexión'),
      button: alPulsar != null,
      child: InkWell(
        onTap: alPulsar,
        borderRadius: BorderRadius.circular(Espacio.radio),
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: Espacio.m,
            vertical: Espacio.s,
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icono, size: 18, color: Colors.white),
              const SizedBox(width: Espacio.xs + 2),
              Flexible(
                child: Text(
                  texto,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 13.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Banda de aviso. Se usa para "modo offline", "archivos perdidos", etc.
class Aviso extends StatelessWidget {
  final String texto;
  final IconData icono;
  final Color color;
  final Color fondo;
  final Widget? accion;

  const Aviso({
    super.key,
    required this.texto,
    this.icono = Icons.info_outline,
    this.color = ColoresEcoing.pendiente,
    this.fondo = ColoresEcoing.pendienteFondo,
    this.accion,
  });

  const Aviso.error({super.key, required this.texto, this.accion})
      : icono = Icons.error_outline,
        color = ColoresEcoing.error,
        fondo = ColoresEcoing.errorFondo;

  const Aviso.exito({super.key, required this.texto, this.accion})
      : icono = Icons.check_circle_outline,
        color = ColoresEcoing.exito,
        fondo = ColoresEcoing.exitoFondo;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(Espacio.m),
      decoration: BoxDecoration(
        color: fondo,
        border: Border(left: BorderSide(color: color, width: 4)),
      ),
      child: Row(
        children: [
          Icon(icono, color: color, size: 22),
          const SizedBox(width: Espacio.m),
          Expanded(
            child: Text(
              texto,
              style: const TextStyle(fontSize: 14, color: ColoresEcoing.texto),
            ),
          ),
          if (accion != null) ...[const SizedBox(width: Espacio.s), accion!],
        ],
      ),
    );
  }
}

/// Estado vacío, de error o de carga, con una acción clara.
///
/// Antes cada pantalla improvisaba su propio `Center(child: Text(...))`, y en
/// varias no había forma de reintentar sin salir y volver a entrar.
class VistaEstado extends StatelessWidget {
  final IconData icono;
  final String titulo;
  final String? detalle;
  final String? textoAccion;
  final VoidCallback? alPulsar;
  final Color color;

  const VistaEstado({
    super.key,
    required this.icono,
    required this.titulo,
    this.detalle,
    this.textoAccion,
    this.alPulsar,
    this.color = ColoresEcoing.textoSuave,
  });

  const VistaEstado.vacio({
    super.key,
    required this.titulo,
    this.detalle,
    this.textoAccion,
    this.alPulsar,
  })  : icono = Icons.inbox_outlined,
        color = ColoresEcoing.textoTenue;

  const VistaEstado.error({
    super.key,
    required this.titulo,
    this.detalle,
    this.textoAccion = 'Reintentar',
    this.alPulsar,
  })  : icono = Icons.cloud_off,
        color = ColoresEcoing.error;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(Espacio.xl),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icono, size: 56, color: color),
            const SizedBox(height: Espacio.l),
            Text(
              titulo,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 17,
                fontWeight: FontWeight.w600,
                color: ColoresEcoing.texto,
              ),
            ),
            if (detalle != null) ...[
              const SizedBox(height: Espacio.s),
              Text(
                detalle!,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 14.5,
                  color: ColoresEcoing.textoSuave,
                  height: 1.4,
                ),
              ),
            ],
            if (textoAccion != null && alPulsar != null) ...[
              const SizedBox(height: Espacio.xl),
              FilledButton.icon(
                onPressed: alPulsar,
                icon: const Icon(Icons.refresh),
                label: Text(textoAccion!),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Barra de progreso con recuento, para listas de estructuras y de fotos.
class BarraProgreso extends StatelessWidget {
  final int hechas;
  final int total;
  final String etiqueta;
  final Color? color;

  const BarraProgreso({
    super.key,
    required this.hechas,
    required this.total,
    required this.etiqueta,
    this.color,
  });

  @override
  Widget build(BuildContext context) {
    final completo = total > 0 && hechas >= total;
    final c = color ?? (completo ? ColoresEcoing.exito : ColoresEcoing.azul);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                etiqueta,
                style: const TextStyle(
                  fontSize: 13.5,
                  color: ColoresEcoing.textoSuave,
                ),
              ),
            ),
            Text(
              '$hechas / $total',
              style: TextStyle(
                fontSize: 13.5,
                fontWeight: FontWeight.bold,
                color: c,
              ),
            ),
          ],
        ),
        const SizedBox(height: Espacio.xs + 2),
        ClipRRect(
          borderRadius: BorderRadius.circular(Espacio.xs),
          child: LinearProgressIndicator(
            value: total == 0 ? 0 : (hechas / total).clamp(0.0, 1.0),
            minHeight: 8,
            backgroundColor: ColoresEcoing.borde,
            color: c,
          ),
        ),
      ],
    );
  }
}

/// Fila de contadores por estado. Es la vista de un golpe del trabajo pendiente.
class ResumenEstados extends StatelessWidget {
  final int completas;
  final int pendientes;
  final int conError;
  final int sinIniciar;

  const ResumenEstados({
    super.key,
    this.completas = 0,
    this.pendientes = 0,
    this.conError = 0,
    this.sinIniciar = 0,
  });

  @override
  Widget build(BuildContext context) {
    final items = <(IconData, int, Color, String)>[
      if (completas > 0)
        (Icons.cloud_done, completas, ColoresEcoing.exito, 'sincronizadas'),
      if (pendientes > 0)
        (Icons.save_alt, pendientes, ColoresEcoing.pendiente, 'por enviar'),
      if (conError > 0)
        (Icons.error_outline, conError, ColoresEcoing.error, 'con error'),
      if (sinIniciar > 0)
        (
          Icons.radio_button_unchecked,
          sinIniciar,
          ColoresEcoing.textoTenue,
          'sin iniciar',
        ),
    ];
    if (items.isEmpty) return const SizedBox.shrink();

    return Wrap(
      spacing: Espacio.m,
      runSpacing: Espacio.xs,
      children: items
          .map(
            (i) => Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(i.$1, size: 16, color: i.$3),
                const SizedBox(width: Espacio.xs),
                Text(
                  '${i.$2} ${i.$4}',
                  style: TextStyle(
                    fontSize: 13,
                    color: i.$3,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          )
          .toList(),
    );
  }
}

/// Superposición de carga que bloquea la pantalla, con mensaje y progreso.
///
/// A diferencia de la anterior, **siempre** tiene forma de salir: o el progreso
/// llega a su fin, o el botón de cancelar está disponible.
class CapaCargando extends StatelessWidget {
  final String mensaje;
  final double? progreso;
  final VoidCallback? alCancelar;

  const CapaCargando({
    super.key,
    required this.mensaje,
    this.progreso,
    this.alCancelar,
  });

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        ModalBarrier(
          dismissible: false,
          color: Colors.black.withValues(alpha: 0.45),
        ),
        Center(
          child: Container(
            width: 300,
            padding: const EdgeInsets.all(Espacio.xl),
            decoration: BoxDecoration(
              color: ColoresEcoing.superficie,
              borderRadius: BorderRadius.circular(Espacio.radioGrande),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const CircularProgressIndicator(),
                const SizedBox(height: Espacio.l),
                Text(
                  mensaje,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 15.5, height: 1.4),
                ),
                if (progreso != null) ...[
                  const SizedBox(height: Espacio.m),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(Espacio.xs),
                    child: LinearProgressIndicator(
                      value: progreso,
                      minHeight: 6,
                      backgroundColor: ColoresEcoing.borde,
                    ),
                  ),
                ],
                if (alCancelar != null) ...[
                  const SizedBox(height: Espacio.l),
                  TextButton(
                    onPressed: alCancelar,
                    child: const Text('Detener'),
                  ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}
