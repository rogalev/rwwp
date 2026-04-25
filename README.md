# RussiaWW Parser Agent

Parser-agent is a standalone Symfony CLI application. It is designed to run on
separate machines and must not require direct access to the main application
database.

Development runs through Docker Compose. Do not rely on locally installed PHP or
Composer versions for day-to-day commands.

## Development

Build the PHP image:

```bash
make build
```

Run Symfony console commands:

```bash
make console cmd="list parser"
```

Run a basic HTTP fetch smoke test:

```bash
make test-fetch
```

Remove stopped containers and orphan Compose resources:

```bash
make clean
```

## Local Runtime Data

Parser-agent keeps local state and output files under `var/`.

- SQLite state database: `var/state/parser.sqlite`
- Parsed articles NDJSON output: `var/output/articles.ndjson`

The `var/` directory is runtime data and must not be committed.

## Git Hygiene

Do not commit local IDE files, dependencies, or runtime data:

- `.idea/`
- `.vscode/`
- `vendor/`
- `var/`
