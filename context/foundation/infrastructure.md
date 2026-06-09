---
project: cvs-composite-valuation-score
researched_at: 2026-05-23
recommended_platform: Cyber_Folks shared hosting
runner_up: Railway
context_type: mvp
tech_stack:
  language: php
  framework: vanilla (no framework)
  runtime: PHP 8.2+
  database: MySQL
---

## Recommendation

**Deploy on Cyber_Folks shared hosting.**

PHP 8.2/8.3 is supported natively, MySQL is co-located and included in the plan, SSH
access enables `composer install`, and a custom document root can point a subdomain
directly at `~/apps/cvs/public/` — matching exactly how the project is structured.
At ~40 PLN/quarter (~$3–4 USD/month), it is 5–15× cheaper than any PaaS alternative.
The developer is already familiar with this environment, which eliminates the learning
curve that would come with containerised deployment on Railway or Render.

## Platform Comparison

Platforms evaluated: Cyber_Folks shared hosting, Railway, Render, Fly.io.
Eliminated at the hard-filter stage (no PHP 8.2+ runtime): Cloudflare Workers,
Vercel, Netlify.

| Platform | CLI-first | Managed/Serverless | Agent-readable docs | Stable deploy API | MCP/Integration | Cost fit | DB co-location |
|---|---|---|---|---|---|---|---|
| Cyber_Folks | Fail | Fail | Fail | Fail | Fail | **Pass** (~€10/quarter) | **Pass** (MySQL included) |
| Railway | Pass | Pass | Partial | Pass | Fail | Pass ($5/mo) | Pass (MySQL service) |
| Render | Pass | Pass | Pass | Pass | Partial | Fail ($30–50/mo) | Partial |
| Fly.io | Pass | Pass | Pass | Pass | Partial | Partial ($10–25/mo + ext. MySQL) | **Fail** |

Soft weights applied: cost = highest priority (interview Q2), co-located DB = important
(Q5), developer familiarity = tiebreaker (Q3), single-region EU acceptable (Q4).

### Shortlisted Platforms

#### 1. Cyber_Folks (Recommended)

Native PHP 8.2/8.3, MySQL included, SSH + Composer supported, subdomain with custom
document root works out-of-the-box, DirectAdmin panel, LiteSpeed web server, Polish
data residency. Scored last on agent-friendly criteria (no deploy CLI, no API, no
preview environments) but first on the two highest-weight interview criteria: cost and
co-location. At MVP scale with a solo developer, operational simplicity beats toolchain
completeness.

#### 2. Railway

Best PaaS alternative. Nixpacks auto-detects PHP 8.2+ from `composer.json` (no
Dockerfile needed). MySQL deployable as a co-located service with private networking.
Hobby plan at $5/month. Clean CLI (`railway up`, `railway logs`, `railway redeploy`).
Amsterdam EU region; ~15–20 ms from Poland. Scores well on agent-friendly criteria.
Falls to second because it still costs 5× more than Cyber_Folks at MVP scale and adds
nginx URL-rewriting configuration overhead for vanilla PHP.

#### 3. Render

Viable PHP+Docker+MySQL stack with Frankfurt EU region and a solid CLI. Eliminated from
top two primarily by cost: $30–50/month for a solo-dev MVP is disproportionate, and the
managed-disk MySQL has a known snapshot-restore corruption issue (use `mysqldump`
instead). The Docker requirement adds minimal but real complexity vs. shared hosting.

## Anti-Bias Cross-Check: Cyber_Folks

### Devil's Advocate — Weaknesses

1. **No deployment CLI or API** — every deploy is SSH + SFTP or manual file upload.
   Automating this with GitHub Actions requires hand-rolled SSH scripts that are fragile
   and Cyber_Folks-specific. If SSH keys or DirectAdmin panel behaviour changes, the
   pipeline breaks silently.
2. **No rollback mechanism** — there is no "revert to previous deployment" command. A
   bad `composer install` mid-deploy or a broken `.htaccess` requires manual SSH
   recovery. The developer must maintain their own rollback procedure.
3. **Shared IP pool** — Cyber_Folks IPs are shared across many tenants. Yahoo Finance's
   cURL calls originate from a shared IP that other customers may be abusing, increasing
   the risk of rate-limiting or blocking.
4. **No preview environments** — there is no automated pull-request preview. Testing
   changes requires a manually configured staging subdomain or deploying to production
   directly.
5. **Resource throttling is invisible** — shared CPU/RAM limits can kick in under
   concurrent load without dashboards or alerts. Bursts of simultaneous stock analysis
   requests could silently time out with no observable signal.

### Pre-Mortem — How This Could Fail

The CVS app launched on Cyber_Folks and worked well for months. Then two things
converged: a small announcement brought 50 concurrent users, and Yahoo Finance started
rate-limiting the shared hosting IP range. Users saw blank result cards. Debugging
required downloading log files manually through the DirectAdmin panel — no live tail.
The dev found the issue (cURL timeout + PHP session lock contention) but could not
reproduce it locally because the shared environment made profiling impossible. A fix was
deployed via SFTP, but the wrong file version was uploaded — no staging environment, and
the Git-based deploy script assumed a directory structure that differed by one folder.
The site returned HTTP 500 for four hours. Rollback meant re-uploading the previous
version manually, taking 40 minutes to locate and transfer. The session-locking root
cause was never fully diagnosed.

### Unknown Unknowns

- **PHP session file locking** — file-based sessions serialize concurrent requests for
  the same user. The CVS dashboard uses AJAX; simultaneous ticker requests from one
  browser session will queue, not run in parallel, causing silent slowness.
