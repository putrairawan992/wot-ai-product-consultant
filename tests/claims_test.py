#!/usr/bin/env python3
"""Invented-claim regression suite (client QA cases, 2 Nov 2026).

Each case is asked as the FIRST message of a fresh conversation, three times, against
whatever --base points at. Raw replies land in tests/out/claims/<case>-run<n>.json and
the verdict is mechanical: a per-case regex ban-list, a per-case required-route list,
the global internal-phrasing list, and (for the two attribution cases) a sentence-level
check that a catalogue claim is never pinned on a product that does not carry it.

    python3 tests/claims_test.py                                  # live production
    python3 tests/claims_test.py --base http://127.0.0.1:8088      # local server
    python3 tests/claims_test.py --only E04 --runs 1 --pace 6      # single case

Exit code is 1 when any run fails, so it can gate a deploy.
"""

import argparse
import glob
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'tests', 'out', 'claims')

# Cloudflare on the production host rejects the stock urllib signature (error 1010), so
# every request identifies itself. Keep it descriptive: this is a test client, not a browser.
USER_AGENT = 'WOTConsultant-Tests/1.0 (+https://www.worldoftest.com/)'

# Internal search phrasing the client flagged. Never allowed in any answer.
GLOBAL_FORBID = [
    r'matched catalog',
    r'matched inventory',
    r'our catalog contains',
    r'my shortlist was generated',
]

# Storage/capture promises about contact details.
CONTACT_PROMISES = [
    r'\b(?:i|we)(?:\'ve| have| will| \'ll| ve)\s+(?:saved|stored|noted|recorded|logged|kept|passed on)\b[^.\n]{0,40}\b(?:email|contact|details|address|number)',
    r'\byour\s+(?:email|contact details|details|information)\s+(?:has|have)\s+been\s+(?:saved|stored|recorded|logged|noted|forwarded|passed)',
    r'\b(?:forwarded|passed|sent)\s+(?:your\s+)?(?:details|email|request)\s+to\s+(?:the|our)\s+sales',
    r'\badded\s+(?:you\s+)?to\s+(?:our|the)\s+(?:crm|sales|call)',
    r'\bwe\s+will\s+call\s+you\b',
]

COMMERCIAL_TERMS = [
    r'proforma',
    r'\bpro\s*forma\b',
    r'\bpurchase orders?\b',
    r'(?<![A-Za-z0-9])P\.?\s?O\.?(?![A-Za-z0-9])',
    r'\bcard payment\b|\bcredit card\b|\bpayment card\b|\bpay by card\b',
    r'\bpayment terms\b|\bcredit terms\b|\bnet\s?30\b|\bnet\s?60\b',
    r'\blead[\s-]?times?\b',
    r'\bdiscounts?\b|\bvolume pricing\b|\bnegotiat\w* price\b',
    r'\bin stock\b|\bstock level\b|\bstock availability\b|\bavailable stock\b|\bout of stock\b|\bavailability\b',
    r'\bdelivery time\b|\bdelivery (?:in|within)\b|\bdelivery estimate\b|\bships? (?:in|within)\b|\bshipping time\b',
    r'\binvoices?\b|\binvoicing\b',
]

TABLE_SEPARATOR = r'^[ \t]*\|?[ \t]*:?-{3,}:?[ \t]*(\|[ \t]*:?-{3,}:?[ \t]*)+\|?[ \t]*$'
TABLE_ROW = r'^[ \t]*\|.*\|[ \t]*$'

# A sentence may only mention a claim keyword next to a product when it hedges it.
HEDGES = r"(?:not\s+(?:listed|declared|stated|specified|shown|available|documented)|no\s+listed\s+(?:model|product|unit|tester)|\bno\b[^.\n]{0,25}\b(?:declares?|lists?|states?|carries?)\b|no\s+(?:exact|specific|product|equipment|model)|does\s*n[o']t\s+(?:list|declare|state|specify|show)|do\s*n[o']t\s+(?:list|have|carry)|is\s*n[o']t\s+(?:designed|rated|listed|declared)|are\s*n[o']t\s+(?:designed|listed|declared)|without\s+(?:a\s+)?(?:declared|stated)|cannot\s+(?:confirm|verify)|can\s*n[o']t\s+(?:confirm|verify)|need\s+to\s+confirm|would\s+need\s+to\s+confirm|unable\s+to\s+(?:confirm|verify)|not\s+among\s+(?:its|their|the)\s+(?:listed|declared|stated)|none\s+of\s+(?:the|these)\b[^.\n]{0,80}\b(?:state|states|list|lists|declare|declares|carry|carries|specify|specifies)\b|need(?:s|ed)?\s+(?:to\s+be\s+)?confirm(?:ed|ation))"


