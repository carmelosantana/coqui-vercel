<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitVercel\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitVercel\VercelClient;

/**
 * Domain management tool — list, add, remove, and verify custom domains on projects.
 *
 * Actions: list, get, add, remove, verify
 */
final class VercelDomainTool implements ToolInterface
{
    public function __construct(
        private readonly VercelClient $client,
    ) {}

    public function name(): string
    {
        return 'vercel_domain';
    }

    public function description(): string
    {
        return 'Manage custom domains on Vercel projects. List, add, remove, or verify domain configuration.';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['list', 'get', 'add', 'remove', 'verify'],
                required: true,
            ),
            new StringParameter('project_id', 'Project ID or name (required for list, add, remove)'),
            new StringParameter('domain', 'Domain name (required for add, remove, verify; e.g. "example.com")'),
            new StringParameter('redirect', 'Domain to redirect to (optional, for add — creates a redirect instead of alias)'),
            new StringParameter('redirect_status_code', 'HTTP status code for redirect: 301, 302, or 307 (optional, for add)'),
            new StringParameter('git_branch', 'Git branch to link this domain to (optional, for add)'),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        try {
            return match ($action) {
                'list' => $this->listDomains($input),
                'get' => $this->getDomain($input),
                'add' => $this->addDomain($input),
                'remove' => $this->removeDomain($input),
                'verify' => $this->verifyDomain($input),
                default => ToolResult::error("Unknown action: {$action}. Valid actions: list, get, add, remove, verify"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error("vercel_domain({$action}) failed: {$e->getMessage()}");
        }
    }

    public function toFunctionSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters() as $param) {
            $properties[$param->name] = $param->toSchema();

            if ($param->required) {
                $required[] = $param->name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => empty($properties) ? new \stdClass() : $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => $schema,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function listDomains(array $input): ToolResult
    {
        $projectId = $input['project_id'] ?? '';

        if ($projectId === '') {
            return ToolResult::error('project_id is required for the "list" action.');
        }

        $data = $this->client->get("/v9/projects/{$projectId}/domains");

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function getDomain(array $input): ToolResult
    {
        $domain = $input['domain'] ?? '';

        if ($domain === '') {
            return ToolResult::error('domain is required for the "get" action.');
        }

        $data = $this->client->get("/v5/domains/{$domain}");

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function addDomain(array $input): ToolResult
    {
        $projectId = $input['project_id'] ?? '';
        $domain = $input['domain'] ?? '';

        if ($projectId === '' || $domain === '') {
            return ToolResult::error('Both "project_id" and "domain" are required for the "add" action.');
        }

        $body = ['name' => $domain];

        if (isset($input['redirect']) && $input['redirect'] !== '') {
            $body['redirect'] = $input['redirect'];
        }
        if (isset($input['redirect_status_code']) && $input['redirect_status_code'] !== '') {
            $body['redirectStatusCode'] = (int) $input['redirect_status_code'];
        }
        if (isset($input['git_branch']) && $input['git_branch'] !== '') {
            $body['gitBranch'] = $input['git_branch'];
        }

        $data = $this->client->post("/v10/projects/{$projectId}/domains", $body);

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function removeDomain(array $input): ToolResult
    {
        $projectId = $input['project_id'] ?? '';
        $domain = $input['domain'] ?? '';

        if ($projectId === '' || $domain === '') {
            return ToolResult::error('Both "project_id" and "domain" are required for the "remove" action.');
        }

        $data = $this->client->delete("/v9/projects/{$projectId}/domains/{$domain}");

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function verifyDomain(array $input): ToolResult
    {
        $domain = $input['domain'] ?? '';

        if ($domain === '') {
            return ToolResult::error('domain is required for the "verify" action.');
        }

        $data = $this->client->post("/v10/domains/{$domain}/verify");

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
