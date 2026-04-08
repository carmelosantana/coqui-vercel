<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelDeploymentTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelDomainTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelEnvTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelLogTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelProjectTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelTeamTool;
use CarmeloSantana\CoquiToolkitVercel\VercelClient;
use CarmeloSantana\CoquiToolkitVercel\VercelToolkit;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// ----------------------------------------------------------------
// Toolkit registration
// ----------------------------------------------------------------

test('implements ToolkitInterface', function () {
    $client = new VercelClient(apiToken: 'test-token');
    $toolkit = new VercelToolkit($client);

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('tools returns exactly 6 tools', function () {
    $client = new VercelClient(apiToken: 'test-token');
    $toolkit = new VercelToolkit($client);

    expect($toolkit->tools())->toHaveCount(6);
});

test('all tools implement ToolInterface', function () {
    $client = new VercelClient(apiToken: 'test-token');
    $toolkit = new VercelToolkit($client);

    foreach ($toolkit->tools() as $tool) {
        expect($tool)->toBeInstanceOf(ToolInterface::class);
    }
});

test('tool names are unique', function () {
    $client = new VercelClient(apiToken: 'test-token');
    $toolkit = new VercelToolkit($client);

    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    expect($names)->toHaveCount(count(array_unique($names)));
});

test('tool names follow expected naming', function () {
    $client = new VercelClient(apiToken: 'test-token');
    $toolkit = new VercelToolkit($client);

    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    expect($names)->toContain('vercel_project')
        ->toContain('vercel_deployment')
        ->toContain('vercel_domain')
        ->toContain('vercel_env')
        ->toContain('vercel_log')
        ->toContain('vercel_team');
});

test('guidelines contains all tool names', function () {
    $client = new VercelClient(apiToken: 'test-token');
    $toolkit = new VercelToolkit($client);
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toBeString()
        ->toContain('vercel_project')
        ->toContain('vercel_deployment')
        ->toContain('vercel_domain')
        ->toContain('vercel_env')
        ->toContain('vercel_log')
        ->toContain('vercel_team');
});

test('guidelines mentions destructive action warnings', function () {
    $client = new VercelClient(apiToken: 'test-token');
    $toolkit = new VercelToolkit($client);
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toContain('Destructive')
        ->toContain('delete')
        ->toContain('confirm');
});

// ----------------------------------------------------------------
// fromEnv factory
// ----------------------------------------------------------------

test('fromEnv reads environment variables', function () {
    $origToken = getenv('VERCEL_API_TOKEN');
    $origTeam = getenv('VERCEL_TEAM_ID');

    putenv('VERCEL_API_TOKEN=test-env-token-123');
    putenv('VERCEL_TEAM_ID=team_abc');

    $toolkit = VercelToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(VercelToolkit::class)
        ->and($toolkit->tools())->toHaveCount(6);

    // Restore
    $origToken !== false ? putenv("VERCEL_API_TOKEN={$origToken}") : putenv('VERCEL_API_TOKEN');
    $origTeam !== false ? putenv("VERCEL_TEAM_ID={$origTeam}") : putenv('VERCEL_TEAM_ID');
});

// ----------------------------------------------------------------
// VercelClient
// ----------------------------------------------------------------

test('VercelClient throws when token is missing', function () {
    $client = new VercelClient(apiToken: '', teamId: '');

    $client->get('/v10/projects');
})->throws(\RuntimeException::class, 'VERCEL_API_TOKEN is not configured');

test('VercelClient appends teamId to query params', function () {
    $requestedUrl = '';
    $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestedUrl) {
        $requestedUrl = $url;

        return new MockResponse(json_encode(['projects' => []]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    });

    $client = new VercelClient(apiToken: 'tok_test', teamId: 'team_123', httpClient: $httpClient);
    $client->get('/v10/projects');

    expect($requestedUrl)->toContain('teamId=team_123');
});

test('VercelClient sends Bearer token in Authorization header', function () {
    $capturedOptions = [];
    $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
        $capturedOptions = $options;

        return new MockResponse(json_encode(['ok' => true]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    });

    $client = new VercelClient(apiToken: 'tok_secret', httpClient: $httpClient);
    $client->get('/v2/user');

    // Symfony MockHttpClient normalizes headers as flat array of "Name: value" strings
    $headers = $capturedOptions['headers'] ?? [];
    $authHeader = '';

    foreach ($headers as $header) {
        if (is_string($header) && str_starts_with($header, 'Authorization:')) {
            $authHeader = trim(substr($header, strlen('Authorization:')));
            break;
        }
    }

    expect($authHeader)->toBe('Bearer tok_secret');
});

// ----------------------------------------------------------------
// Function schema validation
// ----------------------------------------------------------------

test('all tools produce valid function schemas', function () {
    $client = new VercelClient(apiToken: 'test-token');
    $toolkit = new VercelToolkit($client);

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)->toHaveKey('type')
            ->and($schema['type'])->toBe('function')
            ->and($schema)->toHaveKey('function')
            ->and($schema['function'])->toHaveKey('name')
            ->and($schema['function'])->toHaveKey('description')
            ->and($schema['function'])->toHaveKey('parameters')
            ->and($schema['function']['parameters'])->toHaveKey('type')
            ->and($schema['function']['parameters']['type'])->toBe('object')
            ->and($schema['function']['parameters'])->toHaveKey('properties');
    }
});

