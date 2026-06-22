"""
Rule-Discovery Engine (Epic RD) — bottom-up nieuwe koop-rules uit de promising trades.

Consolidatie van de read-only proef-harness (engine/src/parent_*.py) tot één engine, plus de twee
onderdelen die de proef miste:
  - pysubgroup  : segmenteren van de goede trades met kwaliteits-maten + non-redundantie (segment.py)
  - CPCV        : subregel-keuze gestuurd op de trefkans BUITEN de trainingsdata (funnel.py + validate.py)

Draaien (vanuit engine/src):  python -m discovery.run --coin DOGEAI
Methodiek: docs/methodology/rule-discovery.md. Lat = rules 20-23 (§5), niet de willekeurige nullijn.
"""
