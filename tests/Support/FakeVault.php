<?php

declare(strict_types=1);

namespace Mcrm\BoxMigrator\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Mcrm\BoxMigrator\Core\VaultClient;
use Mcrm\BoxMigrator\Core\VaultConfig;
use Psr\Http\Message\RequestInterface;

/**
 * Vault в памяти: отвечает на userpass-login, LIST метаданных и GET секретов KV v2
 * так же, как настоящий, и записывает каждый запрос.
 */
final class FakeVault
{
    /** @var list<string>|null */
    private ?array $boxes = null;

    /** @var array<string, array<string, mixed>> путь «box/service» (url-encoded) => data.data */
    private array $secrets = [];

    /** @var list<array{0: string, 1: string, 2: int}> разовые отказы: метод, окончание пути, код */
    private array $failures = [];

    /** @var list<array{0: string, 1: string, 2: string}> метод, путь, X-Vault-Token */
    private array $log = [];

    public function __construct(private readonly string $token = 't-1')
    {
    }

    /** @param list<string> $keys ключи LIST как их отдаёт Vault (каталоги с «/») */
    public function withBoxes(array $keys): self
    {
        $this->boxes = $keys;

        return $this;
    }

    /** @param array<string, mixed> $data содержимое data.data секрета */
    public function withSecret(string $box, string $serviceKey, array $data): self
    {
        $this->secrets[rawurlencode($box).'/'.rawurlencode($serviceKey)] = $data;

        return $this;
    }

    /** Один раз ответить кодом $status на запрос $method, путь которого кончается на $pathSuffix. */
    public function failNext(string $method, string $pathSuffix, int $status): self
    {
        $this->failures[] = [$method, $pathSuffix, $status];

        return $this;
    }

    public function client(VaultConfig $config): VaultClient
    {
        return new VaultClient($config, new Client(['handler' => HandlerStack::create($this->handler())]));
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public function requests(): array
    {
        return $this->log;
    }

    /** @return callable(RequestInterface, array<string, mixed>): PromiseInterface */
    private function handler(): callable
    {
        return function (RequestInterface $request): PromiseInterface {
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();
            $this->log[] = [$method, $path, $request->getHeaderLine('X-Vault-Token')];

            foreach ($this->failures as $i => [$failMethod, $suffix, $status]) {
                if ($failMethod === $method && str_ends_with($path, $suffix)) {
                    unset($this->failures[$i]);
                    $this->failures = array_values($this->failures);

                    return Create::promiseFor(new Response($status, [], '{"errors":["permission denied"]}'));
                }
            }

            return Create::promiseFor($this->route($method, $path));
        };
    }

    private function route(string $method, string $path): Response
    {
        if ($method === 'POST' && str_contains($path, '/v1/auth/userpass/login/')) {
            return $this->json(['auth' => ['client_token' => $this->token]]);
        }

        if ($method === 'LIST' && str_contains($path, '/metadata')) {
            return $this->boxes === null
                ? new Response(404, [], '{"errors":[]}')
                : $this->json(['data' => ['keys' => $this->boxes]]);
        }

        if ($method === 'GET' && preg_match('#/data/(.+)$#', $path, $m) === 1 && isset($this->secrets[$m[1]])) {
            return $this->json(['data' => ['data' => $this->secrets[$m[1]], 'metadata' => []]]);
        }

        return new Response(404, [], '{"errors":[]}');
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
