# MEXC coin-tracking & classificatie — ontwerp + beslissingen (2026-06-29)

**Status:** Ontwerp vastgelegd, bouw gestart. Deploy wacht op SSH-go (zie §7).
**Aanleiding:** Daan wil systematisch de beste rotatie-munten leren kiezen op `/coins/mexc`. Drie wensen:
1. Per 4 uur vastleggen waar een munt staat (rang + data) → tijdreeks als basis voor business rules.
2. Munten in de lijst kunnen classificeren (niet beoordeeld / goed / slecht), slechte verdwijnen.
3. "Alleen-dalers" (volatiel puur omdat ze kelderen) eruit filteren.

Doel op termijn: steeds betere regels die zeggen *deze munt wél/niet traden*.

---

## 1. Beslissingen (door Daan bevestigd)

| # | Vraag | Beslissing |
|---|---|---|
| 1 | Cadans-uitvoering | **Job op de server** (niet lokaal op de Mac) — gegarandeerd elke 4 uur |
| 2 | Koop/verkoop-volume | **Gratis spread + orderboek-druk eerst**; echte koop/verkoop-trades pas later voor een shortlist |
| 3 | Redenen bij "slecht" | daalt · niet tradebaar · te schokkerig · te weinig volume · scam-vermoeden |
| 4 | Bewaartermijn history | Alles bewaren, later evalueren |
| 5 | DB-locatie | **Eigen DB op de server** (`mexc`), losgekoppeld van de brain-DB op de Mac |
| 6 | Runtime | **Python** (hergebruik `mexc_scan.py`); geen PHP-herschrijving |
| 7 | Server | De bestaande **66bio-VPS** (`116.203.78.110`, Hetzner, Ubuntu 24.04 + MySQL 8.0 + cron) |

---

## 2. MEXC API — wat wél/niet beschikbaar is (geverifieerd 2026-06-29)

**Gratis in bulk (2 calls voor de hele markt — `exchangeInfo` + `ticker/24hr`):**
- prijs, 24u-wijziging, 24u high/low (→ volatiliteit), volume, quoteVolume (USD)
- **bidPrice / askPrice + bidQty / askQty** → spread (liquiditeit) + koop/verkoopdruk bovenaan het orderboek
- leeftijd (`firstOpenTime`), status

**NIET gratis in bulk:**
- **Aantal trades**: `count` komt als `null` terug van MEXC.
- **Echte koop/verkoop-volume per periode**: de 4u-candles (`klines`) bevatten géén taker-buy/sell-splitsing
  (Binance wel, MEXC niet — de array stopt bij `quoteVolume`). Enige bron is `/trades` per munt, en dat is
  een momentopname van de laatste minuten → te zwaar + niet representatief voor 1700 munten.

**Wél historisch op te vragen — sleutelinzicht:**
- `klines?interval=1d&limit=500` geeft **tot 500 dag-candles** per munt, gratis. Dus de **meerdaagse trend**
  (daalt-ie alleen maar?) halen we direct uit de API; die hoeven we NIET zelf op te bouwen.
- Voorbeeld BTWUSDT (2026-06-29): 0,133 → 0,054 in 9 dagen = **−59 %** met enorme dag-ranges → exact het
  "volatiel maar waardeloos"-patroon, direct meetbaar.

