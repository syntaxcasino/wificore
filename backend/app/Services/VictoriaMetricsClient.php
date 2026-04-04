<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VictoriaMetricsClient
{
    public function queryInstant(string $promql, ?int $time = null): array
    {
        $baseUrl = $this->getBaseUrl();
        $endpoint = rtrim($baseUrl, '/') . '/api/v1/query';
        $params = ['query' => $promql];

        if ($time !== null) {
            $params['time'] = $time;
        }

        Log::debug('VictoriaMetrics instant query', [
            'endpoint' => $endpoint,
            'query' => $promql,
        ]);

        $queryString = http_build_query([
            'query' => $promql,
            'time' => $time,
        ]);
        $url = $endpoint . '?' . $queryString;

        Log::debug('VictoriaMetrics instant query URL', [
            'url' => $url,
        ]);

        $response = $this->http()->get($url);

        if (!$response->successful()) {
            $bodySnippet = $this->truncateBody($response->body());

            Log::error('VictoriaMetrics instant query failed', [
                'status' => $response->status(),
                'query' => $promql,
                'endpoint' => $endpoint,
                'body' => $bodySnippet,
            ]);

            throw new \RuntimeException(
                'VictoriaMetrics instant query failed: ' . $response->status() .
                ' query=' . $promql .
                ' body=' . $bodySnippet
            );
        }

        return (array) $response->json();
    }

    public function queryRange(string $promql, int $start, int $end, string $step): array
    {
        $baseUrl = $this->getBaseUrl();
        $endpoint = rtrim($baseUrl, '/') . '/api/v1/query_range';

        Log::debug('VictoriaMetrics range query', [
            'endpoint' => $endpoint,
            'query' => $promql,
            'start' => $start,
            'end' => $end,
            'step' => $step,
        ]);

        // Debug: log the actual HTTP request params
        $requestParams = [
            'query' => $promql,
            'start' => $start,
            'end' => $end,
            'step' => $step,
        ];
        Log::debug('VictoriaMetrics request params', $requestParams);

        $queryString = http_build_query([
            'query' => $promql,
            'start' => $start,
            'end' => $end,
            'step' => $step,
        ]);
        $url = $endpoint . '?' . $queryString;

        Log::debug('VictoriaMetrics range query URL', [
            'url' => $url,
        ]);

        $response = $this->http()->get($url);

        if (!$response->successful()) {
            $bodySnippet = $this->truncateBody($response->body());

            Log::error('VictoriaMetrics range query failed', [
                'status' => $response->status(),
                'query' => $promql,
                'endpoint' => $endpoint,
                'body' => $bodySnippet,
            ]);

            throw new \RuntimeException(
                'VictoriaMetrics range query failed: ' . $response->status() .
                ' query=' . $promql .
                ' body=' . $bodySnippet
            );
        }

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::timeout(config('victoriametrics.http_timeout', 5));
    }

    private function getBaseUrl(): string
    {
        $explicit = (string) config('victoriametrics.query_url', '');
        if ($explicit !== '') {
            return $explicit;
        }

        $writeUrl = (string) config('victoriametrics.write_url', 'http://wificore-victoriametrics:8428');
        $parts = parse_url($writeUrl);

        if (!is_array($parts)) {
            return 'http://wificore-victoriametrics:8428';
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

    private function truncateBody(string $body): string
    {
        if (strlen($body) > 800) {
            return substr($body, 0, 800) . '...';
        }
        return $body;
    }
}
