-- CarValue canonical schema (MariaDB 10.5+, utf8mb4).
-- This is the authoritative DDL; bin/import-data.php creates these tables
-- automatically (CREATE TABLE IF NOT EXISTS) when loading the inventory file.

CREATE DATABASE IF NOT EXISTS `mike-carvalue`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `mike-carvalue`;

-- Deduplicated dealer dimension (natural_key = sha1(name|street|zip)).
CREATE TABLE IF NOT EXISTS dealers (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255) NULL,
    street      VARCHAR(255) NULL,
    city        VARCHAR(128) NULL,
    state       CHAR(2)      NULL,
    zip         VARCHAR(10)  NULL,
    natural_key CHAR(40)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dealer_natural (natural_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Listings fact table. make_key/model_key are upper-cased normalized match keys.
-- idx_ymm (make_key, model_key, year) serves every estimate query.
CREATE TABLE IF NOT EXISTS listings (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vin                       CHAR(17)     NULL,
    `year`                    SMALLINT UNSIGNED NULL,
    make                      VARCHAR(64)  NULL,
    model                     VARCHAR(128) NULL,
    `trim`                    VARCHAR(128) NULL,
    make_key                  VARCHAR(64)  NULL,
    model_key                 VARCHAR(128) NULL,
    dealer_id                 BIGINT UNSIGNED NULL,
    listing_price             INT UNSIGNED NULL,
    listing_mileage           INT UNSIGNED NULL,
    used                      TINYINT(1)   NULL,
    certified                 TINYINT(1)   NULL,
    style                     VARCHAR(128) NULL,
    driven_wheels             VARCHAR(32)  NULL,
    engine                    VARCHAR(128) NULL,
    fuel_type                 VARCHAR(64)  NULL,
    exterior_color            VARCHAR(64)  NULL,
    interior_color            VARCHAR(64)  NULL,
    seller_website            VARCHAR(255) NULL,
    first_seen_date           DATE         NULL,
    last_seen_date            DATE         NULL,
    dealer_vdp_last_seen_date DATE         NULL,
    listing_status            VARCHAR(32)  NULL,
    PRIMARY KEY (id),
    KEY idx_ymm (make_key, model_key, `year`),
    CONSTRAINT fk_listing_dealer FOREIGN KEY (dealer_id) REFERENCES dealers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materialized make/model/year rollup powering the fast lookup endpoints
-- (makes/models autocomplete + the YMM parser). Rebuilt after each import from
-- listings; alpha-only make/model keys, dropping the dirty long tail.
CREATE TABLE IF NOT EXISTS vehicle_summary (
    make_key  VARCHAR(64)  NOT NULL,
    model_key VARCHAR(128) NOT NULL,
    `year`    SMALLINT UNSIGNED NOT NULL,
    make      VARCHAR(64)  NOT NULL,
    model     VARCHAR(128) NOT NULL,
    listings  INT UNSIGNED NOT NULL,
    PRIMARY KEY (make_key, model_key, `year`),
    KEY idx_make (make_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