**Gevolg voor het ontwerp:** alleen wat je *niet* kunt reconstrueren slaan we zelf op:
- **rangpositie** in de lijst (bestaat alleen als je 't op het moment vastlegt)
- **spread + orderboek-druk** (momentopnames)

Prijs/volume/volat-historie komt uit `klines`, niet uit eigen opslag.

---

## 3. Architectuur

### Deel A — scan verrijken met trend uit candles
Voor de munten die door de basisfilters komen (mcap > 10M, vol24h > 100k, leeftijd ≥ 7d → ~250 stuks)
halen we per run de dag-candles en berekenen:
- **meerdaags rendement** (close nu vs N dagen terug) → daler-signaal
- **gemiddelde dag-range** → schokkerigheid
- **richting-consistentie** (dagen omhoog vs omlaag / trend vs intraday-ruis)

Deze kolommen komen in de scan-tabel → meteen zichtbaar + filterbaar.

### Deel B — 4-uurs geheugen (klein, append-only)
Tabel `mexc_snapshots`: per munt per run één rij met rang + spread + orderboek-druk + ankerprijs +
tijdstempel. Goedkoop (~250–1700 rijen × 6/dag).

### Deel C — classificatie + auto-daler
- `mexc_coin_labels` (op munt-identiteit `base`, overleeft de truncate): classification
  (`unrated`/`good`/`bad`) + redenen (set) + notitie.
- Auto-vlag "alleen-daler / schokkerig" uit Deel A → standaard onderaan/verborgen, mét reden.
- **UI komt later** (zie §6): de radio-knoppen + filters hangen aan de Livewire-laag, en die moet eerst
  bij de server-DB kunnen.

### De loop naar business rules
Jouw goed/slecht-labels (B/C) = de ijklat; de gemeten kenmerken per 4 uur + de candle-trend (A) = de
leerdata. Daarmee stemmen we de auto-filters af op jouw oordeel en groeien de traden-of-niet-regels.
Zelfde patroon als de bestaande promising-labeler, maar voor muntkeuze.

---

## 4. Afgewezen / geparkeerde alternatieven

- **Zelf 4-uurs prijs/volume-history opbouwen** → overbodig: `klines` levert 500 dagen direct. Alleen
  rang + spread + druk zelf opslaan.
- **Koop/verkoop-volume via `/trades` per munt** → te zwaar (1 call/munt) + niet representatief. Geparkeerd
  tot we het voor een kleine shortlist willen (beslissing #2).
- **Job lokaal op de Mac (launchd)** → afgewezen: Mac staat niet altijd aan → gaten in de 4-uurs reeks.
- **Job op server → brain-DB op de Mac via Tailscale** → afgewezen: dan moet de Mac alsnog aan + MySQL
  open. Daarom eigen DB op de server (beslissing #5).
- **Scan in PHP herschrijven (conform server-stack)** → afgewezen: code bestaat al in Python, geen winst
  (beslissing #6).

---

## 5. Tabellen (eigen DB `mexc` op de server)

| Tabel | Rol | Schrijfwijze |
|---|---|---|
| `mexc_market_scan` | huidige snapshot (zoals nu) + bid/ask + trend-kolommen | TRUNCATE + INSERT per run |
| `mexc_snapshots` | 4-uurs geheugen: rang/spread/druk/ankerprijs | APPEND per run |
| `mexc_coin_labels` | classificatie per munt (`base`) | upsert vanuit UI |

DB-verbinding wordt **configureerbaar** (env): lokaal default = MAMP, op de server = `mexc`-DB.

---

## 6. UI-spanning (eerlijk benoemd)

Met "eigen DB op de server" komt de scan-data op de VPS, maar het huidige `/coins/mexc`-scherm draait op
de Mac en leest de brain-DB. Die twee staan dan los. Volgorde:
1. **Nu:** de verzamel-job (A+B) draaiend krijgen op de server — elke dag uitstel = een dag minder history.
2. **Daarna:** de classificatie-UI (C) + filters, zodra de UI-laag bij de server-DB kan (of meeverhuist
   met de nobrainersbot.com-migratie — zie `docs/deployment/qr-server.md`, die doen Daan + Claude samen).

---

## 7. Open punten

- **SSH-deploy-go:** het VPS-IP (`116.203.78.110`) is afgeleid uit `66bio/.../migrate-all.sh` + known_hosts,
  niet door Daan in dit repo genoemd → de Claude Code-veiligheidslaag blokkeert root-SSH tot Daan het target
  bevestigt (en bij voorkeur als SSH-config-host + permissie vastlegt). Blokkeert alleen de deploy, niet de bouw.
- **DB-naam op de server:** voorstel `mexc` (eigen, los van de geplande `nobrainers`-feed-DB van epic-TV).
- **Backups:** valt de nieuwe `mexc`-DB onder de bestaande 66bio-backup-routine? Checken bij deploy.

---

## Referenties
- Server-runbook: `docs/deployment/qr-server.md`
- Scan-code: `engine/src/mexc_scan.py`, routine `engine/src/routines.py` (`routine_mexc_scan`)
- UI: `www/app/Livewire/Coins/MexcScan.php`, `www/resources/views/livewire/coins/mexc-scan.blade.php`
- 66bio server-infra: `66bio/deploy/` (setup-vps.sh, Caddyfile, deploy-site.sh)
