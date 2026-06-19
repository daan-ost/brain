# Volatiele MEXC-coins ontdekken — bron, join, volatiliteit-maat en leeftijd

**Datum:** 2026-06-19
**Scope:** een aparte kandidaten-tab onder /coins ("Munten") die volatiele MEXC USDT-paren toont,
gefilterd op marketcap (>10M), 24u-volume en leeftijd. READ-ONLY onderzoek — er is nog niets gebouwd.
Vier dimensies adversarieel geverifieerd tegen de live MEXC- en CoinGecko-API's: MEXC-API-hardening,
marketcap-bron & join, coin-leeftijd, en integratie-architectuur. Bouwt voort op een **werkend prototype**.
**Artefacten (referentie):** het prototype (MEXC ticker/24hr × CoinGecko marketcap, join op base-symbool,
filter mcap>10M, sorteer op 24u-volatiliteit, leeftijd via MEXC klines) draaide al live met reële top:
**ASTEROID** (+88%, volat 164%, 24u-vol $4,5M, mcap 68M, 63d), **VELVET** (mcap 206M, 344d) en de
illiquide uitschieter **MMUI** (volat 972% maar 24u-vol slechts $19k — precies wat het volume-filter moet weren).

---

## High-level vraag

We zoeken **volatile trades** en willen kunnen **roteren** tussen veel coins (de #1 blocker uit eerder
onderzoek: te weinig coins leven gelijktijdig). Kan een dagelijkse scan op de hele MEXC-markt betrouwbaar
**volatiele, handelbare, niet-te-jonge USDT-coins** als kandidaten leveren — gefilterd op marketcap en
volume — zonder API-bans, zonder verkeerde marketcaps, en passend in de bestaande brain-architectuur?

## Antwoord in één alinea

**Ja, en het is bouwbaar binnen de bestaande conventies — met twee scherpe voorwaarden.** MEXC's publieke
spot-API levert alles wat we nodig hebben in **twee goedkope bulk-calls** (geen auth, ruim binnen de
rate-limits), inclusief de **exacte listingdatum** (`firstOpenTime`) voor 97,5% van de paren. Marketcap
moet van **CoinGecko** komen (MEXC heeft géén native marketcap — het gebruikt zelf third-party data), en
dat is de eerste voorwaarde: de gratis Demo-tier mág commercieel, maar **vereist een zichtbare
"Powered by CoinGecko"-attributie** en een gratis Demo-key (10.000 calls/maand — ruim voldoende). De
tweede voorwaarde is de **join**: contractadres als primaire sleutel (collisie-vrij), symbool alleen als
unieke fallback — en de feitelijke dekking moet bij de bouw één keer geteld worden vóór je erop vertrouwt.
De volatiliteit-sorteersleutel is de gratis 24u-range, met volume + marketcap als **liquiditeit-filters**
om illiquide uitschieters als MMUI te weren. Dit breidt het rotatie-fundament uit van "meet de 2 bestaande
coins" naar "ontdek nieuwe kandidaten van buitenaf".

---

## Dimensie 1 — MEXC-API: veilig, goedkoop, geen ban-risico

- **Rate-limits (geverifieerd tegen de officiële MEXC-doc):** het IP-budget is **300 weight per 10 seconden**
  (de UID-limiet van 500/10s is een aparte bak). Gewichten: `/ticker/24hr` (alle symbolen) = **40**,
  `/exchangeInfo` = **10**, `/klines` = **1** per call. Een hele scan = 40 + 10 + een handvol klines ≈
  ruim binnen één venster van 10s, en je doet het maar **1× per dag**. Geen auth nodig voor marktdata.
  De GitHub-mirror noemt "500/10s" en "max 1000 klines" — dat is **verouderd/fout**; de officiële cijfers
  (300/10s, klines-cap 500) zijn leidend en bevestigd door een live BTC-probe (500 candles ondanks limit=1000).
- **Ban-gedrag:** bij overschrijding HTTP **429 met Retry-After**, herhaling → IP-ban (2 min tot 3 dagen),
  **418** = al gebanned. MEXC heeft **geen** verbruiks-header (anders dan Binance) → je moet zelf begroten.
  Met de ruime marge is dat geen probleem; bouw wel een eenvoudige client-side teller als vangnet en
  respecteer Retry-After. De eerder geadviseerde sleeps van 0,2-0,5s per kline zijn **5-25× te voorzichtig**
  (klines = 1 weight) — een kleine sleep (~0,05s) of een teller die pauzeert rond 250 weight/10s volstaat.
- **Handelbare paren selecteren:** `quoteAsset=USDT` EN status online EN `isSpotTradingAllowed=true` EN
  `permissions` bevat `SPOT` EN **`st=false`**. De **ST-tag** (Special Treatment) is een **delisting-waarschuwing**
  → altijd weren, hoe volatiel ook. Live ground-truth: 1915 enabled USDT-paren.
