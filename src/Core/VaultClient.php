<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Core;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Чтение Vault для мигратора: список коробок и секрет сервиса в коробке (KV v2).
 *
 * Вход — только userpass, как у самих сервисов при чтении секретов коробок.
 * Токен живёт в объекте весь процесс; на 403 клиент один раз входит заново и
 * повторяет запрос: обход 20–50 коробок может длиться дольше жизни токена, и
 * без повтора все оставшиеся коробки упали бы разом.
 */
final class VaultClient
{
    private readonly ClientInterface $http;

    private ?string $token = null;

    public function __construct(private readonly VaultConfig $config, ?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client();
    }

    /**
     * Коробки — каталоги верхнего уровня (ключи с «/»); ключи-листья коробками не бывают.
     * 404 — пути ещё нет, коробок ноль.
     *
     * @return list<string>
     */
    public function listBoxes(): array
    {
        $display = $this->config->mount.'/metadata'
            .($this->config->boxesPrefix === '' ? '' : '/'.$this->config->boxesPrefix);
        $encoded = $this->config->mount.'/metadata'
            .($this->config->boxesPrefix === '' ? '' : '/'.self::encodePath($this->config->boxesPrefix));

        $response = $this->send('LIST', $encoded, $display);

        if ($response->getStatusCode() === 404) {
            return [];
        }

        $keys = $this->decode($response, 'LIST', $display)['data']['keys'] ?? [];

        $boxes = [];
        foreach (is_array($keys) ? $keys : [] as $key) {
            if (is_string($key) && str_ends_with($key, '/') && rtrim($key, '/') !== '') {
                $boxes[] = rtrim($key, '/');
            }
        }
        sort($boxes, SORT_STRING);

        return $boxes;
    }

    /**
     * Содержимое data.data секрета сервиса в коробке; null — секрета нет (404).
     *
     * @return array<string, mixed>|null
     */
    public function readSecret(string $box, string $serviceKey): ?array
    {
        $display = $this->secretPath($box, $serviceKey);
        $encoded = $this->config->mount.'/data/'.rawurlencode($box).'/'.rawurlencode($serviceKey);

        $response = $this->send('GET', $encoded, $display);

        if ($response->getStatusCode() === 404) {
            return null;
        }

        $data = $this->decode($response, 'GET', $display)['data']['data'] ?? null;

        if (! is_array($data)) {
            throw new VaultException("Vault: в ответе на GET {$display} нет data.data");
        }

        return $data;
    }

    /** Путь секрета в том виде, в каком его ищут глазами в Vault. */
    public function secretPath(string $box, string $serviceKey): string
    {
        return "{$this->config->mount}/data/{$box}/{$serviceKey}";
    }

    private function send(string $method, string $encodedPath, string $displayPath): ResponseInterface
    {
        $response = $this->request($method, $encodedPath, $displayPath, $this->token());

        if ($response->getStatusCode() === 403) {
            $this->token = null;
            $response = $this->request($method, $encodedPath, $displayPath, $this->token());
        }

        $status = $response->getStatusCode();
        if ($status !== 404 && ($status < 200 || $status >= 300)) {
            throw VaultException::status($method, $displayPath, $status);
        }

        return $response;
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $display = "auth/userpass/login/{$this->config->login}";
        $response = $this->request('POST', 'auth/userpass/login/'.rawurlencode($this->config->login), $display, null, [
            'json' => ['password' => $this->config->password],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw VaultException::status('POST', $display, $response->getStatusCode());
        }

        $token = $this->decode($response, 'POST', $display)['auth']['client_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new VaultException("Vault: в ответе на POST {$display} нет client_token");
        }

        return $this->token = $token;
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $encodedPath, string $displayPath, ?string $token, array $options = []): ResponseInterface
    {
        $options += [
            'http_errors' => false,
            'timeout' => $this->config->timeoutSeconds,
            'connect_timeout' => $this->config->timeoutSeconds,
            'headers' => $token === null ? [] : ['X-Vault-Token' => $token],
        ];

        try {
            return $this->http->request($method, "{$this->config->url}/v1/{$encodedPath}", $options);
        } catch (GuzzleException) {
            // Текст исключения Guzzle несёт куски запроса и ответа — наружу только метод и путь.
            throw VaultException::unreachable($method, $displayPath);
        }
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response, string $method, string $displayPath): array
    {
        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body)) {
            throw new VaultException("Vault: ответ на {$method} {$displayPath} — не JSON");
        }

        return $body;
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
