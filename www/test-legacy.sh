#!/bin/bash

echo "Testing Legacy API (www.example.com)"
echo "=================================="

# Step 1: Login
echo "Step 1: Login..."
SESSION_RESPONSE=$(curl -s -X POST https://www.example.com/api/v1/sessions \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"daan@interus.nl\",\"password\":\"TestPassword7\"}")

echo "$SESSION_RESPONSE" | jq .

SESSION_ID=$(echo "$SESSION_RESPONSE" | jq -r '.session_id')
echo "Session ID: $SESSION_ID"
