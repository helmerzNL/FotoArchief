# Capability register / Functieregister

> Generated from `capabilities.json`; run `php scripts/check-capabilities.php --write` after changing capabilities.

Product version / Productversie: **0.9.65**

| Capability | Status | Requirements / Vereisten | Evidence / Bewijs |
| --- | --- | --- | --- |
| `onboarding` | shipped | - | [`app/Modules/Installation/InstallationBootstrap.php`](../app/Modules/Installation/InstallationBootstrap.php), [`docs/ONBOARDING.md`](../docs/ONBOARDING.md) |
| `private-ingest` | shipped | - | [`app/Modules/Ingest/Jobs/ProcessUpload.php`](../app/Modules/Ingest/Jobs/ProcessUpload.php), [`docs/PHOTO_WORKFLOW.md`](../docs/PHOTO_WORKFLOW.md) |
| `metadata-and-rights` | shipped | - | [`app/Modules/Catalogue/Models/Asset.php`](../app/Modules/Catalogue/Models/Asset.php), [`app/Modules/Publication/Services/PublicationReviewService.php`](../app/Modules/Publication/Services/PublicationReviewService.php) |
| `publication-review` | shipped | - | [`app/Modules/Publication/Services/PublicationReviewService.php`](../app/Modules/Publication/Services/PublicationReviewService.php) |
| `public-portal` | shipped | - | [`app/Http/Controllers/Publication/PublicDiscoveryController.php`](../app/Http/Controllers/Publication/PublicDiscoveryController.php) |
| `iiif-presentation` | shipped | - | [`app/Http/Controllers/Publication/IiifManifestController.php`](../app/Http/Controllers/Publication/IiifManifestController.php), [`public/viewer.js`](../public/viewer.js), [`docs/QUALITY_ACCEPTANCE.md`](../docs/QUALITY_ACCEPTANCE.md) |
| `identity-and-passkeys` | shipped | - | [`app/Modules/Identity`](../app/Modules/Identity), [`docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md) |
| `data-exchange` | shipped | - | [`app/Modules/DataExchange`](../app/Modules/DataExchange), [`docs/DATA_EXCHANGE.md`](../docs/DATA_EXCHANGE.md) |
| `archive-operations` | shipped | - | [`app/Modules/ArchiveOperations`](../app/Modules/ArchiveOperations), [`docs/OPERATIONS.md`](../docs/OPERATIONS.md) |
| `backup-and-restore` | shipped | - | [`app/Modules/ArchiveOperations/Services/BackupRegisterService.php`](../app/Modules/ArchiveOperations/Services/BackupRegisterService.php), [`app/Modules/ArchiveOperations/Services/RestoreDrillService.php`](../app/Modules/ArchiveOperations/Services/RestoreDrillService.php), [`app/Modules/ArchiveOperations/Services/DisposableRestoreDrillService.php`](../app/Modules/ArchiveOperations/Services/DisposableRestoreDrillService.php), [`docs/BACKUP_RESTORE.md`](../docs/BACKUP_RESTORE.md) |
| `encrypted-offsite-backup-copy` | conditional | operator-configured private Laravel filesystem disk; externally encrypted backup bytes | [`app/Modules/ArchiveOperations/Services/EncryptedOffsiteCopyService.php`](../app/Modules/ArchiveOperations/Services/EncryptedOffsiteCopyService.php), [`docs/BACKUP_RESTORE.md`](../docs/BACKUP_RESTORE.md) |
| `storage-migration-preflight` | shipped | - | [`app/Modules/ArchiveOperations/Services/StorageMigrationService.php`](../app/Modules/ArchiveOperations/Services/StorageMigrationService.php), [`docs/OPERATIONS.md`](../docs/OPERATIONS.md) |
| `s3-versioned-cleanup` | conditional | S3 source disk; provider bucket versioning status Enabled; provider noncurrent-version retention | [`app/Modules/ArchiveOperations/Services/S3ProtectionPolicy.php`](../app/Modules/ArchiveOperations/Services/S3ProtectionPolicy.php), [`app/Modules/ArchiveOperations/Models/StorageTombstone.php`](../app/Modules/ArchiveOperations/Models/StorageTombstone.php), [`docs/BACKUP_RESTORE.md`](../docs/BACKUP_RESTORE.md) |
| `local-ai-provider` | conditional | administrator opt-in; approved provider/model | [`app/Modules/Ai`](../app/Modules/Ai), [`docs/AI_PROVIDER_SETUP.md`](../docs/AI_PROVIDER_SETUP.md) |
| `pgvector-semantic-search` | conditional | PostgreSQL pgvector extension; compatible text/image embedding space | [`app/Modules/Ai/Services/PgvectorEmbeddingStore.php`](../app/Modules/Ai/Services/PgvectorEmbeddingStore.php), [`docs/VECTOR_RECOVERY_DELIVERY.md`](../docs/VECTOR_RECOVERY_DELIVERY.md) |
| `quality-regression-gates` | shipped | - | [`tests/Performance/budgets.v1.json`](../tests/Performance/budgets.v1.json), [`tests/Performance/relevance-dataset.v1.json`](../tests/Performance/relevance-dataset.v1.json), [`tests/Browser/acceptance.spec.ts`](../tests/Browser/acceptance.spec.ts), [`docs/QUALITY_ACCEPTANCE.md`](../docs/QUALITY_ACCEPTANCE.md) |
| `vistora-identity` | shipped | - | [`public/brand`](../public/brand), [`docs/BRANDING.md`](../docs/BRANDING.md) |

## External acceptance boundaries / Externe acceptatiegrenzen

- `installation-environment`: https://github.com/helmerzNL/FotoArchief/issues/15
- `model-scale-accessibility`: https://github.com/helmerzNL/FotoArchief/issues/16
