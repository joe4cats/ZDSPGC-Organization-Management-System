<?php
/**
 * Chart.php — chart helpers for the dashboards.
 *
 * Dashboards render their charts with Chart.js. The configuration is produced
 * here (server side) and handed to assets/js/charts.js through data attributes,
 * so chart data is never assembled by hand inside a <script> block.
 *
 * When Chart.js cannot be loaded (offline campus network) charts.js draws the
 * same values as plain accessible bars, and Chart::barList() renders that same
 * fallback server side for print layouts.
 */

declare(strict_types=1);

final class Chart
{
    public const CDN = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js';

    /** Green-first institutional palette (soft, not saturated). */
    public static function palette(): array
    {
        return ['#166534', '#22c55e', '#0ea5e9', '#f59e0b', '#8b5cf6', '#14b8a6', '#ef4444', '#64748b'];
    }

    /** Emits the Chart.js script tag (deferred, loaded once per page). */
    public static function scriptTag(): string
    {
        return '<script src="' . Helpers::e(self::CDN) . '" defer></script>';
    }

    /**
     * Builds one dataset definition.
     *
     * @param array<int,int|float|string> $values
     * @return array<string,mixed>
     */
    public static function dataset(string $label, array $values, string $color = '', string $type = ''): array
    {
        $palette = self::palette();
        $color   = $color !== '' ? $color : $palette[0];

        return [
            'label'               => $label,
            'data'                => array_values($values),
            'type'                => $type !== '' ? $type : null,
            'backgroundColor'     => $color,
            'borderColor'         => $color,
            'borderWidth'         => $type === 'line' ? 2 : 0,
            'borderRadius'        => 6,
            'maxBarThickness'     => 46,
            'tension'             => 0.35,
            'fill'                => false,
            'pointRadius'         => 3,
            'pointBackgroundColor' => $color,
        ];
    }

    /**
     * Complete chart configuration as JSON (type, labels, datasets, options).
     *
     * @param array<int,string>              $labels
     * @param array<int,array<string,mixed>> $datasets
     */
    public static function config(string $type, array $labels, array $datasets, array $options = []): string
    {
        $defaults = [
            'responsive'          => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend'  => ['display' => count($datasets) > 1, 'labels' => ['boxWidth' => 12, 'font' => ['size' => 12]]],
                'tooltip' => ['enabled' => true],
            ],
        ];

        if ($type === 'bar' || $type === 'line') {
            $defaults['scales'] = [
                'x' => ['grid' => ['display' => false], 'ticks' => ['font' => ['size' => 11]]],
                'y' => ['beginAtZero' => true, 'grid' => ['color' => '#e8eeea'], 'ticks' => ['precision' => 0, 'font' => ['size' => 11]]],
            ];
        } elseif ($type === 'doughnut') {
            $defaults['cutout'] = '62%';
        }

        return (string) json_encode(
            ['type' => $type, 'data' => ['labels' => $labels, 'datasets' => $datasets], 'options' => array_replace_recursive($defaults, $options)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Renders a chart card: <canvas> plus its configuration and an accessible
     * fallback table for screen readers, printing and offline use.
     *
     * @param array<int,string>              $labels
     * @param array<int,array<string,mixed>> $datasets
     */
    public static function card(string $id, string $title, string $type, array $labels, array $datasets, string $height = '260px', string $subtitle = ''): string
    {
        $config  = self::config($type, $labels, $datasets);
        $titleId = $id . '-title';

        $head = '<div class="card-head"><h3 id="' . Helpers::e($titleId) . '">' . Helpers::e($title) . '</h3>'
            . ($subtitle !== '' ? '<p class="muted small">' . Helpers::e($subtitle) . '</p>' : '')
            . '</div>';

        $canvas = '<div class="chart-box" style="height:' . Helpers::e($height) . '">'
            . '<canvas id="' . Helpers::e($id) . '" role="img" aria-labelledby="' . Helpers::e($titleId) . '"></canvas>'
            . '<div class="chart-fallback" hidden>' . self::table($labels, $datasets) . '</div>'
            . '</div>';

        $data = '<div class="chart-data" hidden data-chart="' . Helpers::e($config) . '"></div>';

        return '<section class="card chart-card">' . $head . $canvas . $data . '</section>';
    }

    /**
     * Server-side bar list — the same values without JavaScript. Used in print
     * layouts and as the offline fallback.
     *
     * @param array<string,int|float> $pairs label => value
     */
    public static function barList(array $pairs, string $valueSuffix = '', string $colour = 'green'): string
    {
        if ($pairs === []) {
            return '<p class="empty">No data yet.</p>';
        }
        $max = max(1.0, (float) max($pairs));
        $out = '<ul class="bar-list">';
        foreach ($pairs as $label => $value) {
            $pct = round(((float) $value / $max) * 100, 1);
            $out .= '<li><span class="bar-label" title="' . Helpers::e((string) $label) . '">' . Helpers::e((string) $label) . '</span>'
                . '<span class="bar-track"><span class="bar-fill ' . Helpers::e($colour) . '" style="width:' . $pct . '%"></span></span>'
                . '<span class="bar-value">' . Helpers::e((string) (int) $value) . Helpers::e($valueSuffix) . '</span></li>';
        }
        return $out . '</ul>';
    }

    /**
     * Accessible data table used as the no-JavaScript fallback.
     *
     * @param array<int,string>              $labels
     * @param array<int,array<string,mixed>> $datasets
     */
    public static function table(array $labels, array $datasets): string
    {
        $out = '<table class="tbl compact"><thead><tr><th>Label</th>';
        foreach ($datasets as $dataset) {
            $out .= '<th>' . Helpers::e((string) ($dataset['label'] ?? '')) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach (array_values($labels) as $row => $label) {
            $out .= '<tr><td>' . Helpers::e((string) $label) . '</td>';
            foreach ($datasets as $dataset) {
                $values = (array) ($dataset['data'] ?? []);
                $value  = $values[$row] ?? 0;
                $out   .= '<td class="num">' . Helpers::e(is_numeric($value) ? (string) (int) $value : (string) $value) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table>';
    }
}
