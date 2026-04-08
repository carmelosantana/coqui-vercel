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
 * Deployment management tool — list, inspect, create (redeploy), delete, and cancel deployments.
 *
 * Actions: list, get, create, delete, cancel
 */
final class VercelDeploymentTool implements ToolInterface
{
    public function __construct(
        private readonly VercelClient $client,
    ) {}

    public function name(): string
    {
        return 'vercel_deployment';
    }

    public function description(): string
    {
        return 'Manage Vercel deployments. List, get details, trigger redeploys, delete, or cancel deployments.';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['list', 'get', 'create', 'delete', 'cancel'],
                required: true,
            ),
            new StringParameter('deployment_id', 'Deployment ID or URL (required for get, delete, cancel)'),
            new StringParameter('project_id', 'Project ID or name (required for create, optional for list to filter)'),
            new StringParameter('name', 'Project name (required for create — must match the project name)'),
            new EnumParameter(
                'target',
                'Deployment target environment (for create, or filter for list)',
                ['production', 'preview', 'staging'],
            ),
            new EnumParameter(
                'state',
                'Filter deployments by state (for list)',
                ['BUILDING', 'ERROR', 'INITIALIZING', 'QUEUED', 'READY', 'CANCELED'],
            ),
            new NumberParameter('limit', 'Maximum results to return (for list)', required: false, integer: true, minimum: 1, maximum: 100),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        try {
            return match ($action) {
                'list' => $this->listDeployments($input),
                'get' => $this->getDeployment($input),
                'create' => $this->createDeployment($input),
                'delete' => $this->deleteDeployment($input),
                'cancel' => $this->cancelDeployment($input),
                default => ToolResult::error("Unknown action: {$action}. Valid actions: list, get, create, delete, cancel"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error("vercel_deployment({$action}) failed: {$e->getMessage()}");
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
    private function listDeployments(array $input): ToolResult
    {
        $query = [];

        if (isset($input['project_id']) && $input['project_id'] !== '') {
            $query['projectId'] = $input['project_id'];
        }
        if (isset($input['target']) && $input['target'] !== '') {
            $query['target'] = $input['target'];
        }
        if (isset($input['state']) && $input['state'] !== '') {
            $query['state'] = $input['state'];
        }
        if (isset($input['limit'])) {
            $query['limit'] = (int) $input['limit'];
        }

        $data = $this->client->get('/v6/deployments', $query);

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function getDeployment(array $input): ToolResult
    {
        $id = $input['deployment_id'] ?? '';

        if ($id === '') {
            return ToolResult::error('deployment_id is required for the "get" action.');
        }

        $data = $this->client->get("/v13/deployments/{$id}");

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createDeployment(array $input): ToolResult
    {
        $name = $input['name'] ?? '';
        $projectId = $input['project_id'] ?? '';

        if ($name === '') {
            return ToolResult::error('"name" is required for the "create" action (must match the project name).');
        }

        $body = ['name' => $name];

        if ($projectId !== '') {
            $body['project'] = $projectId;
        }

        if (isset($input['target']) && $input['target'] !== '') {
            $body['target'] = $input['target'];
        }

        if (isset($input['deployment_id']) && $input['deployment_id'] !== '') {
            $body['deploymentId'] = $input['deployment_id'];
        }

        $data = $this->client->post('/v13/deployments', $body);

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function deleteDeployment(array $input): ToolResult
    {
        $id = $input['deployment_id'] ?? '';

        if ($id === '') {
            return ToolResult::error('deployment_id is required for the "delete" action.');
        }

        $data = $this->client->delete("/v13/deployments/{$id}");

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function cancelDeployment(array $input): ToolResult
    {
        $id = $input['deployment_id'] ?? '';

        if ($id === '') {
            return ToolResult::error('deployment_id is required for the "cancel" action.');
        }

        $data = $this->client->patch("/v12/deployments/{$id}/cancel");

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
