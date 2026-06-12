#!/bin/bash

# API v1 PDF to Word Test Script
# Test: PDF → Word met OCR + PDF/A

LEGACY_URL="https://www.example.com/api/v1"
STAGING_URL="https://staging.example.com/api/v1"

# Vervang met je credentials
USERNAME="your-email@example.com"
PASSWORD="your-password"

# Test file (moet een PDF zijn)
TEST_FILE="test.pdf"

echo "=== API v1 PDF to Word Test ==="
echo ""

# Functie om requests te maken
make_request() {
    local method=$1
    local url=$2
    local data=$3
    local file=$4
    
    if [ -n "$file" ]; then
        curl -s -u "$USERNAME:$PASSWORD" \
            -X "$method" \
            -H "Content-Type: multipart/form-data" \
            -F "file=@$file" \
            "$url"
    elif [ -n "$data" ]; then
        curl -s -u "$USERNAME:$PASSWORD" \
            -X "$method" \
            -H "Content-Type: application/json" \
            -d "$data" \
            "$url"
    else
        curl -s -u "$USERNAME:$PASSWORD" \
            -X "$method" \
            "$url"
    fi
}

test_environment() {
    local base_url=$1
    local env_name=$2
    
    echo "--- Testing $env_name ---"
    
    # 1. Create session
    echo "1. Creating session..."
    SESSION_RESPONSE=$(make_request POST "$base_url/sessions" '{"expiration_time_seconds": 3600}')
    SESSION_ID=$(echo $SESSION_RESPONSE | jq -r '.session_id // .id')
    echo "Session ID: $SESSION_ID"
    
    if [ "$SESSION_ID" == "null" ] || [ -z "$SESSION_ID" ]; then
        echo "ERROR: Failed to create session"
        echo "Response: $SESSION_RESPONSE"
        return 1
    fi
    
    # 2. Upload PDF
    echo "2. Uploading PDF..."
    UPLOAD_RESPONSE=$(make_request POST "$base_url/sessions/$SESSION_ID/files" "" "$TEST_FILE")
    FILE_ID=$(echo $UPLOAD_RESPONSE | jq -r '.file_id // .id')
    echo "File ID: $FILE_ID"
    echo "Upload response: $UPLOAD_RESPONSE"
    
    # 3. Set options (OCR + PDF/A)
    echo "3. Setting options (OCR + PDF/A)..."
    OPTIONS_RESPONSE=$(make_request PUT "$base_url/sessions/$SESSION_ID/options" '{
        "options": {
            "ocr": true,
            "ocr_language": "Dutch",
            "pdftype": "2B",
            "typeofaction": "batch"
        }
    }')
    echo "Options response: $OPTIONS_RESPONSE"
    
    # 4. Start process (LEGACY API: gebruikt /processes met process_settings)
    echo "4. Starting process..."
    PROCESS_RESPONSE=$(make_request POST "$base_url/sessions/$SESSION_ID/processes" '{
        "process_settings": {
            "process_synchronous": false,
            "immediate": true
        }
    }')
    PROCESS_ID=$(echo $PROCESS_RESPONSE | jq -r '.process_id // .process_result_id // .id')
    VALIDATE_KEY=$(echo $PROCESS_RESPONSE | jq -r '.validate_key')
    echo "Process ID: $PROCESS_ID"
    echo "Validate Key: $VALIDATE_KEY"
    echo "Process response: $PROCESS_RESPONSE"
    
    if [ "$PROCESS_ID" == "null" ] || [ -z "$PROCESS_ID" ]; then
        echo "ERROR: Failed to start process"
        echo "Response: $PROCESS_RESPONSE"
        return 1
    fi
    
    # 5. Check status (poll until done)
    echo "5. Checking status..."
    STATUS="pending"
    ATTEMPTS=0
    MAX_ATTEMPTS=60
    
    while [ "$STATUS" != "done" ] && [ "$STATUS" != "completed" ] && [ $ATTEMPTS -lt $MAX_ATTEMPTS ]; do
        sleep 2
        STATUS_RESPONSE=$(make_request GET "$base_url/process/$PROCESS_ID?validate_key=$VALIDATE_KEY")
        STATUS=$(echo $STATUS_RESPONSE | jq -r '.status')
        echo "Status: $STATUS (attempt $ATTEMPTS)"
        ATTEMPTS=$((ATTEMPTS + 1))
    done
    
    if [ "$STATUS" != "done" ] && [ "$STATUS" != "completed" ]; then
        echo "WARNING: Process did not complete (status: $STATUS)"
    fi
    
    # 6. Download result
    echo "6. Downloading result..."
    make_request GET "$base_url/process/$PROCESS_ID/download?validate_key=$VALIDATE_KEY" > "result_${env_name}.docx"
    
    if [ -f "result_${env_name}.docx" ]; then
        FILE_SIZE=$(stat -f%z "result_${env_name}.docx" 2>/dev/null || stat -c%s "result_${env_name}.docx" 2>/dev/null)
        echo "Downloaded: result_${env_name}.docx (${FILE_SIZE} bytes)"
    else
        echo "ERROR: Download failed"
    fi
    
    echo ""
}

# Run tests
if [ ! -f "$TEST_FILE" ]; then
    echo "ERROR: Test file not found: $TEST_FILE"
    echo "Please set TEST_FILE to a valid PDF file path"
    exit 1
fi

if [ "$USERNAME" == "your-email@example.com" ] || [ "$PASSWORD" == "your-password" ]; then
    echo "ERROR: Please set USERNAME and PASSWORD in the script"
    exit 1
fi

# Test beide omgevingen
test_environment "$LEGACY_URL" "legacy"
test_environment "$STAGING_URL" "staging"

echo "=== Test Complete ==="
echo "Results saved as: result_legacy.docx and result_staging.docx"