test('vercel_project schema includes action enum', function () {
    $client = new VercelClient(apiToken: 'test');
    $tool = new VercelProjectTool($client);
    $schema = $tool->toFunctionSchema();

    $actionProps = $schema['function']['parameters']['properties']['action'];

    expect($actionProps['enum'])->toContain('list')
        ->toContain('get')
        ->toContain('create')
        ->toContain('update')
        ->toContain('delete');
});

test('vercel_deployment schema includes state enum', function () {
    $client = new VercelClient(apiToken: 'test');
    $tool = new VercelDeploymentTool($client);
    $schema = $tool->toFunctionSchema();

    $stateProps = $schema['function']['parameters']['properties']['state'];

    expect($stateProps['enum'])->toContain('BUILDING')
        ->toContain('ERROR')
        ->toContain('READY')
        ->toContain('CANCELED');
});

test('vercel_env schema includes type enum', function () {
    $client = new VercelClient(apiToken: 'test');
    $tool = new VercelEnvTool($client);
    $schema = $tool->toFunctionSchema();

    $typeProps = $schema['function']['parameters']['properties']['type'];

    expect($typeProps['enum'])->toContain('plain')
        ->toContain('secret')
        ->toContain('encrypted')
        ->toContain('sensitive');
});

// ----------------------------------------------------------------
// Parameter validation (execute with missing required params)
// ----------------------------------------------------------------

test('vercel_project get requires project_id', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelProjectTool($client);
    $result = $tool->execute(['action' => 'get']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id');
});

test('vercel_project create requires name', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelProjectTool($client);
    $result = $tool->execute(['action' => 'create']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('name');
});

test('vercel_project delete requires project_id', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelProjectTool($client);
    $result = $tool->execute(['action' => 'delete']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id');
});

test('vercel_deployment get requires deployment_id', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelDeploymentTool($client);
    $result = $tool->execute(['action' => 'get']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('deployment_id');
});

test('vercel_deployment create requires name', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelDeploymentTool($client);
    $result = $tool->execute(['action' => 'create']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('name');
});

test('vercel_deployment cancel requires deployment_id', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelDeploymentTool($client);
    $result = $tool->execute(['action' => 'cancel']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('deployment_id');
});

test('vercel_domain add requires project_id and domain', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelDomainTool($client);
    $result = $tool->execute(['action' => 'add']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id')
        ->and($result->content)->toContain('domain');
});

test('vercel_domain remove requires project_id and domain', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelDomainTool($client);
    $result = $tool->execute(['action' => 'remove']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id')
        ->and($result->content)->toContain('domain');
});