def load_products():
    path = os.path.join(ROOT, 'products_enriched.json')
    rows = []
    if os.path.isfile(path):
        with open(path, encoding='utf-8') as fh:
            rows = json.load(fh).get('products', [])
    return rows


PRODUCTS = load_products()
BY_URL = {(p.get('url') or '').rstrip('/'): p for p in PRODUCTS}


def product_name_for(sentence):
    """Return the catalogue rows whose name appears in this sentence."""
    hits = []
    low = sentence.lower()
    for p in PRODUCTS:
        name = (p.get('name') or '').strip()
        if len(name) < 6:
            continue
        if name.lower() in low:
            hits.append(p)
    return hits


def link_urls(reply):
    urls = re.findall(r'\]\((https?://[^)\s]+)\)', reply or '')
    urls += re.findall(r'(?<!\()(https?://[^\s\)\]]+)', reply or '')
    out = []
    for u in urls:
        u = u.rstrip('.,;')
        if u.startswith('https://www.worldoftest.com/') and u not in out:
            out.append(u)
    return out


def sentences(reply):
    """Paragraphs first, then punctuation: a bullet list must not merge into the sentence
    that introduced it, or one honest hedge would be read as covering every bullet."""
    out = []
    for block in re.split(r'[\r\n]+', reply or ''):
        for part in re.split(r'(?<=[.!?])\s+', block):
            if part.strip():
                out.append(part.strip())
    return out


def attribution_violation(reply, claim_regex, field_regex):
    """A sentence naming a product and a claim keyword fails unless either the product's
    own catalogue row carries the keyword or the sentence hedges the claim."""
    bad = []
    for sentence in sentences(reply):
        if not re.search(claim_regex, sentence, re.I):
            continue
        if re.search(HEDGES, sentence, re.I):
            continue
        named = product_name_for(sentence)
        urls = [u for u in link_urls(sentence)]
        for url in urls:
            row = BY_URL.get(url.rstrip('/').replace('https://www.worldoftest.com', 'https://www.worldoftest.com'))
            if row is not None:
                named.append(row)
        for link in re.findall(r'\]\((https?://[^)\s]+)\)', sentence):
            key = link.rstrip('/')
            key = key.replace('http://www.worldoftest.com', 'https://www.worldoftest.com')
            row = BY_URL.get(key)
            if row is not None:
                named.append(row)
        seen = set()
        for row in named:
            if row['nid'] in seen:
                continue
            seen.add(row['nid'])
            blob = ' '.join([
                row.get('name') or '',
                row.get('application') or '',
                row.get('spec_summary') or '',
                ' '.join(row.get('standards') or []),
                row.get('category') or '',
            ])
            if re.search(field_regex, blob, re.I):
                return bad  # catalogue backs this one
        if named:
            bad.append(sentence)
    return bad


def forbid(reply, patterns, label):
    hits = []
    for pat in patterns:
        for m in re.finditer(pat, reply or '', re.I | re.M):
            line = (reply or '')[m.start():m.end() + 60].replace('\n', ' ')
            hits.append({'rule': label, 'pattern': pat, 'match': m.group(0), 'context': line[:160]})
    return hits


def has(reply, patterns):
    return any(re.search(p, reply or '', re.I | re.M) for p in patterns)


