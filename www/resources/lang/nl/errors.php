<?php

return [
    // ConvertAPI Service Errors
    'conversion_service_unavailable' => 'Service Tijdelijk Niet Beschikbaar',
    'conversion_service_unavailable_message' => 'We ondervinden technische problemen met onze conversieservice. Probeer het over een paar minuten opnieuw of neem contact op met support als het probleem aanhoudt.',

    'conversion_service_error' => 'Conversieservice Fout',
    'conversion_service_error_message' => 'Onze conversieservice is tijdelijk niet beschikbaar. Probeer het later opnieuw.',

    'conversion_server_error' => 'Server Fout',
    'conversion_server_error_message' => 'De conversieservice heeft een fout ondervonden. Probeer het opnieuw.',

    'conversion_timeout' => 'Verwerkingstijd Verstreken',
    'conversion_timeout_message' => 'Het verwerken van uw bestand duurt langer dan verwacht. Probeer het voor grote bestanden opnieuw of neem contact op met support.',

    'conversion_rate_limit' => 'Service Druk Bezet',
    'conversion_rate_limit_message' => 'Onze service verwerkt momenteel veel aanvragen. Wacht even en probeer het opnieuw.',

    'conversion_failed' => 'Conversie Mislukt',
    'conversion_failed_message' => 'We konden uw bestand niet verwerken. Controleer het bestand en probeer het opnieuw, of neem contact op met support.',

    // Specific ConvertAPI Errors
    'pdf_no_tables' => 'Geen Tabellen Gevonden in PDF',
    'pdf_no_tables_message' => 'Deze PDF bevat geen tabellen om te extraheren. De PDF-naar-Excel conversie werkt alleen met PDF\'s die tabelgegevens bevatten.',

    // File Limit Errors
    'file_limit_exceeded' => 'Bestandslimiet Overschreden',
    'file_limit_exceeded_message' => 'Uw abonnement staat :limit bestanden toe. U heeft :count bestanden geselecteerd.',
    'file_limit_remove_files' => 'Verwijder :excess bestand(en) om door te gaan.',

    'zip_too_many_files' => 'ZIP Bevat Te Veel Bestanden',
    'zip_too_many_files_message' => 'Deze ZIP bevat :count bestanden maar uw abonnement staat :limit bestanden toe.',
    'zip_rejection_split' => 'Splits in kleinere ZIPs (max :limit bestanden per ZIP)',
    'zip_rejection_upgrade' => 'Upgrade naar Business abonnement (:business_limit bestanden)',
    'zip_rejection_remove' => 'Verwijder enkele bestanden voordat u zipt',

    'multiple_zips_exceeded' => 'Meerdere ZIPs Overschrijden Limiet',
    'multiple_zips_exceeded_message' => 'Gecombineerd bevatten uw ZIPs :count bestanden. Uw abonnement staat :limit bestanden toe.',

    'file_count_status' => ':current / :limit bestanden',
    'files_remaining' => ':remaining bestanden resterend',

    'zip_open_failed' => 'Kon ZIP-bestand niet openen',

    // PDF Merge Errors
    'pdf_merge_failed' => 'PDF Samenvoegen Mislukt',
    'pdf_merge_failed_message' => 'Kan PDF\'s niet samenvoegen: :error',
    'pdf_corrupt' => 'Corrupt PDF-bestand',
    'pdf_corrupt_message' => 'Een of meer PDF-bestanden zijn corrupt of ongeldig. Controleer uw bestanden en probeer het opnieuw.',
    'pdf_header_not_found' => 'Kan PDF-bestandsheader niet vinden',
];
