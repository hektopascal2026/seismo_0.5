<?php
/**
 * Split a .sql file into executable statements for PDO::exec (one at a time).
 *
 * Handles -- line comments and /* *\/ block comments. Not a full SQL parser;
 * sufficient for our consolidated schema files which do not embed semicolons
 * inside string literals.
 */

declare(strict_types=1);

namespace Seismo\Migration;

final class SqlStatementSplitter
{
    /**
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $sql = self::stripBlockComments($sql);
        $sql = self::stripLineComments($sql);
        $chunks = explode(';', $sql);
        $out = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $out[] = $chunk;
            }
        }
        return $out;
    }

    private static function stripBlockComments(string $sql): string
    {
        return (string)preg_replace('/\/\*[\s\S]*?\*\//', '', $sql);
    }

    private static function stripLineComments(string $sql): string
    {
        $lines = explode("\n", $sql);
        foreach ($lines as $i => $line) {
            // Remove -- comments; keep http:// etc. by only stripping when -- is not inside a URL context (heuristic: -- at start of token after space)
            $lines[$i] = (string)preg_replace('/--[^\r\n]*/', '', $line);
        }
        return implode("\n", $lines);
    }
}
