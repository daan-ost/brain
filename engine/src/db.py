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


# Gedeelde verbindings-opties (timeouts/SSL). Host/poort/user/db komen uit _cfg() per DB.
_CONN_OPTS = dict(autocommit=True, read_timeout=600, write_timeout=600,
                  connect_timeout=10, ssl=None)


def _cfg(prefix, default_name, default_port="8889"):
    """
    Env-configureerbare verbinding voor één DB. Default (geen env) = lokale MAMP (poort 8889,
    root/root), zodat lokaal testen zonder env-gedoe blijft werken. Op de server zet je de
    <PREFIX>_* variabelen in de engine-env (de cron-wrapper sourcet die). Patroon gelijk aan mexc().

      <PREFIX>_HOST  (default 127.0.0.1)
      <PREFIX>_PORT  (default 8889 — MAMP; server: 3306)
      <PREFIX>_USER  (default root — server: eigen DB-user)
      <PREFIX>_PASS  (default root)
      <PREFIX>_NAME  (default {default_name})
    """
    return dict(
        host=os.environ.get(f"{prefix}_HOST", "127.0.0.1"),
        port=int(os.environ.get(f"{prefix}_PORT", default_port)),
        user=os.environ.get(f"{prefix}_USER", "root"),
        password=os.environ.get(f"{prefix}_PASS", "root"),
        database=os.environ.get(f"{prefix}_NAME", default_name),
        **_CONN_OPTS,
    )


def brain(dict_cursor=True):
    return _connect_with_retry(**_cfg("BRAIN_DB", "brain"),
                               cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)


def legacy(dict_cursor=True):
    """Read-only legacy bot_signals — import + offline validation ONLY.
    Bestaat alleen lokaal (MAMP); bot_signals verhuist NIET naar de server (epic-SV beslissing #1)."""
    try:
        return _connect_with_retry(**_cfg("LEGACY_DB", "bot_signals"),
                                   cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)
    except pymysql.err.OperationalError as e:
        if e.args and e.args[0] == 1049:  # Unknown database 'bot_signals'
            raise RuntimeError(
                "legacy() kan de bot_signals-DB niet bereiken. Die bestaat alleen lokaal (MAMP) en "
                "verhuist niet mee naar de server (epic-SV beslissing #1). Draai import/validatie tegen "
                "de legacy-DB lokaal, of zet de LEGACY_DB_* env-variabelen.") from e
        raise


def mexc(dict_cursor=True):
    """
    MEXC coin-tracking DB — env-configureerbaar zodat dezelfde scan-code lokaal én op de server draait.
    Default (geen env) = lokale MAMP brain-DB; op de 66bio-VPS zet MEXC_DB_* naar de eigen `mexc`-DB
    (zie docs/findings/mexc-coin-tracking-2026-06-29.md).
    """
    return _connect_with_retry(**_cfg("MEXC_DB", "brain"),
                               cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)
