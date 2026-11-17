#!/usr/bin/env python3
"""Build products_enriched.json for the WOT product-consultant chatbot.

Input:  catalog/raw/*.tsv  (read-only drush sqlq dumps from worldoftest.com)
Output: ../products_enriched.json  + catalog/stats.json (for README)

No network, no writes to the source site. Deterministic: same dumps -> same JSON.
"""
import base64
import html as htmlmod
import json
import os
import re
import sys
from collections import Counter, OrderedDict
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
RAW = os.path.join(os.path.dirname(HERE), "raw")
OUT = os.path.abspath(os.path.join(HERE, "..", "..", "products_enriched.json"))
STATS = os.path.abspath(os.path.join(HERE, "..", "stats.json"))

BASE = "https://www.worldoftest.com"
MAX_STANDARDS = 20
SPEC_LIMIT = 300
APP_LIMIT = 320


def b64(s):
    if s is None or s == "\\N" or s == "NULL":
        return ""
    try:
        return base64.b64decode(s + "=" * (-len(s) % 4)).decode("utf-8", "replace")
    except Exception:
        return ""


def read_tsv(name):
    path = os.path.join(RAW, name + ".tsv")
    rows = []
    if not os.path.exists(path):
        return rows
    with open(path, encoding="utf-8", errors="replace") as fh:
        for line in fh:
            line = line.rstrip("\n")
            if not line.strip():
                continue
            rows.append(line.split("\t"))
    return rows


TAGBLOCK = re.compile(r"<br\s*/?>|</p>|</div>|</tr>|</h[1-6]>|</li>", re.I)
TAGS = re.compile(r"<[^>]+>")
SPACES = re.compile(r"[ \t\r\f\v\u00a0\u200b]+")


def html_to_text(value):
    if not value:
        return ""
    t = TAGBLOCK.sub("\n", value)
    t = TAGS.sub(" ", t)
    t = htmlmod.unescape(t)
    t = t.replace("\u00a0", " ")
    lines = [SPACES.sub(" ", ln).strip() for ln in t.split("\n")]
    lines = [ln for ln in lines if ln]
    return "\n".join(lines)


SENT_SPLIT = re.compile(r"(?<=[.!?])\s+")


def first_sentence(text, name):
    """One sentence of applications prose, skipping the repeated H2 title line."""
    if not text:
        return None
    lines = text.split("\n")
    if lines and len(lines[0]) < 140 and not lines[0].endswith("."):
        head = lines[0].lower()
        if head in name.lower() or head.startswith(name.lower()[:40]):
            lines = lines[1:]
        elif head.endswith("specifications"):
            lines = lines[1:]
    body = " ".join(lines).strip()
    if not body:
        return None
    for chunk in SENT_SPLIT.split(body):
        c = chunk.strip()
        if len(c) < 30:
            continue
        if not c.endswith((".", "!", "?")):
            c = c.rstrip(" ;:-") + "."
        if len(c) > APP_LIMIT:
            c = c[:APP_LIMIT].rsplit(" ", 1)[0].rstrip(" ,;:-") + "."
        return c
    return None


def spec_summary(text, name):
    if not text:
        return None
    lines = text.split("\n")
    while lines:
        head = lines[0].strip()
        low = head.lower()
        if not head:
            lines = lines[1:]
            continue
        if low.endswith("specifications") or low.endswith("specifications:"):
            lines = lines[1:]
            continue
        if low in name.lower() or low.startswith(name.lower()[:40]):
            lines = lines[1:]
            continue
        if head.endswith((".", ":", "?")):
            break
        break
    body = " ".join(lines).strip()
    if not body:
        return None
    if len(body) <= SPEC_LIMIT:
        return body
    cut = body[:SPEC_LIMIT]
    cut = cut.rsplit(" ", 1)[0]
    return cut.rstrip(" ,;:-(") + "\u2026"