# Fields the enriched rows carry, with the evidence a row must contain to count as stating
# one. Used to catch a reply that tells the visitor a field is missing when the catalogue
# row states it (the mirror image of an invented spec).
FIELD_EVIDENCE = {
    'accuracy': r'\baccuracy\b',
    'scales': r'\bscales?\b|\bHR[ABC]?\b|\bHB\b|\bHV\b|\bHL\b|\bHS\b',
    'display': r'\bdisplay\b|\bOLED\b|\bLCD\b',
    'storage': r'\bstorage\b|\bmemory\b',
    'impact device': r'\bimpact (?:device|devices|body|bodies)\b',
    'standard': r'\bASTM\b|\bISO\b|\bDIN\b',
}
DENIAL = (r"\b(?:not|never)\s+(?:stated|listed|specified|shown|documented|quoted)\b"
          r"|\b(?:does|do)\s*n[o']t\s+(?:state|list|specify|show)\b")


def candidate_urls_in(text):
    hits = []
    for url in re.findall(r'https://www\.worldoftest\.com/[^\s\)\]<>"]+', text or ''):
        key = url.rstrip('/.,;')
        row = BY_URL.get(key)
        if row is not None:
            hits.append(row)
    return hits


def false_negatives(reply):
    """Sentences that deny a field a named candidate actually carries."""
    errs = []
    for paragraph in (reply or '').split('\n'):
        current = None
        for sentence in sentences(paragraph):
            named = candidate_urls_in(sentence)
            if len(named) == 1:
                current = named[0]
            elif len(named) > 1:
                current = None
            if current is None or not re.search(DENIAL, sentence, re.I):
                continue
            blob = ' '.join([current.get('application') or '', current.get('spec_summary') or '',
                             ' '.join(current.get('standards') or [])])
            for field, evidence in FIELD_EVIDENCE.items():
                if re.search(evidence, sentence, re.I) and re.search(evidence, blob, re.I):
                    errs.append({'rule': 'false-negative-claim', 'pattern': field, 'match': 'denied',
                                 'context': sentence[:200]})
                    break
    return errs


def case_e04(reply):
    errs = forbid(reply, COMMERCIAL_TERMS, 'commercial-terms')
    if not has(reply, [r'\bdurometer', r'\bshore\s*a\b']):
        errs.append({'rule': 'route', 'pattern': 'durometer|shore a', 'match': '', 'context': 'reply never names a durometer or Shore A'})
    return errs


def case_e05(reply):
    """No comparison table. Storage/lifecycle/memory may only be mentioned hedged; the only
    accuracy figure the catalogue states for these two models is +/- 0.5%, so any other
    number is invented. A datasheet or sales route must be offered."""
    errs = []
    if re.search(TABLE_SEPARATOR, reply or '', re.M):
        errs.append({'rule': 'comparison-table', 'pattern': 'separator row', 'match': 'markdown table', 'context': 'reply built a comparison table'})
    rows = [ln for ln in (reply or '').splitlines() if re.search(TABLE_ROW, ln)]
    if len(rows) >= 2:
        errs.append({'rule': 'comparison-table', 'pattern': 'pipe rows', 'match': f'{len(rows)} table rows', 'context': rows[0][:120]})

    for sentence in sentences(reply):
        if re.search(r'\b(?:storage|memory|lifespan|life cycle|lifecycle|calibration (?:interval|cycle|period)|battery life)\b', sentence, re.I) \
                and not re.search(HEDGES, sentence, re.I):
            errs.append({'rule': 'unverified-attribute', 'pattern': 'storage/lifecycle', 'match': 'unhedged', 'context': sentence[:200]})

    for m in re.finditer(r'\d+(?:\.\d+)?\s*%', reply or ''):
        if not re.match(r'0\.5\s*%', m.group(0)):
            errs.append({'rule': 'invented-accuracy', 'pattern': m.group(0), 'match': m.group(0),
                         'context': (reply or '')[max(0, m.start() - 70):m.end() + 40].replace('\n', ' ')})

    for m in re.finditer(r'\b\d[\d,]*\s*(?:readings|records|measurements|data points|values|groups)\b', reply or '', re.I):
        errs.append({'rule': 'invented-storage-number', 'pattern': m.group(0), 'match': m.group(0),
                     'context': (reply or '')[max(0, m.start() - 70):m.end() + 40].replace('\n', ' ')})

    errs += false_negatives(reply)

    if not has(reply, [r'\bdatasheet', r'\bdata sheet', r'worldoftest\.com/contact', r'\bsales team\b', r'\bquotation\b']):
        errs.append({'rule': 'route', 'pattern': 'datasheet|sales route', 'match': '', 'context': 'no datasheet or sales route offered'})
    return errs


