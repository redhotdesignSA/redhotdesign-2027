# redhotdesign.co.za

The live site. Everything under `site/` is served at `https://redhotdesign.co.za/`.

## How a change reaches the site

1. A change is committed and pushed to `main`.
2. A cron on the host polls GitHub every 2 minutes for the head commit of `main`.
3. If it has moved, the host downloads that exact commit, checks it looks like a
   site, snapshots what is currently live, and swaps the new files in.
4. The result is written back here as a **commit status** on that commit, so the
   outcome is visible from GitHub without touching the server.
5. Changed pages are then submitted to IndexNow automatically.

Typical time from push to live: under three minutes.

## What is NOT in here

Deliberately, so a bad push cannot cause damage:

- `redhot-form-config.php` (Brevo key, Zoho credentials, studio passphrase,
  cron key, GitHub token) lives one level above the webroot
- lead data and IndexNow state live in `/home/redhotde/redhot-leads/`
- the deployer itself lives in `/home/redhotde/redhot-deploy/`, outside the repo,
  so a broken commit can never break the thing that deploys
- the root `.htaccess` that maps `/` onto this site

## Safety

- The deployer refuses to publish if `index.html`, `assets/site.css` or
  `assets/site.js` are missing, or if `index.html` looks truncated.
- Before every deploy it zips the current live site into
  `/home/redhotde/redhot-deploy/releases/`, keeping the last 6.
- On failure it leaves the live site untouched and marks the commit failed.

## Rollback

Revert the commit and push. The deployer treats it as any other change.

## Layout

```
site/
  index.html, work/, approach/, contact/, privacy/, 404.html
  assets/        css, js, logos
  api/           lead capture, Zoho flow, cron, IndexNow submitter
  studio/        the deal board (passphrase protected)
  intake/        client intake form
  robots.txt, sitemap.xml
  <indexnow-key>.txt
```
