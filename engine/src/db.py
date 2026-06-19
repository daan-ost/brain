"""
DB connections. The pipeline reads/writes ONLY brain. The legacy bot_signals connection
exists for the one import (import_indicators.py / seed_rules.py) and for OFFLINE validation
against the legacy labels/oracle — never in the screens or the production path.
"""
import pymysql

# autocommit=True is ESSENTIEEL: zonder draait PyMySQL+InnoDB op REPEATABLE READ, waarbij een
# connectie de eerste SELECT als bevroren read-view vasthoudt. De apply-poorten meten base → refire
# (in een subprocess dat commit) → new op DEZELFDE connectie; onder REPEATABLE READ ziet de tweede
# meting nog de oude snapshot → de poort vergelijkt base met base en keurt ELKE wijziging goed.
# Met autocommit krijgt elke statement een verse view, dus de refire-writes zijn meteen zichtbaar.
# Veilig: de codebase gebruikt nergens rollback of multi-statement-transacties (alle writes committen
# al expliciet); die expliciete commits worden hooguit harmless no-ops.


def brain(dict_cursor=True):
    return pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                           database="brain", autocommit=True,
                           cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)


def legacy(dict_cursor=True):
    """Read-only legacy bot_signals — import + offline validation ONLY."""
    return pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                           database="bot_signals", autocommit=True,
                           cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)
