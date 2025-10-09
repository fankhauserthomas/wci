#!/bin/bash

echo "=== HRS PAYLOAD DEBUG TEST ==="
echo ""

# Test mit curl
curl -X POST http://localhost/wci/hrs/hrs_create_quota_batch.php \
  -H "Content-Type: application/json" \
  -d '{
    "quotas": [
      {
        "title": "Test 2025-02-12",
        "date_from": "2025-02-12",
        "date_to": "2025-02-13",
        "capacity": 88,
        "categories": {
          "lager": 77,
          "betten": 11,
          "dz": 0,
          "sonder": 0
        }
      }
    ]
  }' 2>&1

echo ""
echo ""
echo "=== Prüfe Error Log ==="
tail -50 /var/log/nginx/error.log | grep "HRS"
