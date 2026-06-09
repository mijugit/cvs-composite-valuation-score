---
change-id: transactional-email
title: "F-03: Serwis maili transakcyjnych (PHPMailer/SMTP)"
status: implemented
created: 2026-06-02
updated: 2026-06-02
roadmap_ref: F-03
prd_refs: [FR-005]
---

# F-03 — Serwis maili transakcyjnych

PHPMailer+SMTP w `src/Mail/MailService.php`, config z .env,
graceful failure (log + return false). Fundament pod S-04 (alerty)
i S-05 (formularz PRO). Sprawdzony wzorzec z C:\python\blog\api\mailer.php.
