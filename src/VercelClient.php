<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitVercel;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client for the Vercel REST API.
 *
 * Authentication uses a Bearer token sent via the Authorization header.
 * An optional team ID is appended as a `teamId` query parameter on every request.
 *
 * All credential values are resolved lazily from constructor args or getenv(),
 * enabling hot-reload after CredentialTool::set() without restarting.
 */
final class VercelClient
{
    private const string BASE_URL = 'https://api.vercel.com';
    private const int TIMEOUT = 30;

    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $apiToken = '',
        private readonly string $teamId = '',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => self::TIMEOUT]);
    }

    /**
     * Factory — reads all credentials from environment variables.
     */
    public static function fromEnv(): self
    {
        return new self(
            apiToken: self::envString('VERCEL_API_TOKEN'),
            teamId: self::envString('VERCEL_TEAM_ID'),
        );
    }

    /**
     * GET request.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, query: $query);
    }

    /**
     * POST request.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, body: $body);
    }

    /**
     * PUT request.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, body: $body);
    }

    /**
     * PATCH request.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $path, body: $body);
    }

    /**
     * DELETE request.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function delete(string $path, array $query = []): array
    {
        return $this->request('DELETE', $path, query: $query);
    }

    /**
     * Execute an HTTP request against the Vercel API.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
    ): array {
        $token = $this->resolveApiToken();

        if ($token === '') {
            throw new \RuntimeException('VERCEL_API_TOKEN is not configured.');
        }

        $url = self::BASE_URL . $path;

        // Append teamId to every request when configured
        $teamId = $this->resolveTeamId();

        if ($teamId !== '') {
            $query['teamId'] = $teamId;
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        // Filter out empty/null query params
        $filteredQuery = array_filter($query, static fn(mixed $v): bool => $v !== null && $v !== '');

        if ($filteredQuery !== []) {
            $options['query'] = $filteredQuery;
        }

        if ($body !== [] && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent();

            if ($content === '') {
                return ['ok' => true, 'status' => $statusCode];
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return ['ok' => true, 'data' => $data, 'status' => $statusCode];
            }

            return $data;
        } catch (HttpExceptionInterface $e) {
            $errorBody = $this->extractErrorBody($e);
            $code = $e->getResponse()->getStatusCode();

            throw new \RuntimeException(sprintf('Vercel API error (HTTP %d): %s', $code, $errorBody));
        }
    }

    /**
     * Extract a readable error body from an HTTP exception response.
     */
    private function extractErrorBody(HttpExceptionInterface $e): string
    {
        try {
            $body = $e->getResponse()->getContent(false);
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                // Vercel error format: {"error": {"message": "...", "code": "..."}}
                if (isset($decoded['error']) && is_array($decoded['error'])) {
                    return $decoded['error']['message'] ?? $decoded['error']['code'] ?? $body;
                }

                return $decoded['message'] ?? $decoded['error'] ?? $decoded['detail'] ?? $body;
            }

            return mb_substr($body, 0, 500);
        } catch (\Throwable) {
            return $e->getMessage();
        }
    }

    private function resolveApiToken(): string
    {
        if ($this->apiToken !== '') {
            return $this->apiToken;
        }

        return self::envString('VERCEL_API_TOKEN');
    }

    private function resolveTeamId(): string
    {
        if ($this->teamId !== '') {
            return $this->teamId;
        }

        return self::envString('VERCEL_TEAM_ID');
    }

    private static function envString(string $key): string
    {
        $value = getenv($key);

        return $value !== false ? $value : '';
    }
}
