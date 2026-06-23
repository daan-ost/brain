# Sell-tuning critical-eye — fixes bug 1-3 (2026-06-23)

Critical-eye review van de sell-tuning routine (per-munt instelknoppen afsteller) leverde 3 echte
bugs op die we nu fixen. History vastgelegd vóór uitvoering (beslissingen + onverkende alternatieven),
uitkomst onderaan.

## Bug 1 — toepas-poort kijkt naar munt-totaal, niet naar de eigen regel
**Waar:** `engine/src/sell_apply.py` GATE 3 (`apply_safe`).
**Probleem:** poort houdt een voorstel iff munt-Σprofit niet daalt én verliezers niet stijgen. Door
single-position-dedup kan een knop op regel 20 een trade naar regel 21 verschuiven: munt-totaal vlak
→ TOEGEPAST, terwijl regel 20 zelf netto slechter werd. `coin_totals` geeft de per-regel split al
terug (nu weggegooid met `_`).
**Beslissing:** eis dat óók de eigen regel niet verslechtert (Σ niet omlaag, verlies niet omhoog).
**Alternatieven (niet gekozen):**
- *Alleen per-regel toetsen (munt-poort laten vallen):* afgewezen — Daans meetlat is het portfolio-totaal
  (rule-precision routine doet dat ook); munt-poort blijft de hoofdpoort, regel-poort is extra zeef.
- *Per-regel verlies-delta met marge toestaan:* afgewezen — onnodig complex; "niet slechter" is de
  bestaande gebalanceerd-regel, consistent doortrekken naar de regel.

## Bug 2 — start-gate van de routine mist trade-drift → routine slaat over, scherm wordt stil
**Waar:** `engine/src/routines.py` runner (`with_fires` alleen voor rule-precision set).
**Probleem:** sell-tuning meet direct op trade-P&L, maar zijn vingerafdruk bevat geen `coin_fires`-drift.
Na een pure code-deploy + handmatige refire (geen nieuwe data/regel/knop) blijft de vingerafdruk gelijk
→ gate slaat de set over → rapport-JSON wordt niet ververst (stale scherm). Exact de
fingerprint-blindspot die voor rule-precision al gefixt is.
**Beslissing:** `with_fires` ook aanzetten voor de sell/buy/discovery-sets (ze meten alle op trades).
**Alternatieven (niet gekozen):**
- *Alleen sell-tuning fixen:* afgewezen — buy-tuning + sell-discovery meten óók op trades en hebben
  dezelfde blindspot; de comment bij die sets claimt al "includes trades".
- *measure() altijd buiten de gate draaien (rapport altijd vers):* afgewezen voor nu — duurder elke dag;
  de gate-fix lost de staleness aan de bron op. Kan later als het scherm live-cijfers moet tonen.

## Bug 3 — apart-gehouden testperiode globaal per munt geknipt i.p.v. per regel
**Waar:** `engine/src/sell_tuning.py` `load_trades` (`mid = n//2` over alle regels) + `verdict`.
**Probleem:** elke trade erft zijn train/test-stempel van de globale mediaan-positie. Regels zijn niet
gelijk over de tijd verdeeld → een regel kan een lege of scheve mini-testperiode (2-3 trades) krijgen,
en het verdict keurt die al goed als die paar trades niet verslechteren. Geen bewijs.
**Beslissing:** (a) knip per regel op de eigen mediaan; (b) eis een minimum-omvang per helft
(`MIN_SPLIT = 4`) anders `GEEN_HOLDOUT` (geen SAFE). `median_split` in het rapport wordt per (munt,regel).
**Alternatieven (niet gekozen):**
- *Globaal houden, alleen min-omvang toevoegen:* afgewezen — lost de scheefheid niet op (een regel die
  laat begon blijft volledig in de testperiode).
- *Tijd-mediaan i.p.v. positie-mediaan:* gelijkwaardig bij chronologisch gesorteerde trades; positie
  is simpeler en deterministisch. Behouden.
- *MIN_SPLIT hoger (bv. 8):* afgewezen voor de executed-set (te dun, bijna alles zou GEEN_HOLDOUT
  worden). 4 is de mildste zinvolle ondergrens. De echte oplossing voor dunne data is de promising-set
  (Vraag 3, apart traject).

## Niet in deze ronde (bewust)
- Bug 4 (toeval-toets vóór auto-apply) — apart, hoort logisch bij het promising-traject (meer
  kandidaten → meer ruis-edges → toets harder nodig).
- Vraag 2/3 (nieuwe berekeningen als verkoop-signaal; promising-set in de tuning) — aparte trajecten.

## Uitkomst (na uitvoering)

Alle drie doorgevoerd op branch `feat/sell-tuning-critical-eye` (read-only geverifieerd, geen DB-mutatie):

- **Bug 1** — `sell_apply.py` GATE 3 toetst nu munt-totaal ÉN de eigen regel (Σ niet omlaag, verlies niet
  omhoog). De per-regel split kwam al uit `coin_totals`. Afwijs-reden meldt expliciet als een vlak
  munt-totaal de schade naar een andere regel verschoof.
- **Bug 2** — `routines.py` runner: `with_fires` nu ook voor de sell/buy/discovery-sets. Een trade-drift
  (code-deploy + refire) hertriggert de routine; geen stil/stale rapport meer.
- **Bug 3** — `sell_tuning.py`: split per regel via pure `split_per_rule()` + `MIN_SPLIT = 4` per helft;
  `median_split` in het rapport nu per (munt,regel).

