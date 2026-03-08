# Catholic Tech Content Aggregator — Implementation Plan

## Overview

An AI-powered system that daily scours Catholic media (RSS feeds, news sites, Vatican documents) for content related to technology and the Church. Results are stored in PostgreSQL with full-text search, linked in a knowledge graph, and made searchable/browsable on the CDCF website.

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│  Docker Compose                                              │
│                                                              │
│  ┌─────────────┐    ┌──────────────────────────────────────┐ │
│  │  PostgreSQL  │    │  Python Worker (aggregator)          │ │
│  │  + AGE ext.  │◄───│  - Fetcher (RSS, scraper)           │ │
│  │              │    │  - AI Classifier (Claude / OpenAI)   │ │
│  │  • articles  │    │  - Pipeline orchestrator             │ │
│  │  • FTS index │    │  - Graph builder (AGE)               │ │
│  │  • knowledge │    └──────────────────────────────────────┘ │
│  │    graph     │                                            │
│  └──────┬───────┘                                            │
│         │                                                    │
│  ┌──────▼───────┐    ┌──────────────────────────────────────┐ │
│  │   Next.js    │    │  WordPress (existing)                │ │
│  │  /research   │    │  - unchanged                         │ │
│  │  - search    │    └──────────────────────────────────────┘ │
│  │  - graph viz │                                            │
│  └──────────────┘                                            │
└──────────────────────────────────────────────────────────────┘
```

**Key design decisions:**

- **PostgreSQL with Apache AGE** — single database for relational data, full-text search (tsvector), and property-graph queries (openCypher). No separate graph database needed.
- **Python worker** — runs daily via cron (or a loop with sleep). Modular design with swappable AI providers.
- **Next.js `/research` route** — a standalone section of the existing site. Fetches directly from PostgreSQL (not WordPress).
- **Decoupled from WordPress** — the aggregator is an independent subsystem. WordPress continues to serve CMS content; the research section queries PostgreSQL directly.

## Database Schema

### PostgreSQL Tables

```sql
-- Sources (RSS feeds, websites to monitor)
CREATE TABLE sources (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    url             TEXT NOT NULL UNIQUE,
    source_type     TEXT NOT NULL CHECK (source_type IN ('rss', 'web', 'vatican', 'api')),
    fetch_interval  INTERVAL NOT NULL DEFAULT '1 day',
    last_fetched_at TIMESTAMPTZ,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    config          JSONB DEFAULT '{}',  -- source-specific settings (selectors, auth, etc.)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Articles / documents discovered
CREATE TABLE articles (
    id              SERIAL PRIMARY KEY,
    source_id       INT NOT NULL REFERENCES sources(id),
    external_url    TEXT NOT NULL UNIQUE,
    title           TEXT NOT NULL,
    author          TEXT,
    published_at    TIMESTAMPTZ,
    fetched_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    content_text    TEXT,               -- plain text (for FTS)
    content_html    TEXT,               -- original HTML
    summary         TEXT,               -- AI-generated summary
    language        TEXT DEFAULT 'en',
    relevance_score REAL DEFAULT 0.0,   -- AI-assigned 0.0–1.0
    metadata        JSONB DEFAULT '{}', -- arbitrary extra fields

    -- Full-text search vector (auto-updated via trigger)
    search_vector   TSVECTOR GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(summary, '')), 'B') ||
        setweight(to_tsvector('english', coalesce(content_text, '')), 'C')
    ) STORED
);

CREATE INDEX idx_articles_search ON articles USING GIN (search_vector);
CREATE INDEX idx_articles_published ON articles (published_at DESC);
CREATE INDEX idx_articles_relevance ON articles (relevance_score DESC);
CREATE INDEX idx_articles_source ON articles (source_id);

-- Tags / categories (AI-assigned)
CREATE TABLE tags (
    id   SERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE
);

CREATE TABLE article_tags (
    article_id INT NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    tag_id     INT NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    confidence REAL DEFAULT 1.0,  -- AI confidence in this tag
    PRIMARY KEY (article_id, tag_id)
);

-- Named entities extracted by AI
CREATE TABLE entities (
    id          SERIAL PRIMARY KEY,
    name        TEXT NOT NULL,
    entity_type TEXT NOT NULL CHECK (entity_type IN (
        'person', 'organization', 'project', 'document', 'event', 'location', 'concept'
    )),
    description TEXT,
    external_url TEXT,
    UNIQUE (name, entity_type)
);

-- Article–entity associations
CREATE TABLE article_entities (
    article_id INT NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    entity_id  INT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
    role       TEXT,  -- e.g. 'author', 'subject', 'publisher', 'mentioned'
    PRIMARY KEY (article_id, entity_id, COALESCE(role, ''))
);

