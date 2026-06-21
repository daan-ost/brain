---
name: brain-promising-labeler
description: How the per-moment Promising labeler works — labeling buy-moment quality (upside, sell-independent) vs sell-result (profit_loss), the coin_moment_labels store with natural key (coin+datetime+rule) that survives the re-fire, the legacy ok_trade/result import, and how labels tune the promising thresholds + feed the rule-success counts. Use when building or changing the labeler screen, the label store, the legacy import, or the label→threshold/rule-success feedback loop.
---

How we hand-label "promising" buy-moments and tune the auto-classification from those labels.
Build spec: `docs/epics/epic-L-promising-labeler.md`. Two invariants this protects:
**(1) buy-moment quality is NOT the sell result**, and **(2) manual labels must never live on
`coin_fires`** — the re-fire deletes that table.

## The two metrics — never conflate

- **Buy-moment quality** = `coin_fires.best_upside` (max favorable excursion over `FORWARD_MINUTES`,
  sell-INdependent) + the multi-horizon upsides (+5/+10/+15/+30/+45/+60 min) + `lowest_10` (early dip
  over the first ~10 ticks). Answers "was there money to make?". This is what `manual_klasse` labels
  and what `promising.py` tunes against.
- **Sell result** = `coin_fires.profit_loss` (what the sell-engine actually realised). A row with high
  upside but negative `profit_loss` is a **sell-engine defect**, NOT a bad buy-moment. Keep it in its
  own column, colour it red, never let it pollute the promising tuning. Sell-engine fixes = Epic S.

## Auto-classification (single source of truth)

- Upside klasse thresholds live in `engine/src/opt_lib.py` and are mirrored in `CoinFire::klasseKey()`:
  goed ≥ 3% / middel 0.5–3% / slecht < 0.5% (on `best_upside`) — used for the chart dot colours.