- **Let op de status-enum (CLAUDE.md single-source-of-truth):** de doc toont `'1'/'2'/'3'`, maar de live API
  kan een tekstwaarde teruggeven. **Verifieer de echte waarde eenmalig tegen een live /exchangeInfo-dump en
  definieer 1 constante** vóór je iets hardcodet — anders stille mis-filtering.

## De marketcap/join-keuze

- **Bron:** MEXC heeft **geen native marketcap** (bevestigd: MEXC's eigen tokenomics-pagina draagt de
  disclaimer "Tokenomics data on this page is from third-party sources"). De spot-API exposet geen
  supply-veld. → **CoinGecko `/coins/markets`** is de bron.
- **Hoeveel pagina's:** de mcap>10M-grens valt op **~rank 1160**, NIET 1000 (live geprobed: pagina 4 nog
  volledig >10M, pagina 5 kruist, pagina 6 = 0 boven 10M). → haal **5 pagina's** (`per_page=250`, rank 1-1250)
  en filter client-side op `market_cap > 10M`. Dat is 5 calls per refresh.
- **Join-sleutel:** **contractadres primair** (MEXC `contractAddress` lowercase/trim ↔ CoinGecko
  `/coins/list?include_platform=true` over **alle** chains — 3.162 CG-coins staan op meerdere chains, dus
  indexeer álle platform-adressen). Contract-join is uniek en immuun voor ticker-collisies; 1638/1916
  MEXC-paren hebben een echt contract. **Symbool-join alleen als fallback** voor de ~278 zonder contract én
  contract-misses, en **alleen bij een unieke match** binnen de mcap>10M-set — bij dubbele ticker: overslaan,
  nooit gokken (liever een coin missen dan een fout marketcap tonen). NULL-mcap coins **tonen met markering
  "mcap onbekend"**, niet stil weg-filteren.
- **Licentie (load-bearing, en in al het oorspronkelijke onderzoek onderbelicht):** CoinGecko's API-voorwaarden
  (sectie 4.1.6) **staan commercieel gebruik expliciet toe** maar verbieden doorverkoop/redistributie van de
  API, en sectie 4.4 maakt de attributie **"Powered by CoinGecko"** (min. fontgrootte 10, met link)
  **verplicht** — ook op een intern admin-scherm. Gebruik een **gratis Demo-key** (header `x-cg-demo-api-key`,
  100 calls/min, **10.000 calls/maand**); bij ~6 calls/dag (≈180/maand) zit je daar ruim binnen. Keyless geeft
  ~30/min shared-per-IP en een 429-muur bij bursts (live gereproduceerd: 5e snelle call = 429) — daarom de key.

## De volatiliteit-maat-keuze

- **Sorteersleutel = de 24u-range** `(highPrice-lowPrice)/lowPrice*100` uit `/ticker/24hr` (gratis, 0 extra
  calls). Dit is bewust de **v1-keuze**: simpel, en het past op het kerninzicht (sorteer op prijs-volatiliteit,
  niet op volume).
- **Illiquide uitschieters worden door het volume-filter geweerd, niet door de maat.** De 24u-range is
  gevoelig voor één wick op een dode coin (MMUI: 972% range op $19k volume). Daarom: `mcap>10M` EN
  `quoteVolume>drempel` als liquiditeit-filters vóór de sortering.
- **Eerlijke kanttekening:** een **klines-gebaseerde maat** (% uur-candles met `|log-return|>=3%`, plus std
  van uur-returns) is robuuster (een spike is 1 candle van ~500) en sluit aan op de bestaande
  `coin_metrics.py`-grammatica (`up_pct`/`vol_pct`). Maar hij kost klines per shortlist-coin en is een
  **v2-verfijning**, niet v1. Wees je ervan bewust dat `volat_pct` (24u-range) een **ander** volatiliteit-begrip
  is dan de engine-tab's `up_pct`/`vol_pct` — documenteer dat in de UI.

## De leeftijd-bron + drempels

- **Bron (gecorrigeerd t.o.v. eerste onderzoek):** gebruik **`exchangeInfo.firstOpenTime`** — het exacte
  eerste-trade/listing-tijdstip per symbool, dat al in de bulk `/exchangeInfo`-call zit. Live geverifieerd:
  BTC firstOpenTime=2017-09-30 (de échte listing, niet de misleidende 2025-02-05 kline-vloer), DOGE=2018-11-26,
  ASTEROID=2026-04-17, VELVET=2025-07-10. **1868/1916 (97,5%)** USDT-paren hebben een niet-nul firstOpenTime,
  tegen **0 extra calls** en **zonder de 500-candle-cap-valkuil**. Het eerder voorgestelde tweetraps
  1d+1M-kline-algoritme is daarmee **overbodig** — klines zijn alleen nog **fallback** voor de ~48 paren zonder
  firstOpenTime. (Twee premissen uit het oorspronkelijke onderzoek bleken fout: exchangeInfo zou geen
  listingdatum hebben, en startTime zou niet voorbij de cap kunnen pagineren — beide door de hoofdagent
  weerlegd tegen de live API.)
