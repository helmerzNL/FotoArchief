@props(['status'])
@php
    // Tone is a fixed map of known workflow states; unknown values stay neutral and readable.
    $tones = [
        'completed' => 'success', 'ready_private' => 'success', 'published' => 'success', 'verified' => 'success',
        'queued' => 'warning', 'running' => 'warning', 'receiving' => 'warning', 'received' => 'warning',
        'in_review' => 'warning', 'quarantine' => 'warning', 'draft' => 'info', 'unverified' => 'info',
        'rejected' => 'error', 'failed' => 'error', 'revoked' => 'error', 'expired' => 'error', 'disputed' => 'error',
    ];
    $labels = trans('shell.status');
    $value = (string) $status;
@endphp
<span class="badge badge-{{ $tones[$value] ?? 'neutral' }}">{{ is_array($labels) ? ($labels[$value] ?? $value) : $value }}</span>
