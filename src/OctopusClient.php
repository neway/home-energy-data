<?php

declare(strict_types=1);

final class OctopusClient
{
    public function __construct(
        private string $apiKey,
        private int $requestDelayMs = 300,
        private int $maxRetries = 3
    ) {}

    public function fetchElectricityConsumption(string $mpan, string $serial, string $fromDate, string $toDate): array
    {
        [$fromUtc, $toUtc] = $this->convertLocalDatesToUtc($fromDate, $toDate);

        $url = sprintf(
            '/electricity-meter-points/%s/meters/%s/consumption/?period_from=%s&period_to=%s&page_size=25000',
            rawurlencode($mpan),
            rawurlencode($serial),
            $fromUtc,
            $toUtc
        );

        return $this->fetchConsumption($url);
    }

    public function fetchGasConsumption(string $mprn, string $serial, string $fromDate, string $toDate): array
    {
        [$fromUtc, $toUtc] = $this->convertLocalDatesToUtc($fromDate, $toDate);

        $url = sprintf(
            '/gas-meter-points/%s/meters/%s/consumption/?period_from=%s&period_to=%s&page_size=25000',
            rawurlencode($mprn),
            rawurlencode($serial),
            $fromUtc,
            $toUtc
        );

        return $this->fetchConsumption($url);
    }

    private function convertLocalDatesToUtc(string $fromDate, string $toDate): array
    {
        $localTz = new DateTimeZone(DEFAULT_TIMEZONE);
        $utcTz = new DateTimeZone('UTC');

        // Start of day in local time
        $fromLocal = new DateTimeImmutable($fromDate . ' 00:00:00', $localTz);
        $fromUtc = $fromLocal->setTimezone($utcTz);

        // End at 23:30:00 local time (exclusive), which excludes the 23:30-00:00 interval
        // Since period_to is exclusive AND returns overlapping intervals,
        // we need to end exactly at the start of the last interval we DON'T want
        $toLocal = new DateTimeImmutable($toDate . ' 23:30:00', $localTz);
        $toUtc = $toLocal->setTimezone($utcTz);

        return [
            $fromUtc->format('Y-m-d\TH:i:s\Z'),
            $toUtc->format('Y-m-d\TH:i:s\Z')
        ];
    }

    private function fetchConsumption(string $url): array
    {
        $allResults = [];
        $nextUrl = $url;

        do {
            $response = $this->request('GET', $nextUrl);
            $json = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);

            if (!isset($json['results']) || !is_array($json['results'])) {
                throw new RuntimeException("Unexpected Octopus API response format");
            }

            $allResults = array_merge($allResults, $json['results']);
            $nextUrl = $this->extractNextUrl($json['next'] ?? null);

            if ($nextUrl && $this->requestDelayMs > 0) {
                $this->sleepMs($this->requestDelayMs);
            }
        } while ($nextUrl);

        return $allResults;
    }

    private function extractNextUrl(?string $next): ?string
    {
        if (!$next) {
            return null;
        }

        // Extract path and query from full URL
        $parsed = parse_url($next);
        return ($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    }

    private function request(string $method, string $path): array
    {
        $attempt = 0;
        $url = OCTOPUS_API_BASE . $path;

        while (true) {
            $attempt++;
            $result = $this->executeRequest($url, $method);

            if ($result['errno'] !== 0) {
                $this->handleCurlError($result['error'], $attempt);
                continue;
            }

            if ($this->isRetryableStatus($result['status'])) {
                $this->handleRetryableStatus($result, $attempt);
                continue;
            }

            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new RuntimeException("Octopus API HTTP {$result['status']}: {$result['body']}");
            }

            return ['status' => $result['status'], 'headers' => $result['headers'], 'body' => $result['body']];
        }
    }

    private function executeRequest(string $url, string $method): array
    {
        $headers = [];
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => $this->createHeaderCallback($headers),
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $body = curl_exec($ch);
        $result = [
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => (string)$body,
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
            'Authorization: Basic ' . base64_encode($this->apiKey . ':'),
            'Accept: application/json',
            'User-Agent: Solar-Octopus-Import/1.0',
        ];
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function handleCurlError(string $error, int $attempt): void
    {
        if ($attempt > $this->maxRetries) {
            throw new RuntimeException("Octopus API cURL error after {$attempt} attempts: {$error}");
        }
        $this->sleepMs($this->backoffMs($attempt));
    }

    private function handleRetryableStatus(array $result, int $attempt): void
    {
        if ($attempt > $this->maxRetries) {
            throw new RuntimeException("Octopus API HTTP {$result['status']} after {$attempt} attempts");
        }

        $retryMs = $this->backoffMs($attempt);
        fwrite(STDERR, "Octopus API retryable HTTP {$result['status']}; sleeping {$retryMs}ms\n");
        $this->sleepMs($retryMs);
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