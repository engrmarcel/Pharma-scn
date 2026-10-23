11087 requests profiled

| Route | n | avg ms | p95 ms | queries (max) | query ms | repeated | peak MB | status |
|---|---|---|---|---|---|---|---|---|
| POST api.v1.pos.sales.payments | 250 | 1271 | 2422 | 133.2 (160) | 1043.4 | 57.4 | 8 | {"201":250} |
| GET api.v1.inventory.stock-levels | 553 | 463 | 602 | 23.0 (31) | 418.3 | 7.0 | 6 | {"200":553} |
| GET api.v1.pos.reports | 663 | 363 | 494 | 29.0 (42) | 313.8 | 9.0 | 6 | {"200":663} |
| GET api.v1.pos.sales.index | 1043 | 176 | 261 | 22.0 (35) | 98.1 | 7.0 | 6 | {"200":1043} |
| GET api.v1.pos.lookup | 1095 | 153 | 261 | 23.1 (36) | 97.6 | 7.1 | 6 | {"200":1095} |
| GET api.v1.inventory.expiring | 1209 | 134 | 222 | 22.0 (30) | 91.2 | 7.0 | 6 | {"200":1209} |
| GET api.v1.catalogue.items.index | 1234 | 126 | 195 | 22.9 (31) | 67.0 | 7.0 | 6 | {"200":1234} |
| GET api.v1.customers.index | 1353 | 95 | 162 | 22.0 (30) | 48.2 | 7.0 | 6 | {"200":1353} |
| POST api.v1.pos.sales.action | 250 | 504 | 1509 | 40.5 (55) | 398.9 | 11.5 | 6 | {"200":250} |
| GET api.v1.analytics.dashboard | 1443 | 87 | 150 | 21.1 (31) | 41.5 | 8.0 | 6 | {"200":1443} |
| GET api.v1.analytics.executive | 1439 | 80 | 133 | 21.1 (86) | 35.9 | 8.0 | 8 | {"200":1439} |
| PUT api.v1.pos.sales.lines | 250 | 291 | 680 | 47.7 (56) | 171.6 | 15.7 | 8 | {"200":250} |
| POST api.v1.pos.sales.start | 250 | 244 | 619 | 32.5 (45) | 157.5 | 7.3 | 8 | {"201":250} |
| POST api.v1.auth.tokens.issue | 27 | 475 | 666 | 22.0 (27) | 71.8 | 13.6 | 8 | {"201":27} |
| GET api.v1.pos.me | 24 | 128 | 199 | 31.5 (36) | 76.4 | 11.4 | 6 | {"200":24} |
| GET api.v1.auth.me | 3 | 91 | 123 | 15.7 (17) | 46.2 | 4.3 | 6 | {"200":3} |
| GET api.v1.health | 1 | 219 | 219 | 13.0 (13) | 52.3 | 6.0 | 14 | {"200":1} |

Most repeated query per route (count in one request):
- POST api.v1.pos.sales.payments: x8  select * from `cache` where `key` in (?)
- GET api.v1.inventory.stock-levels: x8  select * from `cache` where `key` in (?)
- GET api.v1.pos.reports: x8  select * from `cache` where `key` in (?)
- GET api.v1.pos.sales.index: x8  select * from `cache` where `key` in (?)
- GET api.v1.pos.lookup: x8  select * from `cache` where `key` in (?)
- GET api.v1.inventory.expiring: x8  select * from `cache` where `key` in (?)
- GET api.v1.catalogue.items.index: x8  select * from `cache` where `key` in (?)
- GET api.v1.customers.index: x8  select * from `cache` where `key` in (?)
- POST api.v1.pos.sales.action: x8  select * from `cache` where `key` in (?)
- GET api.v1.analytics.dashboard: x9  select * from `cache` where `key` in (?)
- GET api.v1.analytics.executive: x9  select * from `cache` where `key` in (?)
- PUT api.v1.pos.sales.lines: x8  select * from `cache` where `key` in (?)
- POST api.v1.pos.sales.start: x8  select * from `cache` where `key` in (?)
- POST api.v1.auth.tokens.issue: x11  select * from `cache` where `key` in (?)
- GET api.v1.pos.me: x8  select * from `cache` where `key` in (?)
- GET api.v1.auth.me: x4  select * from `cache` where `key` in (?)
- GET api.v1.health: x5  select * from `cache` where `key` in (?)

