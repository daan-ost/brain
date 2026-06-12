# AI Factory — Phase 6.6 v5

## Feature Refinement Pipeline (Spec-Quality First)

---

## 0. Correctie & uitgangspunten

Deze versie verwerkt een **belangrijke rol-correctie**:

- **ChatGPT structureert in Stap 1** (v1)
- **Claude is leidend in de eerste inhoudelijke spec-review (Stap 3a)**
- **ChatGPT fungeert daarna uitsluitend als integrator/beslisser**
- **Gemini is expliciet gericht op \*\*\***verbeter-voorstellen\*\*\* (niet alleen review)

Alle stappen werken met **eigen, onderhoudbare prompts**, configureerbaar via het dashboard.

---

## 1. Doel

Een Feature-spec zó uitwerken dat:

- de **bedoeling inhoudelijk klopt**
- UX, edge cases en impliciete aannames expliciet zijn
- de spec aantoonbaar **beter wordt door meerdere invalshoeken**
- developers zonder interpretatie kunnen bouwen

Pas daarna volgen gates, testability en Fast Track.

---

## 2. Hoofdfases

1. Structurering & Scope-validatie
2. **Spec Review & Verbetering (inhoudelijk, verplicht)**
3. Objectieve Completeness & Testability
4. Admin Gate
5. Fast Track Build
6. Post-Build Learning

---

## 3. Rollen & Principes

| Rol               | Functie                                  |
| ----------------- | ---------------------------------------- |
| Admin             | Strategische input & finale beslissingen |
| ChatGPT           | Structureren, integreren, besluiten      |
| Builder Agent     | Technische realiteit & scope             |
| Claude            | Inhoudelijke + UX-kwaliteitsreview       |
| Gemini (thinking) | Gerichte verbeter-voorstellen            |

**Principe:**

- Reviews zijn _inhoudelijk_
- ChatGPT **reviewt niet**, maar **verwerkt**

---

## 4. Procesflow (detail)

### Stap 0 — Admin Input

**Cost / Token Guard**

Bij start van het proces:

- Als admin-input > vooraf ingestelde tokenlimiet:

  - waarschuwing tonen
  - optie aanbieden: _"Laat AI eerst een samenvatting maken"_

Doel: kostenbeheersing en voorkomen van onnodige loops.

---

### Stap 0 — Admin Input

High-level beschrijving:

- doel
- doelgroep
- non-goals

---

### Stap 1 — ChatGPT → feature_v1.md (Structurering)

**Prompt-type:** Structuring Prompt

```md
# Feature: <title>

## Goal

## User Value

## In Scope

## Out of Scope

## Assumptions

## Initial UX Flow

## Open Questions
```

Geen inhoudelijke optimalisatie — alleen ordenen en expliciteren.

---

### Stap 2 — Builder → feature_v2.md (Technische Analyse)

**Prompt-type:** Technical Analysis Prompt

Toevoegingen:

```md
## Technical Considerations

## Dependencies

## Data Model Impact

## Edge Cases

## Open Technical Questions
```

---

### Stap 2b — Complexity Gate

```md
complexity_assessment: feature | blueprint | needs_split
```

- blueprint → andere track
- needs_split → admin
- feature → door

---

## 5. Stap 3 — Spec Review & Verbetering (KERN)

### Stap 3a — Claude → Inhoudelijke Spec Review

**Prompt-type:** Spec Quality & UX Review Prompt

**Doel:**

- Kwaliteit van de beschrijving verbeteren
- Onvolledigheden en impliciete aannames blootleggen
- UX-flow en unhappy paths aanscherpen

**Claude output:**

- Concrete verbetervoorstellen
- Kritische opmerkingen per sectie

➡️ Output gaat **ongewijzigd** naar ChatGPT

---

### Stap 3a.2 — ChatGPT → Integratie Claude

**Prompt-type:** Review Integration Prompt

ChatGPT:

- verwerkt Claude-feedback
- neemt over / stelt ter discussie
- motiveert keuzes

**Output:** `feature_v3a.md`

---

