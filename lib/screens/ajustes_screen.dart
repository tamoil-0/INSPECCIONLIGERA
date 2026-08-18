import 'package:flutter/material.dart';

import '../core/entorno.dart';
import '../core/preferencias_app.dart';
import '../presentacion/comunes/componentes.dart';
import '../presentacion/diseno/tema_ecoing.dart';
import '../repositorios/fotos_repositorio.dart';
import '../servicios/imagenes/optimizador_imagenes.dart';
import '../servicios/imagenes/perfil_dispositivo.dart';

/// Preferencias operativas del aplicativo.
///
/// Cada cambio se guarda al instante. Las opciones de imagen se aplican a las
/// capturas siguientes; nunca se reprocesa ni se borra una foto existente sin
/// una decisión explícita del inspector.
class AjustesScreen extends StatefulWidget {
  const AjustesScreen({super.key});

  @override
  State<AjustesScreen> createState() => _AjustesScreenState();
}

class _AjustesScreenState extends State<AjustesScreen> {
  final _fotos = FotosRepositorio();

  PreferenciasApp? _prefs;
  PerfilDispositivo? _perfilForzado;
  PoliticaRetencion _retencion = PoliticaRetencion.soloOptimizada;
  bool _soloWifi = false;
  bool _modoOffline = false;
  bool _cargando = true;
  bool _limpiando = false;
  int _espacio = 0;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    final prefs = await PreferenciasApp.instancia();
    final espacio = await _fotos.almacen.espacioOcupado();
    if (!mounted) return;
    setState(() {
      _prefs = prefs;
      _perfilForzado = prefs.perfilEsAutomatico ? null : prefs.perfilImagenes;
      _retencion = prefs.politicaRetencion;
      _soloWifi = prefs.sincronizarSoloWifi;
      _modoOffline = prefs.modoOffline;
      _espacio = espacio;
      _cargando = false;
    });
  }

  Future<void> _liberarOriginales() async {
    final confirmar = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('¿Liberar originales ya enviados?'),
        content: const Text(
          'Solo se borrarán los archivos originales de fotografías que el '
          'servidor ya confirmó. Se conservarán la versión optimizada enviada '
          'y su miniatura. Las fotos pendientes no se tocarán.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Conservar'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Liberar espacio'),
          ),
        ],
      ),
    );
    if (confirmar != true || !mounted) return;

    setState(() => _limpiando = true);
    final resultado = await _fotos.liberarOriginalesSincronizados();
    final espacio = await _fotos.almacen.espacioOcupado();
    if (!mounted) return;
    setState(() {
      _limpiando = false;
      _espacio = espacio;
    });
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        backgroundColor: ColoresEcoing.exito,
        content: Text(
          resultado.archivos == 0
              ? 'No había originales sincronizados para liberar.'
              : 'Se liberaron ${resultado.archivos} original(es): '
                    '${_tamano(resultado.bytes)}.',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Ajustes')),
      body: _cargando
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.only(bottom: Espacio.xxl),
              children: [
                _titulo('Conexión'),
                SwitchListTile.adaptive(
                  title: const Text('Modo offline'),
                  subtitle: const Text(
                    'No intenta enviar ni actualizar datos desde el servidor.',
                  ),
                  value: _modoOffline,
                  onChanged: (valor) async {
                    await _prefs!.setModoOffline(valor);
                    if (mounted) setState(() => _modoOffline = valor);
                  },
                ),
                SwitchListTile.adaptive(
                  title: const Text('Fotografías solo con Wi-Fi'),
                  subtitle: const Text(
                    'Los formularios, que pesan muy poco, pueden enviarse con '
                    'datos móviles.',
                  ),
                  value: _soloWifi,
                  onChanged: (valor) async {
                    await _prefs!.setSincronizarSoloWifi(valor);
                    if (mounted) setState(() => _soloWifi = valor);
                  },
                ),
                const Divider(),
                _titulo('Calidad de imagen'),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Espacio.l),
                  child: DropdownButtonFormField<PerfilDispositivo?>(
                    initialValue: _perfilForzado,
                    decoration: const InputDecoration(
                      labelText: 'Perfil de procesamiento',
                      helperText: 'Se aplica a las próximas fotografías.',
                    ),
                    items: const [
                      DropdownMenuItem(value: null, child: Text('Automático')),
                      DropdownMenuItem(
                        value: PerfilDispositivo.baja,
                        child: Text('Gama baja · 2560 px'),
                      ),
                      DropdownMenuItem(
                        value: PerfilDispositivo.media,
                        child: Text('Gama media · 3072 px'),
                      ),
                      DropdownMenuItem(
                        value: PerfilDispositivo.alta,
                        child: Text('Gama alta · 3072 px'),
                      ),
                    ],
                    onChanged: (perfil) async {
                      await _prefs!.setPerfilImagenes(perfil);
                      if (mounted) setState(() => _perfilForzado = perfil);
                    },
                  ),
                ),
                const SizedBox(height: Espacio.m),
                _retencionTile(
                  PoliticaRetencion.soloOptimizada,
                  'Solo optimizada',
                  'Recomendado: buena calidad y mucho menos espacio.',
                ),
                _retencionTile(
                  PoliticaRetencion.conservarOriginal,
                  'Conservar original',
                  'Máxima fidelidad; puede ocupar cientos de MB por estructura.',
                ),
                _retencionTile(
                  PoliticaRetencion.liberarTrasSincronizar,
                  'Original hasta sincronizar',
                  'Conserva ambas versiones hasta que el servidor confirme.',
                ),
                const Divider(),
                _titulo('Almacenamiento'),
                ListTile(
                  leading: const Icon(Icons.storage, color: ColoresEcoing.azul),
                  title: Text('${_tamano(_espacio)} usados por fotografías'),
                  subtitle: const Text(
                    'Nunca se borran fotos pendientes automáticamente.',
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Espacio.l),
                  child: OutlinedButton.icon(
                    onPressed: _limpiando ? null : _liberarOriginales,
                    icon: _limpiando
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.cleaning_services_outlined),
                    label: const Text('Liberar originales ya sincronizados'),
                  ),
                ),
                const SizedBox(height: Espacio.l),
                const Aviso(
                  icono: Icons.info_outline,
                  texto:
                      'Para exportar un respaldo antes de limpiar, abre '
                      'Sincronización, entra a una línea y usa el botón Exportar.',
                ),
                _titulo('Aplicación'),
                const ListTile(
                  leading: Icon(Icons.info_outline),
                  title: Text('Ecoing IP 26 1.2.2 (5)'),
                  subtitle: Text(
                    'Entorno: ${Entorno.nombre}\nAPI: ${Entorno.apiBaseUrl}',
                  ),
                ),
              ],
            ),
    );
  }

  Widget _retencionTile(
    PoliticaRetencion valor,
    String titulo,
    String detalle,
  ) {
    final seleccionado = _retencion == valor;
    return ListTile(
      leading: Icon(
        seleccionado
            ? Icons.radio_button_checked
            : Icons.radio_button_unchecked,
        color: seleccionado ? ColoresEcoing.azul : ColoresEcoing.textoTenue,
      ),
      title: Text(titulo),
      subtitle: Text(detalle),
      selected: seleccionado,
      onTap: () async {
        await _prefs!.setPoliticaRetencion(valor);
        if (mounted) setState(() => _retencion = valor);
      },
    );
  }

  Widget _titulo(String texto) => Padding(
    padding: const EdgeInsets.fromLTRB(
      Espacio.l,
      Espacio.xl,
      Espacio.l,
      Espacio.s,
    ),
    child: Text(
      texto,
      style: const TextStyle(
        color: ColoresEcoing.azul,
        fontSize: 15,
        fontWeight: FontWeight.bold,
      ),
    ),
  );

  String _tamano(int bytes) {
    if (bytes >= 1024 * 1024 * 1024) {
      return '${(bytes / (1024 * 1024 * 1024)).toStringAsFixed(2)} GB';
    }
    if (bytes >= 1024 * 1024) {
      return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
    }
    return '${(bytes / 1024).toStringAsFixed(0)} KB';
  }
}
