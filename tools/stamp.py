#!/usr/bin/env python3
"""Stamp a content hash onto every stylesheet and script URL.

Browsers cache /assets/site.css for a long time. Without this, a returning
visitor gets new HTML with an old stylesheet and the page renders broken.
The hash changes only when the file does, so caching still works between
deploys. Run before every commit that touches site/.
"""
import hashlib, pathlib, re, sys
ROOT = pathlib.Path(__file__).resolve().parent.parent / 'site'
ASSETS = {'/assets/site.css': 'assets/site.css',
          '/assets/site.js': 'assets/site.js',
          '/studio/studio.css': 'studio/studio.css'}
changed = 0
for url, rel in ASSETS.items():
    h = hashlib.sha1((ROOT / rel).read_bytes()).hexdigest()[:10]
    pat = re.compile(r'((?:href|src)=")' + re.escape(url) + r'(?:\?v=[0-9a-f]+)?(")')
    for f in list(ROOT.rglob('*.html')) + list(ROOT.rglob('*.php')):
        s = f.read_text()
        n = pat.sub(r'\g<1>' + url + '?v=' + h + r'\2', s)
        if n != s:
            f.write_text(n); changed += 1
print(f"stamped, {changed} file update(s)")
