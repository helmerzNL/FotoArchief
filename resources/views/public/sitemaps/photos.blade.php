{!! '<' . '?xml version="1.0" encoding="UTF-8"?' . '>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach($publications as $publication)
    <url><loc>{{ route('public.photo', $publication) }}</loc>@if($publication->published_at)<lastmod>{{ $publication->published_at->toAtomString() }}</lastmod>@endif</url>
@endforeach
</urlset>
