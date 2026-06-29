# Rule 30/31 verbeteren + gepoolde sell-default — bevindingen 28-29 juni 2026

Context: universe geladen, regime-filter operationeel. Vraag van Daan: 20/22/23 zijn live-waardig,
30/31 zijn kanshebbers maar net te dun — zijn er insteken om ze beter te krijgen? Drie onderzoeken op rij.
Alles read-only gemeten; niets aan de koop-rules gewijzigd. Zie ook [[../../CLAUDE.md]] + memory
[[rule-30-31-improve-ultracode]], [[coldstart-min-volume-sell-default]].

> **Let op (universe-noot, 2026-06-29):** de cijfers hieronder zijn een **momentopname van 28-29 juni**. Tijdens
> dit onderzoek noemden queries "12 munten", maar **POPCAT (3168) is daarna verwijderd → het universe is nu 11
> munten** (DOGEAI, NOS, FARTCOIN, MUMU, CATDOG, TURBO, PONKE, PEPE2, ATR, 1DOLLAR, JELLYJELLY), allemaal met een
> `coin_regime`-rij (geen filter-gat). Door de Epic-N refire (min_sl1→0,99) + de POPCAT-cleanup schuiven de
> absolute getallen licht; de conclusies (20/22/23 live, 30/31 dun) veranderen niet.

## 0. Beoordeling per rule (binnen regime, 12 munten, uitgangspunt)

| Rule | trades | winst/trade | middel heft slecht op? | munten + / − | Σ |
|---|---|---|---|---|---|
| 23 | 227 | +1,57% | ✅ +85 vs −55 | 10 / 1 | +356 |
| 20 | 393 | +1,39% | ✅ +127 vs −82 | 11 / 0 | +546 |
| 22 | 1075 | +0,91% | ✅ +371 vs −260 | 11 / 0 | +978 |
| 21 | 570 | +0,36% | ❌ +98 vs −221 | 8 / 4 | +207 |
| 31 | 1284 | +0,26% | ❌ +265 vs −497 | 9 / 3 | +331 |
| 30 | 1434 | +0,25% | ❌ +303 vs −542 | 7 / 5 | +364 |
| 32 | 1583 | +0,20% | ❌ +338 vs −621 | 9 / 3 | +312 |
| 34 | 727 | +0,18% | ❌ +150 vs −243 | 7 / 4 | +133 |

**Scheidslijn:** alleen 20/22/23 overleven ~0,30% slippage (fee ~0,10% + slippage ~0,20%) én laten de
middel-trades de verliezers opheffen. 21/30/31/32/34 vallen na kosten om. Cijfers zijn RUW (zonder kosten).

## 1. Epic N — gepoolde sell-default (AFGEROND, toegepast)

Gepoolde sweep (11 munten, per-munt holdout, toeval-toets + Šidák) op de gedeelde `strategies.sl_settings`.
44 kandidaten getoetst → **5 GLOBAAL_SAFE winnaars toegepast**:
- **min_sl1 0,988→0,99** op rules 20-23 (p=0,003–0,050, géén munt geschaad).
- **minimal_profit 0,8→0,5** op rule 23 (p=0,047, ATR licht geschaad = override-kandidaat).
- **Portfolio:** Σ +3230,9 → +3279,9%, verliezers 4362 → 4199 (−163). Gate gepasseerd.
- **Kern:** de absolute stop-bodem 0,2 procentpunt omhoog is de enige robuuste verbetering; de overige 39
  kandidaten zijn UNSAFE/ZWAK/toeval. Bestanden: `sell_default_sweep.py`, `sell_default_apply.py`,
  `test_sell_default.py`. Detail in [epic-N](../epics/epic-N-pooled-sell-default.md).

## 2. Ultracode multi-agent onderzoek — 30/31 winstgevender maken

14 agents, 6 hefboom-lenzen + adversariële holdout/toeval-toets per idee. **Eindoordeel: 30/31 zijn
structureel begrensd** — winst/verlies wordt bepaald door de prijsbeweging NÁ instap; geen koop-zijdig of
meta-filter kon dat vooruit voorspellen (5e bevestiging van dezelfde muur).

