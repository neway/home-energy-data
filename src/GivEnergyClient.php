<?php

declare(strict_types=1);

final class GivEnergyClient
{
    public function __construct(
        private string $apiKey,
        private int $requestDelayMs = 300,
        private int $maxRetries = 5
    ) {}

    public function fetchDataPointsForDay(string $inverterSerial, string $date): array
    {
        $page = 1;
        $pageSize = 200;
        $allData = [];
        $meta = [];

        do {
            $url = $this->buildDataPointsUrl($inverterSerial, $date, $page, $pageSize);
            $response = $this->request('GET', $url);
            $json = $this->decodeDataPointsResponse($response['body'], $date, $page);

            $allData = array_merge($allData, $json['data']);
            $meta = $json['meta'] ?? [];
            $next = $json['links']['next'] ?? null;
            $page++;

            if ($next) {
                $this->sleepMs($this->requestDelayMs);
            }
        } while ($next);

        return ['data' => $allData, 'meta' => $meta];
    }

    public function fetchEnergyFlows(string $inverterSerial, string $startDate, string $endDate, int $grouping = 1): array
    {
        $url = sprintf('/inverter/%s/energy-flows', rawurlencode($inverterSerial));

        $body = [
            'start_time' => $startDate,
            'end_time' => $endDate,
            'grouping' => $grouping,
            'types' => [0, 1, 2, 3, 4, 5, 6], // All energy flow types
        ];

        fwrite(STDOUT, "  Energy flows request (grouping={$grouping}): {$startDate} to {$endDate}\n");

        $response = $this->request('POST', $url, $body);
        $json = $this->decodeEnergyFlowsResponse($response['body'], $startDate, $endDate, $grouping);

        return $json;
    }

    private function decodeEnergyFlowsResponse(string $body, string $startDate, string $endDate, int $grouping): array
    {
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json['data'])) {
            fwrite(STDERR, "Energy flows API Response: " . json_encode(array_keys($json), JSON_PRETTY_PRINT) . "\n");
            fwrite(STDERR, "Full response: " . substr($body, 0, 2000) . "\n");
            throw new RuntimeException("Unexpected energy flows response format for {$startDate} to {$endDate}");
        }

        // Debug output - show raw response structure
        if (empty($json['data'])) {
            fwrite(STDERR, "WARNING: Energy flows API returned empty data array\n");
            fwrite(STDERR, "Full response: " . $body . "\n");
        } else {
            $count = count($json['data']);
            fwrite(STDOUT, "  Energy flows API returned {$count} data series\n");

            // For grouping=2 (daily), show all periods returned
            if ($grouping === 1 && $count > 0 && false) {
                fwrite(STDOUT, "  Daily summary periods:\n");
                foreach ($json['data'] as $idx => $period) {
                    $start = $period['start_time'] ?? 'N/A';
                    $end = $period['end_time'] ?? 'N/A';
                    fwrite(STDOUT, "    [{$idx}] {$start} to {$end}\n");
                }

                // Dump the full JSON for daily grouping to see the actual structure
                fwrite(STDOUT, "\n  === RAW DAILY JSON RESPONSE ===\n");
                fwrite(STDOUT, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                fwrite(STDOUT, "  === END RAW JSON ===\n\n");
            }
        }

        return $json;
    }
    private function buildDataPointsUrl(string $inverterSerial, string $date, int $page, int $pageSize): string
    {
        return sprintf(
            '/inverter/%s/data-points/%s?page=%d&pageSize=%d',
            rawurlencode($inverterSerial),
            rawurlencode($date),
            $page,
            $pageSize
        );
    }

    private function decodeDataPointsResponse(string $body, string $date, int $page): array
    {
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($json['data']) || !is_array($json['data'])) {
            fwrite(STDERR, "API Response structure: " . json_encode(array_keys($json), JSON_PRETTY_PRINT) . "\n");
            fwrite(STDERR, "Sample data: " . substr($body, 0, 500) . "\n");
            throw new RuntimeException("Unexpected response format on {$date}, page {$page}");
        }

        return $json;
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $attempt = 0;
        $url = API_BASE . $path;

        while (true) {
            $attempt++;
            $result = $this->executeRequest($url, $method, $body);

            if ($result['errno'] !== 0) {
                $this->handleCurlError($result['error'], $attempt);
                continue;
            }

            if ($this->isRetryableStatus($result['status'])) {
                $this->handleRetryableStatus($result, $attempt);
                continue;
            }

            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new RuntimeException("HTTP {$result['status']}: {$result['body']}");
            }

            if ($this->requestDelayMs > 0) {
                $this->sleepMs($this->requestDelayMs);
            }

            return ['status' => $result['status'], 'headers' => $result['headers'], 'body' => $result['body']];
        }
    }

    private function executeRequest(string $url, string $method, ?array $body = null): array
    {
        $headers = [];
        $ch = curl_init($url);

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => $this->createHeaderCallback($headers),
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $result = [
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => (string)$responseBody,
        ];

        curl_close($ch);
        return $result;
    }

    private function createHeaderCallback(array &$headers): callable
    {
        return static function ($curl, string $headerLine) use (&$headers): int {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);

            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $len;
        };
    }

    private function buildHeaders(): array
    {
        return [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: home-energy-toolset/1.0',
        ];
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function handleCurlError(string $error, int $attempt): void
    {
        if ($attempt > $this->maxRetries) {
            throw new RuntimeException("cURL error after {$attempt} attempts: {$error}");
        }
        $this->sleepMs($this->backoffMs($attempt));
    }

    private function handleRetryableStatus(array $result, int $attempt): void
    {
        if ($attempt > $this->maxRetries) {
            throw new RuntimeException("HTTP {$result['status']} after {$attempt} attempts: {$result['body']}");
        }

        $retryMs = $this->calculateRetryDelay($result['headers'], $attempt);
        $remaining = $result['headers']['x-ratelimit-remaining'] ?? null;

        fwrite(STDERR, "Retryable HTTP {$result['status']}; remaining={$remaining}; sleeping {$retryMs}ms\n");
        $this->sleepMs($retryMs);
    }

    private function calculateRetryDelay(array $headers, int $attempt): int
    {
        if (isset($headers['retry-after'])) {
            return (int)$headers['retry-after'] * 1000;
        }

        return $this->backoffMs($attempt);
    }

    private function backoffMs(int $attempt): int
    {
        $base = min(30000, 500 * (2 ** ($attempt - 1)));
        return $base + random_int(0, 500);
    }

    private function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}