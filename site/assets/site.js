/* RedHot Design — site behaviour. No dependencies. */
(() => {
'use strict';
const rm = matchMedia('(prefers-reduced-motion:reduce)').matches;
const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const R  = n => 'R' + Math.round(n).toLocaleString('en-ZA');
const vt = f => (!rm && document.startViewTransition) ? document.startViewTransition(f) : f();

document.addEventListener('visibilitychange',
  () => document.body.classList.toggle('hid', document.hidden));

/* ── nav: solid on scroll, inverts over light sections ── */
const nav = $('#nav');
if (nav) {
  const setNav = t => {
    const light = t === 'light';
    nav.classList.toggle('inv', light);
    nav.style.setProperty('--navbg',  light ? '#f3f2ef' : '#050507');
    nav.style.setProperty('--navln',  light ? '#0a0a0b1f' : '#ffffff1f');
    nav.style.setProperty('--navtx',  light ? '#504e4d' : '#b9babd');
    nav.style.setProperty('--navfg',  light ? '#0a0a0b' : '#fff');
  };
  // detect whatever sits on a thin line directly under the nav bar,
  // so this works identically on every page regardless of section order
  const probe = new IntersectionObserver(
    es => es.forEach(e => { if (e.isIntersecting) setNav(e.target.dataset.theme); }),
    { rootMargin: '-78px 0px -99.8% 0px', threshold: 0 });
  $$('[data-theme]').forEach(sec => probe.observe(sec));
  setNav(document.querySelector('[data-theme]')?.dataset.theme || 'dark');
  addEventListener('scroll', () => nav.classList.toggle('on', scrollY > 24), { passive: true });
}

/* ── cursor spotlight on cards ── */
$$('[data-spot]').forEach(el => el.addEventListener('pointermove', e => {
  const r = el.getBoundingClientRect();
  el.style.setProperty('--mx', (e.clientX - r.left) + 'px');
  el.style.setProperty('--my', (e.clientY - r.top) + 'px');
}, { passive: true }));

/* ── count-up ── */
$$('[data-n]').forEach(el => {
  const run = () => {
    const target = parseFloat(el.dataset.n), pre = el.dataset.prefix || '', suf = el.dataset.suffix || '';
    const dec = target % 1 !== 0 ? 1 : 0, dur = 1300, t0 = performance.now();
    const tail = suf ? `<i>${suf}</i>` : '';
    if (rm) { el.innerHTML = pre + target.toFixed(dec) + tail; return; }
    (function tick(now) {
      const p = Math.min((now - t0) / dur, 1), e = 1 - Math.pow(1 - p, 3);
      el.innerHTML = pre + (target * e).toFixed(dec) + tail;
      if (p < 1) requestAnimationFrame(tick);
    })(t0);
  };
  new IntersectionObserver((es, o) => es.forEach(e => { if (e.isIntersecting) { run(); o.disconnect(); } }),
    { threshold: .6 }).observe(el);
});

/* ── leak calculator ── */
(() => {
  const sl = $('#budget'); if (!sl) return;
  const out = $('#spendOut');
  const SHARE = [.189, .131, .112, .087];           // median split we observe
  const kk = n => 'R' + Math.round(n / 1000).toLocaleString('en-ZA') + 'k';
  const mm = n => n >= 1e6 ? 'R' + (n / 1e6).toFixed(2) + 'm' : kk(n);
  const calc = () => {
    const v = +sl.value, leak = SHARE.reduce((a, b) => a + b, 0) * v;
    out.textContent = R(v);
    $('#svgIn').textContent  = R(v) + ' in';
    $('#svgOut').textContent = kk(v - leak);
    SHARE.forEach((p, i) => { const t = $('#lk' + i); if (t) t.textContent = kk(v * p); });
    $('#lgLeak').textContent = R(leak);
    $('#lgYear').textContent = mm(leak * 12);
  };
  sl.addEventListener('input', calc, { passive: true });
  calc();
})();

/* ── an ordinary visit, replayed ── */
(() => {
  const list = $('#vSteps'); if (!list) return;
  const cat = $('#vCat'), note = $('#vNote'), dots = $('#dots');
  const SCENES = [
    { cat: 'A plumbing company · Tuesday, 19:40',
      steps: ['Arrives from a search, with water on the floor.',
              'Looks for a phone number. It is in the footer.',
              'Scrolls past a paragraph about the company history.',
              'Leaves, and calls the next result.'] },
    { cat: 'An online shop · Saturday, 10:15',
      steps: ['Arrives from an advert for one specific product.',
              'Lands on the homepage. The product is not on it.',
              'Searches, finds it, cannot see the delivery cost.',
              'Leaves, with the basket still empty.'] },
    { cat: 'A consultancy · Thursday, 14:05',
      steps: ['Arrives on a recommendation, already half convinced.',
              'Wants to know what working together looks like.',
              'Finds a list of services and a contact form.',
              'Leaves, meaning to come back. Does not.'] },
    { cat: 'A practice · Monday, 08:20',
      steps: ['Arrives wanting an appointment this week.',
              'Cannot tell whether new patients are being taken.',
              'Is asked to phone during office hours.',
              'Leaves, and books somewhere that lets them.'] }
  ];
  let i = 0, tm, started = false;
  SCENES.forEach((s, n) => {
    const b = document.createElement('button');
    b.type = 'button'; b.setAttribute('role', 'tab');
    b.setAttribute('aria-current', String(n === 0));
    b.setAttribute('aria-label', s.cat);
    b.addEventListener('click', () => { i = n; play(); });
    dots.appendChild(b);
  });
  const NOTE = 'Nothing on the site was broken. It simply was not built around what this person came to do.';
  function play() {
    clearTimeout(tm);
    const s = SCENES[i];
    [...dots.children].forEach((d, n) => d.setAttribute('aria-current', String(n === i)));
    cat.textContent = s.cat; list.innerHTML = ''; note.textContent = ''; note.classList.remove('on');
    const add = k => {
      const li = document.createElement('li');
      li.textContent = s.steps[k];
      if (k === s.steps.length - 1) li.className = 'gone';
      list.appendChild(li);
      requestAnimationFrame(() => li.classList.add('on'));
    };
    if (rm) { s.steps.forEach((_, k) => add(k)); note.textContent = NOTE; note.classList.add('on'); return; }
    let k = 0;
    (function go() {
      add(k); k++;
      if (k < s.steps.length) { tm = setTimeout(go, 1150); return; }
      tm = setTimeout(() => {
        note.textContent = NOTE; note.classList.add('on');
        tm = setTimeout(() => { i = (i + 1) % SCENES.length; play(); }, 5200);
      }, 900);
    })();
  }
  new IntersectionObserver((es, o) => es.forEach(e => {
    if (e.isIntersecting && !started) { started = true; play(); o.disconnect(); }
  }), { threshold: .3 }).observe(list);
})();

/* ── qualifier ─────────────────────────────────────────── */
(() => {
  const dlg = $('#qz'); if (!dlg) return;
  const body = $('#qbody'), bar = $('#qbar'), num = $('#qnum');
  const A = {};
  let step = 0, sending = false;

  const Q = [
    { k:'goal', h:'What should your website be doing for you?', hint:'Pick the closest.', o:[
      ['Bring in enquiries','Bringing in enquiries',''],
      ['Sell products online','Selling products online',''],
      ['Get bookings','Getting bookings or appointments',''],
      ['Make the phone ring','Making the phone ring',''],
      ['Something else','Something else','Tell us in your own words at the end']]},
    { k:'problem', h:'And what is it doing instead?', hint:'', o:[
      ['Looks fine, brings in nothing','It looks fine, but nothing comes of it',''],
      ['People visit and leave','People visit, then leave',''],
      ['Dated and hard to update','It is dated and hard to update',''],
      ['Paying for ads that go nowhere','We pay for ads and they go nowhere',''],
      ['Not sure','Not sure. It just is not working','']]},
    { k:'urgency', h:'When would you like it sorted?', hint:'', o:[
      ['Immediately','As soon as possible',''],
      ['This quarter','In the next few months',''],
      ['Exploring','Just looking for now','']]},
    { k:'you', h:'Where can we reach you?', hint:'We look at your website before we reply, so the first conversation is already about your business.', form:true }
  ];

  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function paint() {
    num.textContent = step < Q.length ? `Step ${step + 1} of ${Q.length}` : 'Sent';
    bar.style.inlineSize = ((step + 1) / (Q.length + 1) * 100) + '%';
    const q = Q[step];

    if (q.form) {
      body.innerHTML = `<h3>${q.h}</h3><p class="hint">${q.hint}</p>
        <form class="fld" id="qf" novalidate>
          <div class="fld-g">
          <label><span>Your name</span><input name="name" autocomplete="name" required></label>
          <label><span>Business name</span><input name="company" autocomplete="organization" required></label>
          <label><span>Email</span><input name="email" type="email" autocomplete="email" inputmode="email" required></label>
          <label><span>Mobile <i>optional</i></span><input name="phone" type="tel" autocomplete="tel" inputmode="tel"></label>
          <label class="w"><span>Your website <i>optional</i></span><input name="url" type="text" inputmode="url" autocomplete="url" placeholder="yourbusiness.co.za"></label>
          <label class="w"><span>Anything we should know <i>optional</i></span><textarea name="notes" rows="2"></textarea></label>
          </div>
          <input class="hp" name="website_url" tabindex="-1" autocomplete="off" aria-hidden="true">
          <label class="consent">
            <input type="checkbox" name="consent" required>
            <span>I agree that RedHot Design may use these details to reply to my enquiry.
              <a href="/privacy/" target="_blank" rel="noopener">Privacy notice</a></span>
          </label>
          <button class="btn btn-pri" type="submit"><span>Send it through</span><span class="arw">&rarr;</span></button>
          <p class="err" id="qerr" hidden></p>
        </form>
        <button class="back" type="button" data-back>&larr; Back</button>`;
      $('#qf').addEventListener('submit', submit);
    } else {
      body.innerHTML = `<h3>${q.h}</h3>${q.hint ? `<p class="hint">${q.hint}</p>` : ''}
        <div class="opts">${q.o.map((o, n) => `<button class="opt" type="button" data-i="${n}" style="--i:${n}">
          <kbd aria-hidden="true">${'ABCDE'[n]}</kbd>
          <span>${esc(o[1])}${o[2] ? `<small>${esc(o[2])}</small>` : ''}</span>
          <span class="arw">&rarr;</span></button>`).join('')}</div>
        ${step ? '<button class="back" type="button" data-back>&larr; Back</button>' : ''}`;
      $$('.opt', body).forEach(b => b.addEventListener('click', () => {
        A[q.k] = q.o[+b.dataset.i][0];
        step++; vt(paint);
      }));
    }
    const back = $('[data-back]', body);
    if (back) back.addEventListener('click', () => { step--; vt(paint); });
  }

  async function submit(e) {
    e.preventDefault();
    if (sending) return;
    const f = e.target, err = $('#qerr');
    if (!f.checkValidity()) { f.reportValidity(); return; }
    const btn = $('button[type=submit]', f);
    sending = true; btn.disabled = true; btn.querySelector('span').textContent = 'Sending…';
    err.hidden = true;

    const fd = new FormData(f);
    const payload = { ...A };
    fd.forEach((v, k) => { payload[k] = k === 'consent' ? true : v; });
    if (payload.url && !/^https?:\/\//i.test(payload.url)) payload.url = 'https://' + payload.url.trim();
    payload.track = A.goal || '';
    payload.notes = ['Wants the site to: ' + (A.goal || 'not given'), 'What it does instead: ' + (A.problem || 'not given'), payload.notes || ''].filter(Boolean).join('\n');
    delete payload.goal; delete payload.problem;
    payload.consent = f.consent.checked;
    A.name = payload.name || '';            // so the result screen can greet them
    payload.src = location.pathname;
    payload.ref_url = document.referrer || '';

    try {
      const r = await fetch('/api/lead.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j.ok) throw new Error(j.error || 'failed');
      done(j.ref);
    } catch (ex) {
      sending = false; btn.disabled = false;
      btn.querySelector('span').textContent = 'Send it through';
      err.hidden = false;
      err.innerHTML = 'That did not go through. Please email '
        + '<a href="mailto:hello@redhotdesign.co.za" style="color:#fff;text-decoration:underline">hello@redhotdesign.co.za</a>'
        + ' and we will pick it up straight away.';
    }
  }

  function done(ref) {
    num.textContent = 'Sent';
    bar.style.inlineSize = '100%';
    vt(() => {
      body.innerHTML = `<div class="res">
        <div class="tick" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
        <h3>Thanks, ${esc((A.name || '').split(' ')[0] || 'we have it')}. It is with us.</h3>
        <ul>
          <li>We look at your website before we reply.</li>
          <li>You will hear from us within one business day.</li>
          <li>If we are not the right fit, we will say so.</li>
        </ul>
        <p class="ref">Reference ${esc(ref || '')}</p>
        <button class="btn btn-sec" type="button" data-close style="margin-block-start:1.4rem"><span>Close</span></button>
      </div>`;
      $('[data-close]', body).addEventListener('click', () => dlg.close());
    });
  }

  $$('[data-open]').forEach(b => b.addEventListener('click', () => {
    step = 0; sending = false; paint(); dlg.showModal();
  }));
  dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
  dlg.addEventListener('keydown', e => {
    if (e.target.closest('input,textarea') || e.metaKey || e.ctrlKey || e.altKey) return;
    const n = 'abcde'.indexOf(e.key.toLowerCase()); const o = n > -1 && $$('.opt', body)[n];
    if (o) { e.preventDefault(); o.click(); }
  });
  $('[data-close]', dlg)?.addEventListener('click', () => dlg.close());
  dlg.addEventListener('close', () => { bar.style.inlineSize = '0%'; });
})();
})();

/* ══════════════════════════════════════════════════════════════
   Analytics, consent-gated. POPIA: nothing loads before opt-in.
   Set GA4_ID below to your Measurement ID (G-XXXXXXXXXX).
   Leave it empty and no banner shows and nothing is ever loaded.
   ══════════════════════════════════════════════════════════════ */
(() => {
  'use strict';
  const GA4_ID = 'G-YLXN2D8WC7';                       // ← paste your G-XXXXXXXXXX here
  const KEY    = 'rh_consent_v1';
  if (!GA4_ID) return;

  const read = () => { try { return localStorage.getItem(KEY); } catch { return null; } };
  const save = v => { try { localStorage.setItem(KEY, v); } catch {} };

  // Consent Mode v2 defaults: everything denied until the visitor says otherwise.
  window.dataLayer = window.dataLayer || [];
  function gtag() { dataLayer.push(arguments); }
  window.gtag = gtag;
  gtag('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied',
    functionality_storage: 'granted',
    security_storage: 'granted',
    wait_for_update: 500
  });

  let loaded = false;
  function loadGA() {
    if (loaded) return; loaded = true;
    const s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA4_ID);
    document.head.appendChild(s);
    gtag('js', new Date());
    gtag('config', GA4_ID, { anonymize_ip: true });
  }

  function grant() {
    gtag('consent', 'update', { analytics_storage: 'granted' });
    loadGA();
  }

  function banner() {
    const el = document.createElement('div');
    el.className = 'cbar';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Analytics consent');
    el.innerHTML = `
      <p>We would like to measure how this site is used. Nothing loads, and nothing is
         stored, unless you agree. <a href="/privacy/">Privacy notice</a></p>
      <div class="cbar-b">
        <button type="button" data-no>Decline</button>
        <button type="button" data-yes>Allow</button>
      </div>`;
    document.body.appendChild(el);
    requestAnimationFrame(() => el.classList.add('in'));
    const close = () => { el.classList.remove('in'); setTimeout(() => el.remove(), 450); };
    el.querySelector('[data-yes]').addEventListener('click', () => { save('granted'); grant(); close(); });
    el.querySelector('[data-no]').addEventListener('click',  () => { save('denied'); close(); });
  }

  const choice = read();
  if (choice === 'granted') grant();
  else if (choice !== 'denied') addEventListener('load', () => setTimeout(banner, 1200), { once: true });
})();

