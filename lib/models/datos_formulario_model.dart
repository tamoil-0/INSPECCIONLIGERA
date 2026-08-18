import 'package:flutter/material.dart';
import 'dart:async';

class EnvioExitosoDialog extends StatefulWidget {
  final VoidCallback onComplete;

  const EnvioExitosoDialog({Key? key, required this.onComplete}) : super(key: key);

  @override
  State<EnvioExitosoDialog> createState() => _EnvioExitosoDialogState();
}

class _EnvioExitosoDialogState extends State<EnvioExitosoDialog> {
  int _countdown = 3;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _startCountdown();
  }

  void _startCountdown() {
    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      setState(() {
        _countdown--;
      });

      if (_countdown <= 0) {
        timer.cancel();
        widget.onComplete();
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.check_circle, color: Colors.green, size: 64),
          const SizedBox(height: 16),
          const Text(
            '¡Envío Exitoso!',
            style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 8),
          const Text('Los datos se guardaron correctamente'),
          const SizedBox(height: 16),
          const CircularProgressIndicator(valueColor: AlwaysStoppedAnimation<Color>(Colors.green)),
          const SizedBox(height: 8),
          Text('Redirigiendo en $_countdown segundos...'),
        ],
      ),
    );
  }
}
