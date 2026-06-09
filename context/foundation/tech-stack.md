---
starter_id: vanilla-php
package_manager: composer
project_name: cvs-composite-valuation-score
hints:
  language_family: php
  team_size: solo
  deployment_target: shared-hosting
  ci_provider: github-actions
  ci_default_flow: manual-promotion
  bootstrapper_confidence: best-effort
  path_taken: custom
  quality_override: true
  self_check_answers:
    typed: false
    from_official_starter: false
    conventions: false
    docs_current: true
    can_judge_agent: true
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: false
  has_background_jobs: false
---

## Why this stack

Vanilla PHP 8.x wybrane świadomie na podstawie znajomości stacku i istniejącego shared hostingu — co eliminuje koszty infrastruktury i czas konfiguracji środowiska dla solo developera z 5-tygodniowym budżetem po godzinach. Wybór jest poza standardowym rejestrem starterów (starter_id: vanilla-php to niestandardowy wpis — /10x-bootstrapper nie wykona auto-scaffoldingu; wymagany pełny manual setup). Quality gates: typed=false i convention_based=false — kompensacja przez jawną strukturę projektu w CLAUDE.md i konsekwentne PHP 8.x type hints w kodzie. CVS model matematyczny (normalizacja statystyczna, Quality Gate, benchmarki sektorowe) zostanie zaimplementowany ręcznie w PHP — ekosystem bibliotek jest węższy niż Python, ale wystarczający dla modelu screenerowego. Deploy: git push + ręczny git pull / FTP na shared hosting. CI/CD: brak pipeline'u automatycznego na MVP.