**De ENIGE bewezen hefboom:** de harde stop-bodem `min_sl1` voor 30/31 staat te ruim (0,988). Hoger naar
**0,992–0,994** (optimum loopt door tot ~0,996) geeft significant meer Σ door ondiepere verliezers: r30
+0,29→+0,31%/trade (0,992), r31 +0,29→+0,32%/trade, **r31 op 0,992 = 0 flips (directe keeper)**, r30 = 2
kleine flips. MAAR klein (~+0,03–0,05%/trade) en **breekt de loser-kern niet** (slecht% blijft ~65%). Bouw:
het `min_sl1`-grid stond op max 0,99 → verbreden naar 0,992/0,994/0,996 en 30/31 meenemen in de Epic-N sweep.
*(Die uitbreiding draait sinds 29 juni in een aparte sessie.)*

**13 doodlopers (niet opnieuw onderzoeken):** consensus 30+31 (p=0,45) / 30+20-23 (stort in holdout) /
vervolg-fire (p=0,19); meta-labeling met forward-features (na Šidák p=0,077); HMM-volatiliteitspoort (mooi
in-sample, nul daarbuiten = vastpinnen op toeval); leeftijds/winst-ladder strakker (offert meer winnaars dan
het redt, p≥0,17); hp6/hp7-ratchet (p≥0,11); loser-munten wegsnijden; min_volume als munt-voorspeller
(p_perm=0,17); sample-uniqueness-weging (posities serialiseren al); Kelly/conforme sizing (hangt op een
meta-model dat niet bestaat).

**3 onverkende hoeken:** (1) confound-breker — 30/31 opnieuw ontdekken op de 9 niet-ontdekmunten (vergt
discovery-engine = brain-mutatie); (2) bodem 0,993–0,996 gericht uittesten (effect loopt door); (3) een
NÁ-instap uitkomst-as (bv. eerste-X-minuten prijs-richting als vroege-exit) — het enige conceptueel
onverkende gat, want alle afgeschoten filters keken vóór instap.

## 3. Marginale-waarde-analyse — wat voegen 30/31 echt toe bovenop 20-23?

De dedup is **cross-rule, één positie tegelijk** (`persist_to_brain.py:223-236`: globale `open_until` over
chronologisch gesorteerde `all_fires` van álle rules; `is_executed=1` respecteert dit al — exact zoals de
legacy bot). Read-only proxy: per 30/31-executed trade gekeken of er een 20-23-signaal binnen het hold-window
viel.

| rule | uitgevoerd | overlap 20-23 | **puur 30/31** | Σ puur | Σ overlap | marge/trade puur | slecht% puur |
|---|---|---|---|---|---|---|---|
| 30 | 1342 | 107 (8%) | **1235 (92%)** | +70 | +337 | **+0,057%** | 70% |
| 31 | 1251 | 92 (7%) | **1159 (93%)** | +103 | +281 | **+0,089%** | 70% |

**Uitkomst:** 92-93% van de 30/31-trades is "puur" (geen 20-23 in het venster → echte witte ruimte, wordt NIET
door 20-23 opgevangen). MAAR juist die pure trades zijn de dunne massa: +0,057/+0,089%/trade, 70% slecht → **na
slippage verlieslatend**. De winst zit in ~8% overlap-trades (~+3%/trade) waar 30/31 vroeg instapt op een
beweging die kort daarna óók een 20-23-signaal geeft — reële "vroeger instappen"-waarde, maar niet vooraf te
isoleren (overlap pas achteraf bekend; consensus-toets faalde al). **Netto marginale bijdrage van 30/31 bovenop
20-23 ná kosten ≈ negatief, behalve op NOS/DOGEAI.**

## Conclusie / besluit

- **Live met 20/22/23.** Robuust over alle munten, dikke marge, overleeft kosten. (rule 23 wel concentratie:
  ~helft van de winst van één munt.)
- **30/31 niet breed live.** Selectief op de munten waar ze bewezen werken (NOS/DOGEAI), als keuzemenu-optie in
  de nog te bouwen live-laag. Verwacht geen promotie naar de 20-23-klasse.
- **Gratis meegenomen winst:** de stop-bodem voor 30/31 hoger (Epic-N sweep, draait).
- **De fundamentele hefboom blijft meer munten, niet nog een filter op deze twee.**
- **Open voor live gaan (los van rules):** kosten nog niet in de backtest verwerkt (draai `harden_rule_filter.py`
  netto-toets), geen echte order-executie getest, regime-gate nog niet live week-vooruit getoetst.