**Tests:** `test_sell_tuning.py` 13/13 (was 11) — toegevoegd: `test_split_per_rule`,
`test_verdict_geen_holdout_klein`.

**Effect (read-only meting 2026-06-23):** de per-regel split (bug 3) brengt **3-4 SAFE-voorstellen** aan
het licht waar de globale split telkens **0 SAFE** gaf (18/19/21 juni). De globale knip maakte de routine
dus blind voor echte afstel-kansen. Beste per (munt,regel):
- DOGEAI r20 `hp_setting6` 4→6 — netto +12,1% (testperiode +9,2%) — sterk
- DOGEAI r21 `hp_setting6` 4→3 — netto +1,6% (testperiode +0,6%) — marginaal
- NOS r23 `hp_setting6` 4→3 — netto +0,9% (testperiode +0,3%) — marginaal

**Let op vóór de volgende `--apply`-run:** de marginale voorstellen (+0,3/0,6% testperiode) kunnen ruis
zijn. GATE 3 (nu ook per-regel, bug 1) is de interim-vangrail, maar bug 4 (toeval-toets + Šidák vóór
auto-apply) wordt hierdoor urgenter — meer regels met een geldige testperiode = meer kansen op een
toevallige SAFE. Aanrader: bug 4 bouwen vóór de volgende auto-apply, of de marginale handmatig laten
liggen. Niets is nu toegepast (alleen read-only gemeten).

**Waargenomen (buiten scope, los te bekijken):** de tuning dekt alleen rules 20-23; de actieve
discovery-rules 30/31 hebben óók executed trades maar krijgen geen sell-knop-afstelling.

## Eerste `--apply`-run (2026-06-23, dagelijkse routine) — afgebroken door verbindingsuitval

De geplande dagelijkse run (`routines.py --set sell-tuning --trigger routine --apply`) liep twee keer
vast op een **tijdelijke MySQL-verbindingsuitval tijdens de refire-gate**, niet op een logische fout.
De refire (`persist_to_brain.py`) is per munt een CPU-zware operatie van ~12-15 min (DOGEAI 2525:
544 periodes × 1663 fires); de gate doet dat per kandidaat, dus een volledige run duurt ~30+ min en is
lang blootgesteld.

- **Run #61** (15:05-15:39): mat 4 veilige + 4 overfit-afgekeurd + 88 doorgerekend. Paste **2** door de
  volledige gate (toeval-toets → echte refire → munt+regel-poort) bevestigd toe vóór de crash:
  - **NOS (244) r23**: `hp_setting6` 4.0→3.0 (in-memory netto +0,9%, testperiode +0,3%)
  - **DOGEAI (2525) r20**: `hp_setting6` 4.0→6.0 (in-memory netto +12,1%, testperiode +9,2% — sterk)

  Daarna crashte de refire van 2525 op `MySQL server has gone away (SSLEOFError)` in `persist_to_brain.py`
  regel 149. De routine-run is `failed` gemarkeerd; de 2 applies waren al gecommit en gate-bevestigd.
- **Herstel-refire** (handmatig, alle munten): `coin_fires` weer consistent met `coin_strategies`.
- **Run #62** (15:55): meet opnieuw, vond nog **1** in-memory SAFE over (DOGEAI 2525 r21 `hp_setting6`
  4→3, netto holdout +0,64% — de marginale uit de lijst hierboven; de andere 2 waren nu de nullijn).
  Schreef de override en stierf 3 s later via **SIGTERM (exit 143)** tijdens de refire-gate. SIGTERM
  omzeilt de except-handler (terugzetten + revert-refire), dus bleef een **niet-bevestigde orphan-override**
  (2525/r21 `hp_setting6:3.0`) gecommit achter, met mogelijk niet-bijgewerkte `coin_fires`.

**Handmatig herstel (uitgevoerd):**
1. Orphan 2525/r21 teruggezet naar de bevestigde waarde `{"minimal_profit":"0.5","min_sl1":"0.99"}`
   (hp_setting6 verwijderd) + refire van 2525 → `coin_fires` weer consistent.
2. Vastgelopen run #62 (`status=running`) als `failed` gemarkeerd zodat hij de scheduler niet blokkeert.
3. Eindstand geverifieerd: NOS Σ+710,9% / 291 verliezers / 655 trades; DOGEAI Σ+917,3% / 465 verliezers
   / 816 trades. Overrides: NOS r23 `hp_setting6=3.0`, DOGEAI r20 `hp_setting6=6.0`, DOGEAI r21 schoon.

**Bewust niet gedaan:** de resterende marginale kandidaat (2525 r21, +0,64% holdout) NIET alsnog
toegepast. Reden: (a) marginaal en door de doc zelf als mogelijk ruis gemarkeerd; (b) toepassen vereist
nóg een 12-15 min refire-gate — exact de lange operatie die vandaag twee keer werd onderbroken, elke
keer met een orphan tot gevolg. Blijft staan als **openstaande kandidaat voor de volgende stabiele run**.

**Les / robuustheid-gap:** de apply-gate is veilig tegen `SystemExit` (refire-fout → restore + revert),
maar **niet tegen SIGTERM** — een kill tijdens de refire laat een gecommit-maar-ongetoetste override
achter. Mogelijke hardening (los traject): override + refire in één transactie-achtige stap met een
"pending"-vlag die bij de volgende run wordt opgeruimd, of een signal-handler die de orphan terugdraait.
Tweede gap: de lange per-munt refire (~12-15 min) maakt de hele run fragiel op een instabiele lokale DB.
