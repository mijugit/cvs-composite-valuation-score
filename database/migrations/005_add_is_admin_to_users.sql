-- F-05: pro-access
-- Dodaje flagę is_admin do tabeli users.
-- Domyślnie 0 — wszyscy istniejący userzy pozostają zwykłymi userami.

ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0;