- **The labeler's own unified promising-definitie** (filter == auto column, `PromisingLabeler::isPromising`):
  `up5 ≥ PROM_UP5 (0.5%) AND up15 ≥ PROM_REACH (3%) AND vroege_dip ≥ PROM_DIP (−0.5%) AND spike_iso <
  PROM_SPIKE_ISO (3%)`. I.e. rises a bit within +5min, reaches ≥3% WITHIN +15min, no early dip worse than
  −0.5%, and the 60m peak is NOT an isolated 1-tick spike (`spike_iso` = how far BOTH neighbour ticks sit
  below the peak; a sharp spike-and-crash you can't trade in time). autoKlasse: goed = promising, else
  middel (max60 ≥ 0.5) / slecht. **Calibration on Daan's ok/niet-ok marks** (important): dip10, up5, up15
  and peak-isolation (extreme tail only — ok p90≈2%, spikes 6-7%) discriminate; **maxDD / reversals /
  below-entry / pullback-ratio / close-retention / sustain do NOT** (ok and niet-ok fully overlap), so a
  generic "too volatile/whippy" BR is NOT possible — that realisability is the sell-engine's job (punt 4).
  These constants are the tunable knobs + the future default-fill.
- Promising verdict (`in_good_period`) gates in `engine/src/config.py` (verified): `FORWARD_MINUTES=60`,
  `MIN_UPSIDE_PCT=5.0`, `MAX_EARLY_DIP_PCT=-0.1`, `MIN_DURATION_MINUTES=10`, `DROP_BELOW_PCT=-0.3`,
  per-coin `UPSIDE_MINUTES_PER_COIN={2525:25, 244:45}` (DOGEAI fast, NOS slow; default 30).

## Promising business rules (BR) — catalog + how to find a new one

Every BR is one extra clause in `PromisingLabeler::isPromising()` with a tunable `PROM_*` constant; the
metric it tests is computed in `metricsFrom()` and threaded via `promInputs()`. The whole point of a BR
is to mark "the upside is real BUT you could not realistically trade it" → exclude from promising.

**Catalog (each born from a Daan example that should NOT be promising):**

| BR | constant | metric (`metricsFrom`) | excludes when | example |
|----|----------|------------------------|---------------|---------|
| A — no early rise | `PROM_UP5=0.5` | `hz[5].up` | +5m < 0,5% | flat eerste 5 min |
| B — never reaches | `PROM_REACH=3.0` | `hz[15].up` | +15m < 3% | komt nooit 3% binnen 15 min |
| early dip | `PROM_DIP=-0.5` | `low10` | vroege dip < −0,5% | zakt eerst weg na entry |
| C — 1-tick spike | `PROM_SPIKE_ISO=3.0` | `spike_iso` | piek-isolatie ≥ 3% | NOS 244 2024-02-02 18:33:50 |

`spike_iso` = `min(drop naar linker buur-tick, drop naar rechter buur-tick)` van de 60m-piek, in %. Hoog
= geïsoleerde spike-en-crash (onverhandelbaar); laag = vloeiende rise.

**The method to find a new BR (data-driven, READ-ONLY — see data-safety gotcha):**
1. Daan vinkt een voorbeeld af dat eruit moet (ok/niet-ok marks zijn de ground truth).
2. Bedenk een kandidaat-metric en bereken hem over ALLE `decision='yes'` (ok) marks vs de niet-ok marks.
3. Vergelijk de verdelingen (percentielen p50/p90). Een metric is **alleen een bruikbare BR als hij
   scheidt** — ok en niet-ok mogen niet volledig overlappen. Kies een drempel die de slechte staart
   vangt en nauwelijks goede marks raakt (conservatief: liever een paar spikes laten staan dan goede
   rises knippen).
4. Verifieer met een read-only Pest test (`->group('brain-db')`): bad-voorbeeld nu niet-promising,
   bekend goed voorbeeld (DOGEAI 2525 2025-02-14 16:23:56) nog steeds goed.

**Metrics die NIET scheiden (bewezen op Daans marks — geen BR mogelijk):** maxDD, reversals,
below-entry, pullback-ratio, close-retention, sustain. ok en niet-ok overlappen volledig → er is GEEN
generieke "te volatiel/choppy" promising-BR. Die realiseerbaarheid hoort bij de sell-engine (punt 4),
niet bij de promising-definitie. Spike-en-crash (BR C) is de uitzondering die WÉL vangbaar bleek.

## The label store — `coin_moment_labels` (NOT coin_fires)

Natural key `(trading_symbol_id, datetime, rule, source)`. `source` = 'manual' | 'legacy'.
Columns: `decision` (yes/no/no_volume = legacy ok_trade), `manual_klasse` (goed/middel/slecht,
overrides `klasseKey`), `category` + `comment` (`CoinAnnotation::CATEGORIES`), `legacy_result` (1/2/3).

**Manual labels are MOMENT-level** (rule-independent): "was this datetime a good entry?" doesn't
depend on which rule fired, and it feeds the per-datetime promising tuning. So Daan's own labels use
`rule = CoinMomentLabel::MOMENT_RULE (0)` and are matched to fires/moments by datetime only
(`CoinMomentLabel::momentKey()` / `manualByMoment()` / `attachManual()` / `attachOne()` / `setManual()`).
Imported legacy labels stay per-rule (a legacy trade had a rule) as reference. `setManual()` writes the
row, or DELETES it when all fields are empty (clearing a label = intrekken; never an empty row).

**WHY a separate table — the overschrijf-risk:** `engine/src/persist_to_brain.py:45-46` does
`DELETE FROM coin_fires` + `DELETE FROM coin_periods` per symbol on EVERY re-fire and re-inserts
WITHOUT any manual label (the INSERT column list has no `manual_klasse`). Anything stored on
`coin_fires.manual_klasse` is gone the next time a routine runs. `coin_moment_labels` is never touched
by persist_to_brain → labels survive. (The old `coin_fires.manual_klasse` string column had no enum
constraint and was wiped on re-fire — it is superseded by this table.)

Override precedence in `CoinFire::klasseKey()`: **manual label > legacy label > computed best_upside**.
Eager-load the label relation in any list view to avoid N+1.

## Two legacy label sources

There are TWO separate owner-labelings in the legacy DB — both imported by `import_legacy_labels.py`:
- **`wp_trading_simulation.result`** (1=goed/2=middel/3=slecht) = quality → `source='legacy'` (the "legacy"
  column reference). Per-coin rebuild.
- **`wp_trading_simulation_trades_result.ok_trade`** (1=ja / 2=nee / 3=geen-volume) = the owner's explicit
  **ok/niet-ok** decision → imported as `source='manual'` moment-level decisions (rule=0, set_by='legacy-ok'),
  **only-if-absent** so in-app marks set via the screen are never overwritten. These populate the "mijn"
  column + drive the grouping (it's the owner's confirmed entries). ok_trade=3 doesn't correlate with any
  trade (no volume → no trade), hence 'no_volume'. ~2161 imported over 42 coins (2525: 84 yes/259 no/94 nv).
  Both align the +5s buy-time via `align_legacy_dt`; dedup per snapped datetime (latest ID wins).

## Legacy import (result detail)

Source: `bot_signals.wp_trading_simulation.result` (1=goed, 2=middel, 3=slecht, NULL=unlabeled).
Read-only via the `bot_signals` connection. **Full migration**: `import_legacy_labels.py` with NO args
imports ALL labeled trades, ALL coins, ALL rules (4.161 rows over 74 coins) into `coin_moment_labels`
(source='legacy'); pass coin ids to limit. The `rule` column keeps the legacy rule; the labeler folds to
moment-level (strongest verdict wins, see `legacyByMoment`). Coins without brain indicators land at
`legacy_dt − 5s` (no ticks to snap to) — **re-run the import for a coin after it's built** in brain so
its labels snap to the fresh ticks (idempotent: per-coin DELETE + re-insert). This re-run is the
**onboarding hook** for every new coin. (Tracked slice context: DOGEAI 2525, NOS 244 are heavily
slecht-skewed on rules 20–23, which is why success criterion 1 (#goed ≥ 2×#slecht) fails.)

**+5s offset (critical):** legacy buy datetimes = the signal tick **+ 5s** (the live engine's wait —
see [[bot-signals-schema]]), so an exact join misses ~100%. The import and `persist_to_brain.py`'s
legacy join both `align_legacy_dt()` (subtract 5s + snap to the nearest tick, `engine/src/align.py`)
so labels land on the real moment (16:24:01 → 16:23:56). Without it `coin_fires.legacy_result` is 100%
NULL and the labeler shows no legacy labels. The labeler reads legacy per moment via
`CoinMomentLabel::legacyByMoment()` (so it shows on non-trade moments too, not just via the fire).

`persist_to_brain.py` joins `wp_trading_simulation` (aligned) as `coin_fires.legacy_result` /
`legacy_profit_loss`. The import turns those into `coin_moment_labels` rows with `source='legacy'`
(rebuild: it DELETEs the coin's legacy rows first, since aligning changes their datetime), mapping
`{1:goed,2:middel,3:slecht}` → `manual_klasse`, `{1:yes,3:no}` → `decision`, `result` → `legacy_result`.
Show legacy vs manual side
by side in the labeler — divergence is the learning signal; it often comes down to a single datetime.

## Feedback loop (decision: visible-only first)

1. **Threshold tuning (advice-only)** — `promising._validate(symbol, order)` (promising.py:147) already
   validates the verdict against the labels; extend to a grid-search over `MIN_UPSIDE_PCT` /
   `MAX_EARLY_DIP_PCT` / `upside_minutes(symbol)` per coin → per-coin precision/recall → proposed
   config.py thresholds. A human edits config.py; never auto-apply.
2. **Rule-success counts** — `engine/src/daily_optimization.py:54` `current_ratios()` counts raw
   `SUM(best_upside>=3)` / `SUM(best_upside<0.5)` on coin_fires and **ignores labels**. Add a SECOND
   ratio that LEFT JOINs `coin_moment_labels` (source='manual') and uses the effective klasse
   (manual > best_upside). Show BOTH ratios (raw vs labelled) in the routine journal. Per the
   decision, labels do NOT yet drive tightening — visible-only until validated.

## The labeler screen

Route `/promising-labeler` (`trades.labeler`), Livewire `App\Livewire\Trades\PromisingLabeler`,
admin-only. **MOMENT-level**, not fire-level: it iterates EVERY distinct volumeud datetime of the day
(`series()` loads the raw day + 60min tail; `dayMoments()` dedups by `momentKey`), so moments where NO
rule fired are visible too. Per moment the +5/+10/+15/+30/+45/+60m upside + early dip are computed on
the fly (`metricsFrom()`, single forward pass — horizons nest, so one pass snapshots all).

Filter (`$view`): `promising` (default — max horizon upside ≥ `$minUpside`, default 3%) | `all` |
`trades` | `executed`. Non-promising views compute horizons only for rendered rows; promising must
compute for all to filter. Rows capped at `ROW_CAP=1500` (a full day ≈ 1000–1160 distinct datetimes
for the slice coins, so `all` rarely truncates); a banner shows the total when capped.

Quick **inline ok/niet-ok** (✓/✗ buttons, `wire:click.stop="setDecision(key, 'yes'|'no')"`) saves the
moment decision INSTANTLY — no modal; clicking the active one again clears it. Quality + reason go via
the row's modal (`selectMoment` → `saveLabel`). Uses the shared `InteractsWithCoinChart` trait +
`zoomChart()`/`labelerChart()` Chart.js stack; horizon-peak markers on the zoom chart. Columns: time |
trade(rule) | ok? | +5..+60m | max up% | dip% | OUR sell% | auto | legacy | my label. Red row =
positive upside but negative profit_loss = sell-engine left money behind. Sidebar link in
`layouts/trading.blade.php` after "Coin explorer", using the `route()` + `routeIs()` pattern.

## Grouping (one rise = one trade)

Grouping is over **ok-marked moments only** (`decision='yes'`), NOT the auto-promising set — the owner
decides the entries; the auto-classification is only a (future) default fill. `dayMoments` pass 2 walks
the yes-moments in time order and starts a NEW group when the gap to the previous yes-moment
> `GROUP_GAP_MIN` (5 min), OR a price drop ≥ `GROUP_DROP_PCT` (1%) occurred between them
(`dropBetween`, min-scan from the prev yes-price). So a >5min gap or a ≥1% dip = a separate trade. Re-runs
on every tick (setDecision resets the memo). **Cross-midnight:** the series is loaded with a
`GROUP_LOOKBACK_MIN` (12 min) lookback before startOfDay, and pass 2 SEEDS prevTs/prevI with the last
ok-moment before the day, so a rise crossing midnight continues into the new day instead of starting a
fresh group — such a group shows lead "↩ HH:i" (continues from yesterday). The table shows a coloured left border + "groep" column
(lead · size) on grouped rows; the modal lists the group's yes-members (clickable). Verified: 20:35 solo,
20:42 solo, 20:48–20:53 one group, 21:07–21:16 one group.

**Manual override (boundary model):** `coin_moment_labels.group_break` per ok-moment overrides the
auto boundary — `'break'` forces a new group here (uncouple/split), `'join'` forces staying with the
previous group (couple, even if the gap/drop rules would split), `null` = auto. `setGroupBreak()` toggles
it; the modal shows ⛓ koppel-aan-vorige / ✂ ontkoppel-hier per ok-moment. Lives on coin_moment_labels →
survives the re-fire. Pass 2 applies it via a `match` before the gap/drop fallback.

**Data-safety note:** never mutate real labels in a throwaway script via `updateOrCreate` + delete-by-set_by
on real datetimes — it clobbers the owner's labels if those datetimes were already labelled. Test read-only,
or snapshot+restore.

## Display + moment source

- **Times are shown AS-STORED (UTC = the indicator/legacy tables), no Amsterdam conversion.** Converting
  to Amsterdam (+1h) made the screen mismatch the source data the owner cross-references (a UTC 16:26:12
  tick showed as 17:26:12). `InteractsWithCoinChart::localFmt()` does NOT setTimezone; the chart JS forces
  `timeZone:'UTC'`. Keep it that way.
- **Moments = volumeud ticks** (the valid buy-moments), NOT every indicator datetime. Every buy rule
  (20-23) has a volumeud `currentvalue` subrule with **operator=time_ago, condition_rule=5** (legacy IDs
  1199/1228/1260/1283) — the volumeud must be **≤5s fresh**. On a non-volumeud datetime (an obv/vzo tick)
  the last volumeud is stale → that subrule can't fire → it's not a valid buy-moment. So `series()` /
  `priceBetween()` use volumeud only. The OTHER indicators ARE present and read AS-OF (last value ≤ T)
  at each volumeud tick — "all indicators in it" = available as-of, not "every indicator's tick is a row".
  (This is why a legacy buy = volumeud signal tick + ≤5s; see [[bot-signals-schema]] +5s offset.)

## Auto-ok labeling (tijdsbesparing — `php artisan trades:auto-ok`)

Handmatig elk promising moment op ok tikken is traag. `app/Console/Commands/AutoOkLabels.php` zet sterke
momenten automatisch op `decision='yes'`. Het werkt op `coin_moment_sells` (= de promising-universe) met
twee knoppen: `--sell` (min onze-sell-winst %) en `--min` (min minuten-in-trade). Default DRY-RUN, `--run`
schrijft. **Veiligheid:** slaat elk moment met een bestaand handmatig label over (overschrijft NOOIT je
ok/niet-ok), schrijft `set_by='auto-ok'` zodat `DELETE FROM coin_moment_labels WHERE set_by='auto-ok'`
alles terugdraait.

**Gekalibreerd op Daans marks (DOGEAI 2525, 8 dagen, 160 ok / 28 niet-ok):**
- De recall-gap is volledig de sell-drempel: 84 van 160 ok-marks hebben sell ≤8%, dus 8% mist de helft.
- De **minuten-in-trade is de veiligheidsknop**, niet de cluster: 3→15 min laat conflict van 6→1 zakken
  (weert de snelle pumps die je in de praktijk niet pakt; sold in 6/12 min = de 2 echte conflicten).
- De cluster-eis ("≥3 binnen 5 min") voegt GEEN veiligheid toe (A/B/C/D allemaal 3 conflicten op de
  hele set) en kost alleen recall → weggelaten.
- Sweet spot = **sell≥5% + min≥15** → ~61% recall, 1 conflict op 28. sell≥3/min≥15 = 69% recall, 6 conf.
  Methode = [[parent-gate-gemene-deler]]-stijl: regel toetsen tegen je marks (recall) én je niet-ok (conf).

## Sell-engine per moment (punt 4 — PREPARED, not run)

`coin_moment_sells` (coin, datetime) holds the sell-engine outcome per buy-moment (P&L, exit, hi/lo,
minutes) so the labeler shows realised P&L next to buy-quality for ANY promising moment — not just the
rule-fires. `engine/src/sell_promising.py` fills it (sell-engine over every promising moment), but is
**PREPARED, not run**: the sell-engine is parked/being improved (Epic S). It defaults to a DRY-RUN
(counts + samples); `--run` computes + writes. Until then the labeler's "onze sell%" falls back to the
executed-fire profit_loss, and the modal shows "⏳ nog niet berekend" for promising moments without a
sell. `CoinMomentSell::byMoment()` attaches it; precedence in the row = sell-store > executed fire > null.
The per-moment SL rule in the script is a placeholder (fire's rule, else 20) — refine with Epic S.

## Gotchas

- Validate `manual_klasse` against the enum on write — the old string column silently accepted typos.
- Horizon upsides use TRUE time windows (the legacy `simulate_buy` used count/12 index fractions —
  do NOT copy that). Early dip `lowest_10` is over the first ~10 TICKS (volumeud is event-driven), not
  10 minutes.
- `bot_signals` is read-only — the import SELECTs only; labels are written to the brain DB.
- **Data-safety (label store):** NEVER probe/tune against real labels with `updateOrCreate` + a
  delete-by-`set_by` cleanup on real datetimes — that once deleted Daan's own yes-marks. When finding a
  new BR or testing, run READ-ONLY (Livewire `->viewData()` / SELECT) only, or snapshot + restore. Real
  manual marks (`set_by` = a real e-mail, e.g. daan@interus.nl) are ground truth — treat as precious.
- After a re-fire, optionally re-fill `coin_fires.manual_klasse` from `coin_moment_labels` for
  backward-compat consumers, matched on `(trading_symbol_id, datetime, rule)`.

## Related skills

[[bot-signals-schema]] (legacy tables + the oracle), [[brain-engine]] (the pipeline + promising),
[[brain-routines]] (daily optimization chain), [[brain-rule-tuning]] (subrule thresholds),
[[brain-indicator-metrics]] (the calc cache).