/* Work wall: tiles lean toward the pointer, and the filter reshuffles them. */
(function(){
  var wk=document.getElementById('wk'); if(!wk) return;
  var fine=matchMedia('(hover:hover) and (pointer:fine)').matches, calm=matchMedia('(prefers-reduced-motion:reduce)').matches;
  if(fine && !calm){
    wk.addEventListener('pointermove',function(e){
      var t=e.target.closest('.wk-t'); if(!t) return;
      var r=t.getBoundingClientRect(), x=(e.clientX-r.left)/r.width, y=(e.clientY-r.top)/r.height;
      t.style.setProperty('--ry',((x-.5)*5).toFixed(2)+'deg');
      t.style.setProperty('--rx',((.5-y)*5).toFixed(2)+'deg');
      t.style.setProperty('--mx',(x*100).toFixed(1)+'%');
      t.style.setProperty('--my',(y*100).toFixed(1)+'%');
    });
    wk.addEventListener('pointerout',function(e){
      var t=e.target.closest('.wk-t'); if(!t||t.contains(e.relatedTarget)) return;
      t.style.setProperty('--rx','0deg'); t.style.setProperty('--ry','0deg');
    });
  }
  var btns=document.querySelectorAll('.wk-bar button'), tiles=wk.querySelectorAll('.wk-t');
  btns.forEach(function(b){b.addEventListener('click',function(){
    var f=b.dataset.f;
    function go(){
      btns.forEach(function(x){x.setAttribute('aria-pressed',x===b?'true':'false')});
      tiles.forEach(function(t){t.hidden=!(f==='all'||t.dataset.c===f)});
    }
    if(document.startViewTransition && !calm) document.startViewTransition(go); else go();
  })});
})();

/* Glass cards: lean toward the pointer and carry a light under it. */
(function(){
  if(!matchMedia('(hover:hover) and (pointer:fine)').matches || matchMedia('(prefers-reduced-motion:reduce)').matches) return;
  document.querySelectorAll('[data-glow]').forEach(function(t){
    t.addEventListener('pointermove',function(e){
      var r=t.getBoundingClientRect(), x=(e.clientX-r.left)/r.width, y=(e.clientY-r.top)/r.height;
      t.style.setProperty('--ry',((x-.5)*6).toFixed(2)+'deg'); t.style.setProperty('--rx',((.5-y)*6).toFixed(2)+'deg');
      t.style.setProperty('--mx',(x*100).toFixed(1)+'%'); t.style.setProperty('--my',(y*100).toFixed(1)+'%');
    });
    t.addEventListener('pointerleave',function(){t.style.setProperty('--rx','0deg');t.style.setProperty('--ry','0deg')});
  });
})();
