# Nieuwe window-berekeningen — internet-research (2026-06-23)

**Vraag (Daan):** welke "berekeningen" (zoals skewness) bestaan in Python-libraries die wij nog NIET in
onze ~31 hebben? Uitkomst: (1) hoeveel kunnen we toevoegen, (2) welke zijn nu al toepasbaar. Onderzocht
via 7 parallelle web-research-agents over 7 families → 86 ruwe kandidaten → synthese + volledigheids-criticus.

**Onze eis:** één leak-vrije scalar over een kort newest-first window (5–20 punten), schaal-vrij
(cross-coin), deterministisch. Daaraan getoetst.

## Uitkomst 1 — wat we kunnen toevoegen
- **13 nu direct** (pure numpy/scipy, al geïnstalleerd) — zie hieronder, **al gebouwd in `extra_calcs.py`**.
- **~38 "later"** — meeste zijn in 2–10 regels numpy zélf te bouwen (de ontbrekende libs antropy/pycatch22/
  pywt/statsmodels hoeven niet); een TA-subgroep heeft per-tick High/Low nodig (hebben we niet → eerst checken).
- **~41 afgevallen** — overlap met onze 31 / het feature-lab, niet-schaalvrij, of te-lang-window (entropie/
  FFT/Hurst/DFA hebben N≥50 nodig en zijn op 5–20 punten ruis).

## Uitkomst 2 — de 13 nu al toepasbare berekeningen (gebouwd + worden gemeten)

| berekening | meet | kant t.o.v. onze 31 |
|---|---|---|
| `kendall_tau` | rang-monotonie tijd↔waarde (robuust, niet-lineair) | nieuw: rang i.p.v. lineaire slope |
| `linreg_r2` | hoe nét­jes de reeks een lijn volgt (los van richting) | nieuw: fit-kwaliteit, niet spreiding/helling |
| `acf_lag1` | persistentie als continu getal (+momentum / −zigzag) | continu vs de tellingen reversal/consecutive |
| `theilsen_slope_normalized` | outlier-robuuste helling / gemiddelde | robuuste tegenhanger van OLS-slope (bad-ticks!) |
| `cumsum_position` | houdt het momentum aan tot T? [−1,+1] | nieuw: momentum-richting-tot-T |
| `second_diff_max_norm` | scherpste knik/versnelling (change-point proxy) | tweede orde — de 31 zijn eerste orde |
| `sign_product_dir_level` | fractie stappen die stijgen én boven mediaan | richting × niveau (kruising) |
| `age_of_max_normalized` | tijd sinds de window-top (vers vs uitgewerkt) | tijd-afstand i.p.v. waarde-afstand tot high |
| `iqr_normalized` | robuuste relatieve spreiding (Q3−Q1)/mediaan | outlier-bestendig vs std/volatility |
| `zero_crossing_rate_detrended` | oscillatie-frequentie rond het gemiddelde | nul-doorgangen vs reversal-toppen |
| `longest_monotone_run_fraction` | langste schone stijg-push / (N−1) | vs huidige streak (consecutive_increases) |
| `gini_coefficient` | volume-concentratie (1 tick domineert?) | nieuw, alleen op volume |
| `path_efficiency` | rechtlijnig naar T vs heen-en-weer | netto/totaal-pad (critic-additie) |

De sterkste verwachte scheiding: `kendall_tau`, `linreg_r2`, `cumsum_position` (directe "geordende opbouw
tot T"), dan `theilsen_slope` en `second_diff_max_norm` (robuust tegen onze bekende bad-ticks).

## Cross-kanaal ronde — GEBOUWD (`cross_calcs.py`)
De 13 zijn univariate (1 reeks). De rijkste niet-verkende as is **cross-kanaal** (volume × prijs samen),
nu geïmplementeerd in `engine/src/cross_calcs.py` (interface (vals, prices) = die van `subrule_value`,
dus direct engine-toepasbaar met indicator='volumeud'):
- **`vol_price_rank_corr`** = Spearman-rang-correlatie volume↔prijs — "stijgt de prijs mét volume (echte
  koopdruk) of op dun volume (zwakke move)?". Criticus' sterkste gemiste feature.
- **`price_rank_in_window`** = robuuste rang-positie van de huidige prijs (vs min/max-`pos_in_range`).
- **`updown_asymmetry`** = netto stijg- vs daal-padlengte, genormaliseerd.
- **`vol_concentration_at_high`** = zat het grootste volume op de piekprijs (laat) of vooraf (opbouw)?
Gemeten als indicator `volprice` in `feature_quality`; resultaten in de meting-sectie.

