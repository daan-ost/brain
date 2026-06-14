"""
Shared trading config — single source of truth for the horizons.

A trade runs on the 5-minute timeframe. Two different horizons:

- FORWARD_MINUTES  — max trade DURATION / hold (the sell-engine exits within this). ~1 hour.
- UPSIDE_MINUTES   — the promising UPSIDE horizon: a good entry must rise soon, within this
                     short window — NOT "peaks somewhere in the next hour". This is what keeps
                     promising windows short and precise (Daan: "direct omhoog binnen x minuten").
- CLUSTER_GAP_MINUTES — promising moments more than this apart are DIFFERENT opportunities;
                     keeps distinct short moves as separate periods (execution overlap between
                     them is handled separately by the shadow logic on fires).
"""

FORWARD_MINUTES = 60        # max hold (sell-engine)
UPSIDE_MINUTES = 15         # promising: the rise must arrive within this short window
CLUSTER_GAP_MINUTES = 15    # separate distinct short moves into separate periods
