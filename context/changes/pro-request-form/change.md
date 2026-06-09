---
change-id: pro-request-form
title: "S-05: Formularz prośby o kod PRO"
status: implemented
created: 2026-06-02
updated: 2026-06-02
roadmap_ref: S-05
prd_refs: [FR-006]
---

# S-05 — Formularz prośby o kod PRO

Rozszerzenie modalu PRO na stronie /analysis/{ticker}: obok pola "wpisz kod"
pojawia się sekcja "Nie masz kodu? Napisz do admina". Formularz (imię + wiadomość)
wysyła mail do admina przez MailService. Po wysłaniu flash + zablokowany przycisk.
