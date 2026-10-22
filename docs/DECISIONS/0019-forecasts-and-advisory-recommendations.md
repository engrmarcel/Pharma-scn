# ADR-0019: Forecasts are explainable; recommendations are advisory and decided by a person

- Status: Accepted (2026-10-22)

## Context
Spec §19.2 asks for demand forecasting, reorder, overstock and stockout risk, expiry risk, procurement recommendations, and integration and traceability anomaly detection. It also sets limits:
- outputs must be labelled as recommendations, predictions or generated explanations;
- a model must never authorize purchases, alter inventory, approve financial transactions or override regulatory restrictions;
- material actions need validated data, explainable assumptions and human approval.

Phase 8 acceptance: AI recommendations cannot independently perform unauthorized financial, inventory or regulatory actions.

## Decision
- **Forecasting behind an interface.**
  - `ForecastProvider` is bound to the local `StatisticalForecaster` (`ANALYTICS_FORECAST_PROVIDER` can name another implementation).
  - The statistical forecaster chooses per series between a moving average, simple exponential smoothing, a damped trend and Croston (SBA) for intermittent demand, by a rolling one-step backtest on recent weeks.
  - Every forecast returns its method, parameters, backtest accuracy (MAE, WAPE), approximate 80% intervals and plain-language assumptions. Fewer than 6 weeks of sales history gives `forecast_insufficient_history` instead of a guess.
  - No data leaves the platform for forecasting.
- **The engine only writes recommendation rows.** `RecommendationEngine` (nightly `analytics:recommend`, or on request) reads analytics and writes `analytics_recommendations`, nothing else. A test checks that requisitions, orders, stock movements, journals, adjustments, trace events and incidents are unchanged by generation. Rules:
  - **Reorder:** sellable stock plus stock on order (approved, sent and part-received purchase orders and open requisitions) is below the forecast demand over the lead time (critical) or at the reorder level (warning). The suggestion covers the lead time plus 30 days, and is at least the reorder quantity. Lead time comes from the preferred supplier's receipts (two or more), else a 14-day default.
  - **Overstock:** more than 180 days of cover.
  - **Expiry risk:** lots expiring within 90 days that recent demand, earliest expiry first, will not clear.
  - **Integration anomalies:** a connector's quarantined or failed share in the last 24 hours is at least 20%, at least 5 messages, and at least three times its 14-day baseline; or a usually active connector has received nothing for 24 hours.
  - **Traceability anomalies:** a broken hash chain; or the organization's dispensed or withdrawn codes scanned again (a possible copy), critical when seen by three or more different scanners.
- **One open recommendation per subject.** A rerun refreshes it, and one whose condition has cleared becomes `superseded`.
- **Each recommendation carries** its type, its kind (recommendation, prediction or anomaly), its severity, its rationale, the figures behind it, its assumptions and its generator. The API adds a label saying it is machine-generated and changes nothing by itself.
- **People decide.**
  - Viewing needs `analytics.view` plus the type's area permission (`inventory.view`, `integrations.view` or `traceability.view`). Warehouse-bound recommendations follow the member's locations.
  - Accepting a reorder creates a **draft** purchase requisition as the accepting person. That person needs `procurement.requisition` and access to the receiving warehouse, and may change the quantity or warehouse. Submission, approval and ordering follow the normal procurement rules.
  - Accepting any other type records the decision only. Dismissal needs a reason. Decisions are audited.

## Consequences
- **Base units.** Suggested quantities are in base units; the person chooses the pack when reviewing the requisition.
- **Bias from stockouts.** Weeks without stock count as zero demand, which can understate demand. This is stated in the assumptions.
- **External models.** A machine-learning or external provider can replace the statistical one. If it sends data outside the platform, the external-AI controls of the reporting assistant (8c) apply.
