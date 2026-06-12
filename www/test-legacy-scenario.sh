#!/bin/bash

echo "=========================================="
echo "Testing Legacy API - PDF Merge Scenario"
echo "=========================================="
echo ""

PROXY_URL="http://localhost:8000/dev/apiv1/proxy"
BASE_URL="https://www.example.com/api"
USERNAME="daan@interus.nl"
PASSWORD="TestPassword7"

# Step 1: Create session
echo "Step 1: Creating session..."
SESSION_RESPONSE=$(curl -s -X POST "$PROXY_URL" \
  -H "Content-Type: application/json" \
  -d "{
    \"base_url\": \"$BASE_URL\",
    \"endpoint\": \"/v1/sessions\",
    \"method\": \"POST\",
    \"username\": \"$USERNAME\",
    \"password\": \"$PASSWORD\",
    \"body\": {}
  }")

echo "$SESSION_RESPONSE" | jq .
SESSION_ID=$(echo "$SESSION_RESPONSE" | jq -r '.body.session_id')
echo "Session ID: $SESSION_ID"
echo ""

if [ "$SESSION_ID" = "null" ] || [ -z "$SESSION_ID" ]; then
    echo "ERROR: Failed to create session"
    exit 1
fi

# Step 2: Upload file 1
echo "Step 2: Uploading file1.pdf..."
FILE1_RESPONSE=$(curl -s -X POST "$PROXY_URL" \
  -F "base_url=$BASE_URL" \
  -F "endpoint=/v1/sessions/$SESSION_ID/files" \
  -F "method=POST" \
  -F "username=$USERNAME" \
  -F "password=$PASSWORD" \
  -F "file=@storage/test-scenarios/pdf-merge/file1.pdf")

echo "$FILE1_RESPONSE" | jq .
FILE1_ID=$(echo "$FILE1_RESPONSE" | jq -r '.body.file_id')
echo "File 1 ID: $FILE1_ID"
echo ""

if [ "$FILE1_ID" = "null" ] || [ -z "$FILE1_ID" ]; then
    echo "ERROR: Failed to upload file 1"
    exit 1
fi

# Step 3: Upload file 2
echo "Step 3: Uploading file2.pdf..."
FILE2_RESPONSE=$(curl -s -X POST "$PROXY_URL" \
  -F "base_url=$BASE_URL" \
  -F "endpoint=/v1/sessions/$SESSION_ID/files" \
  -F "method=POST" \
  -F "username=$USERNAME" \
  -F "password=$PASSWORD" \
  -F "file=@storage/test-scenarios/pdf-merge/file2.pdf")

echo "$FILE2_RESPONSE" | jq .
FILE2_ID=$(echo "$FILE2_RESPONSE" | jq -r '.body.file_id')
echo "File 2 ID: $FILE2_ID"
echo ""

if [ "$FILE2_ID" = "null" ] || [ -z "$FILE2_ID" ]; then
    echo "ERROR: Failed to upload file 2"
    exit 1
fi

# Step 4: Set options (will fail on Legacy, but that's OK)
echo "Step 4: Setting options (expected to fail on Legacy)..."
OPTIONS_RESPONSE=$(curl -s -X POST "$PROXY_URL" \
  -H "Content-Type: application/json" \
  -d "{
    \"base_url\": \"$BASE_URL\",
    \"endpoint\": \"/v1/sessions/$SESSION_ID/options\",
    \"method\": \"PUT\",
    \"username\": \"$USERNAME\",
    \"password\": \"$PASSWORD\",
    \"body\": {
      \"template_id\": 174,
      \"options\": {
        \"pdftype\": \"normal\"
      }
    }
  }")

echo "$OPTIONS_RESPONSE" | jq .
echo ""

# Step 5: Start process
echo "Step 5: Starting process..."
PROCESS_RESPONSE=$(curl -s -X POST "$PROXY_URL" \
  -H "Content-Type: application/json" \
  -d "{
    \"base_url\": \"$BASE_URL\",
    \"endpoint\": \"/v1/sessions/$SESSION_ID/processes\",
    \"method\": \"POST\",
    \"username\": \"$USERNAME\",
    \"password\": \"$PASSWORD\",
    \"body\": {
      \"process_settings\": {
        \"template_id\": 174
      }
    }
  }")

echo "$PROCESS_RESPONSE" | jq .
PROCESS_ID=$(echo "$PROCESS_RESPONSE" | jq -r '.body.process_id // .body.process_result_id')
echo "Process ID: $PROCESS_ID"
echo ""

if [ "$PROCESS_ID" = "null" ] || [ -z "$PROCESS_ID" ]; then
    echo "ERROR: Failed to start process"
    exit 1
fi

# Step 6: Poll for completion
echo "Step 6: Polling for completion..."
MAX_ATTEMPTS=10
for i in $(seq 1 $MAX_ATTEMPTS); do
    echo "Poll attempt $i/$MAX_ATTEMPTS..."

    STATUS_RESPONSE=$(curl -s -X POST "$PROXY_URL" \
      -H "Content-Type: application/json" \
      -d "{
        \"base_url\": \"$BASE_URL\",
        \"endpoint\": \"/v1/sessions/$SESSION_ID/processes/$PROCESS_ID\",
        \"method\": \"GET\",
        \"username\": \"$USERNAME\",
        \"password\": \"$PASSWORD\"
      }")

    echo "$STATUS_RESPONSE" | jq .

    STATUS=$(echo "$STATUS_RESPONSE" | jq -r '.body.status')
    echo "Status: $STATUS"

    if [ "$STATUS" = "done" ] || [ "$STATUS" = "completed" ]; then
        echo ""
        echo "✅ Process completed!"
        echo ""
        echo "Final response:"
        echo "$STATUS_RESPONSE" | jq .

        # Get download URL
        DOWNLOAD_URL=$(echo "$STATUS_RESPONSE" | jq -r '.body.result_url')
        echo ""
        echo "Download URL: $DOWNLOAD_URL"
        exit 0
    fi

    if [ "$STATUS" = "failed" ]; then
        echo ""
        echo "❌ Process failed!"
        exit 1
    fi

    echo "Waiting 3 seconds..."
    sleep 3
    echo ""
done

echo "❌ Timeout after $MAX_ATTEMPTS attempts"