STD_PATTERNS = [
    # families required by the catalog contract: ASTM, ISO, EN, BS, DIN, JIS
    re.compile(r"\bASTM\s+[A-Z]{0,2}\s?\d+(?:-\d+)?\b", re.I),
    re.compile(r"\bISO\s+(?:[A-Z]{1,4}\s?)?\d+(?:-\d+)?\b", re.I),
    re.compile(r"\b(?:BS\s+)?EN\s+\d+(?:-\d+)?\b", re.I),
    re.compile(r"\bBS\s+\d+(?:-\d+)?\b", re.I),
    re.compile(r"\bDIN\s+(?:EN\s+)?\d+(?:-\d+)?\b", re.I),
    re.compile(r"\bJIS\s+[A-Z]{0,2}\s?\d+(?:-\d+)?\b", re.I),
]
# gate applied to curated taxonomy names as well, so nothing outside the
# six families leaks in from editor-entered standards
STD_FAMILY = re.compile(r"^(?:ASTM|ISO|EN|BS(?:\s+EN)?|DIN|JIS)\b", re.I)
STD_BARE = re.compile(r"^(?:ASTM|ISO|EN|BS|DIN|JIS)$", re.I)


def std_key(s):
    return re.sub(r"[^A-Z0-9]", "", s.upper())


def is_std(s):
    t = SPACES.sub(" ", s).strip()
    if not t or STD_BARE.match(t):
        return False
    return bool(STD_FAMILY.match(t)) and bool(re.search(r"\d", t))


def collect_standards(names, texts):
    out = OrderedDict()

    def push(raw):
        s = SPACES.sub(" ", raw).strip()
        # drop editorial suffixes: "ASTM C666-2008 Procedure A", "(6.13)", "（...)"
        s = re.split(r"\s+(?i:clause|procedure|method|section|appendix|annex|part)\b", s)[0]
        s = re.split(r"[(\(\uff08]", s)[0]
        s = s.replace("\u2013", "-").replace("\u2014", "-").replace("\u2212", "-")
        s = re.sub(r":\s+", ":", s)
        s = s.strip().strip(",.;:-")
        s = re.sub(r"\s+", " ", s)
        if not is_std(s):
            return
        out.setdefault(std_key(s), s)

    for n in names:
        push(n)
    for t in texts:
        if not t:
            continue
        for pat in STD_PATTERNS:
            for m in pat.finditer(t):
                push(m.group(0))
    vals = list(out.values())
    # a letterless "ASTM 2863" is the same standard as "ASTM D2863": keep the precise one
    def num(part):
        m = re.search(r"\d+", part)
        return m.group(0) if m else None
    precise = {}
    for v in vals:
        fam = v.split()[0].upper()
        m = re.match(r"^(?:BS\s+)?(ASTM|ISO|EN|DIN|JIS)\s+([A-Z]{1,2})\d", v, re.I)
        if m:
            n = num(v)
            if n:
                precise.setdefault((fam, n), v)
    cleaned = []
    for v in vals:
        m = re.match(r"^(?:BS\s+)?(ASTM|ISO|EN|DIN|JIS)\s+(\d)", v, re.I)
        if m:
            fam = m.group(1).upper()
            n = num(v)
            if n and precise.get((fam, n)):
                continue
        cleaned.append(v)
    return cleaned[:MAX_STANDARDS]


def norm_url(alias):
    a = (alias or "").strip()
    if not a:
        return None
    if a.startswith("http://"):
        a = a[7:]
    if a.startswith("https://"):
        a = a[8:]
    if a.startswith("www.worldoftest.com"):
        a = a[len("www.worldoftest.com"):]
    a = "/" + a.lstrip("/")
    if not a.endswith("/"):
        a += "/"
    return BASE + a


def norm_image(uri):
    if not uri:
        return None
    u = uri.strip()
    if u.startswith("public://"):
        path = u[len("public://"):]
    elif u.startswith("https://") or u.startswith("http://"):
        return u
    else:
        path = u.lstrip("/")
    path = " ".join(path.split())
    path = path.replace(" ", "%20")
    return BASE + "/sites/default/files/" + path.lstrip("/")


