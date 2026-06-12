#!/bin/bash

# Create Dutch translations for all optimize and organize pages

cat << 'EOF' > content/collections/pages/nl/pdf-roteren.md
---
id: pdf-roteren
blueprint: landing_page
title: 'PDF Roteren'
slug: pdf-roteren
template: landing
hero:
  title: 'PDF-pagina''s roteren'
  subtitle: 'Roteer PDF-pagina''s eenvoudig naar de juiste oriëntatie. Corrigeer gescande documenten, pas liggende pagina''s aan en zorg dat alle inhoud correct wordt weergegeven.'
  cta_label: 'Start Roteren'
  cta_url: /pdf-roteren
features:
  -
    id: rotate_feat_1
    type: feature_block
    enabled: true
    title: 'Precieze Paginarotatie'
    text: 'Roteer individuele pagina''s of volledige documenten met 90, 180 of 270 graden. Perfect voor het corrigeren van verkeerd gescande documenten of aanpassen van bestanden met gemengde oriëntatie.'
    alignment: left
    image: pdf_compress_optimize.webp
  -
    id: rotate_feat_2
    type: feature_block
    enabled: true
    title: 'Batch Verwerking'
    text: 'Roteer meerdere PDF-bestanden tegelijk of upload ZIP-archieven met meerdere documenten voor efficiënte batch-rotatie.'
    alignment: right
    image: pdf_compress__batch_optimize.webp
  -
    id: rotate_feat_3
    type: feature_block
    enabled: true
    title: 'Slimme Rotatie & Workflow'
    text: 'Pas rotatie toe op alle pagina''s of selecteer specifieke paginabereiken. Combineer met andere bewerkingen zoals compressie of PDF/A-conversie. Sla uw workflow op voor herhaald gebruik.'
    alignment: left
    image: optimize_organize_settings_pdf.webp
how_it_works:
  -
    id: rotate_step_1
    type: step
    enabled: true
    step_number: 1
    title: 'Upload PDF-bestanden'
    description: 'Selecteer uw PDF-bestanden of upload een ZIP-archief met documenten om te roteren.'
  -
    id: rotate_step_2
    type: step
    enabled: true
    step_number: 2
    title: 'Kies Rotatiehoek'
    description: 'Selecteer de rotatiehoek (90°, 180° of 270°) en geef aan welke pagina''s geroteerd moeten worden.'
  -
    id: rotate_step_3
    type: step
    enabled: true
    step_number: 3
    title: 'Download Geroteerde PDF''s'
    description: 'Ontvang uw correct georiënteerde PDF-bestanden. Meerdere documenten worden verpakt in een ZIP-archief.'
faq_local:
  -
    id: rotate_faq_1
    type: faq
    enabled: true
    question: 'Kan ik individuele pagina''s verschillend roteren?'
    answer: 'Ja, u kunt verschillende rotatiehoeken opgeven voor verschillende paginabereiken binnen hetzelfde document. Bijvoorbeeld, roteer pagina''s 1-3 met 90° en pagina''s 4-6 met 180°.'
  -
    id: rotate_faq_2
    type: faq
    enabled: true
    question: 'Beïnvloedt rotatie de PDF-kwaliteit?'
    answer: 'Nee, rotatie is een verliesloze bewerking die geen invloed heeft op de kwaliteit van uw PDF. Tekst, afbeeldingen en alle andere inhoud behouden hun originele kwaliteit.'
  -
    id: rotate_faq_3
    type: faq
    enabled: true
    question: 'Kan ik meerdere PDF''s tegelijk roteren?'
    answer: 'Ja, u kunt meerdere PDF-bestanden uploaden of een ZIP-archief met maximaal 50 bestanden voor batch-rotatie met dezelfde instellingen.'
  -
    id: rotate_faq_4
    type: faq
    enabled: true
    question: 'Welke rotatiehoeken zijn beschikbaar?'
    answer: 'U kunt pagina''s rechtsom roteren met 90° (rechts), 180° (ondersteboven), of 270° (links/linksom) om de juiste oriëntatie te bereiken.'
final_cta:
  title: 'Klaar om uw PDF''s te roteren?'
  description: 'Corrigeer problemen met pagina-oriëntatie snel en eenvoudig met onze PDF-rotatietool.'
  button_label: 'Roteer PDF-pagina''s'
  button_url: /pdf-roteren
other_conversions:
  enabled: true
  title: 'Andere PDF-tools'
  subtitle: 'Optimaliseer en verbeter uw PDF-documenten met onze professionele tools.'
  selected_conversions:
    - pdf-splitsen
    - pdf-samenvoegen
    - comprimeer-pdf
reviews:
  title: 'Gebruikers en bedrijven bevelen ons graag aan'
  subtitle: 'Wereldwijd vertrouwd door toonaangevende organisaties voor hun documentverwerkingsbehoeften.'
updated_by: 383f16db-79f1-4102-9b08-367e30ee95a8
updated_at: 1759924384
---
EOF

