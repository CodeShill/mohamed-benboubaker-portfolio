# Marketplace push tool (Dolibarr admin module) — design notes

A Dolibarr admin page to see which product attributes are missing on a **Mirakl**-based marketplace (e.g. Leroy Merlin / Castorama)
and push them product by product or in bulk. Production code is not published; this describes how it works.

## How it works
1. On load, the page reads from the Mirakl API: offers, the status of each product, the import history, and the rules of each
   category (mandatory and recommended attributes, allowed values).
2. Mirakl does not let you read a product sheet back. The current value of an attribute is therefore reconstructed as the **last
   non-empty value sent in an import**.
3. Edits stay **pending** — nothing is sent — until "Send". Each send creates **one** import whose rows contain the mandatory
   attributes, the edited attributes **and the current values for everything else**, because a product import replaces the whole
   product: nothing gets erased.
4. An *Imports* tab tracks each import, downloads the error reports, and **verifies** what the marketplace received against what was sent.
5. If the API rate-limits (HTTP 429), the page falls back to the last cached read and shows a warning.

## Structure
- `index.php` — admin-only page
- `ajax.php` — Mirakl API calls
- `lib/*.lib.php` — business logic (category rules, diffing, import building, verification)
- `js/`, `css/` — UI
- Working data (pending changes, send history, cache) stored in Dolibarr's `documents/` directory