- **CoinGecko `genesis_date` is NIET geschikt** (meet token-genesis, niet handelbaarheid: BTC genesis 2009 vs
  MEXC-listing 2017; vaak null). `ath_date`/`atl_date` zijn events, geen leeftijd.
- **Drempels (handelbaarheid):** **<14d = rood/te jong** (geen historie, dun orderboek, pump/rugpull-venster;
  de engine-regels hebben dag-historie nodig) → uitsluiten/streng. **14-90d = amber** → toegestaan mits
  `mcap>10M` EN `volume>drempel`. **>90d = groen/gevestigd**. UI-default: "verberg <7 dagen" aan. Een 4e status
  **DATA-ONBETROUWBAAR** voor coins met grote candle-gaten (mogelijke relist) i.p.v. een schijnleeftijd.
  *Kanttekening:* deze drempels zijn op handelslogica/literatuur gebaseerd, **niet backtest-gevalideerd** —
  valideer tegen historische trade-uitkomsten zodra er meer coins gelijktijdig leven.

## Eerlijke kanttekeningen (de echte risico's)

1. **CoinGecko-afhankelijkheid + voorwaarden** zijn het grootste risico: verplichte "Powered by CoinGecko"-attributie,
   10k calls/maand-cap, en geen native MEXC-fallback als CG wegvalt. Beslis: Demo-key + attributie inbouwen,
   bulk-aanpak (5 markets-pagina's) als invariant vasthouden (per-coin `/coins/{id}` zou de maandcap opblazen).
2. **Join-dekking — nu GEMETEN (live, hoofdagent, via `/coins/list?include_platform=true`):**
   **contract-join = 1379/1507 (92%)** van de paren met bruikbaar contract resolven naar een CoinGecko-coin, en
   een contractadres is **uniek**. Symbool-join haalt 93% maar **520/1789 tickers zijn dubbelzinnig** op CoinGecko
   → symbool zou voor ~29% de verkeerde mcap pakken. Bevestigt: **contract primair, symbool alleen als unieke
   fallback**. Resteert als build-gate (mét Demo-key): hoeveel van de >10M-set daadwerkelijk een mcap krijgt.
3. **Rate-limits MEXC** zijn ruim, maar meerdere processen op één IP delen het budget. **Status-enum nu live
   geverifieerd:** `status='1'` (2353 symbolen) = normaal, `'2'` (1 symbool) = uitzondering; `isSpotTradingAllowed=true`
   voor 2223. → selecteer op `isSpotTradingAllowed=true` (+ `st=false`), niet op een gegokte status-enum.
4. **Illiquide uitschieters** worden door het volume-filter geweerd, maar de exacte `quoteVolume`-drempel
   (>100k USDT is de start) moet empirisch geijkt worden — de grens tussen een dunne-maar-echte nieuwe coin en
   een dode-coin-spike, idealiter tegen de liquiditeit van de 2 bestaande engine-coins.
5. **age_days is een vloer, geen exacte leeftijd** voor de ~48 fallback-coins (kline-cap); markeer die als ">Nd".

## Hoe dit het rotatie-fundament uitbreidt

Epic V legde het **interne** fundament (`coin_daily_metrics`: meet de beweeglijkheid van de coins die we al
handelen, cross-coin vergelijkbaar). Het knelpunt bleef: **te weinig coins leven gelijktijdig** → rotatie is
niet bewijsbaar. Deze scan is de **ontdekkings-trechter ervóór**: hij levert van buitenaf nieuwe, volatiele,
handelbare kandidaten. Strikt gescheiden tabellen en tabs (`mexc_market_scan` = externe ontdekking;
`coin_daily_metrics` = wat we handelen), met één consistent **begrip**: beide sorteren op prijs-volatiliteit,
beide gebruiken volume + mcap als liquiditeit-filter. Het pad kandidaat→engine: mens selecteert een kandidaat
uit de MEXC-tab → symbol toevoegen aan `coins` + indicator-ingestie opzetten → na ~7 dagen pakt de bestaande
`coin-metrics`-routine de coin automatisch mee (zijn `run()` loopt over álle coins in `indicators`) → de coin
verschijnt vanzelf in de engine-ranking. Daarmee lost deze scan precies de #1 blocker op: meer coins, gelijktijdig.
