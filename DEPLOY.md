# SnapCaptioner — Deployment Checklist

All 6 competitor-gap features are built and pushed to GitHub.
Run through these steps ONCE to go live.

---

## STEP 1 — SSH into Hostinger and git pull

```bash
ssh -p 65002 u922271352@89.117.139.240
cd public_html
git pull origin main
```

This pulls all 6 feature commits including the bug fixes.

---

## STEP 2 — Create api/config.php on the server

The template is at `api/config.php.example`. Copy it and fill in your real keys:

```bash
cp api/config.php.example api/config.php
nano api/config.php
```

Fill in every ← replace value. Required before ANY feature works:
- `DB_PASS` — Hostinger MySQL password
- `OPENAI_API_KEY` — OpenAI key

Required per feature:
- **Feature 2 (Hashtag Volume)**: `RAPIDAPI_KEY` (optional — falls back to GPT if blank)
- **Feature 4 (Social Publish)**: `META_APP_ID`, `META_APP_SECRET`, `PINTEREST_APP_ID`, `PINTEREST_APP_SECRET`
- **Feature 5 (Relight)**: `PHOTOROOM_API_KEY`
- **Stripe**: `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, plus 4 price IDs

---

## STEP 3 — Run setup.php to apply DB migrations

Visit in your browser (or curl):

```
https://snapcaptioner.com/api/setup.php
```

This will:
- Create `social_connections` table (Feature 4)
- ADD `meta` column to `usage_log` (Feature 5 fix — was crashing without this)
- Safe no-op on everything already set up

---

## STEP 4 — Stripe: Create 4 products

In Stripe Dashboard → Products → Add product:

| Name | Price | Interval | Plan code |
|------|-------|----------|-----------|
| Starter | $3 | Monthly | starter |
| Premium | $7 | Monthly | premium |
| Pro | $14 | Monthly | pro |
| Teams | $29 | Monthly | teams |

After creating each, copy the **Price ID** (looks like `price_1Xxx...`) into `config.php`.

Then set up the webhook:
- Stripe Dashboard → Webhooks → Add endpoint
- URL: `https://snapcaptioner.com/api/stripe-webhook.php`
- Events: `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`
- Copy the **Signing secret** → `STRIPE_WEBHOOK_SECRET` in config.php

---

## STEP 5 — Meta Developer App (Feature 4 — Instagram)

1. Go to https://developers.facebook.com/apps/ → Create App
2. Use case: **Business** → next
3. Add products: **Facebook Login** + **Instagram Graph API**
4. App Settings → Basic → copy App ID + App Secret → into config.php
5. Facebook Login → Valid OAuth Redirect URIs:
   ```
   https://snapcaptioner.com/api/social-auth.php
   ```
6. Instagram Graph API → enable `instagram_basic`, `instagram_content_publish`, `pages_read_engagement`
7. Submit for App Review (required to publish to other users' accounts)

> **Note:** While in Development mode you can only test with your own IG account.

---

## STEP 6 — Pinterest Developer App (Feature 4 — Pinterest)

1. Go to https://developers.pinterest.com/apps/ → New App
2. Redirect URI:
   ```
   https://snapcaptioner.com/api/social-auth.php
   ```
3. Copy App ID + App Secret → into config.php
4. Request scopes: `boards:read`, `pins:write`, `user_accounts:read`

---

## STEP 7 — Photoroom API Key (Feature 5 — Relight)

1. Sign up at https://www.photoroom.com/api
2. Copy API key → `PHOTOROOM_API_KEY` in config.php

---

## Feature Access Matrix

| Feature | Plan Required |
|---------|--------------|
| Carousel Text Overlay | Premium, Pro, Teams |
| Live Hashtag Volume | Pro, Teams |
| Best-Time-to-Post Queue | Premium, Pro, Teams |
| Social Direct Publish | Pro, Teams |
| AI Photo Relighting | Pro (10/day), Teams (20/day) |
| Brand Kit Carousel Designer | Pro, Teams |

---

## What's Already Done (by Claude)

✅ All 6 features built and committed to GitHub  
✅ `usage_log` meta column migration added to db.php (Feature 5 fix)  
✅ `social_connections` table migration in db.php (Feature 4)  
✅ `api/config.php.example` template committed (safe — no real keys)  
✅ All PHP API endpoints: relight.php, social-auth.php, social-connect.php, social-publish.php  
✅ `.gitignore` protects api/config.php  
✅ Pushed to origin/main — 6 commits ahead  

## What Dinesh Must Do

1. SSH → `git pull` (Step 1)  
2. Create and fill `api/config.php` (Step 2)  
3. Visit `setup.php` once (Step 3)  
4. Create Stripe products + webhook (Step 4)  
5. Create Meta app (Step 5) — needed only when ready for Instagram  
6. Create Pinterest app (Step 6) — needed only when ready for Pinterest  
7. Get Photoroom API key (Step 7) — needed only for Relight feature  
