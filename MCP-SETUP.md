# MCP servers

## 21st.dev

Configured in `.mcp.json`. Provides UI component search, code retrieval, and
generation against the [21st.dev](https://21st.dev) catalog (shadcn/React
components, themes, templates, SVG logos).

The API key is **not** stored in this repo. `.mcp.json` references the
`TWENTYFIRST_API_KEY` environment variable, which Claude Code expands at
startup.

### Setup

Export the variable in your shell profile (`~/.zshrc`, `~/.bashrc`, …):

```bash
export TWENTYFIRST_API_KEY="21st_sk_..."
```

Get a key from https://21st.dev — account settings.

Then start Claude Code in this repo and approve the server when prompted.
Project-scoped servers from `.mcp.json` require a one-time approval per
machine; that choice is stored outside the repo.

### Verify

```bash
claude mcp list
```

Expected: `21st: https://21st.dev/api/mcp (HTTP) - ✓ Connected`

If it reports `Missing environment variables: TWENTYFIRST_API_KEY`, the
variable is not exported in the environment Claude Code was launched from.
If it reports `Pending approval`, run `claude` and approve it.

### Alternative: personal, non-shared install

To register the server only for yourself, with the key inline and nothing
written to the repo:

```bash
claude mcp add --transport http 21st https://21st.dev/api/mcp \
  --header "x-api-key: 21st_sk_..."
```

This writes to your user config instead of `.mcp.json`. Prefer the
environment-variable approach above if the config should be shared.
