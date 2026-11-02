<?php

declare(strict_types=1);

/*
 * Shelly Cloud API (the app's "Authorization cloud key" and server). The cloud allows about one request a second, so
 * every call waits until REQUEST_GAP_SECONDS after the last.
 */
final class ShellyClient
{
    private const REQUEST_GAP_SECONDS = 1.1;
    private const MAX_RETRIES = 3;

    private float $lastRequest = 0.0;

    public function __construct(private string $server, private string $authKey) {}

    /** Every device and channel: [id, channel, name, model], from the app's device list ("id_1" is channel 1). */
    public function devices(): array
    {
        $devices = [];
        foreach ($this->call('POST', 'interface/device/get_all_lists')['data']['devices'] as $key => $d) {
            [$id, $channel] = array_pad(explode('_', (string)$key, 2), 2, '0');
            $devices[] = ['id' => $id, 'channel' => (int)$channel, 'name' => (string)$d['name'], 'model' => (string)$d['type']];
        }
        return $devices;
    }

    /** Live status of every device, by id. */
    public function allStatus(): array
    {
        return $this->call('POST', 'device/all_status')['data']['devices_status'];
    }

    /** One local day of hourly energy (Wh) for a power meter: ['timezone' => ..., 'history' => [...]]. */
    public function hourlyEnergy(string $id, int $channel, string $date): array
    {
        return $this->call('GET', 'v2/statistics/power-consumption',
            ['id' => $id, 'channel' => $channel, 'date_range' => 'day', 'date_from' => $date]);
    }

    /** One day of hourly temperature and humidity for a sensor: ['timezone' => ..., 'history' => [...]]. */
    public function hourlySensor(string $id, string $date): array
    {
        return $this->call('GET', 'statistics/sensor/values',
            ['id' => $id, 'channel' => 0, 'date_range' => 'day', 'date_from' => $date])['data'];
    }

    private function call(string $method, string $path, array $params = []): array
    {
        $params['auth_key'] = $this->authKey;
        $url = rtrim($this->server, '/') . "/$path";
        for ($attempt = 1; ; $attempt++) {
            usleep((int)max(0, ($this->lastRequest + self::REQUEST_GAP_SECONDS - microtime(true)) * 1e6));
            $this->lastRequest = microtime(true);

            $ch = curl_init($method === 'GET' ? $url . '?' . http_build_query($params) : $url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $json = is_string($body) ? json_decode($body, true) : null;
            if ($status === 200 && is_array($json) && ($json['isok'] ?? true) !== false) {
                return $json;
            }
            if ($attempt >= self::MAX_RETRIES) {
                throw new RuntimeException("Shelly $path: HTTP $status " . substr((string)$body, 0, 300));
            }
            sleep(5 * $attempt);
        }
    }
}
