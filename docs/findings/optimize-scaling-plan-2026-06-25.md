# Schaalplan optimize-keten voor 10+ coins — 2026-06-25

Uit een multi-agent ontwerp-workflow (15 agents: 4 understand → 5 design → 5 adversarieel → 1 synthese).
Doel: de optimize-keten werkbaar maken voor 10+ munten via caching/incrementeel, zónder één statistische
poort (LOO cross-coin, 70/30 tijd-holdout, toeval-toets + Šidák) te versoepelen.

## Kern-diagnose

Drie kosten groeien mee met het muntenuniverse en niets wordt bewaard:
1. **`build_indicator_metrics`** herbouwt ELKE munt volledig (DELETE+INSERT van 10-29M rijen + parquet),
   ook als er niets veranderde.
2. **`load_long`** doet de DuckDB-join+melt elke run opnieuw, herhaald door elk rq-subprocess.
3. **`full_validation` / pairs-zoektocht** her-scant de groeiende long-tabel (22M rijen bij 4 munten)
   per kandidaat per split → kwadratisch in munten. **Cruciaal geverifieerd:** de 3u-muur zit in
   `pairs()` (`rq1_tighten.py`), die alleen op `--pairs` draait. De DAGELIJKSE routine doet alleen
   singles — fase 1-3 maken de dagelijkse keten al werkbaar.

## Gefaseerd plan (oplopend risico)

| fase | wat | effort | status |
|---|---|---|---|
| **0** | profit_loss/cls-checksum in de fingerprint + test-vangnet (`test_opt_lib.py`) | klein | ✅ gedaan |
| **1** | `build_indicator_metrics` skip-on-unchanged (eigen scope-fingerprint + state-tabel) | klein | ✅ gedaan |
| **2** | gematerialiseerde per-coin long-parquet + `load_long_cached()` | middel | open |
| **3** | gratis validatie-snoei: SCALE_UNSAFE-prefilter + early-exit op classify-min + groep-cache | middel | open |
| **4** | groupby-vectorisatie van `full_validation` + pairs (de echte 10-coin-muur) — ACHTER orakel-test | groot | open |

### Fase 0 (gedaan)
- `routines.input_fingerprint(with_fires)`: classificatie-checksum `SUM(CRC32(coin|datetime|rule|cls))`.
  Vangt een goed↔slecht-RUIL bij gelijke counts (de blindspot uit [[routine-fingerprint-blindspot]]),
  invariant onder magnitude-drift binnen een klasse. **SUM, niet BIT_XOR** — CRC32 is lineair over XOR,
  dus een gelijk-lengte ruil cancelt onder XOR (door `test_opt_lib.py` gevangen).
- `test_opt_lib.py`: vangnet dat de validatie-kern (bad_edge_conditions, scale_unsafe, sidak,
  full_validation orakel, crosscoin LOO, de checksum-eigenschap) vastlegt — de referentie voor fase 4.

### Fase 1 (gedaan)
- `build_indicator_metrics.py`: `_metrics_fingerprint(SYM)` over de SCOPE-drivers (indicators, coin_fires-
  datetimes via SUM(CRC32), coin_periods-vensters, ok-labels, min_volume, code-versie-hash). Tabel
  `indicator_metrics_state`. Gelijke fingerprint + bestaand parquet + geen `--force` → skip. De cache is
  uitkomst-ONAFHANKELIJK, dus geen profit_loss in deze fingerprint (alleen de scope-datetimes tellen).

## Wat we bewust NIET doen (overleefde de adversariële toets niet)

- ❌ **TOP-K cap** op kandidaten — verschuift de SAFE-set hard (SAFE-verdicts staan niet bovenaan
  drop_insample). Geen neutrale snoei.
- ❌ **Dagelijks op minder munten valideren** — kleinere LOO-set = minder splits + lagere Šidák n_hyp =
  objectief zwakkere lat. Cadans/scope mag verschillen, de TOETS niet.
- ❌ **Append-only long-shards** — mist dat een verschoven coin_period oude datetimes van scope wisselt;
  load_long JOINt de parquet-glob zonder dedup. Houd skip-of-volledige-rebuild per coin.
- ❌ **Fase 4 zonder bit-voor-bit orakel-test** — strikt-<-vs-≤, round-niveaus en NaN-semantiek breken
  daar stil. Houd de oude `full_validation` als referentie-orakel.

## Eindstaat bij 10 munten
Na fase 0-3: de dagelijkse run is geen blocker meer; runtime schaalt met het aantal ACTIEVE munten
(coins.coin_age_class), niet het totaal. Na fase 4: ook de deep-sweep van uren → minuten, lineair in
long-rijen i.p.v. kwadratisch — zonder versoepelde gate. Zie ook A1-A4+B3 ([[coins-universe-4]]) die het
fundament (skip-fingerprint, parallel refire, live-log, MySQL-stabiliteit) al legden.
