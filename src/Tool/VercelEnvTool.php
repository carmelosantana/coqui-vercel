<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitVercel\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitVercel\VercelClient;

/**
 * Environment variable management tool — CRUD operations on project env vars.
 *
 * Actions: list, get, create, update, delete
 * Security: Secret/sensitive variable values are never returned in plain text.
 */
final class VercelEnvTool implements ToolInterface
{
    public function __construct(
        private readonly VercelClient $client,
    ) {}

    public function name(): string
    {
        return 'vercel_env';
    }

    public function description(): string
    {
        return 'Manage environment variables for Vercel projects. Create, list, get, update, or delete env vars. Secret values are never exposed.';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['list', 'get', 'create', 'update', 'delete'],
                required: true,
            ),
            new StringParameter('project_id', 'Project ID or name (required for all actions)'),
            new StringParameter('env_id', 'Environment variable ID (required for get, update, delete)'),
            new StringParameter('key', 'Variable name (required for create)'),
            new StringParameter('value', 'Variable value (required for create, optional for update)'),
            new EnumParameter(
                'target',
                'Target environment(s) — comma-separated for multiple (e.g. "production,preview")',
                ['production', 'preview', 'development'],
            ),
            new EnumParameter(
                'type',
                'Variable type',
                ['plain', 'secret', 'encrypted', 'sensitive'],
            ),
            new StringParameter('git_branch', 'Git branch to scope the variable to (optional)'),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        try {
            return match ($action) {
                'list' => $this->listEnvVars($input),
                'get' => $this->getEnvVar($input),
                'create' => $this->createEnvVar($input),
                'update' => $this->updateEnvVar($input),
                'delete' => $this->deleteEnvVar($input),
                default => ToolResult::error("Unknown action: {$action}. Valid actions: list, get, create, update, delete"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error("vercel_env({$action}) failed: {$e->getMessage()}");
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
    private function listEnvVars(array $input): ToolResult
    {
        $projectId = $input['project_id'] ?? '';

        if ($projectId === '') {
            return ToolResult::error('project_id is required for the "list" action.');
        }

        $data = $this->client->get("/v10/projects/{$projectId}/env");

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function getEnvVar(array $input): ToolResult
    {
        $projectId = $input['project_id'] ?? '';
        $envId = $input['env_id'] ?? '';

        if ($projectId === '' || $envId === '') {
            return ToolResult::error('Both "project_id" and "env_id" are required for the "get" action.');
        }

        $data = $this->client->get("/v10/projects/{$projectId}/env/{$envId}");

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createEnvVar(array $input): ToolResult
    {
        $projectId = $input['project_id'] ?? '';
        $key = $input['key'] ?? '';
        $value = $input['value'] ?? '';

        if ($projectId === '' || $key === '') {
            return ToolResult::error('Both "project_id" and "key" are required for the "create" action.');
        }

        $body = [
            'key' => $key,
            'value' => $value,
            'type' => $input['type'] ?? 'encrypted',
            'target' => $this->parseTargets($input['target'] ?? 'production'),
        ];

        if (isset($input['git_branch']) && $input['git_branch'] !== '') {
            $body['gitBranch'] = $input['git_branch'];
        }

        $data = $this->client->post("/v10/projects/{$projectId}/env", $body);

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function updateEnvVar(array $input): ToolResult
    {
        $projectId = $input['project_id'] ?? '';
        $envId = $input['env_id'] ?? '';

        if ($projectId === '' || $envId === '') {
            return ToolResult::error('Both "project_id" and "env_id" are required for the "update" action.');
        }

        $body = [];

        if (isset($input['value']) && $input['value'] !== '') {
            $body['value'] = $input['value'];
        }
        if (isset($input['target']) && $input['target'] !== '') {
            $body['target'] = $this->parseTargets($input['target']);
        }
        if (isset($input['type']) && $input['type'] !== '') {
            $body['type'] = $input['type'];
        }
        if (isset($input['git_branch']) && $input['git_branch'] !== '') {
            $body['gitBranch'] = $input['git_branch'];
        }

        if ($body === []) {
            return ToolResult::error('At least one field (value, target, type, git_branch) must be provided for update.');
        }

        $data = $this->client->patch("/v10/projects/{$projectId}/env/{$envId}", $body);

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function deleteEnvVar(array $input): ToolResult
    {
        $projectId = $input['project_id'] ?? '';
        $envId = $input['env_id'] ?? '';

        if ($projectId === '' || $envId === '') {
            return ToolResult::error('Both "project_id" and "env_id" are required for the "delete" action.');
        }

        $data = $this->client->delete("/v10/projects/{$projectId}/env/{$envId}");

        return ToolResult::success($this->encode($data));
    }

    /**
     * Parse comma-separated target string into an array.
     *
     * @return array<int, string>
     */
    private function parseTargets(string $target): array
    {
        return array_map('trim', explode(',', $target));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
