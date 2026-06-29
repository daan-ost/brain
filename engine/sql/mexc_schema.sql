-- MEXC coin-tracking — schema voor de eigen `mexc`-database op de 66bio-VPS.
-- Losgekoppeld van de brain-DB (beslissing #5, docs/findings/mexc-coin-tracking-2026-06-29.md).
-- Draaibaar tegen MySQL 8.0 (server) én MAMP MySQL 8 (lokaal testen).
--
-- Server:  CREATE DATABASE mexc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE mexc;
-- Lokaal:  zelfde, of draai dit bestand tegen een lokale `mexc`-DB in MAMP.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. Bestaande data blijft staan.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. mexc_market_scan — huidige snapshot (TRUNCATE + herschreven per run).
--    Uitbreiding op de brain-versie: bid/ask (spread + orderboek-druk), rang,
--    en de candle-trend (meerdaags rendement, dag-range, richting).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mexc_market_scan (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    symbol          VARCHAR(40)  NOT NULL,             -- SYNUSDT
    base            VARCHAR(30)  NOT NULL,             -- SYN
    quote           VARCHAR(12)  NOT NULL DEFAULT 'USDT',
    rank_volat      INT UNSIGNED NULL,                 -- positie op volat_pct binnen de gefilterde set

    -- prijs / volatiliteit / volume (uit ticker/24hr)
    price           DECIMAL(24,10) NULL,
    change24h_pct   DECIMAL(10,2)  NULL,               -- priceChangePercent
    volat_pct       DECIMAL(10,2)  NULL,               -- (high-low)/low*100 — sorteersleutel
    vol24h_usd      DECIMAL(20,2)  NULL,               -- quoteVolume

    -- liquiditeit / orderboek-top (uit ticker/24hr, gratis)
    bid_price       DECIMAL(24,10) NULL,
    ask_price       DECIMAL(24,10) NULL,
    bid_qty         DECIMAL(24,6)  NULL,
    ask_qty         DECIMAL(24,6)  NULL,
    spread_pct      DECIMAL(10,4)  NULL,               -- (ask-bid)/bid*100 — hoe liquide
    book_pressure   DECIMAL(6,4)   NULL,               -- bid_qty/(bid_qty+ask_qty): >0.5 = koopdruk

    -- candle-trend (uit klines 1d, alleen voor de gefilterde set)
    ret_7d_pct      DECIMAL(10,2)  NULL,               -- close nu vs 7d terug
    ret_14d_pct     DECIMAL(10,2)  NULL,               -- close nu vs 14d terug
    avg_day_range_pct DECIMAL(10,2) NULL,              -- gem. (high-low)/low over de venster-dagen — schokkerigheid
    up_days         TINYINT UNSIGNED NULL,             -- # dagen omhoog (close>open) in het venster
    down_days       TINYINT UNSIGNED NULL,             -- # dagen omlaag
    trend_window_d  TINYINT UNSIGNED NULL,             -- hoeveel dag-candles meegenomen
    auto_flag       VARCHAR(20)    NULL,               -- 'faller' | 'choppy' | NULL (afgeleid signaal)

    -- mcap / leeftijd / herkomst
    mcap_usd        DECIMAL(20,2)  NULL,               -- CoinGecko market_cap (NULL = onbekend)
    age_days        INT UNSIGNED   NULL,
    age_source      VARCHAR(20)    NULL,               -- 'firstOpenTime' | 'kline' | 'unknown'
    contract        VARCHAR(120)   NULL,
    cg_id           VARCHAR(80)    NULL,
    status          VARCHAR(20)    NULL,

    fetched_at      TIMESTAMP      NOT NULL,           -- gedeeld per scan
    created_at      TIMESTAMP      NULL,
    updated_at      TIMESTAMP      NULL,

    PRIMARY KEY (id),
    UNIQUE KEY mms_symbol (symbol),
    KEY mms_volat (volat_pct),
    KEY mms_filters (mcap_usd, vol24h_usd),
    KEY mms_flag (auto_flag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. mexc_snapshots — 4-uurs geheugen (APPEND-only).
--    Alleen wat je niet uit klines kunt reconstrueren: rang + orderboek-momentopname.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mexc_snapshots (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    symbol          VARCHAR(40)  NOT NULL,
    base            VARCHAR(30)  NOT NULL,
    rank_volat      INT UNSIGNED NULL,                 -- positie in de lijst op dat moment
    price           DECIMAL(24,10) NULL,               -- ankerprijs (referentie bij de rang)
    change24h_pct   DECIMAL(10,2)  NULL,
    volat_pct       DECIMAL(10,2)  NULL,
    vol24h_usd      DECIMAL(20,2)  NULL,
    bid_price       DECIMAL(24,10) NULL,
    ask_price       DECIMAL(24,10) NULL,
    bid_qty         DECIMAL(24,6)  NULL,
    ask_qty         DECIMAL(24,6)  NULL,
    spread_pct      DECIMAL(10,4)  NULL,
    book_pressure   DECIMAL(6,4)   NULL,
    snapshot_at     TIMESTAMP      NOT NULL,           -- gedeeld per run (het 4-uurs moment)
    created_at      TIMESTAMP      NULL,

    PRIMARY KEY (id),
    KEY ms_base_time (base, snapshot_at),
    KEY ms_time (snapshot_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. mexc_coin_labels — handmatige classificatie per munt (overleeft de truncate).
--    Natuurlijke sleutel = base (munt-identiteit), niet de scan-row.
--    reasons = JSON-array van codes: ["daalt","niet_tradebaar","schokkerig","weinig_volume","scam_vermoeden"]
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mexc_coin_labels (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    base            VARCHAR(30)  NOT NULL,             -- SYN
    symbol          VARCHAR(40)  NULL,                 -- laatst geziene SYNUSDT (info)
    classification  ENUM('unrated','good','bad') NOT NULL DEFAULT 'unrated',
    reasons         JSON         NULL,                 -- alleen bij 'bad': 1+ reden-codes
    note            TEXT         NULL,
    updated_by      VARCHAR(120) NULL,                 -- e-mail/id van de beoordelaar
    created_at      TIMESTAMP    NULL,
    updated_at      TIMESTAMP    NULL,

    PRIMARY KEY (id),
    UNIQUE KEY mcl_base (base),
    KEY mcl_class (classification)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
