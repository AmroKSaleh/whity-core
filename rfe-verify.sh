#!/bin/sh
# Temporary local verification for #1027 — deleted before commit.
set -e
BASE=http://127.0.0.1
J='Content-Type: application/json'
X='X-Requested-With: XMLHttpRequest'
C=/tmp/c.txt

curl -s -c $C -X POST "$BASE/api/v1/login" -H "$J" -H "$X" \
  -d '{"email":"admin@example.com","password":"e1d34324feaac86edcb7c12b63eab427"}' > /dev/null

echo "=== role node: draft preview (POST /user-groups/preview) ==="
curl -s -b $C -X POST "$BASE/api/v1/user-groups/preview" -H "$J" -H "$X" \
  -d '{"rule_kind":"role","rule_config":{"role_id":6}}' | head -c 300
echo ""

echo ""
echo "=== group node: draft preview REFUSES (group-of-groups) ==="
curl -s -b $C -X POST "$BASE/api/v1/user-groups/preview" -H "$J" -H "$X" \
  -d '{"rule_kind":"group","rule_config":{"group_id":1}}' | head -c 300
echo ""

echo ""
echo "=== group node: the RIGHT call — GET /user-groups/{id}/preview ==="
curl -s -b $C "$BASE/api/v1/user-groups/1/preview" | head -c 400
echo ""

echo ""
echo "=== explicit node: draft preview ==="
curl -s -b $C -X POST "$BASE/api/v1/user-groups/preview" -H "$J" -H "$X" \
  -d '{"rule_kind":"explicit","rule_config":{"profile_ids":[3]}}' | head -c 300
echo ""
