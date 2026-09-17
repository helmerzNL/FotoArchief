# AI capability decision and proof gate

**Status:** approved implementation gate for AI rounds  
**Decision date:** 2026-09-16  
**Scope:** image-content analysis, Dutch suggestions and multimodal semantic
search for FotoArchief

## Decision

FotoArchief supports AI only as an explicit opt-in capability. The first
supported topology is:

1. a local or organisation-owned HTTP AI service for image analysis and
   multimodal text/image embeddings;
2. an optional external provider with a separate opt-in, separate credentials
   and separate data-processing notice;
3. PostgreSQL as the authoritative source of truth and `pgvector` as the first
   supported vector backend when semantic search is enabled.

No provider, model or vector index is enabled by default. The application must not silently fall back from local processing to an external provider. If no
approved embedding backend is configured, semantic image-content search is
unavailable with an explicit operational error; it must not degrade to a slow
caption-only scan while presenting itself as visual search.

This document is the step-39 decision gate. Adapter implementation in the next
steps may proceed only by preserving the gate below.

## Non-negotiable behavior

- AI receives only explicitly allowed, validated derivatives of the current
  primary asset file, never raw quarantine uploads.
- The derivative sent to AI strips embedded metadata where possible.
- No face recognition, identity inference or sensitive-attribute classification
  is in scope.
- Suggestions never write metadata automatically. They wait for human review
  and then flow through existing metadata revisions, optimistic locking, audit
  events and publication invalidation.
- Search authorization remains SQL-driven. Vector results are candidates only;
  existing ownership, rights, privacy embargo, publication and soft-delete
  predicates are re-applied before counts or results are returned.
- Model output cannot invent exact years, places or identities as historical
  facts. Suggested descriptions and tags are clearly marked as AI suggestions.
- Each embedding generation is isolated by provider, model id, dimensions,
  distance metric and generation id. Different model spaces are never mixed.

## Alternatives assessed

| Option | License / operations | Privacy | Resource profile | Cost profile | Decision |
| --- | --- | --- | --- | --- | --- |
| Organisation-owned HTTP service running CLIP/OpenCLIP-compatible image/text embeddings plus a separate image-caption/tag model | OpenAI CLIP and OpenCLIP code are MIT-licensed; model weights still need per-model review before deployment. Operator controls endpoint lifecycle. | Best fit for private archives because images stay within the organisation boundary. | CPU works for small proof sets but bulk generation is slow; GPU recommended for 50k assets. PHP ZIP hosts call the endpoint over HTTPS instead of running a model locally. | Infrastructure owned by operator; predictable but may require GPU rental. | Supported first-class path. |
| External multimodal provider | Provider terms, region, retention and training settings must be reviewed per concrete provider before use. | Highest risk; search texts and image derivatives leave the installation. Requires explicit administrator approval and user-facing notice. | No local GPU. Latency and rate limits are provider-dependent. | Per-token/image/vector charges; hard monthly budget and per-run caps are required. | Optional only; never fallback. |
| Caption-only search using generated Dutch descriptions | Depends on caption model; easy to index with existing PostgreSQL text search. | Lower data volume after captions exist, but captions are still derived AI data. | Cheap and simple. | Cheap. | Rejected as semantic visual search. It may help display/review, but cannot be sold as image-content retrieval. |
| PostgreSQL `pgvector` | Open-source Postgres extension. README documents exact and approximate nearest-neighbor search, cosine/L2/IP distances and vector limits. Requires extension availability on the database host. | Keeps candidate vectors in the same operational boundary as metadata and backups. | Good MVP fit; HNSW indexes need memory during build. PHP-only/shared hosting may not permit installing extensions. | Low additional service cost where extension is available. | First supported vector backend, guarded by installation capability checks. |
| Separate private vector service | Depends on product. Adds another stateful system and backup surface. | Acceptable only if private and co-located with the same authorization boundary; still derived, not source-of-truth. | Can scale beyond PostgreSQL but requires operations expertise. | Extra service/runtime cost. | Deferred until measured pgvector limits are reached. |

## Capability proof gate

Before an AI adapter is marked production-ready for an installation, run a
small approved proof set containing only non-sensitive images the operator is
allowed to process. The proof must record:

- provider kind: `local` or `external`;
- endpoint base URL without credentials;
- model id and exact model version or digest where the provider exposes it;
- license/terms summary for model code and weights;
- embedding dimensions and distance metric;
- whether image and text embeddings are produced by the same compatible model
  space;
- derivative type and maximum pixels sent to the provider;
- per-image latency p50/p95, failure rate and retry behavior;
- memory/GPU/CPU observations for local service, or published region,
  retention/training policy and price unit for external service;
- vector backend capability: `pgvector` extension present or explicit private
  vector-service endpoint;
