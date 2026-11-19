#!/usr/bin/env python3
"""Layout gate. Run before every push that touches site/.

Serves site/ locally and fails if any .wrap is off-centre or outside the
viewport, at six widths across the public pages. overflow:clip on sections
hides this kind of break from a scrollWidth test, which is how a stretched
hero once reached production.
"""
import http.server, pathlib, socketserver, sys, threading, functools
from playwright.sync_api import sync_playwright
SITE = pathlib.Path(__file__).resolve().parent.parent / 'site'
H = functools.partial(http.server.SimpleHTTPRequestHandler, directory=str(SITE))
H.log_message = lambda *a, **k: None
srv = socketserver.TCPServer(('127.0.0.1', 0), H); port = srv.server_address[1]
threading.Thread(target=srv.serve_forever, daemon=True).start()
bad = 0
with sync_playwright() as p:
    b = p.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox'])
    for w, h in [(1920,1000),(1440,900),(1366,640),(1024,768),(768,900),(390,844)]:
        for path in ['/', '/work/', '/approach/', '/contact/', '/privacy/']:
            pg = b.new_page(viewport={'width': w, 'height': h}); errs = []
            pg.on('pageerror', lambda e: errs.append(str(e)))
            pg.goto(f'http://127.0.0.1:{port}{path}', wait_until='networkidle'); pg.wait_for_timeout(400)
            out = pg.evaluate("""()=>{const vw=document.documentElement.clientWidth,o=[];
              document.querySelectorAll('.wrap').forEach((e,i)=>{const r=e.getBoundingClientRect();
              if(r.left<-1||r.right>vw+1||Math.abs(r.left-(vw-r.right))>2)o.push(i+':'+Math.round(r.left)+'-'+Math.round(r.right));});return o;}""")
            if out or errs: bad += 1; print('FAIL', w, path, out[:3], errs[:2])
            pg.close()
    b.close()
srv.shutdown()
print('layout: FAILED' if bad else 'layout: ok at 6 widths x 5 pages')
sys.exit(1 if bad else 0)
