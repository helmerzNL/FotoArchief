## Purpose

Describe the change and its scope.

## Validation

List commands/results and any checks not performed. Do not equate static
validation with successful installation or runtime testing.

## Deployment and operator changes

State "No deployment-file changes" if applicable. Otherwise list:

- Exact variable names, defaults and required manual edits.
- Exact changed Compose mappings.
- Whether existing deployments work untouched or need edits before deployment.
- Any new size/rate/timeout limit as a number, including upstream requirements.
- Migration/backup/restore implications for both webhosting and Docker.

## Release checks

- [ ] Runtime/build/deployment/CI changes include a version update.
- [ ] Tests cover changed behavior.
- [ ] No secrets, customer data or unlicensed media are included.
- [ ] Both distribution formats and related documentation remain consistent.
