"""
DB connections. The pipeline reads/writes ONLY brain. The legacy bot_signals connection
exists for the one import (import_indicators.py / seed_rules.py) and for OFFLINE validation
against the legacy labels/oracle — never in the screens or the production path.
"""
import os
import time

import pymysql

# autocommit=True is ESSENTIEEL: zonder draait PyMySQL+InnoDB op REPEATABLE READ, waarbij een
# connectie de eerste SELECT als bevroren read-view vasthoudt. De apply-poorten meten base → refire
# (in een subprocess dat commit) → new op DEZELFDE connectie; onder REPEATABLE READ ziet de tweede
# meting nog de oude snapshot → de poort vergelijkt base met base en keurt ELKE wijziging goed.
# Met autocommit krijgt elke statement een verse view, dus de refire-writes zijn meteen zichtbaar.
# Veilig: de codebase gebruikt nergens rollback of multi-statement-transacties (alle writes committen
# al expliciet); die expliciete commits worden hooguit harmless no-ops.

# Connection-stabiliteit (A2): MAMP's MySQL drop[t] regelmatig SSL-connecties tijdens lange queries
# (SSLEOFError → "MySQL server has gone away"). Drie maatregelen:
#  1. ssl=None expliciet uit (MAMP onderhandelt SSL niet stabiel; we draaien lokaal dus geen risico)
#  2. read_timeout + write_timeout — een hangende socket gaat niet uren wachten maar faalt snel
#  3. _connect_with_retry — exponential backoff op connect-fase (3 pogingen, 1s/2s/4s)
# Mid-query drops blijven moeilijk te recoveren — de aanroep-site moet daarvoor zelf retryen.
_RETRY_ATTEMPTS = 3
_BASE_DELAY_SEC = 1.0


def _connect_with_retry(**kwargs):
    last = None
    for i in range(_RETRY_ATTEMPTS):
        try:
            return pymysql.connect(**kwargs)
        except (pymysql.err.OperationalError, ConnectionError, OSError) as e:
            last = e
            if i < _RETRY_ATTEMPTS - 1:
                time.sleep(_BASE_DELAY_SEC * (2 ** i))
    raise last


_COMMON = dict(host="127.0.0.1", port=8889, user="root", password="root", autocommit=True,
               read_timeout=600, write_timeout=600, connect_timeout=10, ssl=None)


def brain(dict_cursor=True):
    return _connect_with_retry(database="brain", **_COMMON,
                               cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)


def legacy(dict_cursor=True):
    """Read-only legacy bot_signals — import + offline validation ONLY."""
    return _connect_with_retry(database="bot_signals", **_COMMON,
                               cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)


def mexc(dict_cursor=True):
    """
    MEXC coin-tracking DB — env-configureerbaar zodat dezelfde scan-code lokaal én op de server draait.
    Default (geen env) = lokale MAMP brain-DB, zodat de bestaande routine/UI niet breekt.
    Op de 66bio-VPS: zet MEXC_DB_* naar de eigen `mexc`-database (zie docs/findings/mexc-coin-tracking-2026-06-29.md).

      MEXC_DB_HOST  (default 127.0.0.1)
      MEXC_DB_PORT  (default 8889 — MAMP; server: 3306)
      MEXC_DB_USER  (default root)
      MEXC_DB_PASS  (default root)
      MEXC_DB_NAME  (default brain — server: mexc)
    """
    cfg = dict(
        host=os.environ.get("MEXC_DB_HOST", "127.0.0.1"),
        port=int(os.environ.get("MEXC_DB_PORT", "8889")),
        user=os.environ.get("MEXC_DB_USER", "root"),
        password=os.environ.get("MEXC_DB_PASS", "root"),
        database=os.environ.get("MEXC_DB_NAME", "brain"),
        autocommit=True, read_timeout=600, write_timeout=600, connect_timeout=10, ssl=None,
    )
    return _connect_with_retry(**cfg,
                               cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)