- **LiteSpeed vs Apache `.htaccess` differences** — Cyber_Folks uses LiteSpeed, not
  Apache. Most rewrite rules work identically, but some `RewriteCond` patterns and
  `Header` directives behave differently. Test the existing `.htaccess` on LiteSpeed
  explicitly.
- **`composer install` memory ceiling** — PHP memory limit on shared plans (often 128
  MB) can OOM-kill Composer mid-install. Use `COMPOSER_MEMORY_LIMIT=-1 composer install`
  via SSH.
- **cURL and Yahoo Finance shared-IP throttling** — Yahoo Finance rate limits are
  IP-based. A shared IP serving many tenants may already be in a throttled tier. The
  3600-second session cache mitigates this, but cold-start requests remain exposed.
- **GitHub Actions → SSH deployment is non-trivial** — requires storing SSH private key
  as a GitHub Secret, configuring `known_hosts` fingerprint, and writing a deploy
  script. Plan 2–3 hours of setup; introduces a credential rotation surface.

## Operational Story

- **Preview deploys**: Not available natively. Create a second subdomain (e.g.
  `staging.cvs.yourdomain.pl`) pointing to a separate directory as a manual staging
  environment. No automated PR previews.
- **Secrets**: Environment variables live in `.env` (excluded from git). Copy
  `.env.example` to `.env` via SSH on first deploy and edit in place. Do not store
  secrets in the DirectAdmin panel's environment section — it is not encrypted at rest.
- **Rollback**: Keep the previous release in a dated directory (`~/apps/cvs-YYYYMMDD/`).
  To revert: `ssh user@host "rm -rf ~/apps/cvs && cp -r ~/apps/cvs-20260523 ~/apps/cvs"`.
  Migrations do not roll back automatically — plan migration reversibility before deploying.
- **Approval**: All production changes require the developer to initiate. No automated
  publish path exists on shared hosting; this is a feature, not a bug, at MVP stage.
- **Logs**: PHP error log accessible in DirectAdmin panel → Historia → Logi usługi
  (60-day retention). For real-time debugging: `ssh user@host "tail -f ~/logs/php_error.log"`
  once the custom log path is configured in `.htaccess` (`php_flag log_errors on`,
  `php_value error_log /home/user/logs/php_error.log`).

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| Yahoo Finance rate-limit on shared IP | Devil's advocate + Unknown unknowns | M | H | Session cache (3600 s) already implemented. Add exponential backoff to `FinancialDataFetcher::fetch()`. Consider dedicated IP add-on if throttling becomes persistent. |
| No rollback → prolonged downtime on bad deploy | Devil's advocate | M | H | Maintain dated release directories (`~/apps/cvs-YYYYMMDD/`). Write a 3-command rollback script and keep it in `scripts/rollback.sh`. |
| PHP session locking under concurrent AJAX | Unknown unknowns | M | M | Call `session_write_close()` as early as possible in the request lifecycle (after reading session data). Consider storing analysis results in DB cache instead of session. |
| LiteSpeed `.htaccess` parity breaks routing | Unknown unknowns | L | H | Test `.htaccess` rewrite rules on Cyber_Folks before go-live. Check LiteSpeed docs for `RewriteRule` and `Header` directive differences. |
| `composer install` OOM on shared plan | Unknown unknowns | M | M | Always use `COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev` in production deploys. |
| No staging environment → untested deploys | Pre-mortem | M | M | Create `staging.` subdomain on day 1. Never deploy to production without staging validation. |
| Shared-IP reputation affects other tenants | Devil's advocate | L | L | Monitor Yahoo Finance response codes in application logs. Document escalation path (Cyber_Folks support → dedicated IP). |
| GitHub Actions CI/CD setup complexity | Devil's advocate | M | L | Budget 2–3 hours for SSH key setup. Use `appleboy/ssh-action` or `rsync` over SSH. Store server fingerprint in `known_hosts` to prevent MITM on first connection. |

## Getting Started

1. **Enable SSH access** in the DirectAdmin panel: Services → SSH Access → Generate or
   upload your public key. Test with `ssh user@server.cyberfolks.pl`.

2. **Create the app directory and clone the repo**:
   ```bash
   ssh user@server.cyberfolks.pl
   mkdir -p ~/apps/cvs
   cd ~/apps/cvs
   git clone https://github.com/your-org/cvs-composite-valuation-score.git .
   ```

3. **Install dependencies** (use unlimited memory to avoid OOM):
   ```bash
   COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader
   ```

4. **Configure the subdomain document root** in DirectAdmin: Services → Domains →
   Add Subdomain → set document root to `/home/user/apps/cvs/public`. Propagation
   takes up to a few hours.

5. **Set up `.env`**:
   ```bash
   cp .env.example .env
   nano .env   # fill in DB credentials from DirectAdmin → MySQL Databases
   ```

6. **Run the migration**:
   ```bash
   mysql -u db_user -p db_name < database/migrations/001_create_users.sql
   ```

7. **Verify** by visiting `https://cvs.yourdomain.pl` — the login page should render
   with the dark theme. Confirm the disclaimer footer is visible.

## Out of Scope

The following were not evaluated in this research:
- Docker image configuration
- CI/CD pipeline setup (GitHub Actions deploy workflow)
- Production-scale architecture (multi-region, HA, DR)
- SSL certificate automation (Let's Encrypt via Cyber_Folks panel)
- CDN configuration for static assets
