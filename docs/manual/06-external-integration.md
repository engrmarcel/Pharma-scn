# Connecting an external system

How to plug an ERP, warehouse system, e-commerce site, BI tool or partner application into the platform: which method to use, step-by-step setup, message formats, security, and a go-live checklist.

## 1. Choose the method

| You want to… | Method | Authenticates with |
|---|---|---|
| Send master data and stock from your ERP (products, batches, stock positions) | **Integration gateway**, inbound messages or files | Connector key (`ck_…`), optional HMAC signature |
| Be told when things happen (stock received, order confirmed, invoice issued, recall notice…) | **Webhooks** | You verify our HMAC signature |
| Read or create business documents on demand (orders, invoices, stock, scans, analytics) | **REST API as a client** | A dedicated user account's bearer token + `X-Organization-Id` |
| Exchange files on a schedule | Gateway **file upload** or **SFTP** | Connector key / SFTP credentials |

Most integrations combine them: the ERP pushes master data and stock through the gateway, subscribes to webhooks for the events it needs, and uses the REST API for the occasional lookup.

![External system integration](diagrams/08-integration.svg)

## 2. Prerequisites

1. The organization is verified and has the **integrations** module in its plan.
2. A member with the **Integration administrator** role (or owner/administrator) performs the setup.
3. Agree the interface with the system owner: product and version, deployment, what the system can send (REST from middleware, files, SAP IDoc), and who authorizes access. Record it in the connector's `interface_notes`.

## 3. Inbound data through the integration gateway

### Step 1 — Register the connector

```bash
curl -s -X POST $BASE/integration/connectors -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Organization-Id: $ORG" \
  -H "Content-Type: application/json" -d '{
    "code": "ERP1", "name": "Head office ERP", "system_type": "sap_s4hana", "system_version": "2023",
    "deployment_model": "on_premise", "inbound_transport": "rest_push",
    "interface_notes": "Integration Suite pushes JSON; owner: IT operations"}'
```

`system_type`: `sap_s4hana`, `sap_ecc`, `sap_business_one`, `oracle_ebs`, `oracle_fusion`, `dynamics_365`, `odoo`, `sage`, `custom`, `other`. `inbound_transport`: `rest_push`, `file_upload`, `sftp`, `sap_idoc`.

### Step 2 — Secure it

- Issue the inbound key: `POST integration/connectors/{id}/key`. The key (`ck_…`) is shown **once**; store it in the sending system's secret store.
- Recommended: require signed requests with `POST integration/connectors/{id}/signing {"enabled": true}` (the signing secret is returned once).
- Credentials the platform needs to call out (SFTP) go to `PUT integration/connectors/{id}/credentials`; they are write-only and encrypted.

### Step 3 — Map your fields

The platform speaks a **canonical model** (`GET integration/canonical-model` lists every field). Records that can be applied today:

| Entity | Required fields | Useful optional fields |
|---|---|---|
| `product` | `external_id`, `name` | `sku`, `gtin`, `registration_number`, `registration_authority`, `generic_name`, `brand_name`, `strength`, `dosage_form`, `purchase_cost`, `selling_price`, `currency_code`, `reorder_level`, `status` |
| `batch` | `product_external_id` or `gtin`, `batch_number`, `expiry_date` | `manufacturing_date`, `quality_status` |
| `inventory_snapshot` | `warehouse_code`, `product_external_id` or `gtin`, `quantity`, `as_of` | `batch_number`, `package_level_code` |

If your records use your own field names, map them once per entity:

```bash
curl -s -X PUT $BASE/integration/connectors/$CONNECTOR/mappings/product -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Organization-Id: $ORG" -H "Content-Type: application/json" -d '{"mappings": [
    {"target_field": "external_id", "source_path": "MATNR", "transform": "strip_leading_zeros"},
    {"target_field": "name", "source_path": "MAKTX", "transform": "trim"},
    {"target_field": "gtin", "source_path": "EAN11"},
    {"target_field": "purchase_cost", "source_path": "VERPR", "transform": "decimal", "options": {"decimal_separator": ",", "thousands_separator": "."}},
    {"target_field": "currency_code", "source_path": "WAERS", "transform": "upper"}]}'
```

Transforms: `trim`, `upper`, `lower`, `strip_leading_zeros`, `date` (`options.format`, e.g. `Ymd`), `decimal`, `map` (`options.values`, `options.fallback`). A mapping can also set a fixed `default_value`. Ready-made templates (for example SAP material, batch and stock extracts, IDoc MATMAS/BATMAS): `GET integration/mapping-templates`, apply with `POST …/mappings/{entity}/template`. Test with `POST …/mappings/{entity}/preview` (writes nothing).

