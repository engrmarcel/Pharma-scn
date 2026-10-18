import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/api.dart';
import '../state/app_state.dart';
import 'theme.dart';
import 'widgets.dart';

/// Activity report for the visible team (own figures for a representative,
/// the supervised territories for a supervisor). Online only: figures come
/// straight from the server and are never computed from device data.
class ReportScreen extends StatefulWidget {
  const ReportScreen({super.key});

  @override
  State<ReportScreen> createState() => _ReportScreenState();
}

class _ReportScreenState extends State<ReportScreen> {
  late Future<Map<String, dynamic>> _report;
  final _from = DateTime(DateTime.now().year, DateTime.now().month);

  @override
  void initState() {
    super.initState();
    _report = _load();
  }

  Future<Map<String, dynamic>> _load() async {
    final api = context.read<AppState>().repository!.api;
    final res = await api.get('field-force/reports/activity', query: {'from': DateFormat('yyyy-MM-dd').format(_from)});
    return Map<String, dynamic>.from(res['data'] as Map);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Activity this month')),
        body: FutureBuilder<Map<String, dynamic>>(
          future: _report,
          builder: (context, snap) {
            if (snap.hasError) {
              final e = snap.error;
              return EmptyState(icon: Icons.cloud_off, message: e is OfflineException ? 'The report needs a connection.' : '$e');
            }
            if (!snap.hasData) return const Center(child: CircularProgressIndicator());
            final rows = (snap.data!['representatives'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
            if (rows.isEmpty) return const EmptyState(icon: Icons.insights, message: 'No activity recorded this month.');
            return ListView(children: [
              for (final r in rows)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(r['name']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                      const SizedBox(height: 10),
                      Wrap(spacing: 8, runSpacing: 8, children: [
                        _metric('Visits', '${r['completed']}/${r['visits']}', 'completed'),
                        _metric('Verified', '${r['verified_check_ins']}', 'check-ins'),
                        _metric('Unverified', '${r['unverified_check_ins']}', 'check-ins'),
                        _metric('Orders', '${r['orders_accepted']}/${r['orders_submitted']}', 'accepted'),
                        _metric('Rejected', '${r['orders_rejected']}', 'orders'),
                        _metric('Follow-ups', r['follow_up_compliance_percent'] == null ? '-' : '${r['follow_up_compliance_percent']}%', 'on time'),
                      ]),
                    ]),
                  ),
                ),
              Padding(padding: const EdgeInsets.all(16), child: Text(snap.data!['note']?.toString() ?? '', style: const TextStyle(fontSize: 12, color: Colors.blueGrey))),
            ]);
          },
        ),
      );

  Widget _metric(String label, String value, String caption) => Container(
        width: 100,
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: AppColors.royal.withValues(alpha: 0.06), borderRadius: BorderRadius.circular(8)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: const TextStyle(fontSize: 11, color: Colors.blueGrey)),
          Text(value, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AppColors.navy)),
          Text(caption, style: const TextStyle(fontSize: 10, color: Colors.blueGrey)),
        ]),
      );
}
