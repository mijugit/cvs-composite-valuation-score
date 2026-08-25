---
change_id: critical-review-openai
title: Add OpenAI (GPT-5.6 Terra + Luna) as critical review providers
status: implementing
created: 2026-08-25
updated: 2026-08-25
archived_at: null
---

## Notes

Deliberate expansion beyond critical-review-models' "max 2 providers" Non-Goal —
user has decided to move past MVP/PoC and grow the provider roster. Adds TWO
new providers in one change: GPT-5.6 Terra and GPT-5.6 Luna (OpenAI Responses
API, `web_search` tool, `background: true` + polling). User's explicit framing:
treat this as a natural extension of the EXISTING mechanism/UX — same prompt,
same data block, same async job pattern — not a new feature. Reuse the proven
Claude/Gemini pattern exactly (isolated service class + own worker script per
provider, shared CriticalReviewPrompt/CriticalReviewProbabilityParser). No DB
migration needed — `provider` is already a free-form VARCHAR(16) column with
`UNIQUE(ticker, provider)`.
