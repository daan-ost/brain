# Brain — NoBrainersBot

Python trading-engine (`engine/`) die de legacy bot-regels herbouwt + valideert tegen de legacy DB
(de "oracle"), met een Laravel-app (`www/`) op de `brain` DB. Details staan in de skills
(`brain-engine`, `brain-sell-engine`, `brain-routines`, `brain-rule-tuning`, ...) en in de memory.

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