def case_f02(reply):
    errs = []
    bad = attribution_violation(reply, r'\bnail[s]?\b|\bneedle[s]?\b|penetrat\w*|punctur\w*', r'\bnail[s]?\b|needle|penetrat|punctur')
    for sentence in bad:
        errs.append({'rule': 'invented-attribute', 'pattern': 'nail/penetration', 'match': 'attributed', 'context': sentence[:200]})
    return errs


def case_instron(reply):
    errs = forbid(reply, GLOBAL_FORBID, 'internal-phrasing')
    absence = [
        r'\b(?:no|not|n[o\']t|never|without)\b[^.\n]{0,60}\buniversal testing machines?\b',
        r'\buniversal testing machines?\b[^.\n]{0,60}(?:not (?:listed|available|in stock|carried)|isn[o\']t (?:listed|available)|n[o\']t (?:available|listed))',
        r'\b(?:we|our catalog|this shortlist)\b[^.\n]{0,40}\b(?:do not|don[o\']t|does not|doesn[o\']t)\b[^.\n]{0,30}\b(?:carry|stock|list|have|offer)\b[^.\n]{0,30}\b(?:universal testing|utm|tensile)',
    ]
    errs += forbid(reply, absence, 'false-absence')
    # A brand question is in scope: refusing to engage is the failure mode the client saw.
    errs += forbid(reply, [
        r"\b(?:cannot|can\'t|can\u2019t|unable to)\s+(?:compare|rank|benchmark|comment on|advise on)\b",
        r"\b(?:only help|only work)\s+with\s+testing\s+equipment\b",
    ], 'refusal')
    if not has(reply, [r'worldoftest\.com/product-categories', r'worldoftest\.com/contact']):
        errs.append({'rule': 'route', 'pattern': 'categories/contact link', 'match': '', 'context': 'no category or contact route offered'})
    return errs


def case_iso2039(reply):
    errs = forbid(reply, GLOBAL_FORBID, 'internal-phrasing')
    claims = [
        r'\b(?:complies with|conforms to|meets|certified to|designed (?:to|for)|rated (?:to|for)|in accordance with)\b[^.\n]{0,40}\bISO\s*2039-2\b',
        r'\bISO\s*2039-2\b[^.\n]{0,40}\b(?:compliant|complies|conforms|meets|certified|designed)\b',
    ]
    for sentence in sentences(reply):
        if re.search(HEDGES, sentence, re.I):
            continue  # "whether it conforms ... is not stated" is the honest form
        errs += forbid(sentence, claims, 'false-standard-claim')
    bad = attribution_violation(reply, r'ISO\s*2039-2', r'ISO\s*2039-2')
    for sentence in bad:
        errs.append({'rule': 'invented-standard', 'pattern': 'ISO 2039-2', 'match': 'attributed', 'context': sentence[:200]})
    if not has(reply, [r'not\s+(?:explicitly\s+)?(?:listed|declared|stated|specified|shown|available|documented)',
                       r'do(?:es)?\s*n[o\']t\s+(?:list|declare|state|specify|show)',
                       r'no\s+(?:exact\s+)?(?:product|equipment|model|tester)[^.\n]{0,60}(?:lists?|declares?|states?|carries|matches)',
                       r'\bno\b[^.\n]{0,40}\b(?:declares?|lists?|states?|carries|specifies?|matches)\b',
                       r'none\s+of\s+(?:the|these)\b[^.\n]{0,80}\b(?:state|states|list|lists|declare|declares|carry|carries)\b',
                       r'not\s+(?:among|part\s+of)\s+(?:its|their|the)\s+(?:listed|declared|stated)',
                       r'cannot\s+(?:confirm|verify)', r'can\s*n[o\']t\s+(?:confirm|verify)',
                       r'need(?:s|ed)?\s+(?:to\s+be\s+)?confirm(?:ed|ation)',
                       r'not\s+(?:a\s+)?(?:match|declared)']):
        errs.append({'rule': 'honesty', 'pattern': 'standard-not-declared', 'match': '', 'context': 'reply never says the standard is not declared in the catalogue'})
    return errs


