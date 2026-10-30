@php
    /**
     * One monitor's row, before or after it runs. The page swaps rows by data-monitor when a run returns.
     *
     * @var array{id: string, anchor: string, name: string, run_query: string, result: array|null, watches: list<array{name: string, anchor: string, label: string, state: string, state_label: string}>} $monitor
     *     result is set once the monitor has run: state (operational, degraded or failing), rating, rating_label, duration,
     *     duration_ms, error (already hidden when errors aren't exposed), notes, meta (strings) and checked_at
     * @var array<string, string> $tones
     * @var float|null $slowest Milliseconds taken by the slowest result on the page, when known; the script scales bars as results arrive
     * @var bool $onDemand Whether monitors only run when asked; otherwise they all run on every refresh, so rows have no buttons
     */
    $result = $monitor['result'];
    $slowest ??= 0.0;
@endphp
<li class="check tone-{{ $result === null ? 'grey' : $tones[$result['rating']] }} @if ($result === null) is-idle @elseif ($result['state'] === 'failing') is-failing @endif"
    id="{{ $monitor['anchor'] }}" data-monitor="{{ $monitor['id'] }}" @if ($result !== null) data-state="{{ $result['state'] }}" data-duration="{{ $result['duration_ms'] }}" @endif>
    <span class="check__dot" aria-hidden="true"></span>
    <div>
        <div class="check__name">{{ $monitor['name'] }}</div>
        @foreach ($monitor['watches'] as $watch)
            <a class="chip tone-{{ $tones[$watch['state']] }}" href="#{{ $watch['anchor'] }}" data-watch-chip="{{ $watch['name'] }}">
                Watched by {{ $watch['label'] }} · <span data-watch-chip-state>{{ $watch['state_label'] }}</span>
            </a>
        @endforeach
        @if ($result !== null)
            @if ($result['error'] !== null)
                <p class="check__error">{{ $result['error'] }}</p>
            @endif
            @foreach ($result['notes'] as $note)
                <p class="check__note">{{ $note }}</p>
            @endforeach
            @if ($result['meta'] !== [])
                <dl class="check__meta">
                    @foreach ($result['meta'] as $key => $value)
                        <div><dt>{{ $key }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            @endif
        @endif
    </div>
    <div class="check__timing">
        @if ($result !== null)
            <span class="pill">{{ $result['rating_label'] }}</span>
            <span class="check__duration">{{ $result['duration'] }}</span>
            <span class="bar" aria-hidden="true"><span style="width: {{ $slowest > 0 ? max(2, (int) round($result['duration_ms'] / $slowest * 100)) : 0 }}%"></span></span>
            <time class="check__ran" datetime="{{ $result['checked_at']->format(DATE_ATOM) }}" data-time-ago="Ran">just now</time>
        @else
            <span class="check__ran">Not run</span>
        @endif
        @if ($onDemand)
            <span class="check__actions">
                <button type="button" class="button button--live" data-live="{{ $monitor['id'] }}" aria-pressed="false" title="Run this monitor again at every refresh">Live</button>
                <button type="button" class="button" data-run="{{ $monitor['run_query'] }}" data-run-key="{{ $monitor['id'] }}">Run now</button>
            </span>
        @endif
    </div>
</li>
