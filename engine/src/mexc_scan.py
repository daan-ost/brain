#!/usr/bin/env python3
"""
MEXC-marktscan: volatiele, handelbare USDT-paren ontdekken als rotatie-kandidaten + tijdreeks opbouwen.

Haalt de hele MEXC-spotmarkt op (2 bulk-calls, geen auth), joint met CoinGecko voor marketcap
(Demo-key vereist, env var CG_DEMO_KEY), verrijkt de kandidaat-set met candle-trend (klines 1d), en
schrijft naar de mexc-DB (db.mexc(), env-configureerbaar — lokaal MAMP, server eigen `mexc`-database):

  1. mexc_market_scan  — huidige snapshot (TRUNCATE + INSERT, atomair, alleen na geslaagde fetch)
  2. mexc_snapshots    — 4-uurs geheugen (APPEND): rang + orderboek-momentopname per kandidaat

Sorteersleutel = volat_pct (24u prijs-range); volume + mcap = liquiditeit-filters. Spread + orderboek-
druk (bid/ask uit ticker, gratis). Meerdaagse trend + schokkerigheid uit klines → auto_flag (faller/choppy).

Ontwerp + beslissingen: docs/findings/mexc-coin-tracking-2026-06-29.md
Gebruik stdlib urllib (geen requests-dependency). Nooit naar bot_signals.
"""
import json
import os
import sys
import time
import urllib.request
import urllib.error
from datetime import datetime, timezone

from db import mexc as mexc_db

MEXC_BASE = "https://api.mexc.com/api/v3"
CG_BASE = "https://api.coingecko.com/api/v3"
CG_DEMO_KEY = os.environ.get("CG_DEMO_KEY", "CG-Ued2J5LkYYVJhoGD9dYvHSHS")

HTTP_TIMEOUT = 30
CG_SLEEP = 1.2   # CoinGecko rate-limit: 100/min met demo-key, maar voorzichtig
CG_PAGES = 5     # pagina's /coins/markets (rank 1-1250, dekt mcap>10M)
CG_PER_PAGE = 250

# --- candidate-set: matcht de UI-default-filters (www/app/Livewire/Coins/MexcScan.php) ---
CAND_MCAP = 10_000_000   # mcap > 10M OF onbekend
CAND_VOL = 100_000       # vol24h > 100k
CAND_AGE = 7             # leeftijd >= 7d OF onbekend

# --- candle-trend (klines 1d) voor de kandidaat-set ---
TREND_INTERVAL = "1d"
TREND_LIMIT = 16         # candles ophalen: genoeg voor ret_14d (close 14d terug) + nu
TREND_MAX = 500          # harde cap op # klines-calls per run (runtime-begrenzing); log bij overschrijding
TREND_SLEEP = 0.04       # pauze tussen kline-calls

# --- auto_flag drempels (EERSTE GOK — later afstemmen op Daans goed/slecht-labels) ---
FALLER_RET7 = -25.0      # 7-daags rendement < -25% → 'faller' (daalt structureel)
CHOPPY_RANGE = 40.0      # gem. dag-range > 40% ...
CHOPPY_NET = 15.0        # ... én |7-daags rendement| < 15% → 'choppy' (veel beweging, geen richting)


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


