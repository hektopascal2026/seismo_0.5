<?php
/**
 * Deterministic recipe-based scorer.
 *
 * Pure-function port of 0.4's `scoreEntryWithRecipe()` (config.php). Given a
 * recipe JSON (keywords, source_weights, classes, class_weights) and the
 * title + body text of an entry, it returns a `relevance_score`, predicted
 * label, and an explanation of the top contributing features.
 *
 * Keep the math identical to 0.4 — Magnitu's distiller.py trains against the
 * same feature space, and every divergence ends up as a silent drift between
 * Magnitu and Seismo's deterministic fallback. If the tokenisation or softmax
 * has to change, update both sides at once.
 */

declare(strict_types=1);

namespace Seismo\Core\Scoring;

final class RecipeScorer
{
    public const DEFAULT_CLASSES = ['investigation_lead', 'important', 'background', 'noise'];
    public const DEFAULT_CLASS_WEIGHTS = [1.0, 0.66, 0.33, 0.0];

    /**
     * Score one entry. Returns null when the recipe is missing / empty
     * (caller should treat as "unscored"), else the 0.4-shaped dictionary.
     *
     * @param array<string, mixed> $recipe Decoded recipe JSON.
     * @return array{
     *   relevance_score: float,
     *   predicted_label: string,
     *   explanation: array{top_features: array<int, array<string, mixed>>, confidence: float, prediction: string}
     * }|null
     */
    public static function score(array $recipe, string $title, string $content, string $sourceType = ''): ?array
    {
        if ($recipe === [] || empty($recipe['keywords'])) {
            return null;
        }

        /** @var array<int, string> $classes */
        $classes       = $recipe['classes']        ?? self::DEFAULT_CLASSES;
        /** @var array<int, float> $classWeights */
        $classWeights  = $recipe['class_weights']  ?? self::DEFAULT_CLASS_WEIGHTS;
        /** @var array<string, array<string, float>> $keywords */
        $keywords      = $recipe['keywords']       ?? [];
        /** @var array<string, array<string, float>> $sourceWeights */
        $sourceWeights = $recipe['source_weights'] ?? [];

        $text  = mb_strtolower(trim($title . ' ' . $content));
        $words = preg_split(
            '/[^a-zA-ZäöüàéèêïôùûçÄÖÜÀÉÈÊÏÔÙÛÇß0-9]+/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];

        // Unigrams + bigrams.
        $tokens = $words;
        $count  = count($words);
        for ($i = 0; $i < $count - 1; $i++) {
            $tokens[] = $words[$i] . ' ' . $words[$i + 1];
        }

        $classScores  = array_fill_keys($classes, 0.0);
        $topFeatures  = [];

        foreach ($tokens as $token) {
            if (!isset($keywords[$token])) {
                continue;
            }
            foreach ($keywords[$token] as $class => $weight) {
                if (!isset($classScores[$class])) {
                    continue;
                }
                $classScores[$class] += (float)$weight;
                if (!isset($topFeatures[$token])) {
                    $topFeatures[$token] = ['feature' => $token, 'weight' => 0.0, 'class' => $class];
                }
                $topFeatures[$token]['weight'] += (float)$weight;
            }
        }

        if ($sourceType !== '' && isset($sourceWeights[$sourceType])) {
            foreach ($sourceWeights[$sourceType] as $class => $weight) {
                if (isset($classScores[$class])) {
                    $classScores[$class] += (float)$weight;
                }
            }
        }

        // Softmax — subtract max for numerical stability (same as 0.4).
        $maxScore = $classScores === [] ? 0.0 : max($classScores);
        $expScores = [];
        $expSum    = 0.0;
        foreach ($classes as $class) {
            $e = exp(($classScores[$class] ?? 0.0) - $maxScore);
            $expScores[$class] = $e;
            $expSum           += $e;
        }

        $probabilities = [];
        foreach ($classes as $class) {
            $probabilities[$class] = $expSum > 0
                ? $expScores[$class] / $expSum
                : 1.0 / max(count($classes), 1);
        }

        $relevance = 0.0;
        foreach ($classes as $i => $class) {
            $relevance += $probabilities[$class] * (float)($classWeights[$i] ?? 0);
        }

        $predictedLabel = $classes[0] ?? 'noise';
        $maxProb = 0.0;
        foreach ($probabilities as $class => $prob) {
            if ($prob > $maxProb) {
                $maxProb = $prob;
                $predictedLabel = $class;
            }
        }

        usort($topFeatures, static fn ($a, $b) => abs($b['weight']) <=> abs($a['weight']));
        $explanation = array_slice(array_values($topFeatures), 0, 5);
        foreach ($explanation as &$feat) {
            $feat['direction'] = ($feat['weight'] ?? 0) >= 0 ? 'positive' : 'negative';
            $feat['weight']    = round((float)($feat['weight'] ?? 0), 3);
        }
        unset($feat);

        return [
            'relevance_score' => round($relevance, 4),
            'predicted_label' => $predictedLabel,
            'explanation' => [
                'top_features' => $explanation,
                'confidence'   => round($maxProb, 3),
                'prediction'   => $predictedLabel,
            ],
        ];
    }
}