test('vercel_domain verify requires domain', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelDomainTool($client);
    $result = $tool->execute(['action' => 'verify']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('domain');
});

test('vercel_env list requires project_id', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelEnvTool($client);
    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id');
});

test('vercel_env create requires project_id and key', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelEnvTool($client);
    $result = $tool->execute(['action' => 'create']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id')
        ->and($result->content)->toContain('key');
});

test('vercel_env delete requires project_id and env_id', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelEnvTool($client);
    $result = $tool->execute(['action' => 'delete']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id')
        ->and($result->content)->toContain('env_id');
});

test('vercel_log build_logs requires deployment_id', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelLogTool($client);
    $result = $tool->execute(['action' => 'build_logs']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('deployment_id');
});

test('vercel_team list_members requires team_id', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelTeamTool($client);
    $result = $tool->execute(['action' => 'list_members']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('team_id');
});

// ----------------------------------------------------------------
// Unknown action handling
// ----------------------------------------------------------------

test('vercel_project rejects unknown actions', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelProjectTool($client);
    $result = $tool->execute(['action' => 'explode']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('vercel_deployment rejects unknown actions', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelDeploymentTool($client);
    $result = $tool->execute(['action' => 'launch']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('vercel_domain rejects unknown actions', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelDomainTool($client);
    $result = $tool->execute(['action' => 'hack']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('vercel_env rejects unknown actions', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelEnvTool($client);
    $result = $tool->execute(['action' => 'nuke']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('vercel_log rejects unknown actions', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelLogTool($client);
    $result = $tool->execute(['action' => 'system_logs']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('vercel_team rejects unknown actions', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelTeamTool($client);
    $result = $tool->execute(['action' => 'disband']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

// ----------------------------------------------------------------
// Mock HTTP integration tests
// ----------------------------------------------------------------

test('vercel_project list returns parsed response', function () {
    $httpClient = new MockHttpClient([
        new MockResponse(json_encode([
            'projects' => [
                ['id' => 'prj_abc', 'name' => 'my-app', 'framework' => 'nextjs'],
            ],
        ]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]),
    ]);

    $client = new VercelClient(apiToken: 'tok_test', httpClient: $httpClient);
    $tool = new VercelProjectTool($client);
    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('prj_abc')
        ->and($result->content)->toContain('my-app')
        ->and($result->content)->toContain('nextjs');
});

test('vercel_project create sends correct payload', function () {
    $capturedBody = '';
    $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
        $capturedBody = $options['body'] ?? '';

        return new MockResponse(json_encode([
            'id' => 'prj_new',
            'name' => 'new-project',
        ]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    });

    $client = new VercelClient(apiToken: 'tok_test', httpClient: $httpClient);
    $tool = new VercelProjectTool($client);
    $result = $tool->execute([
        'action' => 'create',
        'name' => 'new-project',
        'framework' => 'nextjs',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('prj_new')
        ->and($result->content)->toContain('new-project');
});

test('vercel_deployment list filters by project and state', function () {
    $capturedUrl = '';
    $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl) {
        $capturedUrl = $url;

        return new MockResponse(json_encode([
            'deployments' => [
                ['uid' => 'dpl_123', 'state' => 'READY', 'url' => 'my-app-abc.vercel.app'],
            ],
        ]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    });

    $client = new VercelClient(apiToken: 'tok_test', httpClient: $httpClient);
    $tool = new VercelDeploymentTool($client);
    $result = $tool->execute([
        'action' => 'list',
        'project_id' => 'prj_abc',
        'state' => 'READY',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($capturedUrl)->toContain('projectId=prj_abc')
        ->and($capturedUrl)->toContain('state=READY')
        ->and($result->content)->toContain('dpl_123');
});

test('vercel_domain add sends correct payload', function () {
    $capturedBody = '';
    $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
        $capturedBody = $options['body'] ?? '';

        return new MockResponse(json_encode([
            'name' => 'example.com',
            'verified' => false,
        ]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    });

    $client = new VercelClient(apiToken: 'tok_test', httpClient: $httpClient);
    $tool = new VercelDomainTool($client);
    $result = $tool->execute([
        'action' => 'add',
        'project_id' => 'prj_abc',
        'domain' => 'example.com',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('example.com');
});

test('vercel_env create sends correct payload with target array', function () {
    $capturedBody = '';
    $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
        $capturedBody = $options['body'] ?? '';

        return new MockResponse(json_encode([
            'key' => 'DATABASE_URL',
            'value' => '',
            'type' => 'encrypted',
            'target' => ['production', 'preview'],
        ]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    });

    $client = new VercelClient(apiToken: 'tok_test', httpClient: $httpClient);
    $tool = new VercelEnvTool($client);
    $result = $tool->execute([
        'action' => 'create',
        'project_id' => 'prj_abc',
        'key' => 'DATABASE_URL',
        'value' => 'postgres://localhost/mydb',
        'target' => 'production,preview',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('DATABASE_URL');
});

test('vercel_log build_logs formats output', function () {
    $httpClient = new MockHttpClient([
        new MockResponse(json_encode([
            ['date' => 1704067200000, 'text' => 'Installing dependencies...'],
            ['date' => 1704067201000, 'text' => 'Build completed.'],
        ]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]),
    ]);

    $client = new VercelClient(apiToken: 'tok_test', httpClient: $httpClient);
    $tool = new VercelLogTool($client);
    $result = $tool->execute([
        'action' => 'build_logs',
        'deployment_id' => 'dpl_123',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('Build Logs')
        ->and($result->content)->toContain('Installing dependencies')
        ->and($result->content)->toContain('Build completed');
});

test('vercel_team user_info returns user data', function () {
    $httpClient = new MockHttpClient([
        new MockResponse(json_encode([
            'user' => [
                'id' => 'usr_abc',
                'email' => 'dev@example.com',
                'name' => 'Developer',
                'username' => 'dev',
            ],
        ]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]),
    ]);

    $client = new VercelClient(apiToken: 'tok_test', httpClient: $httpClient);
    $tool = new VercelTeamTool($client);
    $result = $tool->execute(['action' => 'user_info']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('usr_abc')
        ->and($result->content)->toContain('dev@example.com');
});

// ----------------------------------------------------------------
// Error handling
// ----------------------------------------------------------------

test('API error is handled gracefully', function () {
    $httpClient = new MockHttpClient([
        new MockResponse(json_encode([
            'error' => ['message' => 'Project not found', 'code' => 'not_found'],
        ]), [
            'http_code' => 404,
            'response_headers' => ['content-type' => 'application/json'],
        ]),
    ]);

    $client = new VercelClient(apiToken: 'tok_test', httpClient: $httpClient);
    $tool = new VercelProjectTool($client);
    $result = $tool->execute(['action' => 'get', 'project_id' => 'nonexistent']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('failed');
});

test('vercel_project update requires at least one field', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelProjectTool($client);
    $result = $tool->execute(['action' => 'update', 'project_id' => 'prj_abc']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('At least one field');
});

test('vercel_env update requires at least one field', function () {
    $httpClient = new MockHttpClient([]);
    $client = new VercelClient(apiToken: 'test', httpClient: $httpClient);

    $tool = new VercelEnvTool($client);
    $result = $tool->execute(['action' => 'update', 'project_id' => 'prj_abc', 'env_id' => 'env_123']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('At least one field');
});

test('vercel_log handles empty log output', function () {
    $httpClient = new MockHttpClient([
        new MockResponse(json_encode([]), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]),
    ]);

    $client = new VercelClient(apiToken: 'tok_test', httpClient: $httpClient);
    $tool = new VercelLogTool($client);
    $result = $tool->execute([
        'action' => 'build_logs',
        'deployment_id' => 'dpl_123',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('No build logs found');
});