### Step 4 — Activate and send

`POST integration/connectors/{id}/activate`, then the external system sends up to 500 records per call:

```http
POST /api/v1/integration/inbound/messages
Authorization: Bearer ck_live_xxxxxxxx
Content-Type: application/json
X-Timestamp: 1761123456                     (only when signing is on)
X-Signature: sha256=5f0c…                   (only when signing is on)

{"messages": [
  {"message_id": "MAT-100234-v7", "entity_type": "product",
   "record": {"MATNR": "000000000000100234", "MAKTX": "AMOXICILLIN 500MG CAPS 2X10", "EAN11": "6901234000016", "VERPR": "1.250,50", "WAERS": "NGN"}},
  {"message_id": "BAT-100234-AMX2026A", "entity_type": "batch",
   "record": {"product_external_id": "100234", "batch_number": "AMX2026A", "expiry_date": "2028-12-31"}}
]}
```

Rules:
- `message_id` (1–128 characters: letters, digits, `. _ : / -`) identifies the record. **Resend with the same id** after a timeout: an identical record is acknowledged as a duplicate and never applied twice; a different record under a used id is rejected (`message_id_reused`).
- Send products before the batches and stock that refer to them.
- The response is `202 Accepted` with one acknowledgement per record: `accepted` (with `duplicate: true` for a resend) or `rejected` with an error code. Acceptance means *stored safely*; the business outcome follows.

Outcome of one record: `GET integration/inbound/messages/{message_id}`. Status `received` or `processing` (in progress), `processed`, `quarantined` (needs a fix, with the error code), `failed` (gave up after retries) or `ignored` (dismissed by a person). Connectivity check: `GET integration/inbound/ping`.

### Signing a request (when signing is on)

The signature is `sha256=` + hex HMAC-SHA256 of `"<unix timestamp>.<raw body>"` with the signing secret; the timestamp must be within 5 minutes.

```python
import hashlib, hmac, json, time, requests
body = json.dumps({"messages": messages}, separators=(",", ":"))
ts = str(int(time.time()))
sig = "sha256=" + hmac.new(SIGNING_SECRET.encode(), f"{ts}.{body}".encode(), hashlib.sha256).hexdigest()
requests.post(f"{BASE}/integration/inbound/messages", data=body, headers={
    "Authorization": f"Bearer {CONNECTOR_KEY}", "Content-Type": "application/json", "X-Timestamp": ts, "X-Signature": sig})
```

### Step 5 — Handle quarantine and reconcile

- `GET integration/messages?status=quarantined` lists records that could not be applied (unknown product, unknown warehouse, invalid GTIN, …). Fix the data or mapping and `POST integration/messages/{id}/retry`, link it to the right product or warehouse with `…/map`, or `…/ignore` with a reason.
- `GET integration/health` shows each connector's state, backlog and errors.
- `GET integration/connectors/{id}/reconciliation/inventory` compares your stock with the platform's; `…/reconciliation/references` lists unmatched products and warehouses.

### Files, SFTP and SAP IDoc

- Upload a file: `POST integration/connectors/{id}/files` (multipart) or push it raw: `POST integration/inbound/files?entity_type=product&format=csv`. CSV (header row), JSON or XML; identical file content is imported once.
- SFTP: configure `settings.sftp` (host, fingerprint, folders, file patterns) and credentials; the platform polls every five minutes and can also export inventory and catalogue CSVs on a schedule.
- SAP IDoc XML (MATMAS, BATMAS) over HTTP from SAP middleware: `POST integration/inbound/idoc`; the IDoc number is the message id.
- Exports on demand: `GET integration/connectors/{id}/exports/inventory` or `/catalogue` (CSV with your own product keys).

## 4. Receiving events with webhooks

### Subscribe

```bash
curl -s -X POST $BASE/integration/webhooks -H "Authorization: Bearer $ADMIN_TOKEN" -H "X-Organization-Id: $ORG" \
  -H "Content-Type: application/json" -d '{"name": "ERP events", "url": "https://erp.example.com/hooks/pharma",
  "event_types": ["inventory.stock_received", "sales.order_confirmed", "recall.notice_issued"], "connector_id": "'$CONNECTOR'"}'
```

The response contains the **signing secret once**. Linking a connector adds your own product keys (`product_external_id`) to payloads. Send a test event: `POST integration/webhooks/{id}/test`.

