#!/bin/bash

# Restore backup first
cp config/landing_pages.php.backup config/landing_pages.php

echo "Restoring from backup..."

# Now apply fixes using a different marker - use 'allowed_mime_groups' which all have
conversions_true=(
    "images-to-pdf"
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

for conv in "${conversions_true[@]}"; do
    # Insert show_conversion_options after allowed_mime_groups line
    perl -i -0pe "s/('${conv}' => \[[^\]]*?'allowed_mime_groups' => \[[^\]]*?\],\s*\n)/\$1        'show_conversion_options' => true,\n/smg" config/landing_pages.php
    echo "✅ ${conv}"
done

conversions_false=(
    "log-to-pdf"
    "pdfs-to-pdf"
    "epub-to-pdf"
    "repair-pdf"
    "rasterize-pdf"
)

for conv in "${conversions_false[@]}"; do
    perl -i -0pe "s/('${conv}' => \[[^\]]*?'allowed_mime_groups' => \[[^\]]*?\],\s*\n)/\$1        'show_conversion_options' => false,\n/smg" config/landing_pages.php
    echo "✅ ${conv}"
done

echo ""
echo "✨ All conversions fixed!"
