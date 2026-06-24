# Baseline vóór "optimize current rules" — 2026-06-24

Nulmeting vlak na de start van de regel-optimalisatie (rule-precision routine, `routines.py --trigger`).
Tabel-snapshot lukte niet (routine had `coin_fires` gelockt); cijfers via read-only query (READ COMMITTED).
De routine doet de munten één voor één — DOGEAI mogelijk al deels aangeraakt, de rest nog "voor".

Per coin per rule-groep: executed trades | verliezers | Σprofit%

| coin | groep | trades | verliezers | Σprofit |
|---|---|---|---|---|
| DOGEAI (2525) | bot 20-23 | 185 | 87 | +481 |
| DOGEAI | rule 30 | 210 | 127 | +192 |
| DOGEAI | rule 31 | 185 | 103 | +123 |
| DOGEAI | rule 32 | 236 | 148 | +109 |
| DOGEAI | rule 34 | 125 | 73 | +32 |
| NOS (244) | bot 20-23 | 164 | 42 | +334 |
| NOS | rule 30 | 142 | 69 | +87 |
| NOS | rule 31 | 186 | 97 | +176 |
| NOS | rule 32 | 142 | 73 | +104 |
| NOS | rule 34 | 122 | 62 | +37 |
| FARTCOIN (8427) | bot 20-23 | 652 | 390 | +301 |
| FARTCOIN | rule 30 | 1847 | 1174 | +601 |
| FARTCOIN | rule 31 | 689 | 433 | +30 |
| FARTCOIN | rule 32 | 930 | 582 | +30 |
| FARTCOIN | rule 34 | 678 | 412 | +9 |
| MUMU (2735) | bot 20-23 | 506 | 301 | +161 |
| MUMU | rule 30 | 492 | 313 | +47 |
| MUMU | rule 31 | 593 | 398 | **−44** |
| MUMU | rule 32 | 808 | 557 | **−33** |
| MUMU | rule 34 | 355 | 233 | **−19** |

**Direct opvallend (pre-optimize):** de op 2 munten ontdekte regels 30-34 generaliseren matig naar de
nieuwe munten — **MUMU's rule 31/32/34 zijn netto negatief** en FARTCOIN's 31/32/34 zijn vlak (+30/+30/+9).
Rule 30 houdt overal stand (+601 FARTCOIN, +192 DOGEAI). Dit is precies de info die meer munten oplevert.
Na de optimize: zelfde query draaien + `rules_history` + routine-output vergelijken.