Slowest queries per route (most total time; average ms when among the slowest three):
- POST api.v1.pos.sales.payments: avg 788.0ms  select * from `trace_chain_heads` where `organization_id` = ? limit 1 for update
- POST api.v1.pos.sales.payments: avg 166.9ms  insert ignore into `inventory_balances` (`batch_id`, `created_at`, `organization_id`, `product_id`, `quantity`, `reserved_quantity`, `stock_state`, `updated_at`, `warehouse_id`) values (?, ?, ?, ?, ?,
- GET api.v1.inventory.stock-levels: avg 189.5ms  select count(*) as `aggregate` from (select b.product_id, b.warehouse_id, p.display_name as product_name, w.code as warehouse_code,
                SUM(CASE WHEN b.stock_state = 'available' THEN b.qua
- GET api.v1.inventory.stock-levels: avg 184.7ms  select b.product_id, b.warehouse_id, p.display_name as product_name, w.code as warehouse_code,
                SUM(CASE WHEN b.stock_state = 'available' THEN b.quantity ELSE 0 END) as available_on_han
- GET api.v1.pos.reports: avg 80.0ms  select COALESCE(SUM(CASE WHEN a.unit_cost IS NOT NULL THEN a.base_quantity * a.unit_cost END), 0) as cost,
                COALESCE(SUM(CASE WHEN a.unit_cost IS NULL THEN a.base_quantity END), 0) as u
- GET api.v1.pos.reports: avg 38.3ms  select sum(`subtotal`) as `aggregate` from `pos_sales` where `organization_id` = ? and `status` = ? and `completed_at` between ? and ?
- GET api.v1.pos.sales.index: avg 45.7ms  select `id`, `number`, `pos_shift_id`, `pos_terminal_id`, `cashier_id`, `customer_id`, `status`, `currency_code`, `total`, `completed_at`, `created_at` from `pos_sales` where `pos_sales`.`organization
- GET api.v1.pos.sales.index: avg 12.5ms  select count(*) as `aggregate` from `pos_sales` where `pos_sales`.`organization_id` = ?
- GET api.v1.pos.lookup: avg 54.2ms  select `p`.`id` as `product_id`, `p`.`display_name`, `op`.`sku`, (op.sku = ? OR EXISTS (SELECT 1 FROM product_identifiers pi2 WHERE pi2.product_id = p.id AND pi2.value = ?)) as exact_code, (select `bx
- GET api.v1.pos.lookup: avg 7.6ms  select * from `personal_access_tokens` where `personal_access_tokens`.`id` = ? limit 1
- GET api.v1.inventory.expiring: avg 49.4ms  select `b`.`warehouse_id`, `b`.`product_id`, `p`.`display_name` as `product_name`, `b`.`batch_id`, `bt`.`batch_number`, `bt`.`expiry_date`, `b`.`stock_state`, `b`.`quantity`, bt.expiry_date < ? as exp
- GET api.v1.inventory.expiring: avg 8.7ms  select * from `personal_access_tokens` where `personal_access_tokens`.`id` = ? limit 1
- GET api.v1.catalogue.items.index: avg 15.7ms  select * from `organization_products` where (`sku` like ? or `local_name` like ? or exists (select * from `products` where `organization_products`.`product_id` = `products`.`id` and (`display_name` li
- GET api.v1.catalogue.items.index: avg 13.7ms  select count(*) as `aggregate` from `organization_products` where (`sku` like ? or `local_name` like ? or exists (select * from `products` where `organization_products`.`product_id` = `products`.`id` 
- GET api.v1.customers.index: avg 7.7ms  select * from `customers` where (`name` like ? or `code` like ?) and `customers`.`organization_id` = ? order by `name` asc limit 50 offset 0
- GET api.v1.customers.index: avg 8.0ms  select * from `personal_access_tokens` where `personal_access_tokens`.`id` = ? limit 1
- POST api.v1.pos.sales.action: avg 463.2ms  insert into `stock_reservations` (`warehouse_id`, `product_id`, `batch_id`, `quantity`, `reference_type`, `reference_id`, `status`, `expires_at`, `created_by`, `id`, `organization_id`, `updated_at`, `
- POST api.v1.pos.sales.action: avg 109.1ms  select `b`.`batch_id`, `b`.`quantity`, `b`.`reserved_quantity` from `inventory_balances` as `b` inner join `batches` as `bt` on `bt`.`id` = `b`.`batch_id` where `b`.`organization_id` = ? and `b`.`prod
- GET api.v1.analytics.dashboard: avg 6.6ms  select * from `personal_access_tokens` where `personal_access_tokens`.`id` = ? limit 1
- GET api.v1.analytics.dashboard: avg 7.7ms  select * from `cache` where `key` in (?)
- GET api.v1.analytics.executive: avg 6.9ms  select * from `personal_access_tokens` where `personal_access_tokens`.`id` = ? limit 1
- GET api.v1.analytics.executive: avg 8.1ms  select * from `cache` where `key` in (?)
- PUT api.v1.pos.sales.lines: avg 26.0ms  insert into `idempotency_keys` (`scope`, `key`, `method`, `path`, `request_hash`, `expires_at`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?, ?, ?, ?)
- PUT api.v1.pos.sales.lines: avg 61.2ms  insert into `pos_sale_lines` (`product_id`, `package_level_code`, `quantity`, `base_quantity`, `unit_price`, `list_price`, `discount_percent`, `tax_category_code`, `tax_rate`, `line_subtotal`, `line_d
- POST api.v1.pos.sales.start: avg 24.0ms  insert into `idempotency_keys` (`scope`, `key`, `method`, `path`, `request_hash`, `expires_at`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?, ?, ?, ?)
- POST api.v1.pos.sales.start: avg 80.6ms  insert into `pos_sales` (`number`, `client_transaction_id`, `pos_shift_id`, `pos_terminal_id`, `cashier_id`, `customer_id`, `warehouse_id`, `status`, `currency_code`, `id`, `organization_id`, `updated
- POST api.v1.auth.tokens.issue: avg 18.0ms  select * from `cache` where `key` in (?)
- POST api.v1.auth.tokens.issue: avg 13.5ms  delete from `cache` where `key` in (?, ?) and `expiration` <= ?
- GET api.v1.pos.me: avg 17.0ms  select * from `personal_access_tokens` where `personal_access_tokens`.`id` = ? limit 1
- GET api.v1.pos.me: avg 11.0ms  update `personal_access_tokens` set `last_used_at` = ?, `personal_access_tokens`.`updated_at` = ? where `id` = ?
- GET api.v1.auth.me: avg 10.4ms  update `personal_access_tokens` set `last_used_at` = ?, `personal_access_tokens`.`updated_at` = ? where `id` = ?
- GET api.v1.auth.me: avg 25.6ms  select * from `personal_access_tokens` where `personal_access_tokens`.`id` = ? limit 1
- GET api.v1.health: avg 17.0ms  select * from `cache` where `key` in (?)
- GET api.v1.health: avg 8.5ms  delete from `cache` where `key` in (?, ?) and `expiration` <= ?
