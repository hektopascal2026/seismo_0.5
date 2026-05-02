<?php

declare(strict_types=1);

namespace Seismo\Plugin\LexEu;

use EasyRdf\Sparql\Client;
use Seismo\Service\SourceFetcherInterface;

/**
 * EU legislation via Publications Office SPARQL (EUR-Lex data graph).
 */
final class LexEuPlugin implements SourceFetcherInterface
{
    /** EU / Fedlex-style language authority codes (injection-safe allowlist). */
    private const LANGUAGE_CODES = [
        'BUL', 'SPA', 'CES', 'DAN', 'DEU', 'EST', 'ELL', 'ENG', 'FIN', 'FRA', 'GLE',
        'HRV', 'HUN', 'ITA', 'LAV', 'LIT', 'MLT', 'NLD', 'POL', 'POR', 'RON', 'SLK',
        'SLV', 'SWE', 'ROH',
    ];

    public function getIdentifier(): string
    {
        return 'lex_eu';
    }

    public function getLabel(): string
    {
        return 'EUR-Lex (EU SPARQL)';
    }

    public function getEntryType(): string
    {
        return 'lex_item';
    }

    public function getConfigKey(): string
    {
        return 'eu';
    }

    public function getMinIntervalSeconds(): int
    {
        return 4 * 60 * 60;
    }

    public static function normalizeLanguage(string $raw): string
    {
        $u = strtoupper(trim($raw));

        return in_array($u, self::LANGUAGE_CODES, true) ? $u : 'ENG';
    }

    /**
     * Normalise configured document class to a full CDM class IRI (curie cdm:… only).
     */
    public static function documentClassToIri(string $raw): string
    {
        $t = trim($raw);
        if ($t === '') {
            return 'http://publications.europa.eu/ontology/cdm#legislation_secondary';
        }
        if (!preg_match('/^cdm:([A-Za-z0-9_-]+)$/', $t, $m)) {
            throw new \InvalidArgumentException(
                'Invalid EU document_class — use a curie like cdm:legislation_secondary (letters, digits, hyphen, underscore only).'
            );
        }

        return 'http://publications.europa.eu/ontology/cdm#' . $m[1];
    }

    public function fetch(array $config): array
    {
        $lookback = max(1, (int)($config['lookback_days'] ?? 90));
        $sinceDate = gmdate('Y-m-d', strtotime('-' . $lookback . ' days'));
        $lang = self::normalizeLanguage((string)($config['language'] ?? 'ENG'));
        $limit = max(1, min((int)($config['limit'] ?? 100), 200));
        $endpoint = trim((string)($config['endpoint'] ?? 'https://publications.europa.eu/webapi/rdf/sparql'));
        if ($endpoint === '' || !preg_match('#^https://#i', $endpoint)) {
            throw new \InvalidArgumentException('EU SPARQL endpoint must be an https URL.');
        }

        $classIri = self::documentClassToIri((string)($config['document_class'] ?? 'cdm:legislation_secondary'));
        $langUri = 'http://publications.europa.eu/resource/authority/language/' . $lang;
        $until = gmdate('Y-m-d', strtotime('+1 day'));

        // Prefer the configured language; if no expression uses it, take any
        // expression title (OPTIONAL+language often returned only CELEX in DB).
        $sparqlQuery = '
        PREFIX cdm: <http://publications.europa.eu/ontology/cdm#>
        PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

        SELECT ?work ?celex ?docDate (MAX(?titlePick) AS ?title)
        WHERE {
            ?work a <' . $classIri . '> .
            ?work cdm:work_id_document ?celex .
            ?work cdm:work_date_document ?docDate .
            FILTER(REGEX(STR(?celex), "^celex:[0-9]"))
            FILTER(?docDate >= "' . $sinceDate . '"^^xsd:date && ?docDate <= "' . $until . '"^^xsd:date)
            {
                ?work cdm:work_has_expression ?ex .
                ?ex cdm:expression_title ?titlePick .
                ?ex cdm:expression_uses_language <' . $langUri . '> .
            } UNION {
                ?work cdm:work_has_expression ?ex2 .
                ?ex2 cdm:expression_title ?titlePick .
                FILTER NOT EXISTS {
                    ?work cdm:work_has_expression ?ex3 .
                    ?ex3 cdm:expression_uses_language <' . $langUri . '> .
                }
            }
        }
        GROUP BY ?work ?celex ?docDate
        ORDER BY DESC(?docDate)
        LIMIT ' . $limit . '
    ';

        $sparql = new Client($endpoint);
        $results = $sparql->query($sparqlQuery);

        $rows = [];
        foreach ($results as $row) {
            $workUri = trim((string)($row->work ?? ''));
            $celexRaw = trim((string)($row->celex ?? ''));
            if ($workUri === '' || !str_starts_with($celexRaw, 'celex:')) {
                continue;
            }
            $celexId = strtoupper(substr($celexRaw, strlen('celex:')));
            if ($celexId === '' || strlen($celexId) > 64) {
                continue;
            }
            $title = trim((string)($row->title ?? ''));
            if ($title === '') {
                $title = $celexId;
            }
            $langPath = self::eurLexPathLang($lang);
            $eurlexUrl = 'https://eur-lex.europa.eu/legal-content/' . $langPath . '/TXT/HTML/?uri=CELEX:' . rawurlencode($celexId);

            $rows[] = [
                'celex' => $celexId,
                'title' => $title,
                'description' => null,
                'document_date' => (string)($row->docDate ?? ''),
                'document_type' => 'EU legislation',
                'eurlex_url' => $eurlexUrl,
                'work_uri' => $workUri,
                'source' => 'eu',
            ];
        }

        return $rows;
    }

    private static function eurLexPathLang(string $authority3): string
    {
        $map = [
            'BUL' => 'BG', 'SPA' => 'ES', 'CES' => 'CS', 'DAN' => 'DA', 'DEU' => 'DE',
            'EST' => 'ET', 'ELL' => 'EL', 'ENG' => 'EN', 'FIN' => 'FI', 'FRA' => 'FR',
            'GLE' => 'GA', 'HRV' => 'HR', 'HUN' => 'HU', 'ITA' => 'IT', 'LAV' => 'LV',
            'LIT' => 'LT', 'MLT' => 'MT', 'NLD' => 'NL', 'POL' => 'PL', 'POR' => 'PT',
            'RON' => 'RO', 'SLK' => 'SK', 'SLV' => 'SL', 'SWE' => 'SV', 'ROH' => 'RM',
        ];

        return $map[$authority3] ?? 'EN';
    }
}
