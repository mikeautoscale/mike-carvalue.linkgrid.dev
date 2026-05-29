# CarValue — Technical Design Document

> Internal web interface for estimating the average market value of a vehicle by
> **year + make + model** (with optional **mileage**), backed by a reference
> inventory of ~4.7M dealer listings.

- **Status:** Draft for review
- **Last updated:** 2026-05-29
- **Related:** [project-requirements.md](project-requirements.md) · [README.md](../README.md)

---

## 1. Objectives

| # | Objective | Success criterion |
|---|-----------|-------------------|
| O1 | Estimate market value for a year/make/model | Returns a price rounded to the nearest $100 from comparable listings |
| O2 | Account for mileage | A higher mileage input yields a lower (or equal) estimate for the same vehicle |
| O3 | Show supporting evidence | Displays up to 100 representative listings used in the calculation |
| O4 | Ingest the full data file | Loads the 1.2 GB / 4.7M-line file into the database reliably and repeatably |
| O5 | Clean, object-oriented PHP | PSR-12 code, dependency-injected services, open-source libraries only |
| O6 | Verifiable | A documented suite of integration tests covering the estimation contract |
| O7 | Automated deploy | Push-to-`main` ships to production through GitHub Actions |

### Non-goals (v1)
- User accounts / authentication (internal tool, network-restricted).
- Real-time ingestion — data is a static snapshot, refreshed by re-running the importer.
- Per-VIN history / time-series pricing.
- VIN decoding beyond the fields present in the source file.

---

## 2. Architecture & Data Flow

### 2.1 System architecture

```
                        ┌──────────────────────────────────────────────┐
                        │                Browser (user)                 │
                        │   search page  ·  results page  ·  fetch()     │
                        └───────────────────────┬──────────────────────┘
                                                 │ HTTP :80/:8000
                                                 ▼
                        ┌──────────────────────────────────────────────┐
                        │                    nginx                      │
                        │  serves /public, proxies *.php to php-fpm      │
                        └───────────────────────┬──────────────────────┘
                                                 │ FastCGI (unix socket)
                                                 ▼
        ┌────────────────────────────────────────────────────────────────────┐
        │                          PHP 8.0 application                         │
        │                                                                      │
        │   front controller → router → controller → service → repository      │
        │                                          │                           │
        │     ValueEstimator (stats/regression)  ListingRepository (SQL)        │
        └───────────────────────────────────────────┬──────────────────────────┘
                                                     │ PDO
                                                     ▼
                        ┌──────────────────────────────────────────────┐
                        │              MariaDB 10.5 (utf8mb4)            │
                        │        dealers  ·  listings (indexed)          │
                        └───────────────────────────────────────────────┘
                                                     ▲
                                                     │ batched LOAD
        ┌────────────────────────────────────────────┴───────────────────────┐
        │   CLI importer (bin/import-data.php): stream → parse → clean → load   │
        │            source: inventory-listing-2022-08-17.txt (1.2 GB)          │
        └──────────────────────────────────────────────────────────────────────┘
```

### 2.2 Request data flow (estimate)

```
user submits "2015 Toyota Camry" + 150000 mi
        │
        ▼
 search page ──POST/GET──► estimate controller
        │                        │
        │                        ├─ validate + parse "Year Make Model"
        │                        ├─ ListingRepository: SELECT comparable rows
        │                        │     WHERE year/make/model match, price present
        │                        ├─ ValueEstimator:
        │                        │     1. drop price outliers (IQR)
        │                        │     2. mileage given? fit price~mileage line
        │                        │        else → robust median
        │                        │     3. round to nearest $100
        │                        └─ select ≤100 sample listings (nearest mileage)
        ▼
 results page  ◄── { estimate, sampleCount, listings[] }
```

### 2.3 ETL data flow (ingest)

```
raw .txt (pipe-delimited, 25 cols)
   │  stream line-by-line (constant memory)
   ▼
parse row ──► validate / coerce types ──► normalize
   │              │                          │
   │              ├ drop rows w/ no price    ├ trim + upper-case make/model keys
   │              ├ coerce TRUE/FALSE→bool   ├ dedupe dealer (natural key)
   │              └ parse dates              └ map columns → schema
   ▼
buffer N rows ──► multi-row INSERT (transaction, batches of ~2–5k)
   │
   ▼
post-load: ANALYZE TABLE / verify row counts / build indexes
```

---

