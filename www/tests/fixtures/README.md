# Test Fixtures

This directory contains test files for ConvertAPI integration tests.

## Required Files

To run the integration tests (`php artisan test --group=convertapi`), you need the following files:

| File | Description | How to Create |
|------|-------------|---------------|
| `test.pdf` | A simple PDF with text (for OCR tests) | Export any document to PDF |
| `test.docx` | A simple Word document | Create in Word/LibreOffice |
| `test.xlsx` | A simple Excel spreadsheet | Create in Excel/LibreOffice |
| `test.pptx` | A simple PowerPoint presentation | Create in PowerPoint/LibreOffice |
| `test.jpg` | A simple JPEG image | Any image file |
| `test.epub` | A simple e-book file | Download a free epub |

## Notes

- Files should be small (< 1MB) to minimize API credit usage
- The PDF should contain actual text (not just images) for OCR tests
- These files are NOT committed to git (added to .gitignore)

## Running Tests

```bash
# Normal tests (excludes ConvertAPI integration tests)
php artisan test

# Only ConvertAPI integration tests (costs credits!)
php artisan test --group=convertapi

# Specific test
php artisan test tests/Integration/ConvertApiIntegrationTest.php
```
