<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitVercel\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitVercel\VercelClient;

/**
 * Project management tool — CRUD operations on Vercel projects.
 *
 * Actions: list, get, create, update, delete
 */
final class VercelProjectTool implements ToolInterface
{
    public function __construct(
        private readonly VercelClient $client,
    ) {}

    public function name(): string
    {
        return 'vercel_project';
    }

    public function description(): string
    {
        return 'Manage Vercel projects. Create, list, get details, update settings, or delete projects.';
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
            new StringParameter('project_id', 'Project ID or name (required for get, update, delete)'),
            new StringParameter('name', 'Project name (required for create)'),
            new StringParameter('framework', 'Framework preset (e.g. nextjs, vite, remix, nuxtjs, svelte)'),
            new StringParameter('build_command', 'Custom build command override'),
            new StringParameter('output_directory', 'Custom output directory override'),
            new StringParameter('root_directory', 'Root directory for monorepo setups'),
            new StringParameter('git_repository', 'Git repository URL (for create — links project to repo)'),
            new StringParameter('search', 'Search term to filter projects (for list)'),
            new NumberParameter('limit', 'Maximum results to return (for list)', required: false, integer: true, minimum: 1, maximum: 100),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        try {
            return match ($action) {
                'list' => $this->listProjects($input),
                'get' => $this->getProject($input),
                'create' => $this->createProject($input),
                'update' => $this->updateProject($input),
                'delete' => $this->deleteProject($input),
                default => ToolResult::error("Unknown action: {$action}. Valid actions: list, get, create, update, delete"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error("vercel_project({$action}) failed: {$e->getMessage()}");
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
    private function listProjects(array $input): ToolResult
    {
        $query = [];

        if (isset($input['search']) && $input['search'] !== '') {
            $query['search'] = $input['search'];
        }
        if (isset($input['limit'])) {
            $query['limit'] = (int) $input['limit'];
        }

        $data = $this->client->get('/v10/projects', $query);

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function getProject(array $input): ToolResult
    {
        $id = $input['project_id'] ?? '';

        if ($id === '') {
            return ToolResult::error('project_id is required for the "get" action.');
        }

        $data = $this->client->get("/v10/projects/{$id}");

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createProject(array $input): ToolResult
    {
        $name = $input['name'] ?? '';

        if ($name === '') {
            return ToolResult::error('"name" is required for the "create" action.');
        }

        $body = ['name' => $name];

        if (isset($input['framework']) && $input['framework'] !== '') {
            $body['framework'] = $input['framework'];
        }
        if (isset($input['build_command']) && $input['build_command'] !== '') {
            $body['buildCommand'] = $input['build_command'];
        }
        if (isset($input['output_directory']) && $input['output_directory'] !== '') {
            $body['outputDirectory'] = $input['output_directory'];
        }
        if (isset($input['root_directory']) && $input['root_directory'] !== '') {
            $body['rootDirectory'] = $input['root_directory'];
        }
        if (isset($input['git_repository']) && $input['git_repository'] !== '') {
            $body['gitRepository'] = [
                'repo' => $input['git_repository'],
                'type' => 'github',
            ];
        }

        $data = $this->client->post('/v10/projects', $body);

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function updateProject(array $input): ToolResult
    {
        $id = $input['project_id'] ?? '';

        if ($id === '') {
            return ToolResult::error('project_id is required for the "update" action.');
        }

        $body = [];

        if (isset($input['name']) && $input['name'] !== '') {
            $body['name'] = $input['name'];
        }
        if (isset($input['framework']) && $input['framework'] !== '') {
            $body['framework'] = $input['framework'];
        }
        if (isset($input['build_command']) && $input['build_command'] !== '') {
            $body['buildCommand'] = $input['build_command'];
        }
        if (isset($input['output_directory']) && $input['output_directory'] !== '') {
            $body['outputDirectory'] = $input['output_directory'];
        }
        if (isset($input['root_directory']) && $input['root_directory'] !== '') {
            $body['rootDirectory'] = $input['root_directory'];
        }

        if ($body === []) {
            return ToolResult::error('At least one field must be provided for update.');
        }

        $data = $this->client->patch("/v10/projects/{$id}", $body);

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function deleteProject(array $input): ToolResult
    {
        $id = $input['project_id'] ?? '';

        if ($id === '') {
            return ToolResult::error('project_id is required for the "delete" action.');
        }

        $data = $this->client->delete("/v10/projects/{$id}");

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
