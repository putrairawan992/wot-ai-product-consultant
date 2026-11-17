#!/usr/bin/env bash
# Read-only export of the WOT product graph from worldoftest.com (Drupal 11)
# over SSH + drush sqlq. Run from anywhere; writes catalog/raw/*.tsv.
#
#   ./catalog/scripts/export_drush_sql.sh
#
# Strictly SELECT. No writes, no cache clears, no config changes on the host.
set -euo pipefail

SSH_KEY="${WOT_SSH_KEY:-$HOME/.ssh/wot_new}"
SSH_HOST="${WOT_SSH_HOST:-d8157@190.92.168.251}"
RAW="$(cd "$(dirname "${BASH_SOURCE[0]}")/../raw" && pwd)"
TMP="$(mktemp)"
trap 'rm -f "$TMP" "$TMP.err"' EXIT

cat > "$TMP" <<'REMOTE'
set -u
cd ~/public_html || exit 1
D="./vendor/bin/drush sqlq"
# base64 without line wrapping (TO_BASE64 inserts \n every 76 chars)
q() { printf '@@@%s\n' "$1"; shift; $D "$1" 2>/dev/null; printf '@@@END\n'; }

q base      "SELECT CONCAT_WS(CHAR(9), n.nid, REPLACE(TO_BASE64(n.title),CHAR(10),''), a.alias) FROM node_field_data n JOIN path_alias a ON a.path=CONCAT('/node/',n.nid) AND a.status=1 WHERE n.type='product' AND n.status=1 ORDER BY n.nid"
q terms     "SELECT CONCAT_WS(CHAR(9), f.entity_id, f.field_test_application_target_id, REPLACE(TO_BASE64(t.name),CHAR(10),''), f.delta) FROM node__field_test_application f JOIN taxonomy_term_field_data t ON t.tid=f.field_test_application_target_id WHERE f.deleted=0 ORDER BY f.entity_id, f.delta"
q parent    "SELECT CONCAT_WS(CHAR(9), entity_id, field_parent_product_target_id, delta) FROM node__field_parent_product WHERE deleted=0 ORDER BY entity_id, delta"
q standards "SELECT CONCAT_WS(CHAR(9), f.entity_id, REPLACE(TO_BASE64(t.name),CHAR(10),'')) FROM node__field_standards f JOIN taxonomy_term_field_data t ON t.tid=f.field_standards_target_id WHERE f.deleted=0 AND t.vid='standards' ORDER BY f.entity_id"
q images    "SELECT CONCAT_WS(CHAR(9), f.entity_id, f.delta, REPLACE(TO_BASE64(m.uri),CHAR(10),'')) FROM node__field_product_images f JOIN file_managed m ON m.fid=f.field_product_images_target_id WHERE f.deleted=0 ORDER BY f.entity_id, f.delta"
q spec      "SELECT CONCAT_WS(CHAR(9), entity_id, REPLACE(REPLACE(TO_BASE64(field_technical_specifications_value),CHAR(10),''),CHAR(10),'')) FROM node__field_technical_specifications WHERE deleted=0 ORDER BY entity_id"
q apps      "SELECT CONCAT_WS(CHAR(9), entity_id, REPLACE(REPLACE(TO_BASE64(field_applications_value),CHAR(10),''),CHAR(10),'')) FROM node__field_applications WHERE deleted=0 ORDER BY entity_id"
q theory    "SELECT CONCAT_WS(CHAR(9), entity_id, REPLACE(REPLACE(TO_BASE64(field_theory_method_value),CHAR(10),''),CHAR(10),'')) FROM node__field_theory_method WHERE deleted=0 ORDER BY entity_id"
# field_features is big enough that one query drops the connection: split it.
q features_0 "SELECT CONCAT_WS(CHAR(9), entity_id, REPLACE(REPLACE(TO_BASE64(field_features_value),CHAR(10),''),CHAR(10),'')) FROM node__field_features WHERE deleted=0 AND MOD(entity_id,2)=0 ORDER BY entity_id"
q features_1 "SELECT CONCAT_WS(CHAR(9), entity_id, REPLACE(REPLACE(TO_BASE64(field_features_value),CHAR(10),''),CHAR(10),'')) FROM node__field_features WHERE deleted=0 AND MOD(entity_id,2)=1 ORDER BY entity_id"
q tt        "SELECT CONCAT_WS(CHAR(9), f.entity_id, REPLACE(TO_BASE64(t.name),CHAR(10),'')) FROM node__field_test_property f JOIN taxonomy_term_field_data t ON t.tid=f.field_test_property_target_id WHERE f.deleted=0 ORDER BY f.entity_id, f.delta"
printf '@@@DONE\n'
REMOTE

ssh -i "$SSH_KEY" -o ConnectTimeout=20 "$SSH_HOST" 'bash -s' < "$TMP" > "$TMP.out"

python3 - "$TMP.out" "$RAW" <<'PY'
import sys, os
src, raw = sys.argv[1], sys.argv[2]

def unesc(s):
    # mysql --batch mode escapes \t, \n, \\ and friends inside values
    out, i, n = [], 0, len(s)
    table = {'n': '\n', 't': '\t', 'r': '\r', '0': '\0', '\\': '\\', 'Z': '\x1a'}
    while i < n:
        c = s[i]
        if c == '\\' and i + 1 < n:
            nx = s[i + 1]
            out.append(table.get(nx, nx))
            i += 2
        else:
            out.append(c)
            i += 1
    return ''.join(out)

out, cur = {}, None
for line in open(src, encoding='utf-8', errors='replace'):
    line = line.rstrip('\n')
    if line.startswith('@@@'):
        tag = line[3:]
        if tag in ('END', 'DONE'):
            cur = None
        else:
            cur = tag.rsplit('_', 1)[0] if tag.startswith('features_') else tag
            out.setdefault(cur, [])
        continue
    if cur and line.strip():
        out[cur].append('\t'.join(unesc(p) for p in line.split('\\t')))
for k, rows in out.items():
    with open(os.path.join(raw, k + '.tsv'), 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(rows) + '\n')
    print(f'{k:10s} {len(rows):6d} rows')
PY
rm -f "$TMP.out"
echo "done -> $RAW"
