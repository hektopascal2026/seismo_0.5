<?php

declare(strict_types=1);

namespace Seismo\Plugin\LexFedlex;

use EasyRdf\Sparql\Client;
use Seismo\Service\SourceFetcherInterface;

/**
 * Swiss federal legislation via Fedlex SPARQL (ported from 0.4 refreshFedlexItems).
 * Rows without a title or a parseable Fedlex act URI are skipped (normalisation contract).
 */
final class LexFedlexPlugin implements SourceFetcherInterface
{
    /**
     * Fedlex SPARQL language authority codes (EU Publications Office). Used for SPARQL injection defense.
     *
     * @var list<string>
     */
    public const FEDLEX_LANGUAGE_CODES = ['DEU', 'FRA', 'ITA', 'ENG', 'ROH'];

    public function getIdentifier(): string
    {
        return 'fedlex';
    }

    public function getLabel(): string
    {
        return 'Swiss Fedlex';
    }

    public function getEntryType(): string
    {
        return 'lex_item';
    }

    public function getConfigKey(): string
    {
        return 'ch';
    }

    /**
     * 4 hours. Fedlex publications change on a daily-to-weekly cadence; 4h is
     * plenty fresh for a legislation monitor and keeps SPARQL load modest
     * when the master cron fires every 5 minutes. User-initiated refresh
     * from the Lex page bypasses this (force=true).
     */
    public function getMinIntervalSeconds(): int
    {
        return 4 * 60 * 60;
    }

    /**
     * Normalise config language to a safe Fedlex authority code (defaults to DEU).
     */
    public static function normalizeFedlexLanguage(string $raw): string
    {
        $u = strtoupper(trim($raw));

        return in_array($u, self::FEDLEX_LANGUAGE_CODES, true) ? $u : 'DEU';
    }

    public function fetch(array $config): array
    {
        $lookback = (int)($config['lookback_days'] ?? 90);
        $sinceDate = date('Y-m-d', strtotime('-' . $lookback . ' days'));
        $lang = self::normalizeFedlexLanguage((string)($config['language'] ?? 'DEU'));
        $limit = (int)($config['limit'] ?? 100);
        $endpoint = $config['endpoint'] ?? 'https://fedlex.data.admin.ch/sparqlendpoint';

        $resourceTypes = $config['resource_types'] ?? [];
        $typeIds = array_map(static function ($rt) {
            return is_array($rt) ? (int)$rt['id'] : (int)$rt;
        }, $resourceTypes);

        if ($typeIds === []) {
            $typeIds = [21, 22, 29, 26, 27, 28, 8, 9, 10, 31, 32];
        }

        $typeFilter = implode(', ', array_map(static function (int $n) {
            return '<https://fedlex.data.admin.ch/vocabulary/resource-type/' . $n . '>';
        }, $typeIds));

        $until = date('Y-m-d', strtotime('+1 year'));
        $sparqlQuery = '
        PREFIX jolux: <http://data.legilux.public.lu/resource/ontology/jolux#>
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

        SELECT DISTINCT ?act ?title ?pubDate ?typeDoc
        WHERE {
            ?act a jolux:Act .
            ?act jolux:publicationDate ?pubDate .
            ?act jolux:typeDocument ?typeDoc .
            ?act jolux:isRealizedBy ?expr .
            ?expr jolux:title ?title .
            ?expr jolux:language <http://publications.europa.eu/resource/authority/language/' . $lang . '> .
            FILTER(?typeDoc IN (' . $typeFilter . '))
            FILTER(?pubDate >= "' . $sinceDate . '"^^xsd:date && ?pubDate <= "' . $until . '"^^xsd:date)
        }
        ORDER BY DESC(?pubDate)
        LIMIT ' . $limit . '
    ';

        $sparql = new Client($endpoint);
        $results = $sparql->query($sparqlQuery);

        $rows = [];
        $fedlexHost = 'https://fedlex.data.admin.ch/';
        foreach ($results as $row) {
            $actUri = trim((string)$row->act);
            if ($actUri === '' || !str_starts_with($actUri, $fedlexHost)) {
                continue;
            }

            $title = trim((string)$row->title);
            if ($title === '') {
                continue;
            }

            $eliId = trim(str_replace($fedlexHost, '', $actUri), '/');
            if ($eliId === '') {
                continue;
            }

            $dateDoc = (string)$row->pubDate;
            $typeDoc = (string)$row->typeDoc;

            $docType = self::parseFedlexType($typeDoc);
            $fedlexUrl = 'https://www.fedlex.admin.ch/' . $eliId . '/de';

            $rows[] = [
                'celex' => $eliId,
                'title' => $title,
                'document_date' => $dateDoc,
                'document_type' => $docType,
                'eurlex_url' => $fedlexUrl,
                'work_uri' => $actUri,
                'source' => 'ch',
            ];
        }

        return $rows;
    }

    /**
     * Parse the document type from a Fedlex resource-type URI.
     */
    public static function parseFedlexType(string $typeUri): string
    {
        $map = [
            '21' => 'Bundesgesetz',
            '22' => 'Dringl. Bundesgesetz',
            '29' => 'Verordnung BR',
            '26' => 'Departementsverordnung',
            '27' => 'Amtsverordnung',
            '28' => 'Verordnung BV',
            '8'  => 'Bundesbeschluss',
            '9'  => 'Bundesbeschluss',
            '10' => 'Bundesbeschluss',
            '31' => 'Bilateral Treaty',
            '32' => 'Multilateral Treaty',
        ];

        if (preg_match('/resource-type\/(\d+)$/', $typeUri, $m)) {
            return $map[$m[1]] ?? 'Other';
        }

        return 'Other';
    }
}
