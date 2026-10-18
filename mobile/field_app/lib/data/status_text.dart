import 'models.dart';

enum StatusTone { neutral, pending, success, warning, danger }

class StatusView {
  const StatusView(this.label, this.tone, [this.detail]);

  final String label;
  final StatusTone tone;
  final String? detail;
}

/// Spec §12.4: a captured order must never look like an accepted one.
/// Only the server's answer can move an order to "Accepted by ERP", and
/// only a confirmed sales order means stock is reserved.
StatusView describeOrder(LocalOrder o) {
  if (o.syncState == 'failed') {
    return StatusView('Not sent: needs attention', StatusTone.danger, o.lastError);
  }
  if (o.localState == 'draft') {
    return const StatusView('Draft on this device', StatusTone.neutral, 'Not sent. Nothing is reserved.');
  }
  if (o.localState == 'queued' || o.serverStatus == null) {
    return const StatusView('Waiting to sync', StatusTone.pending, 'Saved on this device. It will be sent when there is a connection. Nothing is reserved yet.');
  }

  final so = o.salesOrder;
  final soLabel = so == null ? '' : ' · ${so['number']}';
  return switch (o.serverStatus) {
    'accepted' => o.stockReserved
        ? StatusView('Accepted by ERP$soLabel', StatusTone.success, 'Sales order confirmed; stock is reserved.')
        : StatusView('Accepted by ERP$soLabel', StatusTone.warning, 'Sales order awaiting approval (${so?['status'] ?? 'pending'}); stock is not reserved yet.'),
    'rejected' => StatusView('Rejected by ERP', StatusTone.danger, _firstReason(o.rejection)),
    'submitted' => const StatusView('Submitted: awaiting validation', StatusTone.pending, 'The ERP has not decided yet. Nothing is reserved.'),
    'cancelled' => const StatusView('Cancelled', StatusTone.neutral),
    'draft' => const StatusView('Saved on server as draft', StatusTone.neutral, 'Not submitted. Nothing is reserved.'),
    _ => StatusView(o.serverStatus ?? 'Unknown', StatusTone.neutral),
  };
}

String? _firstReason(List<dynamic>? reasons) {
  if (reasons == null || reasons.isEmpty) return null;
  final first = reasons.first;
  return first is Map ? first['message']?.toString() : first.toString();
}

/// Location verification as decided by the server (never by the device).
StatusView describeVerification(String? verification, {required bool synced}) {
  if (verification == null) {
    return StatusView(synced ? 'Not checked' : 'Awaiting server check', StatusTone.pending);
  }
  return switch (verification) {
    'verified' => const StatusView('Location verified', StatusTone.success),
    'outside_radius' => const StatusView('Outside the customer radius', StatusTone.warning),
    'low_accuracy' => const StatusView('GPS accuracy too low', StatusTone.warning),
    'mock_location' => const StatusView('Simulated location reported', StatusTone.danger),
    'no_location' => const StatusView('No location recorded', StatusTone.warning),
    'customer_location_unknown' => const StatusView('Customer location not on file', StatusTone.neutral),
    _ => StatusView(verification, StatusTone.neutral),
  };
}

StatusView describeVisit(LocalVisit v) {
  if (v.syncState == 'failed') return StatusView('Not accepted by server', StatusTone.danger, v.lastError);
  final state = switch (v.status) {
    'in_progress' => 'In progress',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    _ => v.status,
  };
  return StatusView(v.syncState == 'synced' ? state : '$state · waiting to sync', v.syncState == 'synced' ? StatusTone.neutral : StatusTone.pending);
}
