<?php

declare(strict_types=1);

return [
    'changed' => 'Not changed: the photo, publication or access changed after preview. Create a fresh preview.',
    'blocked' => 'Not changed: review, access, primary scan, rights or privacy block this decision.',
    'decided' => 'Decision recorded.',
    'preview' => 'Private staff preview',
    'preview_hint' => 'Authorized staff only. This is not a public publication; sharing, visitor suggestions and downloads are disabled here.',
    'preview_only' => 'Available after publication; inactive in this preview.',
    'changes' => 'Changes since last approval',
    'approved' => 'Last approved',
    'unchanged' => 'No content differences.',
    'no_snapshot' => 'No approval snapshot was retained. Historical values are not reconstructed retrospectively.',
    'sections' => ['metadata' => 'Metadata', 'tags' => 'Tags', 'rights' => 'Rights', 'primary' => 'Primary file', 'publication' => 'Publication conditions'],
    'checklist' => 'Publication readiness',
    'checks' => ['primary' => 'Primary file present', 'scan' => 'Primary scan clean and ready', 'rights' => 'Rights verified', 'privacy' => 'Privacy confirmed', 'embargo' => 'Embargo elapsed', 'approval' => 'Approved', 'revision' => 'Current revision approved', 'visible' => 'Currently publicly visible'],
    'pass' => 'Satisfied', 'block' => 'Not satisfied',
    'embargo_hint' => 'Approval under embargo is allowed. Public access starts after the embargo date only if all other conditions remain valid. Expiry never automatically grants approval.',
    'embargo_overview' => 'Embargo overview and remaining blockers',
    'embargo' => 'Embargo date and blockers',
    'selection' => 'Select',
    'bulk_hint' => 'At most 25 photos per decision. Preview expires after one hour; every photo is rechecked at confirmation. Valid photos succeed independently of blocked photos.',
    'decision' => 'Publication decision',
    'publish' => 'Publish',
    'reject' => 'Reject',
    'reason' => 'Reason (required for rejection)',
];
