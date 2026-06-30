-- Feed-database voor TradingView-webhooks (epic-TV)
-- DB-naam: nobrainers_feed (stel in als configuratie per omgeving)
-- Uitvoeren als: mysql -u root -p nobrainers_feed < schema_feed.sql
-- (database aanmaken + user zie deploy-instructies hieronder)
--
-- Deploy-stappen:
--   mysql -u root -p -e "CREATE DATABASE nobrainers_feed CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root -p -e "CREATE USER 'feed_user'@'127.0.0.1' IDENTIFIED BY '<STERK_WW>';"
--   mysql -u root -p -e "GRANT ALL PRIVILEGES ON nobrainers_feed.* TO 'feed_user'@'127.0.0.1'; FLUSH PRIVILEGES;"
--   mysql -u root -p nobrainers_feed < schema_feed.sql
-- Config: /home/ploi/nobrainersbot.com/config.feed.php (zie config.feed.php.example)

-- ─── Munt-mapping ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tv_symbols (
    id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    symbol      VARCHAR(50)       NOT NULL,            -- bv. CLAW (zonder USDT)
    coinpair    VARCHAR(50)       NOT NULL,            -- bv. CLAWUSDT (raw TradingView {{ticker}})
    timeframe   SMALLINT UNSIGNED NOT NULL,            -- 1/3/5/15/30/45/60/120/240
    active      TINYINT(1)        NOT NULL DEFAULT 1,
    created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pair_tf (coinpair, timeframe)
) ENGINE=InnoDB AUTO_INCREMENT=100000 DEFAULT CHARSET=utf8mb4;
-- AUTO_INCREMENT=100000: nieuwe munten krijgen id>=100000, nooit botsen met brain trading_symbol_id's (<100000)

-- ─── Indicator-feed ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tv_indicators (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    trading_symbol_id INT UNSIGNED  NOT NULL,          -- = tv_symbols.id
    symbol            VARCHAR(50)   NULL,
    coinpair          VARCHAR(50)   NULL,
    indicator         VARCHAR(30)   NOT NULL,          -- vzo/phobos/obv-x-value/mfi/volumeud
    datetime          DATETIME      NOT NULL,          -- bar-minuut (sec=0), UTC
    value             DOUBLE        NULL,
    price             DOUBLE        NULL,
    action            VARCHAR(10)   NULL,              -- as-is opgeslagen, brain gebruikt het niet
    volume_found      TINYINT(1)    NOT NULL DEFAULT 0,   -- TV-alerts hebben geen legacy-vlag → 0
    received_at       DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),  -- exacte ontvangsttijd (audit)
    remote_ip         VARCHAR(45)   NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tick (trading_symbol_id, indicator, datetime)  -- idempotentie + query-index
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Afkeur-log ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tv_ingest_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    received_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    status      VARCHAR(20)     NOT NULL,              -- 'rejected' / 'error'
    reason      VARCHAR(255)    NOT NULL,
    raw_body    TEXT            NULL,
    remote_ip   VARCHAR(45)     NULL,
    PRIMARY KEY (id),
    KEY idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Seed: bekende munten met hun brain trading_symbol_id ───────────────────
-- Pre-seed zodat de feed voor bekende munten naar dezelfde trading_symbol_id schrijft
-- als de historische import in brain.indicators.
-- Nieuwe munten (niet hieronder) krijgen automatisch id>=100000 bij eerste alert.
INSERT INTO tv_symbols (id, symbol, coinpair, timeframe) VALUES
    (32,   'TURBO',    'TURBOUSDT',    5),
    (244,  'NOS',      'NOSUSDT',      5),
    (2525, 'DOGEAI',   'DOGEAIUSDT',   5),
    (2735, 'MUMU',     'MUMUSDT',      5),
    (6419, 'FARTCOIN', 'FARTCOINUSDT', 5)
ON DUPLICATE KEY UPDATE symbol = VALUES(symbol);
