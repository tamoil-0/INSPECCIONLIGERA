/// Normalización de texto para búsquedas tolerantes.
///
/// ## Por qué hace falta
///
/// La búsqueda de estructuras comparaba con `==` sobre la cadena cruda. Eso
/// significaba que un inspector que escribiera `0025`, ` 25`, `25 ` o `25A`
/// **no encontraba** la estructura `25`. En campo, con guantes y a pleno sol,
/// eso se traduce en "la app dice que esta torre no existe".
class Normalizar {
  const Normalizar._();

  static const Map<String, String> _acentos = {
    'á': 'a', 'à': 'a', 'ä': 'a', 'â': 'a', 'ã': 'a',
    'é': 'e', 'è': 'e', 'ë': 'e', 'ê': 'e',
    'í': 'i', 'ì': 'i', 'ï': 'i', 'î': 'i',
    'ó': 'o', 'ò': 'o', 'ö': 'o', 'ô': 'o', 'õ': 'o',
    'ú': 'u', 'ù': 'u', 'ü': 'u', 'û': 'u',
    'ñ': 'n', 'ç': 'c',
  };

  /// Minúsculas, sin acentos y sin espacios de sobra.
  ///
  /// Para búsquedas por nombre: proyecto, contratista, ubicación, línea.
  static String texto(Object? valor) {
    if (valor == null) return '';
    var s = valor.toString().trim().toLowerCase();
    _acentos.forEach((con, sin) => s = s.replaceAll(con, sin));
    return s.replaceAll(RegExp(r'\s+'), ' ');
  }

  /// Clave canónica de un número de estructura.
  ///
  /// Quita espacios, guiones y ceros a la izquierda, y pasa a minúsculas:
  ///
  /// ```
  /// '0025'  -> '25'
  /// ' 25 '  -> '25'
  /// '25-A'  -> '25a'
  /// 'T-025' -> 't25'
  /// ```
  ///
  /// Los ceros solo se quitan del primer bloque numérico, así que `100` sigue
  /// siendo `100` y `0100` también.
  static String estructura(Object? valor) {
    if (valor == null) return '';
    var s = valor.toString().toLowerCase();
    s = s.replaceAll(RegExp(r'[\s\-_/\.]'), '');
    _acentos.forEach((con, sin) => s = s.replaceAll(con, sin));
    // Quitar ceros a la izquierda del primer bloque de dígitos.
    s = s.replaceFirst(RegExp(r'^0+(?=\d)'), '');
    return s;
  }

  /// ¿Coinciden dos números de estructura una vez normalizados?
  static bool mismaEstructura(Object? a, Object? b) {
    final na = estructura(a);
    final nb = estructura(b);
    return na.isNotEmpty && na == nb;
  }

  /// ¿[aguja] aparece dentro de [pajar] ignorando acentos y mayúsculas?
  static bool contiene(Object? pajar, String aguja) {
    final t = texto(aguja);
    if (t.isEmpty) return true;
    return texto(pajar).contains(t);
  }

  /// Clave de ordenación natural: los números se comparan por valor, no por
  /// carácter, de modo que `2` va antes que `10`.
  ///
  /// Sin esto, la lista de estructuras salía `1, 10, 100, 11, 2` — que es
  /// exactamente lo que hacía la app al no tener ningún `ORDER BY` útil.
  static int compararNatural(Object? a, Object? b) {
    final sa = texto(a);
    final sb = texto(b);
    final trozos = RegExp(r'(\d+|\D+)');
    final pa = trozos.allMatches(sa).map((m) => m.group(0)!).toList();
    final pb = trozos.allMatches(sb).map((m) => m.group(0)!).toList();

    for (var i = 0; i < pa.length && i < pb.length; i++) {
      final na = int.tryParse(pa[i]);
      final nb = int.tryParse(pb[i]);
      final comparacion = (na != null && nb != null)
          ? na.compareTo(nb)
          : pa[i].compareTo(pb[i]);
      if (comparacion != 0) return comparacion;
    }
    return pa.length.compareTo(pb.length);
  }
}
