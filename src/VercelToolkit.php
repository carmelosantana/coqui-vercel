<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitVercel;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelDeploymentTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelDomainTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelEnvTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelLogTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelProjectTool;
use CarmeloSantana\CoquiToolkitVercel\Tool\VercelTeamTool;

/**
 * Vercel deployment management toolkit — projects, deployments, domains,
 * environment variables, logs, and team/user info via the Vercel REST API.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * Requires VERCEL_API_TOKEN. Optionally uses VERCEL_TEAM_ID for team-scoped operations.
 */
final class VercelToolkit implements ToolkitInterface
{
    private readonly VercelClient $client;

    public function __construct(
        ?VercelClient $client = null,
    ) {
        $this->client = $client ?? VercelClient::fromEnv();
    }

    public static function fromEnv(): self
    {
        return new self(VercelClient::fromEnv());
    }

    public function tools(): array
    {
        return [
            new VercelProjectTool($this->client),
            new VercelDeploymentTool($this->client),
            new VercelDomainTool($this->client),
            new VercelEnvTool($this->client),
            new VercelLogTool($this->client),
            new VercelTeamTool($this->client),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        <VERCEL-GUIDELINES>
        ## Vercel Deployment Toolkit

        You have access to a Vercel account via 6 tools for managing deployments and infrastructure:

        ### Tool Overview
        - **vercel_project** — Create, list, get, update, delete projects
        - **vercel_deployment** — List, get, create (trigger redeploy), delete, cancel deployments
        - **vercel_domain** — List, add, remove, verify custom domains on projects
        - **vercel_env** — Manage environment variables (create, list, get, update, delete) per project
        - **vercel_log** — View build and runtime logs for deployments
        - **vercel_team** — Get current user info, team details, and team member lists

        ### Destructive Actions — ALWAYS Confirm First
        Before executing any of these actions, clearly state what will happen and ask the user to confirm:
        - `vercel_project(action: "delete")` — permanently deletes a project and all its deployments
        - `vercel_deployment(action: "delete")` — permanently removes a deployment
        - `vercel_deployment(action: "cancel")` — cancels an in-progress deployment
        - `vercel_domain(action: "remove")` — removes a custom domain from a project
        - `vercel_env(action: "delete")` — deletes an environment variable

        ### Deployment Statuses
        Vercel deployments go through these states: QUEUED → INITIALIZING → BUILDING → READY (success) or ERROR (failure) or CANCELED.

        ### Common Workflows
        1. **Check project status**: `vercel_project(action: "list")` → `vercel_deployment(action: "list", project_id: "<id>")` to see recent deployments
        2. **Trigger redeploy**: `vercel_deployment(action: "create", project_id: "<id>", name: "<project-name>", target: "production")` to trigger a new deployment
        3. **Debug failed deploy**: `vercel_deployment(action: "list", project_id: "<id>", state: "ERROR")` → `vercel_log(action: "build_logs", deployment_id: "<id>")` to view build logs
        4. **Add custom domain**: `vercel_domain(action: "add", project_id: "<id>", domain: "example.com")` → `vercel_domain(action: "verify", domain: "example.com")` to check DNS
        5. **Manage env vars**: `vercel_env(action: "list", project_id: "<id>")` → `vercel_env(action: "create", project_id: "<id>", key: "API_KEY", value: "...", target: "production")`

        ### Team Scoping
        If VERCEL_TEAM_ID is configured, all API calls are automatically scoped to that team. No extra parameters needed.

        ### Important Notes
        - Environment variable values of type "secret" or "sensitive" are never returned in plain text by the API
        - Deployment creation via this toolkit triggers a rebuild from the existing source — it does not upload new files
        - When presenting deployment info, format timestamps as human-readable dates
        - Log output is automatically truncated to prevent context overflow
        </VERCEL-GUIDELINES>
        GUIDELINES;
    }
}