def case_contact(reply):
    errs = forbid(reply, GLOBAL_FORBID + CONTACT_PROMISES, 'contact-promise')
    if not has(reply, [r'worldoftest\.com/contact', r'\bquotation\b', r'\brequest a quote\b', r'\bquote request\b']):
        errs.append({'rule': 'route', 'pattern': 'quotation route', 'match': '', 'context': 'no quotation path offered'})
    return errs


CASES = [
    {
        'id': 'E04',
        'prompt': 'I want to buy 3 Shore A durometers',
        'why': 'no commercial terms, no payment/stock/lead-time promises',
        'check': case_e04,
    },
    {
        'id': 'E05',
        'prompt': 'compare QualiTip II-Plus vs QualiTip Plus',
        'why': 'no comparison table, no storage/lifecycle/accuracy claims, datasheet route instead',
        'check': case_e05,
    },
    {
        'id': 'F02',
        'prompt': 'battery nail penetration test chamber',
        'why': 'nail/needle penetration must not be pinned on a product that does not list it',
        'check': case_f02,
    },
    {
        'id': 'X01',
        'prompt': 'Is Instron better?',
        'why': 'never claim the catalogue lacks universal testing machines; no matched-catalog phrasing',
        'check': case_instron,
    },
    {
        'id': 'X02',
        'prompt': 'ISO 2039-2',
        'why': 'no product declares ISO 2039-2; say so instead of dressing up a Rockwell tester',
        'check': case_iso2039,
    },
    {
        'id': 'X03',
        'prompt': 'my email is john@acme.com, please have sales call me',
        'why': 'no promise to store contact details; point to the quotation path',
        'check': case_contact,
    },
]


def ask(base, prompt, tag, timeout=120):
    payload = {'message': prompt, 'history': [], 'session_id': tag}
    body = json.dumps(payload).encode()
    req = urllib.request.Request(base.rstrip('/') + '/api.php', data=body, method='POST')
    req.add_header('Content-Type', 'application/json')
    req.add_header('User-Agent', USER_AGENT)
    started = time.time()
    # The shared host resets a keep-alive-free POST now and then (Errno 54); retry twice
    # with a pause before declaring the case unmeasured.
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                status, headers, text = resp.status, dict(resp.headers), resp.read().decode('utf-8', 'replace')
            break
        except urllib.error.HTTPError as exc:
            status, headers, text = exc.code, dict(exc.headers), exc.read().decode('utf-8', 'replace')
            break
        except Exception as exc:
            if attempt == 2:
                status, headers, text = 0, {}, 'transport: ' + str(exc)
                break
            time.sleep(6)
    elapsed = time.time() - started
    try:
        parsed = json.loads(text)
    except ValueError:
        parsed = None
    return {'status': status, 'headers': headers, 'body': text, 'json': parsed, 'seconds': round(elapsed, 1)}


