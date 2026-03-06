# CDCF API Client

Python client library and CLI for the CDCF CMS REST API and WPGraphQL. Wraps all `cdcf/v1` WordPress endpoints, the Next.js revalidation endpoint, and GraphQL translation queries, reading credentials from `.env.local` and `.env` so secrets are never exposed to callers.

## Setup

Requires Python 3.10+. Uses [uv](https://docs.astral.sh/uv/) for fast virtual environment management.

```bash
# From the project root
uv venv scripts/.venv
uv pip install -r scripts/requirements.txt --python scripts/.venv/bin/python
```

<details>
<summary>Alternative: using standard venv</summary>

```bash
python3 -m venv scripts/.venv
scripts/.venv/bin/pip install -r scripts/requirements.txt
```

</details>

## Environment Variables

The client reads credentials automatically from the project root:

| Variable | File | Description |
|----------|------|-------------|
| `WP_REST_URL` | `.env.local` | WordPress REST base URL (e.g. `https://cms.catholicdigitalcommons.org/wp-json`) |
| `WP_GRAPHQL_URL` | `.env.local` | WPGraphQL endpoint (e.g. `https://cms.catholicdigitalcommons.org/graphql`) |
| `WP_APP_USERNAME` | `.env.local` | WordPress Application Password username |
| `WP_APP_PASSWORD` | `.env.local` | WordPress Application Password |
| `WP_PREVIEW_SECRET` | `.env` | Shared secret for Next.js preview/revalidation |
| `NEXTJS_URL` | `.env.local` | Next.js base URL (defaults to `http://localhost:3000`) |

## CLI Usage

Run commands via the virtual environment Python:

```bash
scripts/.venv/bin/python scripts/cdcf_api.py <command> [options]
```

Or activate the venv first:

```bash
source scripts/.venv/bin/activate
python scripts/cdcf_api.py <command> [options]
```

All commands output pretty-printed JSON. Errors are printed to stderr with the HTTP status code and response body.

### Available Commands

#### Relationship Fields

```bash
# Read an ACF relationship field
python scripts/cdcf_api.py get-relationship --post-id 5 --field team_members

# Update an ACF relationship field (list of post IDs)
python scripts/cdcf_api.py update-relationship --post-id 5 --field team_members --value 10 12 14
```

#### Content Creation (with auto-translation)

```bash
# Create a team member
python scripts/cdcf_api.py create-team-member \
  --title "Jane Doe" \
  --content "<p>Software engineer and community organizer.</p>" \
  --council technical_council \
  --member-title "Lead Developer" \
  --member-role "Backend Architecture" \
  --member-github-url "https://github.com/janedoe"

# Create a community channel
python scripts/cdcf_api.py create-community-channel \
  --title "CDCF Discord" \
  --channel-description "Main community chat server" \
  --channel-url "https://discord.gg/example" \
  --channel-icon discord

# Create a local group
python scripts/cdcf_api.py create-local-group \
  --title "Catholic Developers Rome" \
  --group-description "Monthly meetup for Catholic developers in Rome" \
  --group-url "https://example.com/rome" \
  --group-location "Rome, Italy"
```

#### Public Submissions

```bash
# Refer a local group
python scripts/cdcf_api.py refer-local-group \
  --group-name "Catholic Tech NYC" \
  --description "A community of Catholic technologists in New York" \
  --url "https://example.com/nyc" \
  --submitter-name "John Smith" \
  --submitter-email "john@example.com" \
  --location "New York, NY"

# Request a verification code for project submission
python scripts/cdcf_api.py submit-project-send-code \
  --project-name "Parish App" \
  --description "Mobile app for parish management" \
  --url "https://parishapp.example.com" \
  --submitter-name "John Smith" \
  --submitter-email "john@example.com"

# Submit a project with verification code
python scripts/cdcf_api.py submit-project \
  --project-name "Parish App" \
  --description "Mobile app for parish management" \
  --url "https://parishapp.example.com" \
  --submitter-name "John Smith" \
  --submitter-email "john@example.com" \
  --verification-code "123456" \
  --repo-urls "https://github.com/example/parish-app"
```

#### Translation

```bash
# Translate a post to another language via OpenAI
python scripts/cdcf_api.py translate-post --source-id 255 --target-lang it

# Deploy translated content to an existing post
python scripts/cdcf_api.py deploy-translation \
  --source-id 255 \
  --target-lang it \
  --content "<p>Contenuto tradotto.</p>" \
  --title "Titolo tradotto"

# Link translation post IDs across languages
python scripts/cdcf_api.py link-translations --translations '{"en": 10, "it": 12, "es": 14}'
```

#### Project Status

```bash
# Update project approval status
python scripts/cdcf_api.py update-project-status --post-id 42 --status approved
```

#### GraphQL Queries

```bash
# Get all translation post IDs for a page
python scripts/cdcf_api.py get-translation-ids --post-id 5
# Output: {"en": 5, "it": 12, "es": 14, "fr": 16, "pt": 18, "de": 20}

# Get translation IDs for a specific post type
python scripts/cdcf_api.py get-translation-ids --post-id 100 --post-type project
python scripts/cdcf_api.py get-translation-ids --post-id 200 --post-type teamMember

# Get the language of a post
python scripts/cdcf_api.py get-post-language --post-id 12
# Output: {"post_id": 12, "language": "it"}

# Run an arbitrary GraphQL query
python scripts/cdcf_api.py graphql \
  --query '{ pages(first: 5) { nodes { databaseId title slug } } }'

# With variables
python scripts/cdcf_api.py graphql \
  --query 'query ($id: ID!) { page(id: $id, idType: DATABASE_ID) { title slug } }' \
  --variables '{"id": 5}'
```

**Supported `--post-type` values:** `page`, `post`, `project`, `teamMember`, `communityChannel`, `localGroup`, `sponsor`, `statItem`

#### Cache Revalidation

```bash
# Revalidate a specific path
python scripts/cdcf_api.py revalidate --path /about

# Revalidate by cache tags
python scripts/cdcf_api.py revalidate --tags sitemap posts

# Revalidate both path and tags
python scripts/cdcf_api.py revalidate --path /projects --tags projects
```

## Library Usage

Import `CdcfClient` directly in Python scripts:

```python
from cdcf_api import CdcfClient

client = CdcfClient()

# Read a relationship field
members = client.get_relationship(post_id=5, field="team_members")

# Create a team member with auto-translation to all languages
result = client.create_team_member(
    title="Jane Doe",
    content="<p>Software engineer.</p>",
    council="technical_council",
    member_role="Backend Architecture",
)
print(result["translations"])  # {"en": 100, "it": 101, "es": 102, ...}

# Translate a single post
translation = client.translate_post(source_id=255, target_lang="fr")

# Revalidate the Next.js cache
client.revalidate(path="/about")

# --- GraphQL ---

# Get all translation post IDs for a page
ids = client.get_translation_ids(post_id=5)
# {"en": 5, "it": 12, "es": 14, "fr": 16, "pt": 18, "de": 20}

# Get translation IDs for a project
ids = client.get_translation_ids(post_id=100, post_type="project")

# Get the language of a specific post
lang = client.get_post_language(post_id=12)  # "it"

# Run an arbitrary GraphQL query
data = client.graphql('{ pages(first: 5) { nodes { databaseId title } } }')
```

All methods return the parsed JSON response as a `dict` and raise `requests.HTTPError` on failure. GraphQL methods raise `ValueError` if the response contains GraphQL errors.
