# Integration architecture

## Principles
- **The ERP (Dolibarr) is the single source of truth** for products, stock, orders and customers.
- Every channel is connected through a small, single-purpose PHP 8 service or CRON job — easy to restart, easy to debug.
- **Never overwrite blindly**: marketplace imports often *replace* the whole product, so every push sends the full current row plus the changes, and is verified afterwards.
- **Credentials live outside the web root**; scripts read them from config files that are never committed.
- Asynchronous APIs (Amazon feeds, Mirakl imports) are tracked with a state file: each run first logs the outcome of the previous one.

## Flows

| Flow | Direction | Mechanism |
|---|---|---|
| Orders, customers | Shopify → Dolibarr | Webhooks + REST API |
| Products, prices | Dolibarr → Shopify | Admin API (REST / GraphQL) |
| Stock | Dolibarr → Shopify, Amazon, Mirakl, ManoMano | CRON jobs, Amazon JSON_LISTINGS_FEED, Mirakl offer imports (CSV) |
| Catalogue | Dolibarr → Leroy Merlin / Castorama | Mirakl product imports (P41) with category rules (mandatory/recommended attributes, allowed values) |
| Marketplace orders | Marketplaces → Dolibarr | API polling |
| Shipping | Dolibarr → Chronopost / GLS / Heppner | Carrier APIs, tracking numbers written back to the shipment |
| Fulfilment | Dolibarr ⇄ CIRRO / Octopia | Product mapping, stock sync, order transmission |
| Support | Zoho Desk ⇄ Dolibarr | SMTP routing + two-way sync of customers / orders / tickets |
| Warehouse | Android app ⇄ Dolibarr | Dolibarr REST API (scan in / out, real-time stock) |

## Custom Dolibarr modules
- **Preparation** — groups shipments from several Dolibarr instances and carriers into one preparation, builds the pick list of child products (kits), generates PDFs and an Excel delivery file.
- **Marketplace pricing** — per-platform default margin, commission, delivery and pallet costs; computes suggested prices for 2 000+ products, flags offers below target and offers about to expire.
- **Marketplace push** — reads offers, product status, import history and category rules from Mirakl; queues attribute changes and sends them as one import; verifies what the marketplace actually received.
- **Internal delivery** — delivery tasks for the company's own drivers, mobile stop page, signed proof of delivery as PDF.
