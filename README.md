# Coqui Toolkit: Vercel

Vercel deployment management toolkit for [Coqui Bot](https://github.com/coquibot/coqui). Manage projects, deployments, domains, environment variables, logs, and teams via the Vercel REST API.

## Installation

```bash
composer require coquibot/coqui-toolkit-vercel
```

The toolkit is auto-discovered by Coqui on startup — no configuration needed.

## Credentials

### Required

| Variable | Description |
|----------|-------------|
| `VERCEL_API_TOKEN` | Vercel API access token |

Create a token at **[vercel.com/account/tokens](https://vercel.com/account/tokens)**. Select the scope (personal or team) and set appropriate permissions.

### Optional

| Variable | Description |
|----------|-------------|
| `VERCEL_TEAM_ID` | Team ID or slug for team-scoped operations |

Find your team ID in your team's **Settings → General** page on Vercel. When set, all API calls are automatically scoped to that team.

### Setting credentials via Coqui

Coqui's credential system handles setup automatically. If credentials are missing, the bot will prompt you:

```
credentials(action: "set", key: "VERCEL_API_TOKEN", value: "your-token-here")
credentials(action: "set", key: "VERCEL_TEAM_ID", value: "team_abc123")
```

## Tools

### `vercel_project`

Manage Vercel projects — create, list, get details, update settings, or delete.

| Action | Required Params | Description |
|--------|----------------|-------------|
| `list` | — | List all projects (optional: `search`, `limit`) |
| `get` | `project_id` | Get project details |
| `create` | `name` | Create a new project (optional: `framework`, `build_command`, `output_directory`, `root_directory`, `git_repository`) |
| `update` | `project_id` | Update project settings |
| `delete` | `project_id` | **Destructive** — delete a project and all its deployments |

### `vercel_deployment`

Manage deployments — list, inspect, trigger redeploys, delete, or cancel.

| Action | Required Params | Description |
|--------|----------------|-------------|
| `list` | — | List deployments (optional: `project_id`, `target`, `state`, `limit`) |
| `get` | `deployment_id` | Get deployment details |
| `create` | `name` | Trigger a new deployment/redeploy (optional: `project_id`, `target`, `deployment_id`) |
| `delete` | `deployment_id` | **Destructive** — permanently remove a deployment |
| `cancel` | `deployment_id` | **Destructive** — cancel an in-progress deployment |

### `vercel_domain`

Manage custom domains on projects — list, add, remove, or verify DNS.

| Action | Required Params | Description |
|--------|----------------|-------------|
| `list` | `project_id` | List domains for a project |
| `get` | `domain` | Get domain info |
| `add` | `project_id`, `domain` | Add a custom domain (optional: `redirect`, `redirect_status_code`, `git_branch`) |
| `remove` | `project_id`, `domain` | **Destructive** — remove a domain from a project |
| `verify` | `domain` | Verify domain DNS configuration |

### `vercel_env`

Manage environment variables per project.

| Action | Required Params | Description |
|--------|----------------|-------------|
| `list` | `project_id` | List all env vars for a project |
| `get` | `project_id`, `env_id` | Get a specific env var |
| `create` | `project_id`, `key` | Create an env var (optional: `value`, `target`, `type`, `git_branch`) |
| `update` | `project_id`, `env_id` | Update an env var |
| `delete` | `project_id`, `env_id` | **Destructive** — delete an env var |

**Note:** Secret and sensitive variable values are never returned in plain text by the Vercel API.

### `vercel_log`

View build and runtime logs for deployments.

| Action | Required Params | Description |
|--------|----------------|-------------|
| `build_logs` | `deployment_id` | Fetch build logs (optional: `direction`, `limit`) |
| `runtime_logs` | `deployment_id` | Fetch runtime logs (optional: `direction`, `limit`) |

Output is automatically truncated to 200 lines to prevent context overflow.

### `vercel_team`

Get user and team information (read-only).

| Action | Required Params | Description |
|--------|----------------|-------------|
| `user_info` | — | Get current authenticated user info |
| `team_info` | — | Get team details (optional: `team_id`) |
| `list_members` | `team_id` | List team members (optional: `search`, `limit`) |

## Usage Examples

```
# List all projects
vercel_project(action: "list")

# Check recent deployments for a project
vercel_deployment(action: "list", project_id: "my-app", limit: 5)

# Debug a failed deployment
vercel_log(action: "build_logs", deployment_id: "dpl_abc123")

# Add a custom domain
vercel_domain(action: "add", project_id: "my-app", domain: "example.com")

# Set an environment variable
vercel_env(action: "create", project_id: "my-app", key: "API_KEY", value: "secret", target: "production", type: "encrypted")

# Get current user info
vercel_team(action: "user_info")
```

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run static analysis
composer analyse
```

## License

MIT
