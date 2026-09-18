<form id="resumable-upload" data-endpoint="{{ $endpoint }}" data-existing="{{ $existing ? '1' : '0' }}" data-max-files="{{ min(250, (int) config('ingest.max_batch_upload_files')) }}" data-max-bytes="{{ min(104857600, (int) config('ingest.max_upload_bytes')) }}" data-messages="{{ json_encode(__('uploads'), JSON_THROW_ON_ERROR) }}">
    @csrf
    <p>{{ __('uploads.limits') }}</p>
    <label for="resumable-files">{{ __('uploads.select') }}</label>
    <input id="resumable-files" type="file" accept="image/jpeg,image/png,image/webp" multiple required>
    <button type="submit">{{ $existing ? __('uploads.resume') : __('uploads.start') }}</button>
    <progress id="resumable-progress" value="0" max="100" aria-label="{{ __('uploads.progress') }}"></progress>
    <p id="resumable-result" role="status" aria-live="polite"></p>
</form>
<noscript><p>{{ __('uploads.unsupported') }}</p></noscript>
<script src="/resumable-uploads.js" defer></script>
