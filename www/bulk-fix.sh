#!/bin/bash

# Backup
cp config/landing_pages.php config/landing_pages.php.backup

# List of conversions needing show_conversion_options => true
conversions_true=(
    "image-to-pdf"
    "doc-to-pdf"
    "excel-to-pdf"
    "powerpoint-to-pdf"
    "pdf-to-word"
    "pdf-to-excel"
    "pdf-to-powerpoint"
    "pdf-to-text"
    "pdf-to-images"
    "ebook-to-pdf"
    "pdf-to-html"
    "html-to-pdf"
    "pdf-to-txt"
    "pdf-to-csv"
    "csv-to-pdf"
    "pub-to-pdf"
    "rtf-to-pdf"
    "vsd-to-pdf"
    "md-to-pdf"
    "odg-to-pdf"
    "pdf-to-split"
    "pdf-to-rotate"
    "pdf-to-protect"
    "pdf-to-unprotect"
    "pdf-to-delete-pages"
    "compress-pdf"
)

# Add show_conversion_options => true after 'job' => 'generic_convert_to_pdf',
for conv in "${conversions_true[@]}"; do
    # Use perl for in-place editing with proper multiline matching
    perl -i -pe "BEGIN{undef $/;} s/('${conv}' => \[[^]]*?'job' => 'generic_convert_to_pdf',\s*\n)/\$1        'show_conversion_options' => true,\n/smg" config/landing_pages.php
    echo "✅ Added show_conversion_options => true to ${conv}"
done

# Conversions needing show_conversion_options => false
conversions_false=(
    "log-to-pdf"
    "pdfs-to-pdf"
    "epub-to-pdf"
    "repair-pdf"
    "rasterize-pdf"
)

for conv in "${conversions_false[@]}"; do
    perl -i -pe "BEGIN{undef $/;} s/('${conv}' => \[[^]]*?'job' => 'generic_convert_to_pdf',\s*\n)/\$1        'show_conversion_options' => false,\n/smg" config/landing_pages.php
    echo "✅ Added show_conversion_options => false to ${conv}"
done

echo ""
echo "✨ Bulk fix complete!"