## 3. Challenges & Solutions

### C1 — Mileage vs. price (the core challenge)
Price and mileage are negatively correlated, but the slope differs per vehicle.
**Solution:** within the comparable set for a given year/make/model, fit a simple
**ordinary-least-squares regression of price on mileage** and evaluate it at the
user's mileage. The slope is clamped to ≤ 0 (a comp set should not imply value
*rising* with mileage), and the prediction is clamped to the observed price range.
When mileage is omitted, or there are too few comps / insufficient mileage
spread, fall back to a **robust median**. See [§6](#6-estimation-algorithm).

### C2 — Other accuracy factors (open-ended challenge)
Beyond mileage, value is driven by **trim**, **certified pre-owned** status,
**region** (state/zip), **condition** (used vs. new), **body style /
drivetrain**, and **listing recency**. v1 keeps the model interpretable
(mileage-only regression + outlier trimming) and exposes these as **future
multivariate inputs** ([§12](#12-future-enhancements)); the schema already
stores every factor so the model can grow without re-ingestion.

### C3 — Dirty data
~21% of sample rows have no price and ~13% have no mileage; prices contain
outliers (e.g. $2,500 → $315,000). **Solution:** rows with no price are excluded
from estimation at query time; price outliers are removed per comp set via the
**IQR rule** before averaging; the median fallback is inherently outlier-resistant.

### C4 — Scale (1.2 GB / 4.7M rows)
**Solution:** the importer **streams** the file (never loads it whole), inserts in
**batched transactions**, and relies on a single composite index on the query
hot-path. Estimation queries touch only the `(year, make, model)` index, keeping
lookups fast regardless of total table size.

### C5 — Case / format variance in user input
"toyota", "Toyota", "TOYOTA CAMRY" must all match. **Solution:** normalize the
match keys (upper-cased, trimmed) both at ingest and query time, and parse the
free-text "Year Make Model" field tolerantly (first token = year, remainder split
make/model against known values).

---

## 4. Database Schema

utf8mb4 / `utf8mb4_unicode_ci`. Two tables: a deduplicated `dealers` dimension and
the `listings` fact table.

### 4.1 ER diagram

```
┌─────────────────────────┐          ┌──────────────────────────────────────┐
│         dealers          │ 1      * │               listings                │
├─────────────────────────┤──────────├──────────────────────────────────────┤
│ id            PK         │          │ id              PK                     │
│ name                     │          │ dealer_id       FK → dealers.id        │
│ street                   │          │ vin                                    │
│ city                     │          │ year, make, model, trim                │
│ state                    │          │ make_key, model_key  (normalized)      │
│ zip                      │          │ listing_price, listing_mileage         │
│ natural_key  UQ          │          │ used, certified                        │
└─────────────────────────┘          │ style, driven_wheels, engine, fuel_type│
                                      │ exterior_color, interior_color         │
                                      │ seller_website                         │
                                      │ first_seen_date, last_seen_date        │
                                      │ dealer_vdp_last_seen_date              │
                                      │ listing_status                         │
                                      └──────────────────────────────────────┘
```

### 4.2 `listings` (fact table)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED, PK, auto | Surrogate key |
| vin | CHAR(17), nullable | From source |
| year | SMALLINT UNSIGNED | Model year |
| make | VARCHAR(64) | Display value |
| model | VARCHAR(128) | Display value |
| trim | VARCHAR(128), nullable | |
| make_key | VARCHAR(64) | Upper-cased/trimmed; indexed |
| model_key | VARCHAR(128) | Upper-cased/trimmed; indexed |
| dealer_id | BIGINT UNSIGNED, FK | → `dealers.id` |
| listing_price | INT UNSIGNED, **nullable** | Null/0 excluded from estimation |
| listing_mileage | INT UNSIGNED, nullable | |
| used | TINYINT(1) | Coerced from TRUE/FALSE |
| certified | TINYINT(1) | Coerced from TRUE/FALSE |
| style, driven_wheels, engine, fuel_type | VARCHAR | Descriptive |
| exterior_color, interior_color | VARCHAR(64) | |
| seller_website | VARCHAR(255), nullable | |
| first_seen_date, last_seen_date, dealer_vdp_last_seen_date | DATE, nullable | |
| listing_status | VARCHAR(32), nullable | e.g. `in_transit` |

### 4.3 `dealers` (dimension)

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED, PK, auto | |
| name | VARCHAR(255) | |
| street | VARCHAR(255) | |
| city | VARCHAR(128) | |
| state | CHAR(2) | |
| zip | VARCHAR(10) | |
| natural_key | VARCHAR(64), **UNIQUE** | Hash of name+street+zip for dedupe |

### 4.4 Indexing strategy
- **`idx_ymm` on `listings (make_key, model_key, year)`** — the single hot-path
  index serving every estimate query.
- Partial filtering on `listing_price IS NOT NULL` handled in the query predicate.
- `dealers.natural_key` UNIQUE — enables idempotent dealer upserts during ingest.
- Indexes are built **after** the bulk load to keep ingest fast.

---

## 5. Solution Overview (input → database → output)

1. **Ingest** — `bin/import-data.php` streams the source file, cleans/normalizes
   each row, dedupes dealers, and bulk-loads `listings`.
2. **Query** — a search resolves to a `(make_key, model_key, year)` lookup that
   returns the comparable set (priced rows only).
3. **Estimate** — `ValueEstimator` trims outliers and computes the value
   (mileage regression or median), rounding to the nearest $100.
4. **Present** — the results page renders the estimate plus ≤100 supporting
   listings (those nearest the requested mileage).

---

## 6. Estimation Algorithm

```
INPUT: year, make, model, [mileage]

1. comps ← listings WHERE make_key=? AND model_key=? AND year=? AND price>floor
2. if comps is empty → return "no data"
3. clean ← remove price outliers from comps via IQR (Q1−1.5·IQR, Q3+1.5·IQR)
4. estimate:
      if mileage given AND |clean| ≥ MIN_FIT AND mileage spread sufficient:
          (a,b) ← OLS fit of price on mileage over clean    // b clamped ≤ 0
          value ← a + b·mileage                              // clamped to [min,max] price
      else:
          value ← median(price over clean)                  // robust fallback
5. value ← round(value, nearest 100)
6. samples ← up to 100 listings from clean, ordered by |mileage − requested|
7. return { value, sampleCount=|clean|, samples }
```

- **Why median fallback:** resistant to the heavy right-tail seen in the data.
- **Why clamp slope/range:** guards against sparse or noisy comp sets producing
  nonsensical extrapolations.
- **Confidence signal:** `sampleCount` is surfaced so users can judge reliability.

---

## 7. API Endpoints & Tests

The frontend consumes a thin internal JSON API; pages may also render server-side
using the same service layer.

### 7.1 Endpoints

| Method | Path | Purpose | Key params | Response |
|--------|------|---------|-----------|----------|
| GET | `/api/estimate.php` | Market value + comps | `ymm` (required), `mileage` (optional) | `{ estimate, sampleCount, listings[] }` |
| GET | `/api/makes.php` | Distinct makes (autocomplete) | — | `{ makes[] }` |
| GET | `/api/models.php` | Models for a make/year | `make`, `year` | `{ models[] }` |

**Error contract:** `400` for missing/invalid `ymm`; `200` with `estimate: null`
and `sampleCount: 0` when no comparable data exists (not an error).

### 7.2 Integration test matrix

| ID | Scenario | Expectation |
|----|----------|-------------|
| T1 | Known YMM, no mileage | Returns estimate; multiple of 100; `sampleCount > 0` |
| T2 | Same YMM, high vs. low mileage | High-mileage estimate ≤ low-mileage estimate |
| T3 | Unknown YMM | `200`, `estimate: null`, `sampleCount: 0` |
| T4 | Missing `ymm` param | `400` |
| T5 | Rows with empty price | Excluded — never appear in samples or average |
| T6 | Listings cap | `listings` length ≤ 100 |
| T7 | Case-insensitivity | "toyota camry" == "Toyota Camry" |
| T8 | Outlier present | Extreme price excluded by IQR; estimate unaffected |
| T9 | Rounding | Estimate is exactly a multiple of 100 |
| T10 | Importer | A fixed sample file yields the expected row/dealer counts |

Tooling: **PHPUnit** for integration tests against a seeded test database
(loaded from [sample-data-1000.txt](sample-data-1000.txt)).

---

## 8. Frontend Pages

| Page | Path | Contents |
|------|------|----------|
| Search | `public/index.php` | Single required field ("2015 Toyota Camry") + optional mileage; minimal, aligned, accessible form |
| Results | `public/results.php` | Big rounded estimate, sample size/confidence note, and a table of ≤100 listings (Vehicle · Price · Mileage · Location) |

- Plain server-rendered HTML + a small CSS file; optional unobtrusive JS for
  make/model autocomplete via the API. No heavyweight framework.
- Mileage displayed with thousands separators; price as `$13,800`.

---

## 9. Project Layout & File Paths

Lower-case, kebab-style filenames throughout; classmap autoloading bridges file
names to `CarValue\` classes.

```
mike-carvalue.linkgrid.dev/
├── public/                      # web root (nginx `root`)
│   ├── index.php                # front controller + search page
│   ├── results.php              # results page
│   ├── api/
│   │   ├── estimate.php
│   │   ├── makes.php
│   │   └── models.php
│   └── assets/
│       ├── css/style.css
│       └── js/app.js
├── src/                         # application classes (PSR-4 \CarValue\)
│   ├── bootstrap.php            # DI wiring / config load
│   ├── router.php
│   ├── database.php             # PDO factory
│   ├── listing-repository.php   # comp-set queries
│   ├── value-estimator.php      # stats / regression
│   ├── estimate-controller.php
│   ├── search-controller.php
│   ├── request.php
│   ├── response.php
│   └── view.php                 # lightweight templating
├── bin/
│   ├── import-data.php          # streaming ETL CLI
│   └── migrate.php              # schema migration runner
├── db/
│   ├── schema.sql
│   └── migrations/
├── config/
│   ├── config.php
│   └── .env                     # DB creds (gitignored)
├── tests/
│   └── integration/             # PHPUnit cases T1–T10
├── conf/
│   └── mike-carvalue.local.conf # nginx site (existing)
├── docs/
│   ├── design-doc.md            # this document
│   ├── project-requirements.md
│   └── sample-data-1000.txt
├── .github/workflows/deploy.yml
├── composer.json
└── README.md
```

---

## 10. Deployment (GitHub Actions)

Push to `main` triggers test → deploy. Production access uses the `SSH_KEY`
repository secret (root@mike-carvalue.linkgrid.dev), deploying to
`/home/mike-carvalue.linkgrid.dev`.

### 10.1 Pipeline

```
push → main
   │
   ▼
[ job: test ]
   checkout → setup-php 8.0 → composer install → phpunit (T1–T10) + lint
   │  (must pass)
   ▼
[ job: deploy ]  (needs: test)
   load SSH_KEY secret → rsync /public,/src,/bin,/db,/config,/composer.* to server
        (exclude .git, tests, .env)
   │
   ▼  ssh root@host:
   composer install --no-dev --optimize-autoloader
   php bin/migrate.php                # apply pending schema migrations
   reload php-fpm  ·  reload nginx
   │
   ▼
   smoke check: curl /api/estimate.php?ymm=... returns 200
```

### 10.2 Deployment actions summary

| Stage | Action |
|-------|--------|
| CI | Install deps, run PHPUnit + PSR-12 lint; block deploy on failure |
| Transfer | `rsync` over SSH (key from `SSH_KEY` secret); exclude dev/test/`.env` |
| Server build | `composer install --no-dev --optimize-autoloader` |
| Migrate | `php bin/migrate.php` (idempotent, forward-only) |
| Reload | Reload php-fpm and nginx (no full restart) |
| Verify | Post-deploy smoke request against the estimate endpoint |

> **Data note:** the 1.2 GB import is **not** part of the deploy pipeline — it is a
> one-off / on-demand operation run manually on the server via `bin/import-data.php`
> to avoid long, repeated transfers and DB churn on every push.

---

## 11. Local Environment

Mirrors production (see [README.md](../README.md)):

- **OS:** AlmaLinux 9 (WSL 2) · **Web:** nginx 1.20.1 · **PHP:** 8.0.30 (php-fpm)
- **DB:** MariaDB 10.5.29, database `mike-carvalue`
- **Root:** `/root/workspace/mike-carvalue.linkgrid.dev` · **URL:** http://localhost:8000/

---

## 12. Future Enhancements

- **Multivariate model:** extend the regression to trim, certified, region, and
  recency (all already stored) for sharper estimates.
- **Recency weighting:** down-weight stale listings by `last_seen_date`.
- **Geographic awareness:** optional location input → regional adjustment via zip.
- **Caching:** memoize hot (year, make, model) comp sets.
- **Incremental ingest:** upsert by VIN to support periodic snapshot refreshes.
```
