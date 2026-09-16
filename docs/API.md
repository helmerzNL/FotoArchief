# Public API contract

## Boundary and versioning

The integration boundary is `/api/v1`. Responses are DTOs, never direct
database models: they omit private notes, audit data, storage keys, checksums,
unpublished revisions and rights-restricted fields unless an authorised scope
explicitly permits them.

Public resources use stable ULID identifiers:

```text
GET /api/v1/assets/{asset}
GET /api/v1/collections/{collection}
GET /api/v1/people/{person}
GET /api/v1/locations/{location}
```

Collection endpoints use plural resources. New breaking changes require a new
API version; additive optional fields are allowed in the current version.

## Listing and pagination

Every list response has a bounded `data` array and an opaque cursor:

```json
{
  "data": [],
  "meta": {
    "next_cursor": "eyJpZCI6IjAxS...\"",
    "has_more": true
  }
}
```

Clients pass `cursor` and a bounded `limit` (default 24, maximum 100).
Implementations must use keyset pagination with a stable sort key. Deep
`OFFSET` pagination is prohibited.

Asset listings support only documented, validated filters such as
`collection`, `person`, `location`, `tag`, `from`, `to`, `rights` and `status`.
Search terms are normalised, length-limited and executed through parameterised
queries or a rebuildable search adapter.

## Rights-aware media

Asset DTOs contain only media URLs permitted by the resolved rights, embargo,
privacy and caller policy. Original-object keys, bucket names and storage
credentials never appear. A time-limited signed media URL is issued only after
server-side authorisation.

## Errors

All errors use `application/problem+json`:

```json
{
  "type": "https://fotoarchief.example/problems/validation",
  "title": "Validation failed",
  "status": 422,
  "code": "VALIDATION_FAILED",
  "errors": {
    "limit": ["The limit must not be greater than 100."]
  },
  "request_id": "01J..."
}
```

Use `401` for unauthenticated calls, `403` for authorised-but-disallowed
access, `404` without revealing restricted resource existence, `409` for
state/precondition conflicts, `422` for validation failures, and `429` for
rate limiting. Responses include the correlation/request ID used in structured
logs.

## Write rules

Write endpoints require explicit permission keys (`assets.create`,
`assets.update`, `assets.publish`, `assets.purge`, and similar). Input is
validated by request DTOs; mass assignment of database attributes is
prohibited. State-changing requests record an audit event, enforce optimistic
concurrency where a revision is supplied, and dispatch long-running actions
as jobs rather than holding HTTP connections.