## add_later (de bouw-backlog, geen lib-blokkade)
Zelf in numpy te bouwen, mits eerst gegate tegen redundantie met de 13: `perm_entropy(order=3)`, `katz_fd`,
`hjorth_mobility/complexity`, `excess_kurtosis`, `bowley_skewness`, `medcouple`, `acf_lag2`, `cid_ce`,
`CO_trev_1_num` (time-reversibility), `Bollinger %B`, `RSI` (~5 regels numpy), `crest_factor`, `CUSUM_drift`,
`peak_count_normalized`, Haar `wavelet_detail_energy_ratio`. **TA-groep met H/L-input** (CMF, Vortex, NATR,
CCI, Stochastic %K, ADX) hangt op één vraag: levert de bron per-tick High/Low? Nu niet → eerst verifiëren.

## Afgevallen (waarom — leerzaam)
Niet-schaalvrij (cross-coin onbruikbaar): MACD-histogram, Force Index, Ease of Movement, Ljung-Box.
Te-lang-window (N≥50, op 5–20 ruis): sample/approx/spectral entropy, Hurst, DFA, Higuchi FD, runs-test,
FFT-spectraalfeatures, matrix-profile, RQA. Overlap met bestaande: ROC (=diff_previous_percentage),
Williams %R (=Stochastic gespiegeld), Petrosian FD (≈reversal_count), monotonie-breuk-index (≈reversal).

## Meting (uitgevoerd — `brain.feature_quality`, bron 'promising')

Gemeten op de brede bron (DOGEAI 18.624 / NOS 15.233 promising momenten). Maat = `separation` (|2·AUC−1|,
cross-coin = min over beide munten). 0.10 ≈ AUC 0.55 (zwak), 0.22 ≈ AUC 0.61 (matig). **Geen enkele losse
berekening scheidt sterk** — bevestigt dat je een COMBINATIE van subregels nodig hebt, geen wonderberekening.

**Top-8 cross-coin scheiders:**

| berekening | beste op | cross-coin sep | nieuw? |
|---|---|---|---|
| sum_average_positive_percentage | price/10 | 0.222 | |
| diff_percentage_prev_max | price/5 | 0.207 | |
| **gini_coefficient** | price/20 | 0.176 | **NIEUW (beste van de 13)** |
| range_percentage | price/10 | 0.175 | |
| volatility | price/20 | 0.170 | |
| max_diff_percentage | price/20 | 0.169 | |
| diff_lowest_value_period | price/10 | 0.165 | |
| **iqr_normalized** | price/20 | 0.158 | **NIEUW** |

**Drie harde lessen uit de cijfers:**
1. **De PRIJS-reeks scheidt, niet de indicatoren.** Bijna alle toppers staan op `price`; phobos/vzo/mfi/obv
   scheiden zwak (sep < 0.13). De prijs-dynamiek is onderbenut in de huidige rules.
2. **AMPLITUDE wint van RICHTING.** Wat scheidt is *hoeveel* de prijs beweegt (sum_avg_positive, range,
   volatility, gini = spreiding/concentratie), kant b_min = "weinig beweging → verliezer" (het dode-coin-
   signaal). De richtings/trend-berekeningen die de research het meest beloofde — `kendall_tau`,
   `cumsum_position`, `linreg_r2`, `theilsen_slope` — scoren juist LAAG (< 0.10). Eerlijke verrassing.
3. **De nieuwe berekeningen voegen toe, geen doorbraak.** `gini_coefficient` (#3) en `iqr_normalized` (#8)
   verdienen hun plek; de overige 11 niet (nu). Winst voor de voorraad, niet voor een directe keeper.

**Cross-kanaal uitkomst — tegengevallen (eerlijk):** `vol_price_rank_corr` (de criticus' "sterkste gemiste
feature") scheidt cross-coin **0.005** — vrijwel niets; `vol_concentration_at_high` 0.03, de rest < 0.03.
De hypothese "prijs stijgt mét volume = koopdruk" houdt geen stand op onze data. Samen met les 2 (richting/
trend scoort laag) scherpt dit het beeld aan: **alleen de prijs-AMPLITUDE scheidt** — niet de richting,
niet de prijs↔volume-relatie, niet de oscillator-niveaus. De berekeningen blijven in de DB + engine (kunnen
met meer munten alsnog meekomen), maar zijn nu geen kandidaat-subregel.

Smalle bron (rule30/31): te weinig winnaars voor de AUC-maat (≥20/klasse) → alleen NOS rule31 gevuld;
voor die bron blijft `subrule_power.py` (good_keep 100%, walk-forward) de juiste maat. Wordt vanzelf
bruikbaar met meer coins. Herhaal `feature_quality.py promising` na elke coin-uitbreiding.
