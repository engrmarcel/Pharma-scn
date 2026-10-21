/// Exact decimal with 4 places, rounding half-up like the server
/// (App\Support\Money and SalesPricing::amounts), so totals computed on the
/// till match the totals the ERP computes when the sale syncs.
class D4 implements Comparable<D4> {
  const D4._(this.units);

  /// Value × 10 000.
  final BigInt units;

  static final _scale = BigInt.from(10000);
  static final zero = D4._(BigInt.zero);

  factory D4.parse(String raw) {
    var s = raw.trim();
    final negative = s.startsWith('-');
    if (negative || s.startsWith('+')) s = s.substring(1);
    final parts = s.split('.');
    final whole = BigInt.parse(parts[0].isEmpty ? '0' : parts[0]);
    final fraction = parts.length > 1 ? parts[1] : '';
    var units = whole * _scale + BigInt.parse(fraction.padRight(4, '0').substring(0, 4).isEmpty ? '0' : fraction.padRight(4, '0').substring(0, 4));
    if (fraction.length > 4 && fraction.codeUnitAt(4) >= 0x35) units += BigInt.one;
    return D4._(negative ? -units : units);
  }

  factory D4.of(num value) => D4.parse(value.toString());

  static BigInt _roundDiv(BigInt a, BigInt b) {
    final q = (a.abs() * BigInt.two + b) ~/ (b * BigInt.two);
    return a.isNegative ? -q : q;
  }

  D4 operator +(D4 o) => D4._(units + o.units);

  D4 operator -(D4 o) => D4._(units - o.units);

  D4 operator *(D4 o) => D4._(_roundDiv(units * o.units, _scale));

  /// this × rate / 100 (rate given in percent).
  D4 percent(D4 rate) => D4._(_roundDiv(units * rate.units, _scale * BigInt.from(100)));

  bool get isZero => units == BigInt.zero;

  bool get isNegative => units.isNegative;

  @override
  int compareTo(D4 other) => units.compareTo(other.units);

  bool operator >(D4 o) => units > o.units;

  bool operator <(D4 o) => units < o.units;

  bool operator >=(D4 o) => units >= o.units;

  @override
  bool operator ==(Object other) => other is D4 && other.units == units;

  @override
  int get hashCode => units.hashCode;

  /// Four decimals, as the API sends them ("6000.0000").
  @override
  String toString() {
    final abs = units.abs();
    final whole = abs ~/ _scale;
    final frac = (abs % _scale).toString().padLeft(4, '0');
    return '${units.isNegative ? '-' : ''}$whole.$frac';
  }

  /// Two decimals with thousands separators, for screens and receipts.
  String money() {
    final cents = _roundDiv(units, BigInt.from(100));
    final abs = cents.abs();
    final whole = (abs ~/ BigInt.from(100)).toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
    return '${cents.isNegative ? '-' : ''}$whole.${(abs % BigInt.from(100)).toString().padLeft(2, '0')}';
  }
}

/// Line amounts exactly as SalesPricing::amounts computes them.
class LineAmounts {
  LineAmounts({required this.subtotal, required this.discount, required this.tax, required this.total});

  final D4 subtotal;
  final D4 discount;
  final D4 tax;
  final D4 total;

  factory LineAmounts.of({required D4 quantity, required D4 unitPrice, required D4 discountPercent, required D4 taxRate}) {
    final subtotal = quantity * unitPrice;
    final discount = subtotal.percent(discountPercent);
    final net = subtotal - discount;
    final tax = net.percent(taxRate);
    return LineAmounts(subtotal: subtotal, discount: discount, tax: tax, total: net + tax);
  }
}
