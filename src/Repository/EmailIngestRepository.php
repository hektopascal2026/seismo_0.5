<?php

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;

/**
 * INSERT / upsert path for the unified `emails` table (IMAP ingestion).
 *
 * Retention stays on {@see EmailRepository}; reads stay on {@see EntryRepository}.
 * All SQL uses {@see entryTable()}. Mutating methods refuse satellite mode.
 */
final class EmailIngestRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Upsert IMAP-fetched rows keyed by non-null `imap_uid` (unique in schema).
     *
     * Each element may contain: imap_uid, message_id, from_addr, to_addr, cc_addr,
     * subject, from_email, from_name, date_utc, date_received, date_sent,
     * body_text, body_html, raw_headers, text_body, html_body.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function upsertImapBatch(array $rows): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('EmailIngestRepository::upsertImapBatch must not run on a satellite.');
        }
        if ($rows === []) {
            return 0;
        }

        $t = entryTable('emails');
        $sql = 'INSERT INTO ' . $t . ' (
            imap_uid, message_id, from_addr, to_addr, cc_addr,
            subject, from_email, from_name, date_utc, date_received, date_sent,
            body_text, body_html, raw_headers, text_body, html_body
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        ) ON DUPLICATE KEY UPDATE
            message_id = VALUES(message_id),
            from_addr = VALUES(from_addr),
            to_addr = VALUES(to_addr),
            cc_addr = VALUES(cc_addr),
            subject = VALUES(subject),
            from_email = VALUES(from_email),
            from_name = VALUES(from_name),
            date_utc = VALUES(date_utc),
            date_received = VALUES(date_received),
            date_sent = VALUES(date_sent),
            body_text = VALUES(body_text),
            body_html = VALUES(body_html),
            raw_headers = VALUES(raw_headers),
            text_body = VALUES(text_body),
            html_body = VALUES(html_body)';

        $stmt = $this->pdo->prepare($sql);

        $this->pdo->beginTransaction();
        try {
            $n = 0;
            foreach ($rows as $row) {
                $uid = isset($row['imap_uid']) ? (int)$row['imap_uid'] : 0;
                if ($uid <= 0) {
                    continue;
                }
                $stmt->execute([
                    $uid,
                    $this->nullStr($row['message_id'] ?? null),
                    $this->nullStr($row['from_addr'] ?? null),
                    $this->nullStr($row['to_addr'] ?? null),
                    $this->nullStr($row['cc_addr'] ?? null),
                    $this->truncate($row['subject'] ?? null, 500),
                    $this->truncate($row['from_email'] ?? null, 255),
                    $this->truncate($row['from_name'] ?? null, 255),
                    $this->nullStr($row['date_utc'] ?? null),
                    $this->nullStr($row['date_received'] ?? null),
                    $this->nullStr($row['date_sent'] ?? null),
                    $this->nullStr($row['body_text'] ?? null),
                    $this->nullStr($row['body_html'] ?? null),
                    $this->nullStr($row['raw_headers'] ?? null),
                    $this->nullStr($row['text_body'] ?? null),
                    $this->nullStr($row['html_body'] ?? null),
                ]);
                ++$n;
            }
            $this->pdo->commit();

            return $n;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function nullStr(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string)$v;

        return $s === '' ? null : $s;
    }

    private function truncate(mixed $v, int $max): ?string
    {
        $s = $this->nullStr($v);
        if ($s === null) {
            return null;
        }
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max);
    }
}