def _f(v):
    """Veilige float-parse → None bij rommel/0-als-leeg waar dat zinvol is."""
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


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
# 2. MEXC ticker/24hr → price, change, volat, volume, spread, orderboek-druk
# ---------------------------------------------------------------------------
def _fetch_tickers():
    data = _get_retry(f"{MEXC_BASE}/ticker/24hr")
    tickers = {}
    for t in data:
        sym = t.get("symbol", "")
        high, low = _f(t.get("highPrice")), _f(t.get("lowPrice"))
        volat = ((high - low) / low * 100) if (high is not None and low and low > 0) else None
        bid, ask = _f(t.get("bidPrice")), _f(t.get("askPrice"))
        bid_qty, ask_qty = _f(t.get("bidQty")), _f(t.get("askQty"))
        spread = ((ask - bid) / bid * 100) if (bid and bid > 0 and ask and ask > 0) else None
        tot_qty = (bid_qty or 0) + (ask_qty or 0)
        pressure = (bid_qty / tot_qty) if (tot_qty > 0 and bid_qty is not None) else None
        tickers[sym] = {
            "price": _f(t.get("lastPrice")),
            "change24h_pct": _f(t.get("priceChangePercent")),
            "volat_pct": volat,
            "vol24h_usd": _f(t.get("quoteVolume")),
            "bid_price": bid, "ask_price": ask, "bid_qty": bid_qty, "ask_qty": ask_qty,
            "spread_pct": spread, "book_pressure": pressure,
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
                ages[sym] = (days, "kline")
            time.sleep(0.05)
        except Exception:
            pass
    return ages


# ---------------------------------------------------------------------------
# 5. Candle-trend (klines 1d) → meerdaags rendement, schokkerigheid, richting, auto_flag
# ---------------------------------------------------------------------------
def _trend_from_klines(candles):
    """candles = MEXC klines [openTime, open, high, low, close, volume, closeTime, quoteVolume]."""
    closes = [_f(c[4]) for c in candles]
    opens = [_f(c[1]) for c in candles]
    highs = [_f(c[2]) for c in candles]
    lows = [_f(c[3]) for c in candles]
    n = len(candles)
    if n == 0 or closes[-1] is None:
        return {}

    last = closes[-1]
    ret_7d = ((last - closes[-8]) / closes[-8] * 100) if n >= 8 and closes[-8] else None
    ret_14d = ((last - closes[-15]) / closes[-15] * 100) if n >= 15 and closes[-15] else None

    ranges = [(highs[i] - lows[i]) / lows[i] * 100
              for i in range(n) if lows[i] and lows[i] > 0 and highs[i] is not None]
    avg_range = (sum(ranges) / len(ranges)) if ranges else None
    up_days = sum(1 for i in range(n) if closes[i] is not None and opens[i] is not None and closes[i] > opens[i])
    down_days = sum(1 for i in range(n) if closes[i] is not None and opens[i] is not None and closes[i] < opens[i])

    flag = None
    if ret_7d is not None and ret_7d < FALLER_RET7:
        flag = "faller"
    elif avg_range is not None and avg_range > CHOPPY_RANGE and ret_7d is not None and abs(ret_7d) < CHOPPY_NET:
        flag = "choppy"

    return {
        "ret_7d_pct": ret_7d, "ret_14d_pct": ret_14d, "avg_day_range_pct": avg_range,
        "up_days": up_days, "down_days": down_days, "trend_window_d": n, "auto_flag": flag,
    }


def _fetch_trends(symbols, verbose=False):
    trends = {}
    capped = symbols[:TREND_MAX]
    if verbose and len(symbols) > TREND_MAX:
        print(f"  LET OP: {len(symbols)} kandidaten > cap {TREND_MAX} — trend alleen voor top-{TREND_MAX} op volat")
    for sym in capped:
        try:
            url = f"{MEXC_BASE}/klines?symbol={sym}&interval={TREND_INTERVAL}&limit={TREND_LIMIT}"
            data = _get_retry(url, retries=2, backoff=1.0)
            trends[sym] = _trend_from_klines(data or [])
            time.sleep(TREND_SLEEP)
        except Exception:
            trends[sym] = {}
    return trends


def _is_candidate(row):
    """Matcht de UI-default-filters: (mcap>10M of onbekend) AND vol>100k AND (age>=7 of onbekend)."""
    mcap = row["mcap_usd"]
    vol = row["vol24h_usd"] or 0
    age = row["age_days"]
    mcap_ok = (mcap is None) or (mcap > CAND_MCAP)
    age_ok = (age is None) or (age >= CAND_AGE)
    return mcap_ok and age_ok and vol > CAND_VOL


# ---------------------------------------------------------------------------
# 6. Join + build rows
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
            "rank_volat": None,
            "price": tk.get("price"),
            "change24h_pct": tk.get("change24h_pct"),
            "volat_pct": tk.get("volat_pct"),
            "vol24h_usd": tk.get("vol24h_usd"),
            "bid_price": tk.get("bid_price"), "ask_price": tk.get("ask_price"),
            "bid_qty": tk.get("bid_qty"), "ask_qty": tk.get("ask_qty"),
            "spread_pct": tk.get("spread_pct"), "book_pressure": tk.get("book_pressure"),
            "ret_7d_pct": None, "ret_14d_pct": None, "avg_day_range_pct": None,
            "up_days": None, "down_days": None, "trend_window_d": None, "auto_flag": None,
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

    return rows, fetched_at


def _rank_and_enrich(rows, verbose=False):
    """Bepaal de kandidaat-set (UI-default), ken rang toe op volat, en verrijk met candle-trend."""
    candidates = [r for r in rows if _is_candidate(r) and r["volat_pct"] is not None]
    candidates.sort(key=lambda r: r["volat_pct"], reverse=True)
    for i, r in enumerate(candidates):
        r["rank_volat"] = i + 1

    trends = _fetch_trends([r["symbol"] for r in candidates], verbose=verbose)
    for r in candidates:
        r.update(trends.get(r["symbol"], {}))
    return candidates


# ---------------------------------------------------------------------------
# 7. Atomic write: snapshot (truncate+insert) + history (append)
# ---------------------------------------------------------------------------
_SNAPSHOT_COLS = [
    "symbol", "base", "quote", "rank_volat", "price", "change24h_pct", "volat_pct", "vol24h_usd",
    "bid_price", "ask_price", "bid_qty", "ask_qty", "spread_pct", "book_pressure",
    "ret_7d_pct", "ret_14d_pct", "avg_day_range_pct", "up_days", "down_days", "trend_window_d", "auto_flag",
    "mcap_usd", "age_days", "age_source", "contract", "cg_id", "status", "fetched_at",
]
_HISTORY_COLS = [
    "symbol", "base", "rank_volat", "price", "change24h_pct", "volat_pct", "vol24h_usd",
    "bid_price", "ask_price", "bid_qty", "ask_qty", "spread_pct", "book_pressure", "snapshot_at",
]


def _write(rows, candidates, snapshot_at):
    """Snapshot = alle rijen (TRUNCATE+INSERT). History = alleen kandidaten (APPEND). Eén transactie."""
    conn = mexc_db(dict_cursor=False)
    conn.autocommit(False)
    try:
        with conn.cursor() as c:
            # 1. snapshot
            ph = ",".join(["%s"] * len(_SNAPSHOT_COLS))
            sql_snap = (f"INSERT INTO mexc_market_scan ({','.join(_SNAPSHOT_COLS)}, created_at, updated_at) "
                        f"VALUES ({ph}, NOW(), NOW())")
            c.execute("TRUNCATE TABLE mexc_market_scan")
            c.executemany(sql_snap, [[r.get(k) for k in _SNAPSHOT_COLS] for r in rows])

            # 2. history-append (kandidaten met een rang)
            if candidates:
                ph2 = ",".join(["%s"] * len(_HISTORY_COLS))
                sql_hist = (f"INSERT INTO mexc_snapshots ({','.join(_HISTORY_COLS)}, created_at) "
                            f"VALUES ({ph2}, NOW())")
                hist_rows = [[(r.get(k) if k != "snapshot_at" else snapshot_at) for k in _HISTORY_COLS]
                             for r in candidates]
                c.executemany(sql_hist, hist_rows)
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.autocommit(True)
        conn.close()
    return len(rows), len(candidates)


# ---------------------------------------------------------------------------
# 8. Main: run()
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

    if not CG_DEMO_KEY and verbose:
        print("  WAARSCHUWING: CG_DEMO_KEY niet gezet — marketcap wordt NULL voor alle coins.")

    if verbose:
        print("CoinGecko: markets + contracten ophalen...")
    mcap_map = _fetch_cg_markets() if CG_DEMO_KEY else {}
    contract_to_cg, symbol_to_cg_ids = _fetch_cg_contracts() if CG_DEMO_KEY else ({}, {})

    if verbose:
        print("Rows bouwen + join...")
    rows, fetched_at = _build_rows(pairs, tickers, mcap_map, contract_to_cg, symbol_to_cg_ids)

    if verbose:
        print("Kandidaat-set + rang + candle-trend (klines)...")
    candidates = _rank_and_enrich(rows, verbose=verbose)

    with_mcap = sum(1 for r in rows if r["mcap_usd"] is not None)
    with_age = sum(1 for r in rows if r["age_source"] == "firstOpenTime")
    n_faller = sum(1 for r in candidates if r.get("auto_flag") == "faller")
    n_choppy = sum(1 for r in candidates if r.get("auto_flag") == "choppy")

    if verbose:
        print(f"  {len(rows)} rijen, {with_mcap} met mcap, {with_age} met firstOpenTime-leeftijd")
        print(f"  {len(candidates)} kandidaten (rang toegekend); auto-flag: {n_faller} faller, {n_choppy} choppy")
        print("Snapshot + history schrijven...")

    written, appended = _write(rows, candidates, fetched_at)

    top = sorted([r for r in candidates if not r.get("auto_flag")],
                 key=lambda r: r["volat_pct"] or 0, reverse=True)
    if verbose:
        print(f"  {written} geschreven, {appended} history-rijen toegevoegd")
        print("  top kandidaten zonder auto-flag:")
        for c in top[:10]:
            r7 = c.get("ret_7d_pct")
            sp = c.get("spread_pct")
            r7s = f"{r7:+.1f}%" if r7 is not None else "n/a"
            sps = f"{sp:.2f}%" if sp is not None else "n/a"
            print(f"    {c['symbol']:18s} #{c['rank_volat']:<3} volat={c['volat_pct']:6.1f}%  "
                  f"7d={r7s:>7}  spread={sps:>7}  vol=${c['vol24h_usd']:>12,.0f}")

    return {
        "fetched": len(pairs),
        "written": written,
        "appended": appended,
        "kept": len(candidates),
        "fallers": n_faller,
        "choppy": n_choppy,
        "with_mcap": with_mcap,
        "with_age_firstopen": with_age,
        "top": top[:20],
    }


if __name__ == "__main__":
    res = run(verbose=True)
    print(f"\nKlaar: {res['fetched']} paren, {res['written']} geschreven, {res['kept']} kandidaten, "
          f"{res['appended']} history-rijen ({res['fallers']} faller / {res['choppy']} choppy uitgevlagd)")
