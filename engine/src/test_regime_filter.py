#!/usr/bin/env python3
"""
test_regime_filter — DE BORGING van Epic H (sectie D): bewijst dat een regime-wijziging NIET door een
oude cache gemaskeerd wordt. Verzet één dag van een nu-actieve munt naar 'inactive' en bewijst:
 1. _long_fingerprint(X) verandert     -> de per-munt long-cache invalideert (anders serveert die de oude set)
 2. input_fingerprint() verandert      -> de data-veranderd-gate hertriggert de keten (anders slaat hij over)
 3. load_trades() levert minder trades; load_trades(include_inactive=True) levert weer alles
Herstelt de coin_regime-tabel in finally. Plain asserts; draai met de venv-python.
"""
import pandas as pd

import opt_lib as ol
import routines
from db import brain

X = 244                       # NOS — heeft executed trades in een nu-actieve periode
TEST_REASON = "H-FILTERTEST"  # herkenbaar zodat de finally alleen de test-rij wist


def main():
    conn = brain()
    inserted = False
    try:
        # --- baseline
        base_all = ol.load_trades()
        base_incl = ol.load_trades(include_inactive=True)
        xtrades = base_all[base_all["sym"] == X]
        assert not xtrades.empty, f"munt {X} heeft geen actieve trades — kies een andere testmunt"
        victim_day = pd.Timestamp(xtrades["datetime"].iloc[0]).date()
        n_victims = int((pd.to_datetime(xtrades["datetime"]).dt.date == victim_day).sum())
        assert n_victims >= 1
        lf_base = ol._long_fingerprint(X)
        if_base = routines.input_fingerprint()

        # --- verzet die dag naar inactief (autocommit -> direct zichtbaar voor de volgende calls)
        with conn.cursor() as c:
            c.execute("INSERT INTO coin_regime (trading_symbol_id, period_from, period_to, state, reason, "
                      "rolling_result, computed_at) VALUES (%s,%s,%s,'inactive',%s,0,NOW())",
                      (X, victim_day, victim_day, TEST_REASON))
        inserted = True

        # --- 1+2: de fingerprints MOETEN veranderen
        assert ol._long_fingerprint(X) != lf_base, "long-fingerprint onveranderd -> cache maskeert de filter!"
        assert routines.input_fingerprint() != if_base, "input_fingerprint onveranderd -> keten hertriggert NIET!"

        # --- 3: minder trades default; alles met include_inactive
        after = ol.load_trades()
        assert len(after) == len(base_all) - n_victims, \
            f"verwacht {len(base_all) - n_victims} (={len(base_all)}-{n_victims}), kreeg {len(after)}"
        after_x = after[after["sym"] == X]
        assert (pd.to_datetime(after_x["datetime"]).dt.date == victim_day).sum() == 0, \
            "de inactief-gemaakte dag zit nog in load_trades()"
        assert len(ol.load_trades(include_inactive=True)) == len(base_incl), \
            "include_inactive=True zou ALLE trades moeten laden (filter genegeerd)"

        print(f"OK test_regime_filter — {n_victims} trade(s) op {victim_day} weggefilterd; "
              f"long-fp {lf_base[:8]}->{ol._long_fingerprint(X)[:8]}, "
              f"trades {len(base_all)}->{len(after)} (incl-inactief {len(base_incl)})")
    finally:
        if inserted:
            with conn.cursor() as c:
                c.execute("DELETE FROM coin_regime WHERE trading_symbol_id=%s AND reason=%s", (X, TEST_REASON))
        conn.close()


if __name__ == "__main__":
    main()
