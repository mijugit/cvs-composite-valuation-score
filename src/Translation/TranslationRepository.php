<?php

declare(strict_types=1);

namespace CVS\Translation;

use CVS\Core\Database;
use PDO;
use PDOException;

/**
 * Cache of on-device translations (Chrome Translator API / Built-in AI).
 *
 * One row per (ticker, lang, field) — when a browser with Translator API
 * support translates a field, the result is cached here so users without
 * that API can still see a translated version.
 *
 * Table DDL: database/migrations/019_create_company_translations.sql
 */
class TranslationRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Cached translation for a ticker/field/lang, or null if none exists.
     */
    public function find(string $ticker, string $lang, string $field): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT translation FROM company_translations WHERE ticker = ? AND lang = ? AND field = ? LIMIT 1'
        );
        $stmt->execute([strtoupper($ticker), $lang, $field]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string) $value : null;
    }

    /**
     * Insert or update the cached translation for this ticker/field/lang.
     * Uses INSERT + catch UNIQUE duplicate → UPDATE pattern (works on MySQL and SQLite).
     */
    public function save(string $ticker, string $lang, string $field, string $translation): void
    {
        $ticker = strtoupper($ticker);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO company_translations (ticker, lang, field, translation) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$ticker, $lang, $field, $translation]);
        } catch (PDOException $e) {
            $msg   = $e->getMessage();
            $isDup = str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint');

            if (!$isDup) {
                error_log('TranslationRepository::save failed: ' . $msg);
                return;
            }

            try {
                $upd = $this->db->prepare(
                    'UPDATE company_translations SET translation = ? WHERE ticker = ? AND lang = ? AND field = ?'
                );
                $upd->execute([$translation, $ticker, $lang, $field]);
            } catch (PDOException $ue) {
                error_log('TranslationRepository::update failed: ' . $ue->getMessage());
            }
        }
    }
}
