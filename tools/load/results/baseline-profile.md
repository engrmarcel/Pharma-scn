11301 requests profiled

| Route | n | avg ms | p95 ms | queries (max) | query ms | repeated | peak MB | status |
|---|---|---|---|---|---|---|---|---|
| GET api.v1.analytics.executive | 277 | 1105 | 1508 | 86.6 (98) | 942.9 | 11.1 | 8 | {"200":277} |
| GET api.v1.analytics.dashboard | 659 | 403 | 578 | 30.2 (42) | 364.2 | 9.0 | 6 | {"200":659} |
| GET api.v1.inventory.stock-levels | 750 | 335 | 501 | 26.1 (38) | 299.1 | 10.0 | 6 | {"200":750} |
| GET api.v1.pos.reports | 980 | 234 | 361 | 31.1 (44) | 194.8 | 11.0 | 6 | {"200":980} |
| GET api.v1.pos.sales.index | 1365 | 132 | 215 | 24.1 (37) | 69.6 | 9.0 | 6 | {"200":1365} |
| GET api.v1.pos.lookup | 1447 | 123 | 240 | 25.3 (38) | 79.5 | 9.0 | 6 | {"200":1447} |
| GET api.v1.inventory.expiring | 1505 | 118 | 231 | 25.1 (35) | 81.9 | 10.0 | 6 | {"200":1505} |
| GET api.v1.catalogue.items.index | 1623 | 100 | 177 | 24.9 (35) | 53.8 | 9.0 | 6 | {"200":1623} |
| GET api.v1.customers.index | 1737 | 80 | 147 | 26.1 (38) | 41.1 | 11.0 | 6 | {"200":1737} |
| POST api.v1.pos.sales.payments | 195 | 479 | 1129 | 117.8 (159) | 336.1 | 49.1 | 8 | {"201":153,"500":42} |
| POST api.v1.pos.sales.start | 288 | 203 | 606 | 35.1 (48) | 125.1 | 9.2 | 8 | {"201":260,"500":28} |
| PUT api.v1.pos.sales.lines | 260 | 215 | 687 | 45.6 (58) | 122.5 | 14.5 | 8 | {"200":196,"500":64} |
| POST api.v1.pos.sales.action | 196 | 261 | 879 | 41.8 (47) | 180.8 | 12.6 | 8 | {"200":195,"500":1} |
| POST api.v1.auth.tokens.issue | 9 | 344 | 426 | 23.4 (27) | 69.5 | 14.4 | 8 | {"201":9} |
| GET api.v1.pos.me | 8 | 87 | 106 | 37.0 (37) | 51.5 | 15.0 | 6 | {"200":8} |
| GET api.v1.health | 1 | 105 | 105 | 13.0 (13) | 29.9 | 6.0 | 14 | {"200":1} |
| GET api.v1.auth.me | 1 | 94 | 94 | 17.0 (17) | 48.6 | 5.0 | 6 | {"200":1} |

Most repeated query per route (count in one request):
- GET api.v1.analytics.executive: x8  select * from `cache` where `key` in (?)
- GET api.v1.analytics.dashboard: x8  select * from `cache` where `key` in (?)
- GET api.v1.inventory.stock-levels: x8  select * from `cache` where `key` in (?)
- GET api.v1.pos.reports: x8  select * from `cache` where `key` in (?)
- GET api.v1.pos.sales.index: x8  select * from `cache` where `key` in (?)
- GET api.v1.pos.lookup: x8  select * from `cache` where `key` in (?)
- GET api.v1.inventory.expiring: x8  select * from `cache` where `key` in (?)
- GET api.v1.catalogue.items.index: x8  select * from `cache` where `key` in (?)
- GET api.v1.customers.index: x8  select * from `cache` where `key` in (?)
- POST api.v1.pos.sales.payments: x8  select * from `cache` where `key` in (?)
- POST api.v1.pos.sales.start: x8  select * from `cache` where `key` in (?)
- PUT api.v1.pos.sales.lines: x8  select * from `cache` where `key` in (?)
- POST api.v1.pos.sales.action: x8  select * from `cache` where `key` in (?)
- POST api.v1.auth.tokens.issue: x11  select * from `cache` where `key` in (?)
- GET api.v1.pos.me: x8  select * from `cache` where `key` in (?)
- GET api.v1.health: x5  select * from `cache` where `key` in (?)
- GET api.v1.auth.me: x4  select * from `cache` where `key` in (?)
