#!/usr/bin/env python3
"""
MEXC-marktscan: volatiele, handelbare USDT-paren ontdekken als rotatie-kandidaten.

Haalt de hele MEXC-spotmarkt op (2 bulk-calls, geen auth), joint met CoinGecko voor marketcap
(Demo-key vereist, env var CG_DEMO_KEY), en schrijft een snapshot naar brain.mexc_market_scan.
Sorteersleutel = volat_pct (24u prijs-range); volume + mcap = liquiditeit-filters.

Atomaire snapshot: TRUNCATE + INSERT in 1 transactie, ALLEEN na volledig geslaagde fetch+parse.
Bij elke fout: vorige snapshot intact, error gerapporteerd.

Gebruik stdlib urllib (geen requests-dependency). Nooit naar bot_signals.
"""
import json
import os
import sys
import time
import urllib.request
import urllib.error
from datetime import datetime, timezone

from db import brain

MEXC_BASE = "https://api.mexc.com/api/v3"
CG_BASE = "https://api.coingecko.com/api/v3"
CG_DEMO_KEY = os.environ.get("CG_DEMO_KEY", "CG-Ued2J5LkYYVJhoGD9dYvHSHS")

HTTP_TIMEOUT = 30
CG_SLEEP = 1.2   # CoinGecko rate-limit: 100/min met demo-key, maar voorzichtig
CG_PAGES = 5     # pagina's /coins/markets (rank 1-1250, dekt mcap>10M)
CG_PER_PAGE = 250


def _get(url, headers=None):
    req = urllib.request.Request(url, headers=headers or {})
    req.add_header("User-Agent", "NoBrainersBot/1.0")
    with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT) as resp:
        return json.loads(resp.read())


def _get_retry(url, headers=None, retries=3, backoff=2.0):
    for attempt in range(retries):
        try:
            return _get(url, headers)
        except urllib.error.HTTPError as e:
            if e.code == 429:
                wait = float(e.headers.get("Retry-After", backoff * (attempt + 1)))
                time.sleep(min(wait, 30))
                continue
            raise
        except (urllib.error.URLError, OSError):
            if attempt < retries - 1:
                time.sleep(backoff * (attempt + 1))
                continue
            raise
    raise RuntimeError(f"HTTP failed after {retries} retries: {url}")


# ---------------------------------------------------------------------------
# 1. MEXC exchangeInfo → tradeable USDT pairs
# ---------------------------------------------------------------------------
def _fetch_exchange_info():
    data = _get_retry(f"{MEXC_BASE}/exchangeInfo")
    pairs = {}
    for s in data.get("symbols", []):
        if (s.get("quoteAsset") != "USDT"
                or not s.get("isSpotTradingAllowed")
                or "SPOT" not in (s.get("permissions") or [])
                or s.get("st", False)):
            continue
        sym = s["symbol"]
        contract = (s.get("contractAddress") or "").strip().lower() or None
        first_open = s.get("firstOpenTime")
        pairs[sym] = {
            "symbol": sym,
            "base": s["baseAsset"],
            "contract": contract,
            "first_open_ms": first_open if first_open and first_open > 0 else None,
        }
    return pairs


# ---------------------------------------------------------------------------
# 2. MEXC ticker/24hr → price, change, volat, volume
# ---------------------------------------------------------------------------
def _fetch_tickers():
    data = _get_retry(f"{MEXC_BASE}/ticker/24hr")
    tickers = {}
    for t in data:
        sym = t.get("symbol", "")
        try:
            high = float(t.get("highPrice", 0))
            low = float(t.get("lowPrice", 0))
            volat = ((high - low) / low * 100) if low > 0 else None
        except (ValueError, ZeroDivisionError):
            volat = None
        try:
            price = float(t.get("lastPrice", 0))
        except (ValueError, TypeError):
            price = None
        try:
            change = float(t.get("priceChangePercent", 0))
        except (ValueError, TypeError):
            change = None
        try:
            vol = float(t.get("quoteVolume", 0))
        except (ValueError, TypeError):
            vol = None
        tickers[sym] = {
            "price": price,
            "change24h_pct": change,
            "volat_pct": volat,
            "vol24h_usd": vol,
        }
    return tickers


