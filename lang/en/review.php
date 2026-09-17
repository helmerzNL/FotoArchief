<?php

declare(strict_types=1);

return [
    'filters' => 'Filter review queue',
    'status' => 'Review status',
    'collection' => 'Collection',
    'all' => 'All',
    'provider' => 'Provider identifier',
    'from' => 'From date',
    'until' => 'Until date',
    'apply' => 'Apply filters',
    'states' => ['reviewable' => 'Awaiting review', 'pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'superseded' => 'Superseded', 'reverted' => 'Reverted'],
    'current' => 'Current metadata',
    'proposed' => 'Original AI proposal',
    'description' => 'Description to accept (replaces the current description)',
    'tag_effect' => 'This tag is added only if it is not already linked. Existing tags are retained.',
    'select' => 'Select proposal :id',
    'confirm' => 'I confirm the selected proposals and decision.',
    'bulk' => 'Apply to selected proposals',
    'decision' => 'Decision',
    'processed' => 'Decision saved.',
    'results' => 'Results per proposal',
    'undo' => 'Undo my acceptance',
    'undone' => 'Acceptance reverted; original proposal and audit history retained.',
    'undo_unavailable' => 'Only your own accepted proposals with a recovery receipt can be undone.',
    'undo_conflict' => 'The photo changed after acceptance. No metadata was overwritten.',
    'invalid_description' => 'Enter a description of 1 to 10000 characters.',
    'invalid_tag' => 'This tag cannot produce a valid identifier.',
];