cat << 'EOF' > content/collections/pages/nl/pdf-splitsen.md
---
id: pdf-splitsen
blueprint: landing_page
title: 'PDF Splitsen'
slug: pdf-splitsen
template: landing
hero:
  title: 'PDF-bestanden splitsen'
  subtitle: 'Extraheer specifieke pagina''s of splits PDF''s in meerdere documenten. Perfect voor het delen van alleen relevante secties of het maken van aparte bestanden uit grote documenten.'
  cta_label: 'Start Splitsen'
  cta_url: /pdf-splitsen
features:
  -
    id: split_feat_1
    type: feature_block
    enabled: true
    title: 'Flexibele Pagina-extractie'
    text: 'Extraheer specifieke pagina''s, paginabereiken, of splits op vast aantal pagina''s. Maak meerdere documenten uit één PDF met precieze controle over inhoudsverdeling.'
    alignment: left
    image: pdf_compress_optimize.webp
  -
    id: split_feat_2
    type: feature_block
    enabled: true
    title: 'Slimme Splitsopties'
    text: 'Splits op aantal pagina''s, extraheer elke N pagina''s, of definieer aangepaste bereiken. Verwerk meerdere PDF''s tegelijk met ZIP-archiefondersteuning voor batch-bewerkingen.'
    alignment: right
    image: pdf_compress__batch_optimize.webp
  -
    id: split_feat_3
    type: feature_block
    enabled: true
    title: 'Geavanceerde Instellingen & Workflow'
    text: 'Kies extractiemodus: splits in vaste delen, extraheer specifieke pagina''s, of splits bij elke N pagina''s. Voeg compressie of PDF/A-conversie toe aan geëxtraheerde bestanden. Sla workflows op voor herhaald gebruik.'
    alignment: left
    image: optimize_organize_settings_pdf.webp
how_it_works:
  -
    id: split_step_1
    type: step
    enabled: true
    step_number: 1
    title: 'Upload PDF-bestanden'
    description: 'Selecteer uw PDF-bestand of upload meerdere PDF''s voor batch-splitsing.'
  -
    id: split_step_2
    type: step
    enabled: true
    step_number: 2
    title: 'Definieer Splitsregels'
    description: 'Kies hoe te splitsen: per paginabereik (1-3, 4-8), vaste intervallen, of extraheer specifieke pagina''s.'
  -
    id: split_step_3
    type: step
    enabled: true
    step_number: 3
    title: 'Download Gesplitste PDF''s'
    description: 'Ontvang uw geëxtraheerde PDF-bestanden verpakt in een handig ZIP-archief.'
faq_local:
  -
    id: split_faq_1
    type: faq
    enabled: true
    question: 'Hoe kan ik specifieke pagina''s uit een PDF extraheren?'
    answer: 'U kunt exacte paginanummers opgeven (bijv. 1, 3, 5) of paginabereiken (bijv. 1-5, 10-15). Gebruik komma''s voor meerdere selecties.'
  -
    id: split_faq_2
    type: faq
    enabled: true
    question: 'Kan ik een PDF in gelijke delen splitsen?'
    answer: 'Ja, u kunt een PDF in documenten van gelijke grootte splitsen door het aantal pagina''s per splitsing op te geven. Bijvoorbeeld, splits een 100-pagina PDF in 10-pagina documenten.'
  -
    id: split_faq_3
    type: faq
    enabled: true
    question: 'Welke splitsmodi zijn beschikbaar?'
    answer: 'We bieden drie modi: Extraheer specifieke pagina''s (aangepaste bereiken), Splits op vast aantal pagina''s (elke N pagina''s), en Splits in N gelijke delen.'
  -
    id: split_faq_4
    type: faq
    enabled: true
    question: 'Hoe worden de gesplitste bestanden genoemd?'
    answer: 'Gesplitste bestanden krijgen automatisch een naam met de originele bestandsnaam plus een achtervoegsel dat het paginabereik of deelnummer aangeeft (bijv. document_paginas_1-10.pdf, document_deel_2.pdf).'
final_cta:
  title: 'Klaar om uw PDF''s te splitsen?'
  description: 'Extraheer pagina''s en splits PDF-documenten snel en nauwkeurig.'
  button_label: 'Splits PDF-bestanden'
  button_url: /pdf-splitsen
other_conversions:
  enabled: true
  title: 'Andere PDF-tools'
  subtitle: 'Optimaliseer en verbeter uw PDF-documenten met onze professionele tools.'
  selected_conversions:
    - pdf-samenvoegen
    - pdf-roteren
    - comprimeer-pdf
reviews:
  title: 'Gebruikers en bedrijven bevelen ons graag aan'
  subtitle: 'Wereldwijd vertrouwd door toonaangevende organisaties voor hun documentverwerkingsbehoeften.'
updated_by: 383f16db-79f1-4102-9b08-367e30ee95a8
updated_at: 1759924384
---
EOF

echo "Dutch pages created successfully!"