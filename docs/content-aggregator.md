# Catholic Tech Content Aggregator — Implementation Plan

## Overview

An AI-powered system that daily scours Catholic media (RSS feeds, news sites, Vatican documents), multimedia (YouTube channels, podcasts), and academic sources (arXiv papers, Catholic university news) for content related to technology and the Church. Results are stored in PostgreSQL with full-text search, linked in a knowledge graph, and made searchable/browsable on the CDCF website.

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│  Docker Compose                                              │
│                                                              │
│  ┌─────────────┐    ┌──────────────────────────────────────┐ │
│  │  PostgreSQL  │    │  Python Worker (aggregator)          │ │
│  │  + AGE ext.  │◄───│  - Fetcher (RSS + Crawl4AI + yt-dlp)│ │
│  │              │    │  - Transcriber (Whisper)             │ │
│  │  • articles  │    │  - AI Classifier (Claude / OpenAI)   │ │
│  │  • FTS index │    │  - Pipeline orchestrator             │ │
│  │  • knowledge │    │  - Graph builder (AGE)               │ │
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
- **[Crawl4AI](https://github.com/unclecode/crawl4ai)** — open-source web crawler purpose-built for LLM pipelines. Outputs clean Markdown from any web page (including JavaScript-rendered content via Playwright), eliminating the need for manual HTML-to-text extraction. RSS feeds are still parsed directly with `feedparser`.
- **Multimedia ingestion** — YouTube channels and podcasts are first-class source types. Audio/video content is transcribed and summarized by dedicated tools before entering the standard AI classification pipeline (see [Multimedia Processing](#multimedia-processing) below).
- **Academic sources** — arXiv papers, Catholic university news, and academic institution feeds are monitored for research at the intersection of technology, ethics, and the Church. Papers are fetched via the arXiv API and PDF text extraction; university news via RSS/web crawling (see [Academic & Scientific Sources](#academic--scientific-sources) below).
- **Decoupled from WordPress** — the aggregator is an independent subsystem. WordPress continues to serve CMS content; the research section queries PostgreSQL directly.

## Database Schema

### PostgreSQL Tables

```sql
-- Sources (RSS feeds, websites to monitor)
CREATE TABLE sources (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    url             TEXT NOT NULL UNIQUE,
    source_type     TEXT NOT NULL CHECK (source_type IN ('rss', 'web', 'vatican', 'api', 'youtube', 'podcast', 'arxiv')),
    origin          TEXT NOT NULL DEFAULT 'manual' CHECK (origin IN ('manual', 'discovered')),
    fetch_interval  INTERVAL NOT NULL DEFAULT '1 day',
    last_fetched_at TIMESTAMPTZ,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    config          JSONB DEFAULT '{}',  -- source-specific settings (selectors, auth, etc.)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Candidate sources discovered by the aggregator, pending promotion
CREATE TABLE candidate_sources (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    url             TEXT NOT NULL UNIQUE,
    source_type     TEXT NOT NULL CHECK (source_type IN ('rss', 'web', 'youtube', 'podcast', 'arxiv')),
    discovered_from INT REFERENCES articles(id),  -- article where this source was found
    confidence      REAL NOT NULL DEFAULT 0.0,     -- AI confidence score 0.0–1.0
    hit_count       INT NOT NULL DEFAULT 1,        -- how many times links from this domain appeared
    avg_relevance   REAL NOT NULL DEFAULT 0.0,     -- average relevance of articles from this domain
    status          TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'auto_promoted', 'approved', 'rejected')),
    promoted_to     INT REFERENCES sources(id),    -- set when promoted to active source
    reviewed_at     TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_candidate_sources_status ON candidate_sources (status) WHERE status = 'pending';

-- Articles / documents discovered
CREATE TABLE articles (
    id              SERIAL PRIMARY KEY,
    source_id       INT NOT NULL REFERENCES sources(id),
    external_url    TEXT NOT NULL UNIQUE,
    title           TEXT NOT NULL,
    author          TEXT,
    published_at    TIMESTAMPTZ,
    fetched_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    content_type    TEXT NOT NULL DEFAULT 'article' CHECK (content_type IN ('article', 'video', 'podcast_episode', 'audio', 'paper')),
    content_text    TEXT,               -- plain text or transcript (for FTS)
    content_html    TEXT,               -- original HTML (null for AV content)
    transcript      TEXT,               -- full transcript for audio/video content
    summary         TEXT,               -- AI-generated summary
    language        TEXT DEFAULT 'en',
    duration_seconds INT,               -- duration for audio/video content
    media_url       TEXT,               -- direct URL to audio/video file or stream
    thumbnail_url   TEXT,               -- thumbnail/poster image URL
    relevance_score REAL DEFAULT 0.0,   -- AI-assigned 0.0–1.0
    metadata        JSONB DEFAULT '{}', -- arbitrary extra fields

    -- Full-text search vector (auto-updated via trigger)
    -- For AV content, transcript is indexed at weight C alongside content_text
    search_vector   TSVECTOR GENERATED ALWAYS AS (
        setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(summary, '')), 'B') ||
        setweight(to_tsvector('english', coalesce(content_text, '')), 'C') ||
        setweight(to_tsvector('english', coalesce(transcript, '')), 'C')
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
        'person', 'organization', 'project', 'document', 'event', 'location', 'concept', 'university', 'journal'
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
│   ├── crawl4ai.py       # Web fetcher using Crawl4AI (Markdown output)
│   ├── vatican.py        # Vatican.va specific fetcher (extends crawl4ai)
│   ├── youtube.py        # YouTube channel/playlist fetcher (yt-dlp + transcription)
│   ├── podcast.py        # Podcast RSS feed fetcher (feedparser + audio download)
│   └── arxiv.py          # arXiv / academic paper fetcher (arXiv API + PDF extraction)
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
│   ├── transcriber.py    # Audio/video transcription (Whisper)
│   ├── link_follower.py  # Discover + crawl outbound links from articles
│   └── source_discoverer.py  # Auto-discover and promote new sources
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
   a. Fetch new items (RSS → feedparser, web → Crawl4AI)
   b. Deduplicate against existing articles (by external_url)
   c. For each new article:
      i.    For web sources: Crawl4AI returns clean Markdown directly; for RSS: extract text from HTML
      ii.   AI: Score relevance (0.0–1.0) — skip if < 0.3
      iii.  AI: Generate summary (1–2 sentences)
      iv.   AI: Assign tags from controlled vocabulary + suggest new ones
      v.    AI: Extract named entities (people, orgs, projects, documents)
      vi.   Insert article + tags + entities into PostgreSQL
      vii.  Sync to Apache AGE graph (nodes + edges)
      viii. Log processing result
3. Link-following pass (see below)
4. Source discovery pass (see below)
5. Run co-occurrence analysis across recent articles
6. Update graph edges for entity relationships
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
      i.   Fetch the target URL via Crawl4AI (respecting robots.txt and rate limits)
      ii.  Crawl4AI returns clean Markdown — ready for AI processing
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
- University/academic domains (`.edu`, `.ac.*`, `arxiv.org`, `doi.org`, `scholar.google.com`)
- Church organization domains (`usccb.org`, national bishops' conferences)
- Curated additions stored in a `link_domain_allowlist` config table

Links to social media (Twitter/X, Facebook, Instagram), generic platforms (Medium), and unrecognized domains are skipped by default. YouTube links discovered within articles are evaluated: if they point to a channel already in the `sources` table, the specific video is queued for transcription; otherwise they are skipped. The allowlist can be extended at runtime without code changes via the `link_domain_allowlist` table or a `AGG_LINK_EXTRA_DOMAINS` environment variable.

### Source Discovery

As the aggregator follows links and ingests articles, it tracks which external domains consistently produce relevant content. When a domain crosses a confidence threshold, it is automatically promoted to a crawlable source — enabling the system to organically grow its source list over time.

```
4. Source discovery pass:
   a. Aggregate stats from recently ingested articles by domain:
      - Count of articles ingested from this domain
      - Average relevance score across those articles
      - Whether the domain offers an RSS feed (auto-detected via <link rel="alternate"> or /feed, /rss paths)
   b. For each domain not already in sources or candidate_sources:
      i.   AI: Evaluate the domain — is it a Catholic news outlet, blog, or institutional site?
           Score confidence (0.0–1.0) based on domain name, article content patterns, and About page
      ii.  Insert into candidate_sources with confidence score
   c. For existing candidate_sources, update hit_count and avg_relevance with new data
   d. Auto-promote candidates that meet ALL of these criteria:
      - confidence >= AGG_SOURCE_AUTO_PROMOTE_CONFIDENCE (default 0.8)
      - hit_count >= AGG_SOURCE_MIN_HITS (default 5)
      - avg_relevance >= AGG_SOURCE_MIN_AVG_RELEVANCE (default 0.6)
      i.   Insert into sources table (origin='discovered', is_active=true)
      ii.  Set candidate_sources.status='auto_promoted', promoted_to=new source id
      iii. Log the promotion in processing_log
   e. Sources that don't meet auto-promote thresholds remain as 'pending' candidates
      for manual review via the admin interface
```

**Safeguards:**

| Setting | Default | Description |
|---------|---------|-------------|
| `AGG_SOURCE_AUTO_PROMOTE_CONFIDENCE` | `0.8` | Minimum AI confidence to auto-promote a candidate source |
| `AGG_SOURCE_MIN_HITS` | `5` | Minimum articles from this domain before promotion is considered |
| `AGG_SOURCE_MIN_AVG_RELEVANCE` | `0.6` | Minimum average relevance score across articles from this domain |
| `AGG_SOURCE_MAX_AUTO_PER_RUN` | `3` | Maximum sources to auto-promote per pipeline run (prevents runaway growth) |

**Manual review:** Candidates below the auto-promote threshold are visible in the admin interface (and future `/research` admin panel) where a human can approve or reject them. Rejected candidates are not reconsidered unless explicitly reset.

**RSS auto-detection:** When a candidate is promoted, the system attempts to find an RSS/Atom feed on the domain (checking `<link rel="alternate" type="application/rss+xml">`, common paths like `/feed`, `/rss`, `/rss.xml`). If found, the source is created with `source_type='rss'`; otherwise it falls back to `source_type='web'` for HTML scraping.

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

### Web Fetching with Crawl4AI

[Crawl4AI](https://github.com/unclecode/crawl4ai) is the web fetching layer for all non-RSS sources. It replaces the typical `httpx + BeautifulSoup` approach with a purpose-built crawler that outputs clean, LLM-ready Markdown.

**Why Crawl4AI over raw HTTP + HTML parsing:**

- **Markdown output** — pages are converted to clean Markdown automatically, removing boilerplate (nav, footer, ads, sidebars). This feeds directly into the AI classifier without a separate text-extraction step.
- **JavaScript rendering** — built on Playwright, so it handles SPAs, lazy-loaded content, and infinite scroll (common on modern news sites).
- **Structured extraction** — supports CSS/XPath selectors and LLM-powered extraction with custom schemas, useful for consistently structured pages like Vatican document indexes.
- **Session management** — persistent browser sessions for sites that require cookies or authentication.
- **Self-hosted** — runs entirely within the Docker stack, no external API dependencies or per-request costs.

**Integration in the pipeline:**

```python
# aggregator/fetchers/crawl4ai.py
from crawl4ai import AsyncWebCrawler, CrawlerRunConfig, BrowserConfig

class Crawl4AIFetcher(BaseFetcher):
    async def fetch(self, url: str) -> FetchResult:
        browser_config = BrowserConfig(headless=True)
        run_config = CrawlerRunConfig(
            word_count_threshold=50,       # skip pages with very little content
            excluded_tags=["nav", "footer", "aside", "header"],
            bypass_cache=False,
        )
        async with AsyncWebCrawler(config=browser_config) as crawler:
            result = await crawler.arun(url=url, config=run_config)
            return FetchResult(
                markdown=result.markdown,          # clean Markdown for AI
                raw_html=result.html,              # preserved for reference
                links=result.links,                # extracted outbound links (for link-following)
                metadata=result.metadata,          # title, description, author, etc.
            )
```

Crawl4AI also extracts all outbound links from the page, which feeds directly into the link-following step — no separate link-extraction pass needed.

**RSS feeds** are still handled by `feedparser` directly, since RSS provides structured data (title, content, date, author) without needing a browser. When an RSS entry links to a full article, Crawl4AI fetches the full page content if the RSS excerpt is truncated.

### Multimedia Processing

YouTube channels, podcasts, and other audio/video sources are first-class content types. Since the AI classification pipeline operates on text, multimedia content must be **transcribed** before it can be scored, tagged, and summarized.

#### Transcription Stack

| Tool | Purpose | Notes |
|------|---------|-------|
| **[OpenAI Whisper](https://github.com/openai/whisper)** (local) | Speech-to-text transcription | Runs locally via `faster-whisper` (CTranslate2-optimized). No API costs. Model size configurable (`base`, `small`, `medium`, `large-v3`). |
| **OpenAI Whisper API** (cloud fallback) | Speech-to-text transcription | Used when local transcription is disabled or for languages where local models underperform. Per-minute cost. |
| **[yt-dlp](https://github.com/yt-dlp/yt-dlp)** | YouTube audio extraction | Downloads audio-only streams (no video) to minimize bandwidth/storage. Also extracts metadata (title, description, duration, thumbnail, upload date, channel info). |
| **[feedparser](https://github.com/kurtmckee/feedparser)** | Podcast RSS parsing | Parses podcast RSS/Atom feeds to discover episodes. Extracts enclosure URLs (audio files), episode metadata, and show notes. |

The transcription provider is selected via the `AGG_TRANSCRIPTION_PROVIDER` environment variable (`local` or `openai_api`). Local transcription is the default to avoid per-minute API costs.

#### YouTube Fetcher

```python
# aggregator/fetchers/youtube.py
class YouTubeFetcher(BaseFetcher):
    """Fetches new videos from YouTube channels/playlists."""

    async def fetch(self, source_url: str) -> list[FetchResult]:
        # 1. Use yt-dlp to list recent videos from channel/playlist
        #    (--flat-playlist --dateafter <last_fetched>)
        # 2. For each new video:
        #    a. Download audio-only stream (yt-dlp -x --audio-format mp3)
        #    b. Extract metadata (title, description, duration, thumbnail, upload date)
        #    c. Check for existing YouTube captions (yt-dlp --write-auto-sub)
        #       - If captions exist, use them directly (faster, free)
        #       - If no captions, transcribe audio via Whisper
        #    d. Clean up downloaded audio file after transcription
        # 3. Return FetchResult with transcript as content_text,
        #    video description as content_html, media metadata populated
```

**YouTube caption priority:** Many YouTube videos already have auto-generated or manually uploaded captions. The fetcher checks for these first (via `yt-dlp --write-auto-sub --sub-lang en`) and uses them when available, falling back to Whisper transcription only when captions are missing or of poor quality.

#### Podcast Fetcher

```python
# aggregator/fetchers/podcast.py
class PodcastFetcher(BaseFetcher):
    """Fetches new episodes from podcast RSS feeds."""

    async def fetch(self, source_url: str) -> list[FetchResult]:
        # 1. Parse podcast RSS feed with feedparser
        # 2. For each new episode (since last_fetched):
        #    a. Download audio file from enclosure URL
        #    b. Extract episode metadata (title, description, duration, publish date)
        #    c. Transcribe audio via Whisper
        #    d. Use show notes / episode description as supplementary content
        #    e. Clean up downloaded audio file after transcription
        # 3. Return FetchResult with transcript as content_text,
        #    show notes as content_html, media metadata populated
```

#### Transcription Processor

```python
# aggregator/processors/transcriber.py
class Transcriber:
    """Transcribes audio/video content to text."""

    async def transcribe(self, audio_path: str, language: str = None) -> TranscriptionResult:
        if self.provider == 'local':
            # Use faster-whisper (CTranslate2-optimized Whisper)
            from faster_whisper import WhisperModel
            model = WhisperModel(self.model_size, device="cpu", compute_type="int8")
            segments, info = model.transcribe(audio_path, language=language)
            text = " ".join(seg.text for seg in segments)
        elif self.provider == 'openai_api':
            # Use OpenAI Whisper API
            with open(audio_path, "rb") as f:
                response = openai.audio.transcriptions.create(
                    model="whisper-1", file=f, language=language
                )
            text = response.text

        return TranscriptionResult(
            text=text,
            language=info.language if self.provider == 'local' else language,
            duration_seconds=info.duration if self.provider == 'local' else None,
        )
```

#### Pipeline Integration

For multimedia sources, the pipeline flow is extended:

```
2. For each source due for refresh:
   a. Fetch new items:
      - RSS → feedparser
      - Web → Crawl4AI
      - YouTube → yt-dlp (audio extraction + metadata)
      - Podcast → feedparser (RSS) + audio download
   b. For YouTube/podcast items:
      i.   Check for existing captions/transcripts (YouTube only)
      ii.  If no captions: transcribe audio via Whisper (local or API)
      iii. Set content_text = transcript, content_type = 'video' or 'podcast_episode'
      iv.  Store duration_seconds, media_url, thumbnail_url
   c. Deduplicate against existing articles (by external_url)
   d. For each new article/episode/video:
      ... (standard AI classification pipeline continues unchanged)
```

The AI classifier receives the transcript as `content_text` and processes it identically to article text — no special handling needed for relevance scoring, tagging, entity extraction, or summarization. The `content_type` field allows the frontend to render multimedia items with appropriate UI (embedded player, duration badge, etc.).

#### Storage Considerations

- **Audio files are not stored permanently.** They are downloaded to a temporary directory, transcribed, and deleted. Only the transcript text is persisted in the database.
- **Transcripts can be long** (a 1-hour podcast produces ~10,000 words). The `transcript` column stores the full text for FTS, while `summary` stores the AI-generated 1–2 sentence summary as with articles.
- **YouTube thumbnails and media URLs** are stored as references (not downloaded) since they are served by YouTube/podcast CDNs.

#### Safeguards

| Setting | Default | Description |
|---------|---------|-------------|
| `AGG_TRANSCRIPTION_PROVIDER` | `local` | Transcription backend: `local` (faster-whisper) or `openai_api` |
| `AGG_WHISPER_MODEL` | `base` | Whisper model size for local transcription (`base`, `small`, `medium`, `large-v3`) |
| `AGG_MAX_AUDIO_DURATION` | `7200` | Maximum audio duration in seconds to transcribe (skip files longer than 2 hours) |
| `AGG_AUDIO_TEMP_DIR` | `/tmp/aggregator-audio` | Temporary directory for audio downloads (cleaned after transcription) |
| `AGG_YOUTUBE_MAX_VIDEOS_PER_RUN` | `10` | Maximum new videos to process per channel per pipeline run |
| `AGG_PODCAST_MAX_EPISODES_PER_RUN` | `5` | Maximum new episodes to process per podcast per pipeline run |

### Academic & Scientific Sources

Academic papers, Catholic university news sites, and research institution feeds are monitored for scholarly work at the intersection of technology, ethics, theology, and the Church.

#### arXiv Fetcher

arXiv provides a free [API](https://info.arxiv.org/help/api/index.html) (Atom-based) that supports keyword queries across titles, abstracts, and full text. The fetcher runs periodic searches against relevant arXiv categories and keyword combinations.

```python
# aggregator/fetchers/arxiv.py
import arxiv  # python-arxiv wrapper

class ArxivFetcher(BaseFetcher):
    """Fetches papers from arXiv matching Catholic-tech-relevant queries."""

    # Targeted arXiv categories
    CATEGORIES = ["cs.AI", "cs.CY", "cs.HC", "cs.SI", "cs.CL"]

    # Keyword queries (combined with categories via AND)
    QUERIES = [
        "catholic OR church OR vatican OR theology",
        "religious AND (technology OR digital OR AI)",
        "ethics AND (artificial intelligence OR machine learning)",
        "digital evangelization OR digital ministry",
        "canon law AND (software OR digital OR database)",
    ]

    async def fetch(self, source_url: str) -> list[FetchResult]:
        # 1. For each category+query combination:
        #    a. Search arXiv API (sorted by submittedDate, max_results per query)
        #    b. Filter to papers submitted since last_fetched_at
        # 2. For each new paper:
        #    a. Extract metadata (title, authors, abstract, categories, DOI, published date)
        #    b. Download PDF and extract full text via PyMuPDF (fitz)
        #    c. Set content_type='paper', content_text=full_text, summary=abstract
        #    d. Store arxiv_id, DOI, author list, categories in metadata JSONB
        #    e. Clean up downloaded PDF
        # 3. Return FetchResults
```

**PDF text extraction** uses [PyMuPDF](https://pymupdf.readthedocs.io/) (`fitz`) to extract text from downloaded PDFs. This is fast and local — no OCR needed since arXiv PDFs contain embedded text. For papers where PDF extraction yields poor results (scanned documents, heavy math notation), the abstract alone is used as `content_text`.

#### University News & Academic Institutions

Catholic university newsrooms and research centers are crawled via the standard RSS or web fetchers — no special fetcher needed. They are simply added to the `sources` table with `source_type='rss'` or `source_type='web'` and a `config` JSONB field that can include CSS selectors for news article extraction.

The AI classifier handles these like any other source, but the `content_type='article'` is used (not `'paper'`) since university news is journalistic rather than scholarly. Actual research papers linked from university news sites will be discovered via the link-following step and ingested with `content_type='paper'` if they lead to arXiv, DOI-resolvable URLs, or institutional repositories.

#### Pipeline Integration

For arXiv sources, the pipeline flow extends step 2:

```
2. For each source due for refresh:
   a. Fetch new items:
      - RSS → feedparser
      - Web → Crawl4AI
      - YouTube → yt-dlp
      - Podcast → feedparser + audio download
      - arXiv → arXiv API + PDF text extraction
   b. For arXiv papers:
      i.   Use abstract as initial content for quick relevance pre-screening
      ii.  If abstract relevance >= threshold: download PDF, extract full text
      iii. Set content_type='paper', store authors/categories/DOI in metadata
      iv.  Create 'university' and 'journal' entities from author affiliations
   c. Deduplicate against existing articles (by external_url / arxiv_id)
   d. For each new article/paper/episode/video:
      ... (standard AI classification pipeline continues unchanged)
```

#### Knowledge Graph Extensions

Academic sources enrich the knowledge graph with new node and edge types:

| Label | Properties | Mapped from |
|-------|-----------|-------------|
| `Entity` (type=`university`) | `name`, `location`, `external_url` | Author affiliations, source metadata |
| `Entity` (type=`journal`) | `name`, `external_url` | Paper publication venue |

| Edge | From → To | Properties |
|------|-----------|------------|
| `AUTHORED_BY` | Article (paper) → Entity (person) | `affiliation`, `position` |
| `AFFILIATED_WITH` | Entity (person) → Entity (university) | — |
| `PUBLISHED_IN` | Article (paper) → Entity (journal) | `doi`, `volume`, `year` |
| `CITES` | Article (paper) → Article (paper) | — |

The `CITES` edge is created when arXiv papers reference other papers already in the database (matched by arXiv ID or DOI). This builds a **citation graph** within the knowledge graph, enabling queries like "which papers on AI ethics cite Vatican documents?"

#### Safeguards

| Setting | Default | Description |
|---------|---------|-------------|
| `AGG_ARXIV_MAX_RESULTS_PER_QUERY` | `20` | Maximum papers to fetch per arXiv query per run |
| `AGG_ARXIV_FETCH_INTERVAL` | `7 days` | How often to re-run arXiv queries (papers don't change frequently) |
| `AGG_ARXIV_PDF_MAX_PAGES` | `50` | Skip PDF extraction for papers longer than this (use abstract only) |
| `AGG_ARXIV_ABSTRACT_RELEVANCE_THRESHOLD` | `0.4` | Minimum abstract relevance to justify downloading the full PDF |

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
  # Includes Crawl4AI + Playwright, yt-dlp, faster-whisper
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
      AGG_TRANSCRIPTION_PROVIDER: ${AGG_TRANSCRIPTION_PROVIDER:-local}
      AGG_WHISPER_MODEL: ${AGG_WHISPER_MODEL:-base}
    # Crawl4AI uses Playwright which needs shared memory for Chromium
    shm_size: '512m'
    # Temporary storage for audio downloads during transcription
    tmpfs:
      - /tmp/aggregator-audio:size=2G
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
| `AGG_SOURCE_AUTO_PROMOTE_CONFIDENCE` | Minimum AI confidence to auto-promote a discovered source | `0.8` |
| `AGG_SOURCE_MIN_HITS` | Minimum articles from a domain before promotion is considered | `5` |
| `AGG_SOURCE_MIN_AVG_RELEVANCE` | Minimum average relevance for auto-promotion | `0.6` |
| `AGG_SOURCE_MAX_AUTO_PER_RUN` | Maximum sources to auto-promote per pipeline run | `3` |
| `AGG_TRANSCRIPTION_PROVIDER` | Transcription backend: `local` (faster-whisper) or `openai_api` | `local` |
| `AGG_WHISPER_MODEL` | Whisper model size for local transcription | `base` |
| `AGG_MAX_AUDIO_DURATION` | Maximum audio duration in seconds to transcribe | `7200` |
| `AGG_AUDIO_TEMP_DIR` | Temporary directory for audio downloads | `/tmp/aggregator-audio` |
| `AGG_YOUTUBE_MAX_VIDEOS_PER_RUN` | Max new videos to process per channel per run | `10` |
| `AGG_PODCAST_MAX_EPISODES_PER_RUN` | Max new episodes to process per podcast per run | `5` |
| `AGG_ARXIV_MAX_RESULTS_PER_QUERY` | Max papers to fetch per arXiv query per run | `20` |
| `AGG_ARXIV_FETCH_INTERVAL` | How often to re-run arXiv queries | `7 days` |
| `AGG_ARXIV_PDF_MAX_PAGES` | Skip PDF extraction for papers longer than this | `50` |
| `AGG_ARXIV_ABSTRACT_RELEVANCE_THRESHOLD` | Minimum abstract relevance to download full PDF | `0.4` |
| `AGG_DATABASE_URL` | Full PostgreSQL connection string (overrides individual vars) | — |

For production (Plesk), `AGG_DATABASE_URL` points to a PostgreSQL instance on the server. The Next.js app reads it to serve the `/research` API routes.

## Phased Implementation

### Phase 1 — Database & Skeleton (Week 1–2)

- Set up PostgreSQL + Apache AGE in Docker Compose
- Create SQL schema (`aggregator/sql/init.sql`)
- Scaffold Python package with config, DB connection, models
- Implement RSS fetcher (`feedparser`) with 3–5 seed Catholic media sources
- Implement Crawl4AI web fetcher for non-RSS sources
- Implement YouTube fetcher (`yt-dlp`) and podcast fetcher
- Implement arXiv fetcher (`arxiv` + `PyMuPDF` for PDF text extraction)
- Set up Whisper transcription processor (`faster-whisper`)
- Basic pipeline: fetch → transcribe (if AV) → deduplicate → store (no AI yet)
- Verify articles land in the database

### Phase 2 — AI Classification (Week 3–4)

- Implement AI provider abstraction (Claude + OpenAI)
- Build classifier: relevance scoring, tag assignment, entity extraction, summarization
- Define initial controlled tag vocabulary (e.g. "AI Ethics", "Digital Evangelization", "Church Documents", "Open Source", "Data Privacy", "Academic Research", "Bioethics", "Science-Faith Dialogue", "Catholic Social Teaching")
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
│   ├── crawl4ai.py
│   ├── vatican.py
│   ├── youtube.py
│   ├── podcast.py
│   └── arxiv.py
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
│   ├── transcriber.py
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

### YouTube Channels

| Source | Type | URL | Language |
|--------|------|-----|----------|
| Bishop Robert Barron | YouTube | `https://www.youtube.com/@BishopBarron` | EN |
| Ascension Presents (Fr. Mike Schmitz) | YouTube | `https://www.youtube.com/@AscensionPresents` | EN |
| Catholic Answers | YouTube | `https://www.youtube.com/@catholiccom` | EN |
| EWTN | YouTube | `https://www.youtube.com/@ABORREZNOV` | EN |
| Vatican News | YouTube | `https://www.youtube.com/@VaticanNews` | EN |
| Breaking in the Habit | YouTube | `https://www.youtube.com/@BreakingInTheHabit` | EN |
| The Thomistic Institute | YouTube | `https://www.youtube.com/@TheThomisticInstitute` | EN |

### Podcasts

| Source | Type | Feed URL | Language |
|--------|------|----------|----------|
| The Catholic Talk Show | Podcast | `https://feeds.buzzsprout.com/180009.rss` | EN |
| Pints with Aquinas | Podcast | `https://feeds.buzzsprout.com/25546.rss` | EN |
| Catholic Stuff You Should Know | Podcast | `https://catholicstuffpodcast.com/feed/podcast` | EN |
| Godsplaining | Podcast | `https://feeds.soundcloud.com/users/soundcloud:users:267228498/sounds.rss` | EN |
| The Pillar Podcast | Podcast | `https://www.pillarcatholic.com/feed/podcast` | EN |
| Jimmy Akin's Mysterious World | Podcast | `https://sqpn.com/category/podcasts/mysterious-world/feed/` | EN |

### Academic & Scientific

| Source | Type | URL | Language | Notes |
|--------|------|-----|----------|-------|
| arXiv (AI Ethics) | arXiv | `https://export.arxiv.org/api/query` | EN | cs.AI + ethics/religion queries |
| arXiv (Computers & Society) | arXiv | `https://export.arxiv.org/api/query` | EN | cs.CY category |
| Catholic University of America — News | RSS | `https://www.catholic.edu/news/rss.xml` | EN | |
| University of Notre Dame — News | RSS | `https://news.nd.edu/feed/` | EN | |
| Georgetown University — News | Web | `https://www.georgetown.edu/news/` | EN | |
| Boston College — News | RSS | `https://www.bc.edu/content/bc-web/bcnews/rss.xml` | EN | |
| Pontifical Gregorian University | Web | `https://www.unigre.it/en/news/` | EN | |
| Catholic University of the Sacred Heart (Milan) | Web | `https://www.unicatt.it/news` | IT | |
| Pontifical Academy for Life | Web | `https://www.academyforlife.va/content/pav/en/news.html` | EN | AI ethics, bioethics & technology |
| Pontifical Academy of Sciences | Web | `https://www.pas.va/en/news.html` | EN | Science–faith dialogue |
| Vatican Dicastery for Communication | Web | `https://www.dicasteriumcommunication.va/en.html` | EN | Digital communication policy |
| McGrath Institute for Church Life (Notre Dame) | RSS | `https://mcgrath.nd.edu/feed/` | EN | Church & technology intersection |

Sources are stored in the `sources` table and can be added/removed at any time without code changes.
