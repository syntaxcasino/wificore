<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class VictoriaMetricsClient
{
    public function queryInstant(string $promql, ?int $time = null): array
    {
        $baseUrl = $this->getBaseUrl();
        $params = ['query' => $promql];

        if ($time !== null) {
            $params['time'] = $time;
        }

        $response = $this->http()
            ->get(rtrim($baseUrl, '/') . '/api/v1/query', $params);

        if (!$response->successful()) {
            $body = $response->body();
            $bodySnippet = $body;
            if (is_string($bodySnippet) && strlen($bodySnippet) > 800) {
                $bodySnippet = substr($bodySnippet, 0, 800) . '...';
            }

            throw new \RuntimeException(
                'VictoriaMetrics instant query failed: ' . $response->status() .
                ' query=' . $promql .
                ' body=' . (string) $bodySnippet
            );
        }

        return (array) $response->json();
    }

    public function queryRange(string $promql, int $start, int $end, string $step): array
    {
        $baseUrl = $this->getBaseUrl();

        $response = $this->http()
            ->get(rtrim($baseUrl, '/') . '/api/v1/query_range', [
                'query' => $promql,
                'start' => $start,
                'end' => $end,
                'step' => $step,
            ]);

        if (!$response->successful()) {
            $body = $response->body();
            $bodySnippet = $body;
            if (is_string($bodySnippet) && strlen($bodySnippet) > 800) {
                $bodySnippet = substr($bodySnippet, 0, 800) . '...';
            }

            throw new \RuntimeException(
                'VictoriaMetrics range query failed: ' . $response->status() .
                ' query=' . $promql .
                ' body=' . (string) $bodySnippet
            );
        }

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::timeout((float) env('VICTORIA_METRICS_HTTP_TIMEOUT', 5));
    }

    private function getBaseUrl(): string
    {
        $explicit = (string) env('VICTORIA_METRICS_QUERY_URL', '');
        if ($explicit !== '') {
            return $explicit;
        }

        $writeUrl = (string) env('VICTORIA_METRICS_WRITE_URL', 'http://wificore-nginx/internal/vm/api/v1/write');
        $parts = parse_url($writeUrl);

        if (!is_array($parts)) {
            return 'http://wificore-nginx/internal/vm';
        }

        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? 'wificore-victoriametrics';
        $port = isset($parts['port']) ? (':' . $parts['port']) : '';

        $path = $parts['path'] ?? '';
        $basePath = '';
        if ($path !== '') {
            $basePath = preg_replace('#/api/v1/write$#', '', $path);
        }

        return rtrim($scheme . '://' . $host . $port . $basePath, '/');
    }
}