# ---------------------------------------------------------------------------
# 3. CoinGecko: marketcap + contract→id map
# ---------------------------------------------------------------------------
def _cg_headers():
    h = {"User-Agent": "NoBrainersBot/1.0"}
    if CG_DEMO_KEY:
        h["x-cg-demo-api-key"] = CG_DEMO_KEY
    return h


def _fetch_cg_markets():
    mcap_map = {}
    h = _cg_headers()
    for page in range(1, CG_PAGES + 1):
        url = (f"{CG_BASE}/coins/markets?vs_currency=usd&order=market_cap_desc"
               f"&per_page={CG_PER_PAGE}&page={page}")
        data = _get_retry(url, headers=h)
        for coin in data:
            cg_id = coin.get("id")
            mcap = coin.get("market_cap")
            sym = (coin.get("symbol") or "").upper()
            if cg_id:
                mcap_map[cg_id] = {"mcap": mcap, "symbol": sym}
        if page < CG_PAGES:
            time.sleep(CG_SLEEP)
    return mcap_map


def _fetch_cg_contracts():
    h = _cg_headers()
    url = f"{CG_BASE}/coins/list?include_platform=true"
    data = _get_retry(url, headers=h)
    contract_to_id = {}
    symbol_to_ids = {}
    for coin in data:
        cg_id = coin.get("id", "")
        sym = (coin.get("symbol") or "").upper()
        symbol_to_ids.setdefault(sym, []).append(cg_id)
        platforms = coin.get("platforms") or {}
        for _chain, addr in platforms.items():
            if addr:
                contract_to_id[addr.strip().lower()] = cg_id
    return contract_to_id, symbol_to_ids


# ---------------------------------------------------------------------------
# 4. Age: firstOpenTime primary, kline fallback
# ---------------------------------------------------------------------------
def _compute_age(first_open_ms, now_ms):
    if first_open_ms and first_open_ms > 0:
        return int((now_ms - first_open_ms) / 86400000), "firstOpenTime"
    return None, "unknown"


def _kline_age_fallback(symbols, now_ms):
    ages = {}
    for sym in symbols:
        try:
            url = f"{MEXC_BASE}/klines?symbol={sym}&interval=1d&limit=500"
            data = _get_retry(url, retries=2, backoff=1.0)
            if data and len(data) > 0:
                oldest_ts = data[0][0]
                days = int((now_ms - oldest_ts) / 86400000)
                source = "kline"
                if len(data) >= 500:
                    source = "kline"
                ages[sym] = (days, source)
            time.sleep(0.05)
        except Exception:
            pass
    return ages


# ---------------------------------------------------------------------------
# 5. Join + build rows
# ---------------------------------------------------------------------------
def _build_rows(pairs, tickers, mcap_map, contract_to_cg, symbol_to_cg_ids):
    now_ms = int(datetime.now(timezone.utc).timestamp() * 1000)
    fetched_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

    need_kline = []
    rows = []

    for sym, info in pairs.items():
        tk = tickers.get(sym, {})
        age_days, age_source = _compute_age(info["first_open_ms"], now_ms)
        if age_days is None:
            need_kline.append(sym)

        # CoinGecko join: contract primary, symbol fallback (unique only)
        cg_id = None
        mcap = None
        contract = info["contract"]
        if contract and contract in contract_to_cg:
            cg_id = contract_to_cg[contract]
        elif info["base"] in symbol_to_cg_ids:
            candidates = symbol_to_cg_ids[info["base"]]
            if len(candidates) == 1:
                cg_id = candidates[0]

        if cg_id and cg_id in mcap_map:
            mcap = mcap_map[cg_id]["mcap"]

        rows.append({
            "symbol": sym,
            "base": info["base"],
            "quote": "USDT",
            "price": tk.get("price"),
            "change24h_pct": tk.get("change24h_pct"),
            "volat_pct": tk.get("volat_pct"),
            "vol24h_usd": tk.get("vol24h_usd"),
            "mcap_usd": mcap,
            "age_days": age_days,
            "age_source": age_source,
            "contract": contract,
            "cg_id": cg_id,
            "status": "online",
            "fetched_at": fetched_at,
        })

    # kline fallback for pairs without firstOpenTime
    if need_kline:
        kline_ages = _kline_age_fallback(need_kline, now_ms)
        for row in rows:
            if row["age_source"] == "unknown" and row["symbol"] in kline_ages:
                days, source = kline_ages[row["symbol"]]
                row["age_days"] = days
                row["age_source"] = source

    return rows


