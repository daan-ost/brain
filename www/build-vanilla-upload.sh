#!/bin/bash

# Build Vanilla Upload Assets
# Copies JS and CSS to public directory

echo "Building Vanilla Upload assets..."

# Create directories if they don't exist
mkdir -p public/js/vanilla-upload
mkdir -p public/css

# Copy JavaScript files
echo "Copying JavaScript..."
cp -r resources/js/vanilla-upload/*.js public/js/vanilla-upload/

# Copy CSS
echo "Copying CSS..."
cp resources/css/vanilla-upload.css public/css/

echo "✓ Assets built successfully!"
echo ""
echo "Files created:"
echo "  - public/js/vanilla-upload/UploadManager.js"
echo "  - public/js/vanilla-upload/init.js"
echo "  - public/css/vanilla-upload.css"
echo ""
echo "Usage in Blade:"
echo '  <x-vanilla-upload :page-slug="$pageSlug" :page-config="$pageConfig" />'
