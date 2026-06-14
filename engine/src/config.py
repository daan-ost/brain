"""
Shared trading config — single source of truth for the horizons.

A trade runs on the 5-minute timeframe and lasts at most ~1 hour. That same horizon
bounds BOTH the promising determination (how far forward we look for upside / the peak)
AND the sell-engine (max hold). Keeping it here means changing it in one place.
"""

# Max look-ahead for promising AND max trade duration for the sell-engine (minutes).
FORWARD_MINUTES = 60