### Stap 3b — Gemini (thinking) → Verbeter-voorstellen

**Prompt-type:** Feature Improvement Prompt

**Instructie (essentie):**

> Stel **exact 3 inhoudelijke verbeteringen** voor die deze feature aantoonbaar beter maken qua:
>
> - gebruik
> - volledigheid
> - robuustheid

Niet reviewen, maar **voorstellen doen**.

**Gemini output:**

- 3 verbeterpunten (met rationale)

---

### Stap 3b.2 — Claude → Reactie op Gemini

**Prompt-type:** Cross-Review Prompt

Claude ontvangt:

- feature_v3a.md
- Gemini-voorstellen

Claude:

- beoordeelt haalbaarheid en UX-impact
- reageert expliciet op elk voorstel

---

### Stap 3c — ChatGPT → Finale Integratie & Besluit

**Prompt-type:** Decision & Merge Prompt

ChatGPT verwerkt:

- Gemini-voorstellen
- Claude-reacties daarop

**Output:** `feature_v3.md`

Toevoeging:

````md
## Review Decisions

### Accepted

### Deferred / Discussed

## Confidence Score

- confidence_percentage: <0-100>
- confidence_rationale: korte toelichting gebaseerd op mate van discussie en open punten

```md
## Review Decisions

### Accepted

### Deferred / Discussed
```
````

---

## 6. Stap 4 — Objectieve Completeness Gate

Checklist-based:

```md
□ Geen open questions
□ Geen TBD / vaag taalgebruik
□ ≥3 acceptance criteria
□ Data model impact expliciet
□ ≥2 edge cases
```

- FAIL → terug naar Stap 3
- PASS → door

---

## 7. Stap 5 — Testability Validation

**Prompt-type:** Testability Prompt

Agent genereert Given/When/Then tests.

```md
testability_status: PASS | FAIL
missing_prerequisites:
```

- FAIL → terug naar Stap 3c

---

## 8. Stap 6 — Admin Review

Admin ziet alleen specs die:

- inhoudelijk zijn verrijkt
- gates hebben gehaald

Extra zichtbaarheid voor admin:

```md
Status: Ready for Fast Track
Confidence: <confidence_percentage>%
```

Daarnaast bevat de spec een korte samenvatting:

```md
## Changes made by AI

- Toegevoegd: ...
- Aangepast: ...
- Verwijderd: ...
```

Acties:

- Approve
- Reject (verplicht notitieveld)
- Cancel (verplicht notitieveld)
- Request Split

Geen reactie = impliciete goedkeuring.

---

## 9. Stap 7 — Builder Final Check

Laatste sanity check.

---

## 10. Stap 8 — Fast Track Build

---

## 11. Stap 9 — Post-Build Feedback Loop

```md
build_feedback:

- type: missing_spec | ambiguity | wrong_assumption
- spec_location
```

Voedt prompt- en procesverbetering.

---

## 10a. Loop Protection

Om oneindige refinement-loops te voorkomen:

```md
- Max 2 refinement cycles per gate
- Na 2x FAIL op dezelfde gate → Admin escalatie
- Status: "Refinement Stalled – Admin Input Required"
```

Tracking:

- refinement_cycle_count per spec
- last_failed_gate

---

## 10b. Artifact Versioning

Eenduidige naamgeving van artefacten:

```md
feature_v1.md → na Stap 1 (structuur)
feature_v2.md → na Stap 2 (technisch)
feature_v3.md → na Stap 3c (review compleet)
feature_final.md → na alle gates PASS
```

Interne iteraties tijdens refinement:

```md
feature_v3_r1.md
feature_v3_r2.md
```

(r = refinement round)

---

## 12. Waarom dit de juiste balans is

- Claude doet wat hij het best kan: **inhoud & UX**
- Gemini doet wat hij goed kan: **nieuwe verbeteringen voorstellen**
- ChatGPT bewaakt samenhang en beslissingen
- Prompts zijn per stap vervangbaar en onderhoudbaar

Dit maximaliseert spec-kwaliteit zonder admin-frictie.
