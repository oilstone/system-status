@php
    /**
     * Monitors run from the dashboard: each one's row, and its result for the rows of watches that check it.
     * The page puts each [data-monitor] row in place of its own and each [data-check-for] into the matching
     * [data-check-slot].
     *
     * @var list<array{slot: string, result: array, monitor: array|null}> $items monitor is null for a watch with its own monitor definition
     * @var array<string, string> $tones
     */
@endphp
<ul>
    @foreach ($items as $item)
        @if ($item['monitor'] !== null)
            @include('system-status::partials.monitor', ['monitor' => $item['monitor']])
        @endif
    @endforeach
</ul>
@foreach ($items as $item)
    <div data-check-for="{{ $item['slot'] }}">
        @include('system-status::partials.check', ['result' => $item['result']])
    </div>
@endforeach
