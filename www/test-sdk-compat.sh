#!/bin/bash

SESSION_ID="019ab64c-69cc-70e2-a742-0989cc961484"
BASE_URL="http://localhost:8000/api/v1"

echo "=========================================="
echo "SDK COMPATIBILITY TESTS"
echo "=========================================="
echo ""

echo "✅ Test 1: Login Flow - ALREADY PASSED"
echo ""

echo "=========================================="
echo "Test 2: Session Load (4 parallel requests)"
echo "=========================================="

echo ""
echo "Request 1: GET /sessions/$SESSION_ID/ordering"
curl -s "$BASE_URL/sessions/$SESSION_ID/ordering" | jq -C .
echo ""

echo "Request 2: GET /sessions/$SESSION_ID/files"
curl -s "$BASE_URL/sessions/$SESSION_ID/files" | jq -C .
echo ""

echo "Request 3: GET /sessions/$SESSION_ID/options"
curl -s "$BASE_URL/sessions/$SESSION_ID/options" | jq -C .
echo ""

echo "Request 4: GET /sessions/$SESSION_ID"
curl -s "$BASE_URL/sessions/$SESSION_ID" | jq -C .
echo ""

echo "=========================================="
echo "Test 3: Configuration Endpoint"
echo "=========================================="
echo ""
curl -s "$BASE_URL/configuration" | jq -C .
echo ""

echo "=========================================="
echo "Test 4: Templates"
echo "=========================================="
echo ""
curl -s "$BASE_URL/sessions/$SESSION_ID/templates" | jq -C .
echo ""

echo "=========================================="
echo "Test 5: Options PATCH"
echo "=========================================="
echo ""
curl -s -X PATCH "$BASE_URL/sessions/$SESSION_ID/options" \
  -H "Content-Type: application/json" \
  -d '{"pdftype":"2B","ocr":true}' | jq -C .
echo ""

echo "=========================================="
echo "Test 6: Current Process Endpoint"
echo "=========================================="
echo ""
curl -s "$BASE_URL/sessions/$SESSION_ID/current-process" | jq -C .
echo ""
