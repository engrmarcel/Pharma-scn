import 'dart:convert';

/// A planned call for the day, from the representative's submitted or approved visit plan.
class PlanCall {
  PlanCall({required this.id, required this.customerId, required this.customerName, required this.sequence, required this.status, this.city, this.objective, this.planStatus});

  final String id;
  final String customerId;
  final String customerName;
  final String? city;
  final int sequence;
  final String? objective;
  final String status; // planned | visited | missed
  final String? planStatus; // submitted | approved

  factory PlanCall.fromRow(Map<String, Object?> r) => PlanCall(
        id: r['id'] as String,
        customerId: r['customer_id'] as String,
        customerName: r['customer_name'] as String,
        city: r['city'] as String?,
        sequence: (r['sequence'] as num?)?.toInt() ?? 0,
        objective: r['objective'] as String?,
        status: r['status'] as String,
        planStatus: r['plan_status'] as String?,
      );
}

/// An approved key message, cached so detailing can be recorded offline.
class DetailingMessage {
  DetailingMessage({required this.id, required this.productId, required this.title, required this.keyMessage});

  final String id;
  final String productId;
  final String title;
  final String keyMessage;

  factory DetailingMessage.fromRow(Map<String, Object?> r) =>
      DetailingMessage(id: r['id'] as String, productId: r['product_id'] as String, title: r['title'] as String, keyMessage: r['key_message'] as String);
}

/// Detailing recorded on this device for one product during a visit.
class LocalDetailing {
  LocalDetailing({required this.clientReference, required this.visitReference, required this.productId, required this.messageIds, required this.reception, required this.syncState, this.productName, this.serverId, this.lastError});

  final String clientReference;
  final String visitReference;
  final String productId;
  final String? productName;
  final List<String> messageIds;
  final String reception;
  final String syncState;
  final String? serverId;
  final String? lastError;

  factory LocalDetailing.fromRow(Map<String, Object?> r) => LocalDetailing(
        clientReference: r['client_reference'] as String,
        visitReference: r['visit_reference'] as String,
        productId: r['product_id'] as String,
        productName: r['product_name'] as String?,
        messageIds: (jsonDecode(r['message_ids'] as String) as List).cast<String>(),
        reception: r['reception'] as String,
        syncState: r['sync_state'] as String,
        serverId: r['server_id'] as String?,
        lastError: r['last_error'] as String?,
      );
}

/// Customer feedback recorded on this device. A possible adverse event carries the
/// pharmacovigilance notice, shown at once and again when the server confirms it.
class LocalFeedback {
  LocalFeedback({required this.clientReference, required this.customerId, required this.category, required this.description, required this.possibleAdverseEvent, required this.syncState, this.serverId, this.lastError});

  final String clientReference;
  final String customerId;
  final String category;
  final String description;
  final bool possibleAdverseEvent;
  final String syncState;
  final String? serverId;
  final String? lastError;

  factory LocalFeedback.fromRow(Map<String, Object?> r) => LocalFeedback(
        clientReference: r['client_reference'] as String,
        customerId: r['customer_id'] as String,
        category: r['category'] as String,
        description: r['description'] as String,
        possibleAdverseEvent: (r['possible_adverse_event'] as num) == 1,
        syncState: r['sync_state'] as String,
        serverId: r['server_id'] as String?,
        lastError: r['last_error'] as String?,
      );
}

const feedbackCategories = {
  'complaint': 'Complaint',
  'product_quality': 'Product quality',
  'service': 'Service',
  'delivery': 'Delivery',
  'pricing': 'Pricing',
  'praise': 'Praise',
  'suggestion': 'Suggestion',
  'other': 'Other',
};

const receptions = {'positive': 'Positive', 'neutral': 'Neutral', 'negative': 'Negative', 'not_assessed': 'Not assessed'};

const pharmacovigilanceNotice =
    'Possible adverse event: report it to your pharmacovigilance or drug-safety contact now, following your organization\'s procedure. Do not record patient details here.';
