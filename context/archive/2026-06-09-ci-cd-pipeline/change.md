---
change_id: ci-cd-pipeline
title: GitHub Actions CI pipeline (CI bez CD)
status: archived
created: 2026-06-09
updated: 2026-06-10
archived_at: "2026-06-10T06:26:20Z"
---

## Notes

Wymaganie certyfikacji 10xDev 3.0: projekt musi mieć CI/CD pipeline.

Zdecydowano na **CI bez automatycznego CD** — deploy pozostaje ręczny przez
`/MiJu-CF-Deploy`, ponieważ Cyber_Folks nie ma deploy API i automatyczny
push na produkcję bez staging byłby ryzykowny dla projektu z aktywnymi userami.

Pipeline: `.github/workflows/ci.yml`
Commity wdrożenia: `3732814`, `042657f`
