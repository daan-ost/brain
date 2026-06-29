# Brain — NoBrainersBot

Python trading-engine (`engine/`) die de legacy bot-regels herbouwt + valideert tegen de legacy DB
(de "oracle"), met een Laravel-app (`www/`) op de `brain` DB. Details staan in de skills
(`brain-engine`, `brain-sell-engine`, `brain-routines`, `brain-rule-tuning`, ...) en in de memory.

## Git-werkwijze (verplicht)

**Switch of maak NOOIT zelf een branch aan — blijf op de branch waar we zijn (meestal `main`), tenzij
Daan expliciet om een (andere) branch vraagt.** Dit overschrijft de standaard "splits af op de
default-branch". Committen mag gewoon op de huidige branch wanneer Daan erom vraagt; geen `git checkout`
/ `git switch` / `git checkout -b` zonder zijn aanwijzing.

## Terminologie (altijd aanhouden — in communicatie, docs en UI)

- **"trades"** — niet "fires" of "coin_fires". De databasetabel heet technisch `coin_fires`, maar in
  taal, uitleg en schermen praten we over **trades**.
- **"winst-lock"** — niet "ratchet". Het mechanisme dat de stop-loss trapsgewijs omhoog zet naarmate
  de winst stijgt en nooit terugzakt, zodat winst wordt vastgezet. De code-functie heet `lock_profit`;
  het concept noemen we de **winst-lock**. Voor het hele meebewegende stop-gedrag: **"meelopende stop"**.

Technische identifiers (tabel- en functienamen) houden hun bestaande naam — dit gaat over de woorden
die we tegen elkaar en in documentatie/UI gebruiken.

## Verboden jargon → gebruik de Nederlandse uitleg (in communicatie met Daan)

Daan wil gewone-taal uitleg, geen Engels ML-jargon. Gebruik deze woorden NIET; gebruik de vertaling
(technische identifiers in code mogen blijven):

| Niet gebruiken | Wel zeggen |
|---|---|
| greedy | **stapsgewijs** — steeds de beste eerstvolgende subregel erbij |
| holdout | **apart-gehouden testperiode** — data die je NIET gebruikt om te zoeken, alleen om te toetsen |
| overfit / overfitting | **vastpinnen op toeval** — de regel past op de specifieke voorbeelden i.p.v. het echte patroon |
| holdout-recall | **trefkans op de testperiode** — hoeveel van de goede momenten de regel dáár nog pakt |
| recall | **trefkans / dekking** (hoeveel goede momenten gevangen) |
| precision / selectiviteit | **selectiviteit** (hoe vaak de regel vuurt; mag, is al NL) |
| baseline | **nullijn / willekeurige vergelijking** |
| permutatie-test | **toeval-toets** — schud de uitkomsten, kijk of het signaal ook door toeval ontstaat |

Bij nieuwe Engelse termen: leg ze één keer in gewone taal uit en hou daarna de Nederlandse term aan.

## Munt-onboarding: wat we uit legacy halen

Uit `bot_signals` (legacy) halen we **alleen twee dingen**:

1. **Indicatoren** — via `import_indicators.py <id>`: de 5 tijdreeksen (vzo, phobos, obv-x-value, mfi,
   volumeud) + de coin-rij. Meer niet.
2. **min_volume per rule** — uit `bot_signals.wp_trading_symbols_rule` (kolom `settings` → JSON-veld
   `min_volume`). Dit is het volumeud-drempelwaarde per rule per munt.

Rule-definities (subrules, condities, operators) komen **uitsluitend uit `brain.rules`** — die beheren
we zelf. `seed_rules.py` NOOIT draaien: die wist alle coin_rule_settings + rules en herseeded.

### min_volume is een per-munt ijk-constante (GEEN optimizer-output)

`min_volume` is de **volume-schaal van de munt**: de normalisator in `relvol = volumeud / min_volume`
(`volume.py:72`). Daarom varieert het enorm per munt (DOGEAI ~15k, MUMU ~33 mln). Empirisch (4 munten)
zit min_volume rond het **~90e percentiel** van de volumeud-verdeling: ~7,5–12,7% van de ticks ligt
erboven.

**Geen enkele routine schrijft `min_volume`** — alleen `seed_rules.py` (init) en een test. De
optimalisatie-keten tunet het **niet**. Wat je seedt is dus permanent tot je het handmatig wijzigt, en
het is **vereist vóór** `compute_volume_found.py` (zonder min_volume → `brain_volume_found=0` → géén
kandidaat-ticks → géén trades).

**Let op — `min_volume=0` crasht de engine** (`volume.py:72` deelt door min_volume zonder nul-guard).

**Munt mét legacy rules 20-23** (MUMU/FARTCOIN/DOGEAI/NOS): kopieer de legacy min_volume per rule.
**Munt zónder** (bijv. TURBO gebruikte legacy alleen rules 12/29/101): seed min_volume ≈ **p90 van de
volumeud-reeks** van die munt (zodat ~10% van de ticks erboven ligt, gelijk aan de 4 bestaande munten).
Niet de legacy min_volume van andere rule-nummers overnemen — die hoort bij andere volume-settings-bands.

## Lange taken (refire, sweep, etc.)

Taken die langer dan ~5 minuten CPU-tijd kosten (volledige refire over alle munten, grote sweeps) **niet
zelf in de achtergrond draaien** — die worden gekilld als de sessie afloopt. Geef in plaats daarvan het
exacte bash-commando aan Daan, zodat hij het in een losse terminal kan draaien (met `nohup` of `screen`).
Korte taken (<5 min, bijv. 1-2 munten refiren of een read-only sweep) mogen wél direct.

## Rule-discovery filosofie (Daans uitgangspunt — altijd aanhouden)

Nieuwe koop-rules (30, 31, …) vinden is **speelruimte om een portfolio op te bouwen**, geen jacht op
een directe keeper:

- **Een rule hoeft NU niet aan de strenge 20-23-lat te voldoen** (slecht ≤45%, goed ≥19%, ≤0,1% ticks).
  "Voldoet nog niet" is géén reden om 'm weg te gooien. We leggen elke gevonden rule **inactief**
  (`active=0`) vast in `brain.rules` mét zijn volledige toets-cijfers (toeval-toets p, CPCV-OOS, slecht%,
  goed%, ΔΣ bovenop 20-23).
- **De aan/uit-beslissing valt pas bij live traden** (nog niet gebouwd). Dán bepaalt Daan per moment
  welke rules actief zijn — de inactieve rules zijn het keuzemenu.
- **Meer data tilt rules over de lat.** De bindende grens is nu het 2-munten-universe (elke rule is netto
  winstgevend maar loser-zwaar). Met ~10 munten erbij komen er mogelijk een paar bovendrijven die de lat
  wél halen. De hefboom is meer munten, niet strengere filters op de huidige twee.
- **Verfijnen kan later** — de per-munt slecht%-tuning (`brain-rule-tuning`, rule-precision routine) draaien
  we pas wanneer een rule richting live gaat, niet bij het vinden.
- **Doel: ~10 rules vinden, dan stoppen.** Daarna is de portfolio gevuld en wachten we op meer munten +
  de live-laag.

De enige harde eis bij het vinden: de rule moet door de **toeval-toets** (p<0,05, Šidák-gecorrigeerd) +
de **apart-gehouden testperiode** komen — anders is het ruis, geen rule. Zie [[brain-rule-discovery]] +
`docs/methodology/rule-discovery.md` §13.