- relevance threshold chosen from the proof set with at least one positive and
  one negative Dutch text query per test image.

The proof fails closed when any of these facts is missing. Synthetic timings,
SQLite, mocks and caption-only comparisons are not accepted as proof for a live
provider. The result may be attached to release evidence, but provider secrets
and source images must not be committed.

## Numerical limits for implementation

These are implementation defaults until a later measured proof tightens them:

- AI remains disabled globally until configured by an administrator.
- No automatic full-library scan. Jobs are queued only for explicitly selected
  assets or bounded admin batches.
- Maximum proof batch: 25 assets.
- Maximum default AI derivative edge: 1024 pixels.
- Maximum default provider request timeout: 60 seconds.
- Maximum external monthly budget default: zero, meaning blocked until set.
- Maximum in-flight AI jobs default: one worker process per installation unless
  explicitly increased.
- Vector result candidate cap before SQL authorization filtering: 250.
- Public result page cap after authorization filtering: 24.

## Implementation consequences

- Step 40 must expose explicit configuration, budget limits, provider
  separation and a kill switch. The implemented administration settings keep AI
  off by default, store no provider secrets, require separate local/external
  opt-ins and reject external localhost/private-network endpoints.
- Step 41 persists run status, source asset lock version, source file checksum,
  model space, suggestion review state and embedding generation id in dedicated
  AI tables. Jobs must compare their stored source snapshot with the current
  primary file before writing suggestions or embeddings.
- Step 42 implements the local/organisation-owned HTTP adapter with
  `/v1/capabilities`, `/v1/analyze-image`, `/v1/embed-image` and
  `/v1/embed-text`. It refuses use unless the local provider is explicitly
  enabled and reports one compatible text/image embedding space.
- Step 43 must implement external adapters behind the same contracts with no
  automatic cross-provider fallback. The external adapter refuses use unless
  the admin settings have external provider, external data-processing consent,
  public HTTPS endpoint, region/retention text and non-zero budget configured.
  Provider settings and encrypted API keys are stored in the database and
  managed through the administrator UI. Keys are never returned, rendered or
  logged after storage.
- Step 44 queues AI image analysis through the existing bounded operation-run
  mechanism. HTTP requests record selected asset ids and provider choice only;
  workers read the current clean primary file, call the chosen provider and
  store suggestions as pending review without metadata writes.
- Step 45 exposes pending AI suggestions for human review. Accepting a
  suggestion re-checks the asset lock version and source checksum, writes
  ordinary metadata/tag changes, increments the asset lock version and records
  an audit event. Rejecting a suggestion changes only review status.
- Step 46 builds a bounded image-embedding index through operation runs.
  Embeddings are stored per provider/model space/generation with the source
  asset lock version and primary-file checksum. Text search must embed the
  query through the same provider/model space; caption-only retrieval is still
  not accepted as visual semantic search.
- Step 47 adds staff/admin semantic search over the bounded image-embedding
  candidate set. Every result is filtered through the existing AssetPolicy, so
  embeddings rank candidates but SQL ownership/publication rules decide what a
  user may see.
- Step 48 exposes optional public image-content search on the existing
  discovery route. The vector result list is only an ordered candidate set;
  the response is rebuilt through Publication::publiclyVisible(), so revoked,
  embargoed, privacy-blocked, unclean or lock-version-invalid assets still do
  not render or affect public pagination.
- Step 46 must index real multimodal image embeddings; captions alone are not a
  substitute.
- Steps 47 and 48 must apply SQL authorization and publication predicates after
  vector candidate retrieval and before counts, snippets or previews.

## Sources consulted

- OpenAI CLIP license: MIT.
- OpenCLIP license: MIT.
- pgvector README: stores vectors in PostgreSQL, supports exact/approximate
  nearest-neighbor search, cosine/L2/IP distances, HNSW indexes and documented
  vector dimensional limits.

## Native external providers

FotoArchief can call the official OpenAI, Anthropic, Gemini and OpenRouter APIs
directly for image analysis. Each native provider is separately configured in
the database through the administrator UI and separately enabled by an
administrator. Provider API keys are encrypted at rest and are never displayed
again. The image-analysis and embedding provider/model selections are
independent.

Only Gemini and an explicit OpenRouter multimodal-model allowlist are permitted
for native semantic embeddings. OpenAI's documented embedding API is
text-only, and Anthropic does not offer a native embeddings API. Neither is a
valid substitute for image-content retrieval. Public external semantic search
requires a clear per-visitor opt-in; declining leaves ordinary discovery
search available and sends no semantic query to a provider.

See [AI_PROVIDER_SETUP.md](AI_PROVIDER_SETUP.md) for safe configuration,
budget and proof-set instructions.
