---
change_id: ci-cd-pipeline
title: GitHub Actions CI pipeline (CI bez CD)
status: implemented
created: 2026-06-09
updated: 2026-06-09
archived_at: null
---

## Notes

Wymaganie certyfikacji 10xDev 3.0: projekt musi mieć CI/CD pipeline.

Zdecydowano na **CI bez automatycznego CD** — deploy pozostaje ręczny przez
`/MiJu-CF-Deploy`, ponieważ Cyber_Folks nie ma deploy API i automatyczny
push na produkcję bez staging byłby ryzykowny dla projektu z aktywnymi userami.

Pipeline: `.github/workflows/ci.yml`
Commity wdrożenia: `3732814`, `042657f`
