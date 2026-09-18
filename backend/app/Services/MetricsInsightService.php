<?php

namespace App\Services;

/**
 * Turns the raw metrics computed by MetricsController into the two callouts
 * shown at the top of the dashboard: the metric that most needs attention
 * right now ("critical") and the one that is currently most consistent
 * ("stable"). Deliberately simple threshold scoring, not a forecasting
 * model — see Documento 2's discussion of first-pass-rate/escaped-defects
 * needing to be read together instead of in isolation.
 */
class MetricsInsightService
{
    private const MIN_SAMPLE = 5;

    private const DEFINITIONS = [
        'first_pass_rate' => ['label' => 'Taxa de aprovação na 1ª tentativa', 'higher_is_better' => true, 'warn' => 70.0, 'target' => 90.0, 'unit' => '%'],
        'rework_rate' => ['label' => 'Taxa de retrabalho do desenvolvedor', 'higher_is_better' => false, 'warn' => 30.0, 'target' => 10.0, 'unit' => '%'],
        'escaped_defect_rate' => ['label' => 'Bugs encontrados após publicação', 'higher_is_better' => false, 'warn' => 10.0, 'target' => 2.0, 'unit' => '%'],
        'deploy_success_rate' => ['label' => 'Taxa de sucesso de deploy', 'higher_is_better' => true, 'warn' => 90.0, 'target' => 98.0, 'unit' => '%'],
        'avg_retries' => ['label' => 'Tentativas médias por tarefa', 'higher_is_better' => false, 'warn' => 1.0, 'target' => 0.2, 'unit' => ''],
    ];

    /**
     * @param  array<string, float|null>  $metrics  keyed by metric name from self::DEFINITIONS
     */
    public function build(array $metrics, int $sampleSize): array
    {
        if ($sampleSize < self::MIN_SAMPLE) {
            return [
                'critical' => null,
                'stable' => null,
                'note' => 'Dados insuficientes (mínimo de '.self::MIN_SAMPLE.' tarefas concluídas no período) para apontar pontos de destaque com confiança.',
            ];
        }

        $scored = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $value = $metrics[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $scored[$key] = ['value' => (float) $value, 'score' => $this->healthScore((float) $value, $definition)];
        }

        if (count($scored) < 1) {
            return ['critical' => null, 'stable' => null, 'note' => null];
        }

        uasort($scored, fn ($a, $b) => $a['score'] <=> $b['score']);
        $worstKey = array_key_first($scored);
        $bestKey = array_key_last($scored);

        return [
            'critical' => $this->describe($worstKey, $scored[$worstKey], true),
            'stable' => $bestKey !== $worstKey ? $this->describe($bestKey, $scored[$bestKey], false) : null,
            'note' => null,
        ];
    }

    private function healthScore(float $value, array $definition): float
    {
        $warn = $definition['warn'];
        $target = $definition['target'];
        $span = abs($target - $warn) ?: 1.0;
        $progress = $definition['higher_is_better'] ? ($value - $warn) / $span : ($warn - $value) / $span;

        return max(0.0, min(1.0, $progress));
    }

    private function describe(string $key, array $entry, bool $isCritical): array
    {
        $definition = self::DEFINITIONS[$key];
        $formatted = $definition['unit'] === '%' ? round($entry['value'], 1).'%' : round($entry['value'], 2);

        return [
            'metric' => $key,
            'label' => $definition['label'],
            'value' => round($entry['value'], 2),
            'unit' => $definition['unit'],
            'message' => $isCritical
                ? "{$definition['label']} está em {$formatted} — é o que mais precisa de atenção agora."
                : "{$definition['label']} está em {$formatted} — o ponto mais consistente do fluxo agora.",
        ];
    }
}
