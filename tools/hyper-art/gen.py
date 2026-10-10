#!/usr/bin/env python3
"""Generate the game's painted assets through OpenRouter (Gemini image models).
The key is read from the repo .env (OPENROUTERAI_API_KEY) and never printed. Every response's cost is summed; the run stops
before the budget would be exceeded. Usage: gen.py <manifest.json> <outdir> [--budget 5.0] [--only id,id]
"""

import base64, json, os, sys, time, urllib.request

ENV_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '.env')

def read_key():
    # Only the one variable is read; the value never leaves this process.
    for line in open(ENV_FILE):
        if line.startswith('OPENROUTERAI_API_KEY='):
            return line.split('=', 1)[1].strip().strip('"').strip("'")
    raise SystemExit('OPENROUTERAI_API_KEY missing in .env')
URL = 'https://openrouter.ai/api/v1/chat/completions'

def main():
    manifest, outdir = sys.argv[1], sys.argv[2]
    budget = float(sys.argv[sys.argv.index('--budget') + 1]) if '--budget' in sys.argv else 5.0
    only = set(sys.argv[sys.argv.index('--only') + 1].split(',')) if '--only' in sys.argv else None
    key = read_key()
    items = json.load(open(manifest))
    os.makedirs(outdir, exist_ok=True)
    ledger_path = os.path.join(outdir, 'ledger.json')
    ledger = json.load(open(ledger_path)) if os.path.exists(ledger_path) else {'spent': 0.0, 'items': {}}
    style = items.get('style', '')
    for it in items['assets']:
        if only and it['id'] not in only:
            continue
        out = os.path.join(outdir, it['id'] + '.png')
        if os.path.exists(out) and not only:
            continue
        if ledger['spent'] >= budget:
            print('budget reached, stop at', it['id']); break
        body = {
            'model': it.get('model', items.get('model', 'google/gemini-3-pro-image')),
            'messages': [{'role': 'user', 'content': (it['prompt'] + ' ' + style).strip()}],
            'modalities': ['image', 'text'],
            # `size` (1K, 2K, 4K) asks Gemini for a larger image: the broadcast pack needs 2x for 4K sources.
            'image_config': {'aspect_ratio': it.get('aspect', '1:1'), **({'image_size': it['size']} if 'size' in it else {})},
            'usage': {'include': True},
        }
        req = urllib.request.Request(URL, data=json.dumps(body).encode(), headers={'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'})
        try:
            with urllib.request.urlopen(req, timeout=240) as r:
                res = json.load(r)
        except urllib.error.HTTPError as e:
            print('HTTP', e.code, it['id'], e.read()[:300].decode('utf-8', 'replace')); break
        msg = res['choices'][0]['message']
        imgs = msg.get('images') or []
        cost = float((res.get('usage') or {}).get('cost') or 0)
        ledger['spent'] = round(ledger['spent'] + cost, 4)
        if not imgs:
            print('no image for', it['id'], (msg.get('content') or '')[:200]); ledger['items'][it['id']] = {'cost': cost, 'ok': False}
        else:
            data = imgs[0]['image_url']['url'].split(',', 1)[1]
            open(out, 'wb').write(base64.b64decode(data))
            ledger['items'][it['id']] = {'cost': cost, 'ok': True, 'model': body['model']}
            print(f"ok {it['id']}  cost {cost:.4f}  total {ledger['spent']:.4f}")
        json.dump(ledger, open(ledger_path, 'w'), indent=1)
        time.sleep(0.5)
    print('spent', ledger['spent'])

if __name__ == '__main__':
    main()