-- Links discovered within article content (for link-following crawler)
CREATE TABLE discovered_links (
    id              SERIAL PRIMARY KEY,
    source_article_id INT NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    target_url      TEXT NOT NULL,
    target_article_id INT REFERENCES articles(id),  -- set once the target is fetched
    link_type       TEXT,           -- AI-classified: 'cited_document', 'related_project', 'source_reference', 'press_release'
    link_context    TEXT,           -- surrounding text where the link appeared
    domain          TEXT NOT NULL,  -- extracted domain for allowlist filtering
    crawl_depth     INT NOT NULL DEFAULT 1,
    status          TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'fetched', 'skipped', 'error')),
    discovered_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (source_article_id, target_url)
);

CREATE INDEX idx_discovered_links_status ON discovered_links (status) WHERE status = 'pending';
CREATE INDEX idx_discovered_links_target ON discovered_links (target_url);

-- Processing log (audit trail)
CREATE TABLE processing_log (
    id           SERIAL PRIMARY KEY,
    article_id   INT REFERENCES articles(id),
    step         TEXT NOT NULL,  -- 'fetch', 'classify', 'tag', 'graph'
    status       TEXT NOT NULL CHECK (status IN ('success', 'error', 'skipped')),
    details      JSONB DEFAULT '{}',
    processed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

### Apache AGE Knowledge Graph

The knowledge graph is built on top of the relational data using Apache AGE (a PostgreSQL extension that adds openCypher graph queries).

```sql
-- Enable the extension
CREATE EXTENSION IF NOT EXISTS age;
LOAD 'age';
SET search_path = ag_catalog, "$user", public;

SELECT create_graph('catholic_tech');
```

**Node types (labels):**

| Label | Properties | Mapped from |
|-------|-----------|-------------|
| `Article` | `id`, `title`, `url`, `published_at`, `relevance_score` | `articles` table |
| `Entity` | `id`, `name`, `entity_type`, `description` | `entities` table |
| `Tag` | `id`, `name`, `slug` | `tags` table |
| `Source` | `id`, `name`, `url`, `source_type` | `sources` table |

**Edge types:**

| Edge | From → To | Properties |
|------|-----------|------------|
| `TAGGED_WITH` | Article → Tag | `confidence` |
| `MENTIONS` | Article → Entity | `role` |
| `PUBLISHED_BY` | Article → Source | — |
| `RELATED_TO` | Entity → Entity | `relation_type`, `weight` |
| `CO_OCCURS_WITH` | Entity → Entity | `count`, `articles[]` |
| `REFERENCES` | Article → Article | `link_type`, `context` |

Entity-to-entity relationships (`RELATED_TO`, `CO_OCCURS_WITH`) are inferred by the AI classifier and co-occurrence analysis. `REFERENCES` edges are created when an article links to another document that was also ingested — `link_type` indicates the nature of the reference (e.g. `cited_document`, `related_project`, `source_reference`, `press_release`) and `context` stores the surrounding text where the link appeared.

## Python Worker

### Directory Structure

```
aggregator/
├── __init__.py
├── __main__.py           # Entry point: `python -m aggregator`
├── config.py             # Settings from env vars
├── pipeline.py           # Orchestrates the full daily run
├── fetchers/
│   ├── __init__.py
│   ├── base.py           # Abstract fetcher interface
│   ├── rss.py            # RSS/Atom feed fetcher (feedparser)
│   ├── web.py            # Generic web scraper (httpx + BeautifulSoup)
│   └── vatican.py        # Vatican.va specific fetcher
├── ai/
│   ├── __init__.py
│   ├── base.py           # Abstract AI provider interface
│   ├── claude.py         # Anthropic Claude provider
│   └── openai.py         # OpenAI provider
├── processors/
│   ├── __init__.py
│   ├── classifier.py     # Relevance scoring + tag assignment
│   ├── extractor.py      # Named entity extraction
│   ├── summarizer.py     # Article summarization
│   └── link_follower.py  # Discover + crawl outbound links from articles
├── graph/
│   ├── __init__.py
│   └── builder.py        # Apache AGE graph builder
├── db.py                 # Database connection + helpers (psycopg)
└── models.py             # Pydantic models for articles, entities, etc.
```

### Pipeline Flow

```
1. Load active sources from `sources` table
2. For each source due for refresh:
   a. Fetch new items (RSS → feedparser, web → httpx + BS4)
   b. Deduplicate against existing articles (by external_url)
   c. For each new article:
      i.    Extract plain text from HTML
      ii.   AI: Score relevance (0.0–1.0) — skip if < 0.3
      iii.  AI: Generate summary (1–2 sentences)
      iv.   AI: Assign tags from controlled vocabulary + suggest new ones
      v.    AI: Extract named entities (people, orgs, projects, documents)
      vi.   Insert article + tags + entities into PostgreSQL
      vii.  Sync to Apache AGE graph (nodes + edges)
      viii. Log processing result
3. Link-following pass (see below)
4. Run co-occurrence analysis across recent articles
5. Update graph edges for entity relationships
```

### Link Following

After the initial fetch-and-classify pass, the pipeline runs a **link-following step** that discovers and crawls outbound links found within article content.

```
3. Link-following pass:
   a. For each newly ingested article:
      i.   Extract all outbound URLs from content_html
      ii.  Filter against domain allowlist (see below) — skip social media, ads, navigation links
      iii. Deduplicate against articles.external_url and discovered_links.target_url
      iv.  AI: Classify each link's type (cited_document, related_project, source_reference, press_release)
           and extract the surrounding context text
      v.   Insert into discovered_links table with status='pending'
   b. For each pending discovered link (up to depth limit):
      i.   Fetch the target URL (httpx, respecting robots.txt and rate limits)
      ii.  Extract plain text from HTML
      iii. AI: Score relevance (0.0–1.0) — mark as 'skipped' if < 0.3
      iv.  If relevant: insert as a new article, run full classification pipeline (summary, tags, entities)
      v.   Set discovered_links.target_article_id and status='fetched'
      vi.  Create REFERENCES edge in the knowledge graph
      vii. Recursively discover outbound links from the new article (if crawl_depth < max)
```

**Safeguards:**

| Setting | Default | Description |
|---------|---------|-------------|
| `AGG_LINK_MAX_DEPTH` | `2` | Maximum crawl depth from original source article |
| `AGG_LINK_BATCH_SIZE` | `50` | Max discovered links to process per pipeline run |
| `AGG_LINK_RATE_LIMIT` | `2` | Seconds between requests to the same domain |
| `AGG_LINK_RELEVANCE_THRESHOLD` | `0.4` | Minimum relevance to ingest a discovered link (slightly higher than source threshold) |

**Domain allowlist** — only links to these domain categories are followed:

- Vatican domains (`vatican.va`, `vaticannews.va`)
- Catholic news outlets (domains from the Initial Source List)
- GitHub repositories and project pages (`github.com`, `gitlab.com`)
- University/academic domains (`.edu`, `.ac.*`)
- Church organization domains (`usccb.org`, national bishops' conferences)
- Curated additions stored in a `link_domain_allowlist` config table

Links to social media (Twitter/X, Facebook, Instagram), generic platforms (YouTube, Medium), and unrecognized domains are skipped by default. The allowlist can be extended at runtime without code changes via the `link_domain_allowlist` table or a `AGG_LINK_EXTRA_DOMAINS` environment variable.

### AI Provider Abstraction

```python
# aggregator/ai/base.py
from abc import ABC, abstractmethod
from pydantic import BaseModel

class ClassificationResult(BaseModel):
    relevance_score: float          # 0.0–1.0
    tags: list[str]
    entities: list[dict]            # {name, type, role}
    summary: str

class AIProvider(ABC):
    @abstractmethod
    async def classify_article(self, title: str, content: str) -> ClassificationResult:
        """Score relevance, extract tags/entities, summarize."""
        ...
```

Both Claude and OpenAI providers implement this interface. The pipeline selects the provider based on the `AI_PROVIDER` environment variable.

### Scheduling

In Docker Compose, the worker runs as a long-lived container with a cron-like loop:

```python
# aggregator/__main__.py
import asyncio, schedule
from aggregator.pipeline import run_pipeline

schedule.every().day.at("03:00").do(lambda: asyncio.run(run_pipeline()))

while True:
    schedule.run_pending()
    time.sleep(60)
```

Alternatively, an on-demand run can be triggered:

```bash
docker compose run --rm aggregator python -m aggregator --once
```

## Next.js Frontend

### Route: `/research`

A new top-level route (`app/[lang]/research/page.tsx`) that queries PostgreSQL directly via a thin API route.

**Features:**

- **Search bar** — full-text search via `ts_query` against `articles.search_vector`
- **Faceted filters** — by tag, source, date range, relevance threshold, entity type
- **Results list** — title, source, date, relevance badge, summary, tags
- **Article detail** — full content view with related entities sidebar
- **Knowledge graph** — interactive D3.js force-directed graph visualization
  - Nodes = entities + articles, colored by type
  - Edges = relationships (mentions, co-occurrence, tagged-with)
  - Click a node to filter the search results
  - Zoom/pan, search within graph

### API Routes

```
app/api/research/
├── search/route.ts       # GET: full-text search with facets
├── articles/[id]/route.ts # GET: single article with entities
├── tags/route.ts         # GET: all tags with counts
├── entities/route.ts     # GET: entities with filters
└── graph/route.ts        # GET: knowledge graph data (nodes + edges)
```

These API routes connect to PostgreSQL using `pg` (node-postgres) with a connection pool.

### Knowledge Graph Visualization

The graph visualization uses D3.js force-directed layout with:

- Node radius proportional to connection count
- Color coding: articles (blue), people (green), organizations (purple), projects (orange), concepts (gray)
- Edge thickness proportional to co-occurrence count
- Hover tooltips with entity details
- Click-to-filter integration with the search results

This is a client component (`'use client'`) using `useRef` for the D3 SVG container.

## Docker Compose Additions

```yaml
  # PostgreSQL with Apache AGE extension for knowledge graph
  aggregator-db:
    image: apache/age:PG16_latest
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${AGG_DB_NAME:-aggregator}
      POSTGRES_USER: ${AGG_DB_USER:-aggregator}
      POSTGRES_PASSWORD: ${AGG_DB_PASSWORD}
    volumes:
      - aggregator_db_data:/var/lib/postgresql/data
      - ./aggregator/sql/init.sql:/docker-entrypoint-initdb.d/01-init.sql:ro
    ports:
      - "5432:5432"

  # Python worker for content aggregation
  aggregator:
    build:
      context: ./aggregator
      dockerfile: Dockerfile
    restart: unless-stopped
    depends_on:
      - aggregator-db
    environment:
      DATABASE_URL: postgresql://${AGG_DB_USER:-aggregator}:${AGG_DB_PASSWORD}@aggregator-db:5432/${AGG_DB_NAME:-aggregator}
      AI_PROVIDER: ${AI_PROVIDER:-claude}
      ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY:-}
      OPENAI_API_KEY: ${OPENAI_API_KEY:-}
    profiles:
      - aggregator

# Add to volumes:
  aggregator_db_data:
```

The `aggregator` profile keeps these services opt-in — they only start when explicitly requested:

```bash
docker compose --profile aggregator up -d
```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `AGG_DB_NAME` | PostgreSQL database name | `aggregator` |
| `AGG_DB_USER` | PostgreSQL user | `aggregator` |
| `AGG_DB_PASSWORD` | PostgreSQL password | (required) |
| `AI_PROVIDER` | AI backend: `claude` or `openai` | `claude` |
| `ANTHROPIC_API_KEY` | Anthropic API key (if using Claude) | — |
| `OPENAI_API_KEY` | OpenAI API key (if using OpenAI) | — |
| `AGG_FETCH_INTERVAL` | Default fetch interval | `24h` |
| `AGG_RELEVANCE_THRESHOLD` | Minimum relevance score to store | `0.3` |
| `AGG_LINK_MAX_DEPTH` | Maximum crawl depth for link following | `2` |
| `AGG_LINK_BATCH_SIZE` | Max discovered links to process per run | `50` |
| `AGG_LINK_RATE_LIMIT` | Seconds between requests to the same domain | `2` |
| `AGG_LINK_RELEVANCE_THRESHOLD` | Minimum relevance to ingest a discovered link | `0.4` |
| `AGG_LINK_EXTRA_DOMAINS` | Comma-separated extra domains to allow for link following | — |
| `AGG_DATABASE_URL` | Full PostgreSQL connection string (overrides individual vars) | — |

For production (Plesk), `AGG_DATABASE_URL` points to a PostgreSQL instance on the server. The Next.js app reads it to serve the `/research` API routes.

## Phased Implementation

### Phase 1 — Database & Skeleton (Week 1–2)

- Set up PostgreSQL + Apache AGE in Docker Compose
- Create SQL schema (`aggregator/sql/init.sql`)
- Scaffold Python package with config, DB connection, models
- Implement RSS fetcher with 3–5 seed Catholic media sources
- Basic pipeline: fetch → deduplicate → store (no AI yet)
- Verify articles land in the database

### Phase 2 — AI Classification (Week 3–4)

- Implement AI provider abstraction (Claude + OpenAI)
- Build classifier: relevance scoring, tag assignment, entity extraction, summarization
- Define initial controlled tag vocabulary (e.g. "AI Ethics", "Digital Evangelization", "Church Documents", "Open Source", "Data Privacy")
- Integrate AI step into pipeline
- Add processing log for observability

### Phase 3 — Knowledge Graph (Week 5–6)

- Build Apache AGE graph sync (nodes + edges from relational data)
- Implement co-occurrence analysis (entities that appear together across articles)
- Add AI-inferred entity relationships (`RELATED_TO` edges)
- Cypher query helpers for graph traversal

### Phase 4 — Frontend Search (Week 7–8)

- Next.js API routes for search, articles, tags, entities
- PostgreSQL connection pool in Next.js (`pg` package)
- `/research` page with search bar + faceted filters
- Article detail view with entity sidebar
- Responsive design with existing CDCF styling (`cdcf-section`, `cdcf-heading`, etc.)

### Phase 5 — Graph Visualization & Polish (Week 9–10)

- D3.js force-directed graph component
- Click-to-filter integration between graph and search
- Performance optimization (pagination, caching, lazy loading)
- Add more sources (Vatican.va, diocesan tech blogs, Catholic tech project feeds)
- Monitoring & alerting for pipeline failures
- Documentation

## File List

### New Files

```
aggregator/
├── Dockerfile
├── pyproject.toml
├── __init__.py
├── __main__.py
├── config.py
├── db.py
├── models.py
├── pipeline.py
├── fetchers/
│   ├── __init__.py
│   ├── base.py
│   ├── rss.py
│   ├── web.py
│   └── vatican.py
├── ai/
│   ├── __init__.py
│   ├── base.py
│   ├── claude.py
│   └── openai.py
├── processors/
│   ├── __init__.py
│   ├── classifier.py
│   ├── extractor.py
│   ├── summarizer.py
│   └── link_follower.py
├── graph/
│   ├── __init__.py
│   └── builder.py
└── sql/
    └── init.sql

src/app/[lang]/research/
├── page.tsx
└── [id]/page.tsx

src/app/api/research/
├── search/route.ts
├── articles/[id]/route.ts
├── tags/route.ts
├── entities/route.ts
└── graph/route.ts

src/components/research/
├── SearchBar.tsx
├── FacetedFilters.tsx
├── ArticleList.tsx
├── ArticleDetail.tsx
├── KnowledgeGraph.tsx        # 'use client' — D3.js
├── GraphControls.tsx         # 'use client'
└── RelevanceBadge.tsx

src/lib/research/
├── db.ts                     # pg connection pool
├── queries.ts                # SQL query builders
└── types.ts                  # TypeScript interfaces
```

### Modified Files

```
docker-compose.yml            # Add aggregator-db + aggregator services
.env.example                  # Add AGG_* and AI provider variables
src/i18n/routing.ts           # (no change needed — /research is locale-aware by default)
messages/*.json               # Add research.* translation keys
CLAUDE.md                     # Document aggregator subsystem
```

## Initial Source List

| Source | Type | URL | Language |
|--------|------|-----|----------|
| Vatican News | RSS | `https://www.vaticannews.va/en.rss.xml` | EN |
| Catholic News Agency (CNA) | RSS | `https://www.catholicnewsagency.com/feed` | EN |
| EWTN / National Catholic Register | RSS | `https://ncregister.com/feeds/general-news.xml` | EN |
| Our Sunday Visitor (OSV News) | RSS | `https://www.osv.com/RSS.aspx` | EN |
| America Magazine | RSS | `https://www.americamagazine.org/feed` | EN |
| National Catholic Reporter | RSS | `https://www.ncronline.org/rss.xml` | EN |
| The Pillar | RSS | `https://www.pillarcatholic.com/feed` | EN |
| Catholic World News | RSS | `https://feeds.feedburner.com/CatholicWorldNewsFeatureStories` | EN |
| Catholic Online | RSS | `https://www.catholic.org/xml/` | EN |
| Crux | RSS | `https://cruxnow.com/feed` | EN |
| ACI Prensa | RSS | `https://www.aciprensa.com/rss/news` | ES |
| ACI Digital | RSS | `https://www.acidigital.com/rss/news` | PT |
| ZENIT | RSS | `https://zenit.org/feed/` | EN |
| Catholic Herald | RSS | `https://catholicherald.co.uk/feed/` | EN |
| Aleteia | RSS | `https://aleteia.org/feed/` | EN |
| Holy See Documents | Vatican | `https://www.vatican.va/content/vatican/en.html` | EN |
| USCCB News | RSS | `https://www.usccb.org/subscribe/rss` | EN |

Sources are stored in the `sources` table and can be added/removed at any time without code changes.