def main():
    base_rows = read_tsv("base")
    if not base_rows:
        sys.exit("no base.tsv — run the drush export first")

    products = OrderedDict()
    for r in base_rows:
        if len(r) < 3:
            continue
        nid = int(r[0])
        name = b64(r[1]).strip()
        products[nid] = {
            "nid": nid,
            "name": name,
            "url": norm_url(r[2]),
        }

    def fill(dataset, split_b64=True):
        out = {}
        for r in read_tsv(dataset):
            if len(r) < 2:
                continue
            try:
                nid = int(r[0])
            except ValueError:
                continue
            out.setdefault(nid, []).append(r)
        return out

    term_rows = fill("terms")
    parent_rows = fill("parent")
    std_rows = fill("standards")
    img_rows = fill("images")
    spec_rows = fill("spec")
    apps_rows = fill("apps")
    theory_rows = fill("theory")
    feat_rows = fill("features")
    tt_rows = fill("tt")

    # direct category: lowest delta wins
    direct = {}
    for nid, rows in term_rows.items():
        rows = sorted(rows, key=lambda r: int(r[3]) if len(r) > 3 and r[3].isdigit() else 0)
        tid = rows[0][1]
        name = b64(rows[0][2]).strip()
        via = "field_test_application"
        direct[nid] = (int(tid), name, via)

    # parent map: lowest delta parent wins
    parents = {}
    for nid, rows in parent_rows.items():
        rows = sorted(rows, key=lambda r: int(r[2]) if len(r) > 2 and r[2].isdigit() else 0)
        p = int(rows[0][1])
        if p and p != nid:
            parents[nid] = p

    def resolve_category(nid):
        if nid in direct:
            return direct[nid][1], "direct"
        seen = set()
        cur = nid
        for _ in range(8):
            cur = parents.get(cur)
            if not cur or cur in seen:
                return None, "none"
            seen.add(cur)
            if cur in direct:
                return direct[cur][1], "inherited"
        return None, "none"

    def joined_text(rows, col=1):
        if not rows:
            return ""
        return "\n".join(b64(r[col]) for r in rows if len(r) > col)

    cat_counter = Counter()
    cat_source = Counter()
    have_std = have_app = have_spec = have_img = have_cat = 0
    std_counter = Counter()
    missing_notes = []

    out_products = []
    for nid, p in products.items():
        name = p["name"]
        cat, cat_src = resolve_category(nid)
        cat_source[cat_src] += 1
        if cat:
            have_cat += 1
            cat_counter[cat] += 1

        std_names = []
        for r in std_rows.get(nid, []):
            if len(r) > 1:
                std_names.append(b64(r[1]))
        spec_txt = html_to_text(joined_text(spec_rows.get(nid, [])))
        apps_txt = html_to_text(joined_text(apps_rows.get(nid, [])))
        theory_txt = html_to_text(joined_text(theory_rows.get(nid, [])))
        feat_txt = html_to_text(joined_text(feat_rows.get(nid, [])))
        tt_txt = " ".join(b64(r[1]) for r in tt_rows.get(nid, []) if len(r) > 1)

        standards = collect_standards(std_names, [spec_txt, apps_txt, theory_txt, feat_txt, tt_txt])
        for s in standards:
            std_counter[s] += 1

        application = first_sentence(apps_txt, name)
        summary = spec_summary(spec_txt, name)

        image = None
        for r in sorted(img_rows.get(nid, []), key=lambda r: int(r[1]) if len(r) > 1 and r[1].isdigit() else 0):
            image = norm_image(b64(r[2]))
            if image:
                break

        if standards:
            have_std += 1
        if application:
            have_app += 1
        if summary:
            have_spec += 1
        if image:
            have_img += 1

        out_products.append({
            "nid": nid,
            "name": name,
            "url": p["url"],
            "category": cat,
            "category_path": ["Products", cat] if cat else [],
            "standards": standards,
            "application": application,
            "spec_summary": summary,
            "image": image,
        })

    out_products.sort(key=lambda x: x["nid"])
    doc = {
        "generated": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "source": "db",
        "count": len(out_products),
        "products": out_products,
    }

    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump(doc, fh, ensure_ascii=False, indent=1)
        fh.write("\n")

    total = len(out_products)
    pct = lambda n: round(100.0 * n / total, 1) if total else 0.0
    stats = {
        "generated": doc["generated"],
        "count": total,
        "coverage": {
            "standards": have_std, "standards_pct": pct(have_std),
            "application": have_app, "application_pct": pct(have_app),
            "spec_summary": have_spec, "spec_summary_pct": pct(have_spec),
            "image": have_img, "image_pct": pct(have_img),
            "category": have_cat, "category_pct": pct(have_cat),
        },
        "category_source": dict(cat_source),
        "per_category": cat_counter.most_common(),
        "top_standards": std_counter.most_common(30),
        "unique_standards": len(std_counter),
        "products_without_category": total - have_cat,
    }
    with open(STATS, "w", encoding="utf-8") as fh:
        json.dump(stats, fh, ensure_ascii=False, indent=1)
    print(json.dumps({k: v for k, v in stats.items() if k not in ("per_category", "top_standards")}, indent=1))


if __name__ == "__main__":
    main()