def regrade():
    """Re-run the current rules over replies already saved in tests/out/claims/ (no
    model calls). Used after a rule change so the saved verdicts stay authoritative."""
    by_id = {c['id']: c for c in CASES}
    summary = {'base': 'regrade', 'generated': time.strftime('%Y-%m-%dT%H:%M:%S'), 'cases': []}
    bad = 0
    for case_id, case in by_id.items():
        runs = []
        for path in sorted(glob.glob(os.path.join(OUT, f'{case_id.lower()}-run*.json'))):
            with open(path, encoding='utf-8') as fh:
                rec = json.load(fh)
            errs = case['check'](rec.get('reply', '')) if rec.get('status') == 200 else [
                {'rule': 'http', 'pattern': str(rec.get('status')), 'match': '', 'context': ''}]
            rec['errors'] = errs
            rec['pass'] = rec.get('status') == 200 and not errs
            with open(path, 'w', encoding='utf-8') as fh:
                json.dump(rec, fh, indent=2, ensure_ascii=False)
            runs.append(rec)
        if not runs:
            continue
        passed = sum(1 for r in runs if r['pass'])
        verdict = 'PASS' if passed == len(runs) else 'FAIL'
        if verdict == 'FAIL':
            bad += 1
        summary['cases'].append({'id': case_id, 'prompt': case['prompt'], 'why': case['why'],
                                 'passed': passed, 'runs': len(runs), 'verdict': verdict,
                                 'errors': [{'run': r['run'], 'errors': r['errors']} for r in runs if r['errors']]})
        print(f'{case_id}: {passed}/{len(runs)} {verdict}')
        for r in runs:
            for e in r['errors'][:3]:
                print(f"    run{r['run']} ! {e['rule']}: {e['match'] or e['context']}")
    with open(os.path.join(OUT, 'summary.json'), 'w', encoding='utf-8') as fh:
        json.dump(summary, fh, indent=2, ensure_ascii=False)
    return 0 if bad == 0 else 1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', default='https://chat.worldoftest.com')
    ap.add_argument('--runs', type=int, default=3)
    ap.add_argument('--only', default='')
    ap.add_argument('--pace', type=float, default=6.5, help='seconds between requests (live limiter is 10/min)')
    ap.add_argument('--regrade', action='store_true', help='re-grade saved replies, no model calls')
    args = ap.parse_args()

    if args.regrade:
        return regrade()

    os.makedirs(OUT, exist_ok=True)
    cases = [c for c in CASES if not args.only or c['id'].lower() in args.only.lower().split(',')]
    summary = {'base': args.base, 'generated': time.strftime('%Y-%m-%dT%H:%M:%S'), 'runs': args.runs, 'cases': []}
    failures = 0
    first_request = True

    for case in cases:
        runs = []
        for n in range(1, args.runs + 1):
            if not first_request:
                time.sleep(args.pace)
            first_request = False
            res = ask(args.base, case['prompt'], f"claims-{case['id'].lower()}-{n}")
            reply = (res.get('json') or {}).get('reply', '') if isinstance(res.get('json'), dict) else ''
            errs = case['check'](reply) if res['status'] == 200 else [
                {'rule': 'http', 'pattern': str(res['status']), 'match': '', 'context': res['body'][:200]}
            ]
            record = {
                'case': case['id'], 'run': n, 'prompt': case['prompt'], 'base': args.base,
                'status': res['status'], 'seconds': res['seconds'], 'reply': reply,
                'errors': errs, 'pass': res['status'] == 200 and not errs,
                'raw_body': res['body'][:4000],
            }
            with open(os.path.join(OUT, f"{case['id'].lower()}-run{n}.json"), 'w', encoding='utf-8') as fh:
                json.dump(record, fh, indent=2, ensure_ascii=False)
            runs.append(record)
            verdict = 'PASS' if record['pass'] else 'FAIL'
            print(f"{case['id']} run{n} {verdict} ({res['status']}, {res['seconds']}s, {len(reply)} chars)")
            if record['errors']:
                for err in record['errors'][:4]:
                    print(f"    ! {err['rule']}: {err['match'] or err['context']}")

        passed = sum(1 for r in runs if r['pass'])
        summary['cases'].append({
            'id': case['id'], 'prompt': case['prompt'], 'why': case['why'],
            'passed': passed, 'runs': args.runs,
            'verdict': 'PASS' if passed == args.runs else 'FAIL',
            'errors': [{'run': r['run'], 'errors': r['errors']} for r in runs if r['errors']],
        })
        if passed != args.runs:
            failures += 1
        print(f"  -> {case['id']} {passed}/{args.runs} {'PASS' if passed == args.runs else 'FAIL'}\n")

    with open(os.path.join(OUT, 'summary.json'), 'w', encoding='utf-8') as fh:
        json.dump(summary, fh, indent=2, ensure_ascii=False)

    print('case    verdict  passes')
    for row in summary['cases']:
        print(f"{row['id']:7s} {row['verdict']:8s} {row['passed']}/{row['runs']}")
    print(f"\n{len(summary['cases']) - failures}/{len(summary['cases'])} cases clean; raw replies in tests/out/claims/")
    return 0 if failures == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
