# CarValue API Reference

The CarValue API is a small read-only JSON interface for estimating a vehicle's
market value and for powering make/model autocomplete. It is the layer behind the
search/results pages and can also be called directly.

- **Related:** [design-doc.md](design-doc.md) · [project-requirements.md](project-requirements.md)
- **Implementation:** [public/api/](../public/api/) (entry points) · [src/](../src/) (controllers, repository, estimator)

---

## Conventions

| | |
|---|---|
| **Base URL (local)** | `http://localhost:8000` |
| **Method** | `GET` only |
| **Response type** | `application/json; charset=utf-8` (pretty-printed) |
| **Encoding** | UTF-8; query strings use standard URL encoding (spaces as `+` or `%20`) |
| **Auth** | None (internal tool) |

### Status codes

| Code | Meaning |
|------|---------|
| `200 OK` | Successful request. Note: "no comparable data found" is still a `200` — see the estimate endpoint. |
| `400 Bad Request` | A required parameter is missing or a supplied value is invalid. Body is `{ "error": "<message>" }`. |

### Error shape

```json
{
  "error": "Missing required parameter: ymm"
}
```

---

## GET /api/estimate.php

Estimates the market value for a year/make/model and returns the comparable
listings the estimate was derived from.

### Query parameters

| Name | Required | Description | Examples |
|------|----------|-------------|----------|
| `ymm` | **yes** | Free-text "Year Make Model". The leading 4-digit year is required; the make is matched against known makes (multi-word makes like "Land Rover" are supported), and the remainder is the model. Case-insensitive. | `2015 Toyota Camry`, `2018 land rover range rover` |
| `mileage` | no | Vehicle mileage. Non-digit characters are stripped, so commas and units are accepted. | `150000`, `150,000`, `150,000 miles` |

### Response (200)

| Field | Type | Description |
|-------|------|-------------|
| `query.year` | int | Parsed model year. |
| `query.make` | string | Parsed make (as typed). |
| `query.model` | string | Parsed model (as typed). |
| `query.mileage` | int \| null | Normalized mileage, or `null` when not supplied. |
| `estimate` | int \| null | Estimated market value, rounded to the nearest **$100**. `null` when no comparable listings exist. |
| `sampleCount` | int | Number of comparable listings used after cleaning (outlier removal). |
| `method` | string | How the estimate was computed: `regression` (mileage-adjusted), `median` (fallback), or `none` (no data). |
| `listings` | array | Up to **100** supporting listings, nearest the requested mileage first. |
| `listings[].vehicle` | string | `Year Make Model Trim`. |
| `listings[].price` | int | Listing price (USD). |
| `listings[].mileage` | int \| null | Listing mileage. |
| `listings[].location` | string | `City, ST` (may be partial/empty when unknown). |

> **How the estimate is computed.** Comparable listings (same year/make/model,
> priced above a floor) have price outliers removed via the IQR rule. If a mileage
> is supplied and there are enough comps with sufficient mileage spread, price is
> regressed on mileage (OLS) and evaluated at the requested mileage, clamped to the
> observed price range; otherwise the robust median is used. See
> [design-doc §6](design-doc.md).

### Example — with mileage

```
GET /api/estimate.php?ymm=2015+Toyota+Camry&mileage=150000
```

```json
{
  "query": { "year": 2015, "make": "Toyota", "model": "Camry", "mileage": 150000 },
  "estimate": 14600,
  "sampleCount": 1018,
  "method": "regression",
  "listings": [
    {
      "vehicle": "2015 Toyota Camry SE",
      "price": 14995,
      "mileage": 150000,
      "location": "Dallas, TX"
    },
    {
      "vehicle": "2015 Toyota Camry LE",
      "price": 16490,
      "mileage": 149850,
      "location": "Oakville, ON"
    }
  ]
}
```

### Example — no comparable data (still 200)

```
GET /api/estimate.php?ymm=2015+Toyota+Zzgibberish
```

```json
{
  "query": { "year": 2015, "make": "Toyota", "model": "Zzgibberish", "mileage": null },
  "estimate": null,
  "sampleCount": 0,
  "method": "none",
  "listings": []
}
```

### Errors

| Condition | Status | Body |
|-----------|--------|------|
| `ymm` missing or empty | `400` | `{ "error": "Missing required parameter: ymm" }` |
| `ymm` has no parseable year + make + model | `400` | `{ "error": "Could not parse a year + make + model from: ..." }` |
| `mileage` supplied but contains no digits | `400` | `{ "error": "Invalid mileage; expected a non-negative number" }` |

---

## GET /api/makes.php

Returns the list of vehicle makes for autocomplete. Makes are filtered to those
with a meaningful number of listings and an alphabetic name, dropping the raw
data's dirty long tail.

### Query parameters

None.

### Response (200)

| Field | Type | Description |
|-------|------|-------------|
| `makes` | string[] | Distinct makes, alphabetical. |

### Example

```
GET /api/makes.php
```

```json
{
  "makes": ["Acura", "Alfa Romeo", "Aluma", "AM General", "Anvil"]
}
```

---

## GET /api/models.php

Returns the models available for a make (optionally constrained to a year), for
autocomplete. Models are filtered the same way as makes.

### Query parameters

| Name | Required | Description | Examples |
|------|----------|-------------|----------|
| `make` | **yes** | Make name (case-insensitive). | `Toyota` |
| `year` | no | Restrict to models offered in this year. Must be numeric. | `2015` |

### Response (200)

| Field | Type | Description |
|-------|------|-------------|
| `models` | string[] | Distinct models, alphabetical. Empty array when the make is unknown. |

### Example

```
GET /api/models.php?make=Toyota&year=2015
```

```json
{
  "models": ["4Runner", "4Runner Limited SUV", "4Runner SR5 SUV", "Avalon", "Avalon Hybrid"]
}
```

### Errors

| Condition | Status | Body |
|-----------|--------|------|
| `make` missing or empty | `400` | `{ "error": "Missing required parameter: make" }` |
| `year` supplied but non-numeric | `400` | `{ "error": "Invalid year" }` |

---

## Notes & limitations

- **Model strings include trim/description text** as entered by dealers (e.g.
  `"4Runner SR5 SUV"`); value matching is on the normalized model, so users
  typically search just the base model (e.g. `Camry`).
- **Make/model display casing** is taken from the source data and may be
  inconsistent (e.g. `RAV4` vs `tacoma`).
- The estimate's `sampleCount` doubles as a **confidence signal** — a small count
  means few comparable listings backed the figure.
- Lookups (`makes`/`models`) and the YMM parser are served from a materialized
  `vehicle_summary` rollup rebuilt on each import, keeping responses fast over the
  ~4.7M-row data set.
