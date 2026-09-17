<?php

declare(strict_types=1);

return [
    'detail' => 'Task details',
    'timings' => 'Created / latest attempt started / finished',
    'attempts' => 'Worker claims (including continuation batches)',
    'settings' => 'Saved task settings',
    'items' => 'Photo selection and latest known outcome',
    'processed' => 'Processed', 'failed' => 'Failed', 'waiting' => 'Waiting', 'skipped' => 'Skipped',
    'not_processed' => 'No processing result recorded yet.',
    'cancelled_item' => 'Not processed: the task was cancelled.',
    'no_selection' => 'This task has no saved AI photo selection. Refer to task settings and events.',
    'pause' => 'Pause', 'resume' => 'Resume', 'paused' => 'Paused',
    'pausing' => 'Pause requested; in-flight work finishes first.',
    'pause_notice' => 'AI and integrity verification pause before the next photo. Storage copy pauses after the current batch of at most 25 files. An in-flight request is not aborted. A fully finished task remains completed.',
    'control_saved' => 'Task control saved.',
    'select_failed' => 'Select this failed item',
    'confirm_retry' => 'I confirm a new task for only the selected failed items; provider costs may apply again.',
    'retry_selected' => 'Retry selected failures',
    'retry_saved' => 'A follow-up task was created; the original task and history remain stored.',
    'only_failed' => 'Select only items whose latest outcome in this task was failed.',
    'already_retried' => 'This selection contains items already retried. Use the follow-up task for another attempt.',
    'audit' => 'Searchable audit log',
    'asset' => 'Photo ID or accession number', 'run' => 'Task ID', 'actor' => 'User ID', 'event' => 'Exact event type',
    'export' => 'Export JSONL',
    'export_notice' => 'At most 10000 events. Export contains identifiers, event type and timestamp only; no free text, technical context, provider keys or photo metadata.',
    'export_limit' => 'More than 10000 events: narrow the filters first.',
];
