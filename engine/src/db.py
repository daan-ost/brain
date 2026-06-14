"""
DB connections. The pipeline reads/writes ONLY brain. The legacy bot_signals connection
exists for the one import (import_indicators.py / seed_rules.py) and for OFFLINE validation
against the legacy labels/oracle — never in the screens or the production path.
"""
import pymysql


def brain(dict_cursor=True):
    return pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                           database="brain",
                           cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)


def legacy(dict_cursor=True):
    """Read-only legacy bot_signals — import + offline validation ONLY."""
    return pymysql.connect(host="127.0.0.1", port=8889, user="root", password="root",
                           database="bot_signals",
                           cursorclass=pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor)