# ---------------------------------------------------------------------------
# 6. Atomic write to brain.mexc_market_scan
# ---------------------------------------------------------------------------
def _write_snapshot(rows):
    conn = brain()
    conn.autocommit(False)
    try:
        with conn.cursor() as c:
            c.execute("TRUNCATE TABLE mexc_market_scan")
            for r in rows:
                c.execute(
                    "INSERT INTO mexc_market_scan "
                    "(symbol, base, quote, price, change24h_pct, volat_pct, vol24h_usd, "
                    " mcap_usd, age_days, age_source, contract, cg_id, status, fetched_at, "
                    " created_at, updated_at) "
                    "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW())",
                    (r["symbol"], r["base"], r["quote"], r["price"], r["change24h_pct"],
                     r["volat_pct"], r["vol24h_usd"], r["mcap_usd"], r["age_days"],
                     r["age_source"], r["contract"], r["cg_id"], r["status"], r["fetched_at"]))
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.autocommit(True)
        conn.close()
    return len(rows)


# ---------------------------------------------------------------------------
# 7. Main: run()
# ---------------------------------------------------------------------------
def run(verbose=True):
    if verbose:
        print("MEXC-scan: exchangeInfo ophalen...")
    pairs = _fetch_exchange_info()
    if verbose:
        print(f"  {len(pairs)} tradeable USDT-paren")

    if verbose:
        print("MEXC-scan: ticker/24hr ophalen...")
    tickers = _fetch_tickers()

    if not CG_DEMO_KEY:
        if verbose:
            print("  WAARSCHUWING: CG_DEMO_KEY niet gezet — marketcap wordt NULL voor alle coins.")

    if verbose:
        print("CoinGecko: markets + contracten ophalen...")
    mcap_map = _fetch_cg_markets() if CG_DEMO_KEY else {}
    contract_to_cg, symbol_to_cg_ids = _fetch_cg_contracts() if CG_DEMO_KEY else ({}, {})

    if verbose:
        print("Rows bouwen + join...")
    rows = _build_rows(pairs, tickers, mcap_map, contract_to_cg, symbol_to_cg_ids)

    # Stats before write
    with_mcap = sum(1 for r in rows if r["mcap_usd"] is not None)
    with_age = sum(1 for r in rows if r["age_source"] == "firstOpenTime")

    if verbose:
        print(f"  {len(rows)} rijen, {with_mcap} met mcap, {with_age} met firstOpenTime-leeftijd")
        print("Snapshot schrijven...")

    written = _write_snapshot(rows)

    # top candidates (mcap>10M, vol>100k, age>=7)
    top = [r for r in rows
           if (r["mcap_usd"] or 0) > 10_000_000
           and (r["vol24h_usd"] or 0) > 100_000
           and (r["age_days"] or 0) >= 7]
    top.sort(key=lambda r: r["volat_pct"] or 0, reverse=True)

    if verbose:
        print(f"  {len(top)} kandidaten (mcap>10M, vol>100k, age>=7d)")
        for c in top[:10]:
            print(f"    {c['symbol']:20s} volat={c['volat_pct']:7.1f}%  "
                  f"vol24h=${c['vol24h_usd']:>12,.0f}  mcap=${c['mcap_usd']:>14,.0f}  "
                  f"age={c['age_days']}d ({c['age_source']})")

    return {
        "fetched": len(pairs),
        "written": written,
        "kept": len(top),
        "with_mcap": with_mcap,
        "with_age_firstopen": with_age,
        "top": top[:20],
    }


if __name__ == "__main__":
    res = run(verbose=True)
    print(f"\nKlaar: {res['fetched']} paren opgehaald, {res['written']} geschreven, "
          f"{res['kept']} kandidaten (mcap>10M, vol>100k, age>=7d)")
