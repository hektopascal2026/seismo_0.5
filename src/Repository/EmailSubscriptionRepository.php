<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;

/**
 * `email_subscriptions` — domain-first newsletter registry (Slice 8).
 *
 * Do not use `sender_tags`; that table is legacy-only for dashboard pills.
 */
final class EmailSubscriptionRepository
{
    public const MAX_LIMIT = 200;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Whether `from_email` matches a subscription row (domain or exact email).
     * Used by tests and any future ingest path that resolves subscription rows.
     */
    public static function matchesAddress(string $fromEmail, string $matchType, string $matchValue): bool
    {
        $from = strtolower(trim($fromEmail));
        $mv   = trim($matchValue);
        if ($from === '' || $mv === '') {
            return false;
        }
        if ($matchType === 'email') {
            return $from === strtolower($mv);
        }
        if ($matchType !== 'domain') {
            return false;
        }
        $domain = strtolower(ltrim($mv, '@'));
        if ($domain === '') {
            return false;
        }
        $at = strpos($from, '@');
        if ($at === false) {
            return false;
        }

        return substr($from, $at + 1) === $domain;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $t      = entryTable('email_subscriptions');
        $sql    = "SELECT * FROM {$t}
            WHERE removed_at IS NULL
            ORDER BY id DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll();
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $t   = entryTable('email_subscriptions');
        $sql = "SELECT * FROM {$t} WHERE id = ? AND removed_at IS NULL LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{
     *   match_type: string,
     *   match_value: string,
     *   display_name: string,
     *   category?: string|null,
     *   disabled?: int|bool,
     *   show_in_magnitu?: int|bool,
     *   unsubscribe_url?: string|null,
     *   unsubscribe_mailto?: string|null,
     *   unsubscribe_one_click?: int|bool,
     * } $data
     */
    public function insert(array $data): int
    {
        $this->assertNotSatellite();
        $matchType = trim((string)($data['match_type'] ?? ''));
        if (!in_array($matchType, ['domain', 'email'], true)) {
            throw new \InvalidArgumentException('match_type must be domain or email.');
        }
        $matchValue = trim((string)($data['match_value'] ?? ''));
        if ($matchValue === '') {
            throw new \InvalidArgumentException('match_value is required.');
        }
        if ($matchType === 'domain') {
            $matchValue = ltrim(strtolower($matchValue), '@');
        } else {
            $matchValue = strtolower($matchValue);
        }
        $displayName = trim((string)($data['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $matchValue;
        }
        $showMagnitu = array_key_exists('show_in_magnitu', $data)
            ? (!empty($data['show_in_magnitu']) ? 1 : 0)
            : 1;

        $t   = entryTable('email_subscriptions');
        $sql = "INSERT INTO {$t} (
            match_type, match_value, display_name, category, disabled, show_in_magnitu,
            auto_detected, unsubscribe_url, unsubscribe_mailto, unsubscribe_one_click,
            item_count
        ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 0)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $matchType,
            $matchValue,
            $displayName,
            $data['category'] ?? null,
            !empty($data['disabled']) ? 1 : 0,
            $showMagnitu,
            $data['unsubscribe_url'] ?? null,
            $data['unsubscribe_mailto'] ?? null,
            !empty($data['unsubscribe_one_click']) ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid subscription id.');
        }
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Subscription not found.');
        }
        $matchType = array_key_exists('match_type', $data)
            ? trim((string)$data['match_type'])
            : (string)$existing['match_type'];
        if (!in_array($matchType, ['domain', 'email'], true)) {
            throw new \InvalidArgumentException('match_type must be domain or email.');
        }
        $matchValue = array_key_exists('match_value', $data)
            ? trim((string)$data['match_value'])
            : (string)$existing['match_value'];
        if ($matchValue === '') {
            throw new \InvalidArgumentException('match_value is required.');
        }
        if ($matchType === 'domain') {
            $matchValue = ltrim(strtolower($matchValue), '@');
        } else {
            $matchValue = strtolower($matchValue);
        }
        $displayName = array_key_exists('display_name', $data)
            ? trim((string)$data['display_name'])
            : (string)$existing['display_name'];
        if ($displayName === '') {
            $displayName = $matchValue;
        }
        $disabled = array_key_exists('disabled', $data)
            ? (!empty($data['disabled']) ? 1 : 0)
            : (int)($existing['disabled'] ?? 0);
        $showMagnitu = array_key_exists('show_in_magnitu', $data)
            ? (!empty($data['show_in_magnitu']) ? 1 : 0)
            : (int)($existing['show_in_magnitu'] ?? 1);

        $t   = entryTable('email_subscriptions');
        $sql = "UPDATE {$t} SET
            match_type = ?,
            match_value = ?,
            display_name = ?,
            category = ?,
            disabled = ?,
            show_in_magnitu = ?,
            unsubscribe_url = ?,
            unsubscribe_mailto = ?,
            unsubscribe_one_click = ?
            WHERE id = ? AND removed_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $matchType,
            $matchValue,
            $displayName,
            $data['category'] ?? $existing['category'],
            $disabled,
            $showMagnitu,
            $data['unsubscribe_url'] ?? $existing['unsubscribe_url'],
            $data['unsubscribe_mailto'] ?? $existing['unsubscribe_mailto'],
            array_key_exists('unsubscribe_one_click', $data)
                ? (!empty($data['unsubscribe_one_click']) ? 1 : 0)
                : (int)($existing['unsubscribe_one_click'] ?? 0),
            $id,
        ]);
    }

    /**
     * Soft-remove a subscription row.
     */
    public function softDelete(int $id): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid subscription id.');
        }
        $t   = entryTable('email_subscriptions');
        $sql = "UPDATE {$t} SET removed_at = UTC_TIMESTAMP() WHERE id = ? AND removed_at IS NULL LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
    }

    /**
     * One-click style disable (keeps the row for audit).
     */
    public function setDisabled(int $id, bool $disabled): void
    {
        $this->assertNotSatellite();
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid subscription id.');
        }
        $t   = entryTable('email_subscriptions');
        $sql = "UPDATE {$t} SET disabled = ? WHERE id = ? AND removed_at IS NULL LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$disabled ? 1 : 0, $id]);
    }

    private function assertNotSatellite(): void
    {
        if (isSatellite()) {
            throw new \RuntimeException('Satellite mode — email subscriptions are managed on the mothership only.');
        }
    }
}