Event types: `inventory.stock_received`, `inventory.stock_transferred`, `inventory.adjusted`, `inventory.batch_created`, `procurement.purchase_order_created`, `procurement.purchase_order_approved`, `sales.order_created`, `sales.order_confirmed`, `sales.invoice_issued`, `finance.payment_confirmed`, `organization.verification_changed`, `recall.notice_issued`, `logistics.temperature_excursion`, `webhook.test` (descriptions: `GET integration/webhooks/event-types`).

### What you receive

```http
POST https://erp.example.com/hooks/pharma
X-Webhook-Id: 01JBX…            (event id: deduplicate on it)
X-Webhook-Event: sales.order_confirmed
X-Webhook-Delivery: 4182
X-Webhook-Attempt: 1
X-Webhook-Timestamp: 1761123456
X-Webhook-Signature: sha256=9a1e…

{"id": "01JBX…", "type": "sales.order_confirmed", "occurred_at": "2026-10-22T09:30:00Z", "organization_id": "01J…", "data": {…}}
```

### Verify, deduplicate, answer fast

```php
// PHP (any framework)
$body = file_get_contents('php://input');
$ts = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$expected = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, getenv('PHARMA_WEBHOOK_SECRET'));
if (! hash_equals($expected, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '') || abs(time() - (int) $ts) > 300) {
    http_response_code(401); exit;
}
$event = json_decode($body, true);
if (already_processed($event['id'])) { http_response_code(200); exit; }   // at-least-once delivery
queue_for_processing($event);                                               // do the work asynchronously
http_response_code(200);
```

```javascript
// Node.js (Express): use the raw body, not the parsed JSON
app.post('/hooks/pharma', express.raw({ type: 'application/json' }), (req, res) => {
  const ts = req.get('X-Webhook-Timestamp');
  const expected = 'sha256=' + crypto.createHmac('sha256', process.env.PHARMA_WEBHOOK_SECRET).update(`${ts}.${req.body}`).digest('hex');
  const given = req.get('X-Webhook-Signature') || '';
  if (given.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(given), Buffer.from(expected))) return res.sendStatus(401);
  enqueue(JSON.parse(req.body));          // deduplicate on the event id inside the job
  res.sendStatus(200);
});
```

Answer with any 2xx within 10 seconds. Anything else is retried with backoff for about 18 hours; then the delivery is marked dead and can be redelivered (`POST integration/webhook-deliveries/{id}/redeliver`). A receiver that keeps failing is paused; its events wait and are delivered when it is resumed. Rotate the secret with `POST integration/webhooks/{id}/secret`.

## 5. Using the REST API as a client system

1. Create a dedicated user for the system and invite it to the organization with the narrowest role that works (for example **Auditor** for read-only reporting, **Inventory manager** for stock documents).
2. Obtain a token: `POST auth/tokens {email, password, device_name}`; renew it before its 30-day expiry.
3. Call the API with `Authorization: Bearer …` and `X-Organization-Id: …`, an `Idempotency-Key` on every write, and backoff on `429`/`503`.

See the API guide for conventions, error codes and examples.

## 6. Go-live checklist

- [ ] Interface agreed and recorded in `interface_notes`; owner and support contact known on both sides.
- [ ] Connector key and signing secret stored in a secret manager; signing enabled.
- [ ] Mappings previewed with real samples; templates checked against your system's field definitions.
- [ ] Product master loaded first; quarantine empty or explained.
- [ ] `message_id` stable across resends; retries use the same id.
- [ ] Webhook endpoint verifies signatures, deduplicates on `X-Webhook-Id`, answers within 10 seconds.
- [ ] Inventory reconciliation shows no unexplained differences.
- [ ] Monitoring on `integration/health` and on webhook failures; a named person reviews quarantine daily.

## 7. Troubleshooting

| Code | Meaning | What to do |
|---|---|---|
| `connector_unauthenticated` (401) | Wrong or missing key | Check the `Authorization: Bearer ck_…` header; reissue the key if lost |
| `signature_invalid` (401) | Signature or timestamp wrong | Sign `timestamp + "." + raw body` exactly as sent; check the clock |
| `connector_inactive` (403) | Connector not activated or paused | `POST …/activate` |
| `rate_limited` (429) | Too many requests for this connector | Respect `Retry-After`; batch up to 500 records |
| `message_id_reused` | Same id, different content | Use a new id for a new version of the record |
| `entity_unknown` / `entity_not_ingestible` | Unsupported entity | Use `product`, `batch` or `inventory_snapshot` |
| `product_reference_unknown` (quarantine) | Batch or stock for a product not yet received | Send the product first, then retry |
| `warehouse_unmatched` (quarantine) | Warehouse code not known | Map it (`…/map`) or create the warehouse |
