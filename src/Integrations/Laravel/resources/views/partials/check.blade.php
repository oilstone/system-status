@php
    /**
     * What running a watch's monitor from the page found, shown in the watch's row. It doesn't change the watch.
     *
     * @var array{state: string, rating: string, rating_label: string, duration: string, error: string|null, checked_at: \DateTimeImmutable} $result
     * @var array<string, string> $tones
     */
@endphp
<p class="check__note check__note--checked tone-{{ $tones[$result['rating']] }}">
    Checked <time datetime="{{ $result['checked_at']->format(DATE_ATOM) }}" data-time-ago="Checked">just now</time>:
    <strong>{{ $result['rating'] }}</strong> in {{ $result['duration'] }}
</p>
@if ($result['error'] !== null)
    <p class="check__error">{{ $result['error'] }}</p>
@endif
