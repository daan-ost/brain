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
