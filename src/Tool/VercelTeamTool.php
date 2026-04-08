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
 * Team and user info tool — read-only access to current user, team details, and members.
 *
 * Actions: user_info, team_info, list_members
 */
final class VercelTeamTool implements ToolInterface
{
    public function __construct(
        private readonly VercelClient $client,
    ) {}

    public function name(): string
    {
        return 'vercel_team';
    }

    public function description(): string
    {
        return 'Get current user info, team details, or list team members on Vercel. Read-only operations.';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['user_info', 'team_info', 'list_members'],
                required: true,
            ),
            new StringParameter('team_id', 'Team ID or slug (optional — defaults to configured VERCEL_TEAM_ID)'),
            new StringParameter('search', 'Search term to filter members (for list_members)'),
            new NumberParameter('limit', 'Maximum results to return (for list_members)', required: false, integer: true, minimum: 1, maximum: 100),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        try {
            return match ($action) {
                'user_info' => $this->getUserInfo(),
                'team_info' => $this->getTeamInfo($input),
                'list_members' => $this->listMembers($input),
                default => ToolResult::error("Unknown action: {$action}. Valid actions: user_info, team_info, list_members"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error("vercel_team({$action}) failed: {$e->getMessage()}");
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

    private function getUserInfo(): ToolResult
    {
        $data = $this->client->get('/v2/user');

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function getTeamInfo(array $input): ToolResult
    {
        $teamId = $input['team_id'] ?? '';

        // If no team_id provided, the client's configured teamId will be used via query param
        if ($teamId !== '') {
            $data = $this->client->get("/v2/teams/{$teamId}");
        } else {
            // List teams and return the first/current one
            $data = $this->client->get('/v2/teams');
        }

        return ToolResult::success($this->encode($data));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function listMembers(array $input): ToolResult
    {
        $teamId = $input['team_id'] ?? '';

        if ($teamId === '') {
            return ToolResult::error('team_id is required for the "list_members" action (or configure VERCEL_TEAM_ID).');
        }

        $query = [];

        if (isset($input['search']) && $input['search'] !== '') {
            $query['search'] = $input['search'];
        }
        if (isset($input['limit'])) {
            $query['limit'] = (int) $input['limit'];
        }

        $data = $this->client->get("/v2/teams/{$teamId}/members", $query);

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
