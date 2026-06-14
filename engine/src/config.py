"""
Shared trading config — single source of truth for the horizons and the promising gates.

Horizons:
- FORWARD_MINUTES  — max trade DURATION / hold (the sell-engine exits within this). ~1 hour.
- UPSIDE_MINUTES   — the promising UPSIDE horizon: a good entry must rise to +min_upside within
                     this short window. PER-COIN, derived from each coin's own time-to-+5% (p90):
                     DOGEAI is fast (~25 min), NOS is slow (~45 min). Default 30.
- CLUSTER_GAP_MINUTES — promising moments more than this apart are DIFFERENT opportunities;
                     keeps distinct short moves as separate periods (execution overlap between
                     them is handled by the shadow logic on fires).

Promising gates (calibrated on good vs bad trades):
- MIN_DURATION_MINUTES — the move must stay above entry (within DROP_BELOW_PCT) at least this
                     long. Good trades sustain ~56 min; bad trades collapse within ~4-9 min, so
                     this drops most bad while keeping most good ("minstens een bepaalde duur").
- (a "niet te snel" rate cap was tested but the first-60s rise is ~0% for all trades — no
   signal — so it is omitted for now.)
"""

FORWARD_MINUTES = 60
UPSIDE_MINUTES_DEFAULT = 30
UPSIDE_MINUTES_PER_COIN = {2525: 25, 244: 45}     # DOGEAI fast, NOS slow (p90 time-to-+5%)
CLUSTER_GAP_MINUTES = 15

MIN_UPSIDE_PCT = 5.0                              # the move must reach at least this within UPSIDE_MINUTES
MAX_EARLY_DIP_PCT = -0.1                           # the first ~10 ticks may dip at most this ("only rises")
MIN_DURATION_MINUTES = 10                          # the move must stay above entry at least this long
DROP_BELOW_PCT = -0.3                              # "above entry" tolerance for the duration gate


def upside_minutes(symbol):
    return UPSIDE_MINUTES_PER_COIN.get(int(symbol), UPSIDE_MINUTES_DEFAULT)
