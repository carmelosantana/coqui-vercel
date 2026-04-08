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
 * Log viewing tool — fetch build and runtime logs for deployments.
 *
 * Actions: build_logs, runtime_logs
 * Output is automatically truncated to prevent context overflow.
 */
final class VercelLogTool implements ToolInterface
{
    private const int MAX_LOG_LINES = 200;

    public function __construct(
        private readonly VercelClient $client,
    ) {}

    public function name(): string
    {
        return 'vercel_log';
    }

    public function description(): string
    {
        return 'View build and runtime logs for Vercel deployments. Output is truncated to prevent context overflow.';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The type of logs to retrieve',
                ['build_logs', 'runtime_logs'],
                required: true,
            ),
            new StringParameter('deployment_id', 'Deployment ID or URL (required)'),
            new EnumParameter(
                'direction',
                'Log order direction',
                ['forward', 'backward'],
            ),
            new NumberParameter('limit', 'Maximum log entries to return', required: false, integer: true, minimum: 1, maximum: 500),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        try {
            return match ($action) {
                'build_logs' => $this->getBuildLogs($input),
                'runtime_logs' => $this->getRuntimeLogs($input),
                default => ToolResult::error("Unknown action: {$action}. Valid actions: build_logs, runtime_logs"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error("vercel_log({$action}) failed: {$e->getMessage()}");
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
    private function getBuildLogs(array $input): ToolResult
    {
        $id = $input['deployment_id'] ?? '';

        if ($id === '') {
            return ToolResult::error('deployment_id is required for the "build_logs" action.');
        }

        $query = [];

        if (isset($input['direction']) && $input['direction'] !== '') {
            $query['direction'] = $input['direction'];
        }
        if (isset($input['limit'])) {
            $query['limit'] = (int) $input['limit'];
        }

        $data = $this->client->get("/v3/deployments/{$id}/events", $query);

        return ToolResult::success($this->formatLogs($data, 'build'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function getRuntimeLogs(array $input): ToolResult
    {
        $id = $input['deployment_id'] ?? '';

        if ($id === '') {
            return ToolResult::error('deployment_id is required for the "runtime_logs" action.');
        }

        $query = [];

        if (isset($input['direction']) && $input['direction'] !== '') {
            $query['direction'] = $input['direction'];
        }
        if (isset($input['limit'])) {
            $query['limit'] = (int) $input['limit'];
        }

        // Runtime logs use the same events endpoint
        $data = $this->client->get("/v3/deployments/{$id}/events", $query);

        return ToolResult::success($this->formatLogs($data, 'runtime'));
    }

    /**
     * Format log entries into readable text, truncated to prevent context overflow.
     *
     * @param array<string, mixed> $data
     */
    private function formatLogs(array $data, string $type): string
    {
        // The events endpoint returns an array of log event objects
        $events = $data;

        // If the response is wrapped in a key, unwrap it
        if (isset($data['events']) && is_array($data['events'])) {
            $events = $data['events'];
        }

        if ($events === []) {
            return "No {$type} logs found for this deployment.";
        }

        $lines = [];
        $count = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $timestamp = '';
            if (isset($event['date'])) {
                $ts = is_numeric($event['date']) ? (int) $event['date'] / 1000 : strtotime((string) $event['date']);
                $timestamp = $ts !== false ? date('Y-m-d H:i:s', (int) $ts) : (string) $event['date'];
            }

            $text = $event['text'] ?? $event['payload']?? '';

            if (is_array($text)) {
                $text = json_encode($text, JSON_THROW_ON_ERROR);
            }

            $text = (string) $text;

            if ($text === '') {
                continue;
            }

            $prefix = $timestamp !== '' ? "[{$timestamp}] " : '';
            $lines[] = $prefix . $text;
            $count++;

            if ($count >= self::MAX_LOG_LINES) {
                $lines[] = sprintf('... (truncated — showing %d of %d entries)', self::MAX_LOG_LINES, count($events));
                break;
            }
        }

        if ($lines === []) {
            return "No {$type} log entries with content found.";
        }

        return sprintf("=== %s Logs (%d entries) ===\n\n%s", ucfirst($type), $count, implode("\n", $lines));
    }
}
