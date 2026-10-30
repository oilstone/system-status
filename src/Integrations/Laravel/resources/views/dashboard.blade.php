@php
    /**
     * @var string $title
     * @var bool $onDemand Whether the watches lead the page and monitors only run when asked to; otherwise every monitor ran for this page
     * @var string $state The headline's state: up, down, overdue, pending or unknown when the watches lead; operational, degraded, failing, empty or invalid otherwise
     * @var string $headline
     * @var string $summary The line under the headline
     * @var list<array{label: string, value?: string, alert?: bool, time?: \DateTimeImmutable|null, format?: string}> $stats format is clock or ago
     * @var int $refresh Seconds between refreshes; 0 when off
     * @var int|null $liveSeconds Seconds between runs of monitors the viewer makes live; null when monitors run on every refresh anyway
     * @var string $runUrl Where the page runs monitors; see run.blade.php
     * @var string|null $error Why the request's query could not be used
     * @var array<string, string> $tones The colour of each state and rating
     * @var list<array{name: string, anchor: string, label: string, state: string, condition: string, state_label: string, detail: string|null, forced: string|null, forced_until: \DateTimeImmutable|null, checked_at: \DateTimeImmutable|null, schedule: string, cron: string|null, next_run_at: \DateTimeImmutable|null, overdue_since: \DateTimeImmutable|null, error: string|null, last_check: array{rating: string, duration: string|null}|null, monitor: array{id: string, anchor: string, label: string}|null, check_query: string, check_slot: string}> $watches
     *     state is up, down, pending (never run) or unknown (unreadable); condition is the state, or overdue when an up or pending watch has missed a run;
     *     error is the last check's, not yet hidden when errors aren't exposed; monitor is the status monitor the watch checks
     * @var array{state: string, label: string} $watchSummary up, down, overdue or unknown
     * @var bool $exposeErrors
     * @var array<string, array{label: string, anchor: string, monitors: list<array>, state: string|null, open: bool}> $categories See partials/monitor.blade.php for each monitor
     * @var array{state: string, label: string} $monitorSummary operational, degraded, failing or empty
     */
    $colours = ['green' => '#12b76a', 'teal' => '#15b79e', 'amber' => '#f79009', 'red' => '#f04438', 'grey' => '#98a2b3'];
    $stateLabels = ['operational' => 'Operational', 'degraded' => 'Degraded', 'failing' => 'Failing'];
    $favicon = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><circle cx='8' cy='8' r='7' fill='" . $colours[$tones[$state]] . "'/></svg>";
    // Times are shown in the viewer's own time by the script; this is what shows without it
    $clock = fn (\DateTimeInterface $time) => $time->format($time->format('Y-m-d') === date('Y-m-d') ? 'H:i' : 'j M H:i');
@endphp
<!DOCTYPE html>
<html lang="en" data-state="{{ $state }}" data-refresh="{{ $refresh }}" data-run-url="{{ $runUrl }}" @if ($liveSeconds !== null) data-live-seconds="{{ $liveSeconds }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $headline }} · {{ $title }}</title>
    <link rel="icon" href="data:image/svg+xml,{{ rawurlencode($favicon) }}">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f5f6f8;
            --surface: #ffffff;
            --border: #e4e7ec;
            --divider: #f0f2f5;
            --text: #101828;
            --muted: #667085;
            --shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 4px 16px rgba(16, 24, 40, .04);
            --green: #12b76a; --green-soft: #ecfdf3; --green-ink: #027a48;
            --teal: #15b79e; --teal-soft: #f0fdf9; --teal-ink: #107569;
            --amber: #f79009; --amber-soft: #fffaeb; --amber-ink: #b54708;
            --red: #f04438; --red-soft: #fef3f2; --red-ink: #b42318;
            --grey: #98a2b3; --grey-soft: #f2f4f7; --grey-ink: #475467;
            --sans: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0c111d;
                --surface: #161b26;
                --border: #262d3b;
                --divider: #1f2532;
                --text: #f5f5f6;
                --muted: #94969c;
                --shadow: 0 1px 2px rgba(0, 0, 0, .3);
                --green-soft: rgba(18, 183, 106, .14); --green-ink: #47cd89;
                --teal-soft: rgba(21, 183, 158, .14); --teal-ink: #2ed3b7;
                --amber-soft: rgba(247, 144, 9, .14); --amber-ink: #fdb022;
                --red-soft: rgba(240, 68, 56, .14); --red-ink: #f97066;
                --grey-soft: rgba(152, 162, 179, .14); --grey-ink: #cecfd2;
            }
        }

        .tone-green { --tone: var(--green); --tone-soft: var(--green-soft); --tone-ink: var(--green-ink); }
        .tone-teal { --tone: var(--teal); --tone-soft: var(--teal-soft); --tone-ink: var(--teal-ink); }
        .tone-amber { --tone: var(--amber); --tone-soft: var(--amber-soft); --tone-ink: var(--amber-ink); }
        .tone-red { --tone: var(--red); --tone-soft: var(--red-soft); --tone-ink: var(--red-ink); }
        .tone-grey { --tone: var(--grey); --tone-soft: var(--grey-soft); --tone-ink: var(--grey-ink); }

        *, *::before, *::after { box-sizing: border-box; }

        [hidden] { display: none !important; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 15px/1.5 var(--sans);
            -webkit-font-smoothing: antialiased;
        }

        .page { max-width: 880px; margin: 0 auto; padding: 40px 16px 56px; }

        .eyebrow {
            margin: 0 0 16px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow);
        }

        .banner {
            display: flex;
            gap: 18px;
            align-items: center;
            padding: 24px;
            background: linear-gradient(120deg, var(--tone-soft), var(--surface) 65%);
            border-color: color-mix(in srgb, var(--tone) 30%, var(--border));
        }

        .banner__icon {
            position: relative;
            flex: none;
            display: grid;
            place-items: center;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: var(--tone);
            color: #fff;
        }

        .banner__icon svg { width: 26px; height: 26px; }

        [data-state="failing"] .banner__icon::after,
        [data-state="degraded"] .banner__icon::after,
        [data-state="down"] .banner__icon::after,
        [data-state="overdue"] .banner__icon::after {
            content: "";
            position: absolute;
            inset: -6px;
            border-radius: 50%;
            border: 2px solid var(--tone);
            opacity: 0;
            animation: ripple 2.4s ease-out infinite;
        }

        .banner h1 { margin: 0; font-size: 24px; line-height: 1.25; letter-spacing: -.01em; }
        .banner p { margin: 4px 0 0; color: var(--muted); }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin: 16px 0 0;
        }

        .stat { padding: 14px 16px; }
        .stat dt { color: var(--muted); font-size: 13px; }
        .stat dd { margin: 2px 0 0; font-size: 20px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .stat--alert dd { color: var(--red-ink); }

        .section-header {
            display: flex;
            gap: 12px;
            align-items: center;
            margin: 32px 0 12px;
        }

        .section-header h2 { margin: 0; font-size: 18px; letter-spacing: -.01em; }
        .section-header .button:first-of-type { margin-left: auto; }

        .category { margin-top: 12px; overflow: hidden; }

        .category__header {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
        }

        .category__header h2, .category__header h3 { margin: 0; font-size: 16px; }
        .category__header .pill { margin-left: auto; }
        .category__count { color: var(--muted); font-size: 13px; white-space: nowrap; }

        summary.category__header { cursor: pointer; list-style: none; }
        summary.category__header::-webkit-details-marker { display: none; }

        summary.category__header::before {
            content: "";
            flex: none;
            width: 7px;
            height: 7px;
            margin: 0 4px 0 2px;
            border-right: 2px solid var(--muted);
            border-bottom: 2px solid var(--muted);
            transform: rotate(-45deg);
            transition: transform .15s;
        }

        details[open] > summary.category__header::before { transform: rotate(45deg); }
        details:not([open]) > summary.category__header { border-bottom: 0; }
        summary.category__header:hover { background: var(--divider); }

        .pill {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            padding: 2px 10px;
            border-radius: 999px;
            background: var(--tone-soft);
            color: var(--tone-ink);
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--tone); }

        .chip {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            margin: 6px 6px 0 0;
            padding: 1px 9px;
            border: 1px solid color-mix(in srgb, var(--tone) 35%, var(--border));
            border-radius: 999px;
            color: var(--tone-ink);
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        .chip::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--tone); }
        .chip:hover { background: var(--tone-soft); }

        .button {
            padding: 5px 11px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
            font: 600 12px/1.4 var(--sans);
            white-space: nowrap;
            cursor: pointer;
        }

        .button:hover { background: var(--divider); }
        .button:disabled { opacity: .6; cursor: progress; }

        /* Live toggles only show where monitors don't already run on every refresh */
        html:not([data-live-seconds]) [data-live] { display: none; }
        .button--live { display: inline-flex; gap: 6px; align-items: center; }
        .button--live::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--grey); }
        .button--live:disabled { cursor: default; }

        .button--live[aria-pressed="true"] {
            border-color: color-mix(in srgb, var(--green) 45%, var(--border));
            background: var(--green-soft);
            color: var(--green-ink);
        }

        .button--live[aria-pressed="true"]::before { background: var(--green); animation: blink 1.6s ease-in-out infinite; }

        .checks { margin: 0; padding: 0; list-style: none; }

        .check {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 4px 14px;
            align-items: start;
            padding: 16px 20px;
            scroll-margin-top: 16px;
        }

        .check + .check { border-top: 1px solid var(--divider); }
        .check:target { animation: highlight 2.4s ease-out; }
        .is-running { opacity: .6; transition: opacity .2s; }

        .check__dot {
            width: 10px;
            height: 10px;
            margin-top: 7px;
            border-radius: 50%;
            background: var(--tone);
            box-shadow: 0 0 0 4px var(--tone-soft);
        }

        .is-idle .check__dot { background: transparent; box-shadow: inset 0 0 0 2px var(--tone); }
        .is-failing .check__dot { animation: pulse 1.6s ease-in-out infinite; }

        .check__name { font-weight: 600; overflow-wrap: anywhere; }
        .check__schedule { margin: 2px 0 0; color: var(--muted); font-size: 13px; }
        .check__schedule a { color: inherit; text-underline-offset: 3px; }

        .check__error {
            margin: 6px 0 0;
            padding: 8px 10px;
            border-radius: 8px;
            background: var(--red-soft);
            color: var(--red-ink);
            font: 13px/1.45 var(--mono);
            overflow-wrap: anywhere;
        }

        .check__note { margin: 4px 0 0; color: var(--muted); font-size: 14px; }
        .check__note--forced, .check__note--overdue { color: var(--amber-ink); }
        .check__note--checked strong { color: var(--tone-ink); }
        .check__note::before { content: "↳ "; }

        .check__meta { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 0; }

        .check__meta div {
            display: inline-flex;
            border: 1px solid var(--border);
            border-radius: 6px;
            overflow: hidden;
            font: 12px/1.8 var(--mono);
        }

        .check__meta dt { padding: 0 6px; background: var(--divider); color: var(--muted); }
        .check__meta dd { margin: 0; padding: 0 6px; overflow-wrap: anywhere; }

        /* Two columns: the rating or state and its timing, then when it ran and the button to run it */
        .check__timing {
            display: grid;
            grid-template-columns: auto auto;
            gap: 8px 10px;
            align-items: center;
            justify-content: end;
            justify-items: end;
            min-width: 150px;
            padding-top: 1px;
        }

        .check__timing .button, .check__actions { grid-column: 2; }
        .check__actions { display: inline-flex; gap: 6px; }
        .check__duration { color: var(--muted); font: 13px var(--mono); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .check__ran { color: var(--muted); font-size: 12px; white-space: nowrap; }

        .empty { margin-top: 12px; padding: 32px 24px; color: var(--muted); text-align: center; }
        .empty code { font-family: var(--mono); color: var(--text); }

        .footer {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            align-items: center;
            justify-content: space-between;
            margin-top: 28px;
            color: var(--muted);
            font-size: 13px;
        }

        .footer p { margin: 0; }
        .footer a { color: inherit; font-weight: 600; text-underline-offset: 3px; }

        .notice {
            margin: 0 0 16px;
            padding: 10px 14px;
            border-radius: 10px;
            background: var(--amber-soft);
            color: var(--amber-ink);
            font-size: 14px;
        }

        .is-refreshing [data-region]:not([data-keep]) { opacity: .7; transition: opacity .2s; }

        @keyframes pulse { 50% { box-shadow: 0 0 0 7px var(--tone-soft); } }
        @keyframes ripple { 0% { opacity: .6; transform: scale(.85); } 100% { opacity: 0; transform: scale(1.35); } }
        @keyframes highlight { from { background: var(--tone-soft); } }
        @keyframes blink { 50% { opacity: .35; } }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }

        @media (max-width: 560px) {
            .page { padding-top: 24px; }
            .banner { padding: 18px; }
            .banner h1 { font-size: 20px; }
            .stat dd { font-size: 18px; }
            .category__header { flex-wrap: wrap; row-gap: 4px; padding: 12px 16px; }
            .check { grid-template-columns: auto minmax(0, 1fr); }
            .check__timing { grid-column: 2; display: flex; flex-wrap: wrap; justify-content: flex-start; min-width: 0; margin-top: 6px; }
        }
    </style>
</head>
<body>
<div class="page">
    <p class="eyebrow">{{ $title }}</p>

    <p class="notice" data-notice hidden></p>

    <main id="system-status">
        <div data-region="summary">
            <section class="card banner tone-{{ $tones[$state] }}" role="status" aria-live="polite">
                <span class="banner__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                        @switch($state)
                            @case('operational')
                            @case('up')<path d="M5 12.5l4.5 4.5L19 7.5"/>@break
                            @case('degraded')
                            @case('overdue')<path d="M12 6.5v7"/><path d="M12 17.5h.01"/>@break
                            @case('failing')
                            @case('down')<path d="M7 7l10 10M17 7L7 17"/>@break
                            @default<path d="M7 12h10"/>
                        @endswitch
                    </svg>
                </span>
                <div>
                    <h1>{{ $headline }}</h1>
                    <p>{{ $summary }}</p>
                </div>
            </section>

            @if ($stats !== [])
                <dl class="stats">
                    @foreach ($stats as $stat)
                        <div class="card stat @if ($stat['alert'] ?? false) stat--alert @endif">
                            <dt>{{ $stat['label'] }}</dt>
                            <dd>
                                @if (isset($stat['time']))
                                    @if ($stat['format'] === 'clock')
                                        <time datetime="{{ $stat['time']->format(DATE_ATOM) }}" data-checked-at>{{ $stat['time']->format('H:i:s') }}</time>
                                    @else
                                        <time datetime="{{ $stat['time']->format(DATE_ATOM) }}" data-time-ago="{{ $stat['label'] }}">{{ $clock($stat['time']) }}</time>
                                    @endif
                                @else
                                    {{ $stat['value'] ?? '–' }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>

        @if ($watches !== [])
            <section data-region="watches" data-watches>
                <header class="section-header">
                    <h2>Watches</h2>
                    <span class="pill tone-{{ $tones[$watchSummary['state']] }}">{{ $watchSummary['label'] }}</span>
                </header>
                <div class="card category">
                    <ul class="checks">
                        @foreach ($watches as $watch)
                            <li class="check tone-{{ $tones[$watch['condition']] }} @if ($watch['state'] === 'down') is-failing @endif"
                                id="{{ $watch['anchor'] }}" data-watch="{{ $watch['name'] }}" data-watch-tone="{{ $tones[$watch['state']] }}" data-watch-state="{{ $watch['state_label'] }}">
                                <span class="check__dot" aria-hidden="true"></span>
                                <div>
                                    <div class="check__name">{{ $watch['label'] }}</div>
                                    <p class="check__schedule">
                                        <span @if ($watch['cron'] !== null) title="{{ $watch['cron'] }}" @endif>{{ $watch['schedule'] }}</span>
                                        @if ($watch['next_run_at'] !== null)
                                            · next <time datetime="{{ $watch['next_run_at']->format(DATE_ATOM) }}" data-local-time>{{ $clock($watch['next_run_at']) }}</time>
                                        @endif
                                        @if ($watch['monitor'] !== null)
                                            · checks <a href="#{{ $watch['monitor']['anchor'] }}">{{ $watch['monitor']['label'] }}</a>
                                        @endif
                                    </p>
                                    @if ($watch['overdue_since'] !== null)
                                        <p class="check__note check__note--overdue">
                                            Overdue since <time datetime="{{ $watch['overdue_since']->format(DATE_ATOM) }}" data-local-time>{{ $clock($watch['overdue_since']) }}</time>. Is the scheduler running?
                                        </p>
                                    @endif
                                    @if ($watch['error'] !== null)
                                        <p class="check__error">{{ $exposeErrors ? $watch['error'] : 'Check failed' }}</p>
                                    @elseif ($watch['last_check'] !== null)
                                        <p class="check__note">Last check rated {{ $watch['last_check']['rating'] . ($watch['last_check']['duration'] === null ? '' : ' in ' . $watch['last_check']['duration']) }}</p>
                                    @endif
                                    @if ($watch['detail'] !== null)
                                        <p class="check__note">{{ $watch['detail'] }}</p>
                                    @endif
                                    @if ($watch['forced'] !== null)
                                        <p class="check__note check__note--forced">
                                            Forced {{ $watch['forced'] }}
                                            @if ($watch['forced_until'] !== null)
                                                until <time datetime="{{ $watch['forced_until']->format(DATE_ATOM) }}" data-local-time>{{ $clock($watch['forced_until']) }}</time>
                                            @else
                                                until cleared
                                            @endif
                                        </p>
                                    @endif
                                    <div data-check-slot="{{ $watch['check_slot'] }}"></div>
                                </div>
                                <div class="check__timing">
                                    <span class="pill">{{ $watch['state_label'] }}</span>
                                    <span class="check__ran">
                                        @if ($watch['checked_at'] !== null)
                                            <time datetime="{{ $watch['checked_at']->format(DATE_ATOM) }}" data-time-ago="Last checked">{{ $clock($watch['checked_at']) }}</time>
                                        @endif
                                    </span>
                                    <button type="button" class="button" data-run="{{ $watch['check_query'] }}" title="Runs the monitor without changing the watch">Check now</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        <section data-region="monitors" @if ($onDemand) data-keep @endif>
            @if ($categories !== [])
                <header class="section-header">
                    <h2>Monitors</h2>
                    <span class="pill tone-{{ $tones[$monitorSummary['state']] }}" data-monitors-pill>{{ $monitorSummary['label'] }}</span>
                    <button type="button" class="button button--live" data-live="" aria-pressed="false" title="Run every monitor again at every refresh">Live</button>
                    <button type="button" class="button" data-run="">Run all</button>
                </header>

                @foreach ($categories as $category)
                    <details class="card category" id="{{ $category['anchor'] }}" data-section data-category @if ($category['open']) open @endif>
                        <summary class="category__header">
                            <h3>{{ $category['label'] }}</h3>
                            <span class="category__count">{{ count($category['monitors']) }} {{ \Illuminate\Support\Str::plural('monitor', count($category['monitors'])) }}</span>
                            <span class="pill tone-{{ $tones[$category['state'] ?? 'empty'] }}" data-category-pill @if ($category['state'] === null) hidden @endif>{{ $stateLabels[$category['state']] ?? '' }}</span>
                        </summary>
                        <ul class="checks">
                            @foreach ($category['monitors'] as $monitor)
                                @include('system-status::partials.monitor', ['monitor' => $monitor])
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            @elseif ($onDemand && $error !== null)
                <div class="card empty">{{ $error }}</div>
            @elseif ($state === 'empty')
                <div class="card empty">
                    Add monitors to the <code>monitors</code> section of <code>config/system-status.php</code>.
                </div>
            @endif
        </section>
    </main>

    <footer class="footer">
        <p>
            @if ($refresh > 0)
                Refreshes every {{ $refresh }} seconds.
            @endif
            <a href="" data-refresh-now>Refresh now</a>
        </p>
        <p>Powered by oilstone/system-status</p>
    </footer>
</div>

<script>
    (function () {
        var root = document.documentElement;
        var seconds = parseInt(root.getAttribute('data-refresh'), 10) || 0;
        var runUrl = root.getAttribute('data-run-url');
        var liveSeconds = parseInt(root.getAttribute('data-live-seconds'), 10) || 0;
        var notice = document.querySelector('[data-notice]');
        var busy = false;
        var missed = false;
        var missedLive = false;
        // Monitors re-run at every live tick: all of them, or those picked one by one. Kept in ?live= so a page can be bookmarked live
        var live = { all: false, ids: {} };
        // Runs waiting for a response, by query, so a slow monitor isn't run again before it answers
        var running = {};
        // Sections the viewer has opened or closed, which refreshes and runs then leave alone
        var toggled = {};
        var states = ['operational', 'degraded', 'failing'];
        var stateLabels = { operational: 'Operational', degraded: 'Degraded', failing: 'Failing' };
        var stateTones = { operational: 'green', degraded: 'amber', failing: 'red' };

        function find(attribute, value, scope) {
            return (scope || document).querySelectorAll('[' + attribute + '="' + CSS.escape(value) + '"]');
        }

        function timeAgo(date) {
            var seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));

            return seconds < 5 ? 'just now'
                : seconds < 90 ? seconds + 's ago'
                : seconds < 5400 ? Math.round(seconds / 60) + 'm ago'
                : seconds < 129600 ? Math.round(seconds / 3600) + 'h ago'
                : Math.round(seconds / 86400) + 'd ago';
        }

        // Show when the report was checked in the viewer's own time, and how long ago
        function showCheckedAt() {
            var element = document.querySelector('[data-checked-at]');

            if (!element) {
                return;
            }

            var checked = new Date(element.getAttribute('datetime'));
            var ago = timeAgo(checked);

            element.textContent = checked.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            element.title = ago;
            element.setAttribute('aria-label', element.textContent + ', ' + ago);
        }

        // Other times are shown in the viewer's own time too, with the date when it isn't today
        function showLocalTimes() {
            var today = new Date().toDateString();

            document.querySelectorAll('[data-local-time]').forEach(function (element) {
                var date = new Date(element.getAttribute('datetime'));
                var time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

                element.textContent = date.toDateString() === today ? time : date.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ' ' + time;
                element.title = date.toLocaleString();
            });

            document.querySelectorAll('[data-time-ago]').forEach(function (element) {
                var date = new Date(element.getAttribute('datetime'));

                element.textContent = timeAgo(date);
                element.title = element.getAttribute('data-time-ago') + ' ' + date.toLocaleString();
            });
        }

        // Category and section pills follow the monitors that have run, and a category with a problem opens
        function summariseMonitors() {
            var counts = { operational: 0, degraded: 0, failing: 0 };
            var rows = document.querySelectorAll('[data-monitor]');
            var ran = 0;

            document.querySelectorAll('[data-category]').forEach(function (category) {
                var pill = category.querySelector('[data-category-pill]');
                var worst = null;

                category.querySelectorAll('[data-monitor][data-state]').forEach(function (row) {
                    var state = row.getAttribute('data-state');

                    counts[state]++;
                    ran++;

                    if (worst === null || states.indexOf(state) > states.indexOf(worst)) {
                        worst = state;
                    }
                });

                if (worst !== null) {
                    pill.className = 'pill tone-' + stateTones[worst];
                    pill.textContent = stateLabels[worst];
                    pill.hidden = false;
                }

                if (worst !== null && worst !== 'operational' && !(category.id in toggled)) {
                    category.open = true;
                }
            });

            var pill = document.querySelector('[data-monitors-pill]');
            var summary = counts.failing ? ['red', counts.failing + ' failing']
                : counts.degraded ? ['amber', counts.degraded + ' degraded']
                : ran && ran === rows.length ? ['green', 'All operational']
                : ran ? ['grey', ran + ' of ' + rows.length + ' run']
                : null;

            if (pill && summary) {
                pill.className = 'pill tone-' + summary[0];
                pill.textContent = summary[1];
            }
        }

        // Monitors kept across refreshes still show the current state of the watches that check them
        function linkWatches() {
            document.querySelectorAll('[data-watch-chip]').forEach(function (chip) {
                var watch = find('data-watch', chip.getAttribute('data-watch-chip'))[0];

                if (watch) {
                    chip.className = 'chip tone-' + watch.getAttribute('data-watch-tone');
                    chip.querySelector('[data-watch-chip-state]').textContent = watch.getAttribute('data-watch-state');
                }
            });
        }

        function showNotice(message) {
            notice.textContent = message;
            notice.hidden = false;
        }

        // Run one monitor (monitor=), a watch's monitor (watch=) or all of them (an empty query), and put the results in place
        function runQuery(query) {
            var params = new URLSearchParams(window.location.search);
            var url = new URL(runUrl, window.location.href);

            params.delete('live');
            new URLSearchParams(query).forEach(function (value, key) {
                params.set(key, value);
            });

            url.search = params.toString();
            running[query] = true;

            return fetch(url.toString(), { headers: { Accept: 'text/html' }, cache: 'no-store', credentials: 'same-origin' })
                .then(function (response) {
                    return response.text().then(function (body) {
                        if (!response.ok) {
                            throw new Error(response.status === 404 || response.status === 422 ? body : 'HTTP ' + response.status);
                        }

                        return body;
                    });
                })
                .then(function (html) {
                    var results = new DOMParser().parseFromString(html, 'text/html');

                    results.querySelectorAll('[data-monitor]').forEach(function (row) {
                        find('data-monitor', row.getAttribute('data-monitor')).forEach(function (current) {
                            current.replaceWith(document.importNode(row, true));
                        });
                    });

                    results.querySelectorAll('[data-check-for]').forEach(function (result) {
                        find('data-check-slot', result.getAttribute('data-check-for')).forEach(function (slot) {
                            slot.innerHTML = result.innerHTML;
                        });
                    });

                    notice.hidden = true;
                    summariseMonitors();
                    linkWatches();
                    showLive();
                    showLocalTimes();
                })
                .then(function () {
                    delete running[query];
                }, function (error) {
                    delete running[query];

                    throw error;
                });
        }

        function run(button) {
            var query = button.getAttribute('data-run');
            var all = query === '';
            var buttons = all ? Array.prototype.slice.call(document.querySelectorAll('[data-run]')) : [button];
            var rows = all ? Array.prototype.slice.call(document.querySelectorAll('[data-monitor]')) : [button.closest('.check')];
            var label = button.textContent;

            buttons.forEach(function (element) { element.disabled = true; });
            rows.forEach(function (row) { row.classList.add('is-running'); });
            button.textContent = 'Running…';

            runQuery(query)
                .catch(function (error) {
                    showNotice('Couldn’t run the check: ' + error.message);
                })
                .then(function () {
                    // Rows that were replaced brought their own buttons
                    buttons.forEach(function (element) { element.disabled = false; });
                    rows.forEach(function (row) { row.classList.remove('is-running'); });
                    button.textContent = label;
                });
        }

        function liveQueries() {
            return live.all ? [''] : Object.keys(live.ids).map(function (id) {
                return new URLSearchParams({ monitor: id }).toString();
            });
        }

        // Run the live monitors, skipping any still waiting for their last run, quietly so rows don't flicker
        function runLive(queries) {
            if (document.hidden) {
                missedLive = true;

                return;
            }

            (queries || liveQueries()).forEach(function (query) {
                if (!running[query] && !running['']) {
                    runQuery(query).catch(function (error) {
                        showNotice('Couldn’t run a live check at ' + new Date().toLocaleTimeString() + ': ' + error.message);
                    });
                }
            });
        }

        // Rows come back from each run as the server renders them, so live toggles are set again after every change
        function showLive() {
            document.querySelectorAll('[data-live]').forEach(function (button) {
                var id = button.getAttribute('data-live');

                button.setAttribute('aria-pressed', live.all || (id !== '' && live.ids[id] === true) ? 'true' : 'false');
                button.disabled = id !== '' && live.all;
            });
        }

        function toggleLive(button) {
            var id = button.getAttribute('data-live');
            var on = button.getAttribute('aria-pressed') !== 'true';
            var url = new URL(window.location.href);

            if (id === '') {
                live.all = on;
            } else if (on) {
                live.ids[id] = true;
            } else {
                delete live.ids[id];
            }

            url.searchParams.delete('live');

            (live.all ? ['all'] : Object.keys(live.ids)).forEach(function (value) {
                url.searchParams.append('live', value);
            });

            history.replaceState(null, '', url.toString());
            showLive();

            if (on) {
                runLive(id === '' ? [''] : [new URLSearchParams({ monitor: id }).toString()]);
            }
        }

        // Refresh the summary and watches in place. Monitors that only run when asked keep their results, and
        // sections stay as the viewer left them.
        function refresh() {
            if (busy) {
                return;
            }

            busy = true;
            root.classList.add('is-refreshing');

            fetch(window.location.href, { headers: { Accept: 'text/html' }, cache: 'no-store', credentials: 'same-origin' })
                .then(function (response) {
                    // 422 is a page explaining a bad query; anything else unexpected keeps the last result
                    if (!response.ok && response.status !== 422) {
                        throw new Error('HTTP ' + response.status);
                    }

                    return response.text();
                })
                .then(function (html) {
                    var next = new DOMParser().parseFromString(html, 'text/html');
                    var icon = next.querySelector('link[rel="icon"]');

                    if (!next.getElementById('system-status')) {
                        throw new Error('Unexpected response');
                    }

                    next.querySelectorAll('[data-region]').forEach(function (region) {
                        var current = find('data-region', region.getAttribute('data-region'))[0];

                        if (!current || current.hasAttribute('data-keep')) {
                            return;
                        }

                        region.querySelectorAll('details[data-section]').forEach(function (details) {
                            if (details.id in toggled) {
                                details.open = toggled[details.id];
                            }
                        });

                        current.querySelectorAll('[data-check-slot]').forEach(function (slot) {
                            var target = find('data-check-slot', slot.getAttribute('data-check-slot'), region)[0];

                            if (target && slot.innerHTML.trim() !== '') {
                                target.innerHTML = slot.innerHTML;
                            }
                        });

                        current.replaceWith(document.importNode(region, true));
                    });

                    document.title = next.title;
                    root.setAttribute('data-state', next.documentElement.getAttribute('data-state'));

                    if (icon) {
                        document.querySelector('link[rel="icon"]').href = icon.href;
                    }

                    notice.hidden = true;
                    summariseMonitors();
                    linkWatches();
                    showLive();
                    showCheckedAt();
                    showLocalTimes();
                })
                .catch(function () {
                    showNotice('Couldn’t refresh at ' + new Date().toLocaleTimeString() + '. Showing the last result.');
                })
                .then(function () {
                    busy = false;
                    root.classList.remove('is-refreshing');
                });
        }

        document.addEventListener('click', function (event) {
            var summary = event.target.closest('summary');
            var button = event.target.closest('[data-run]');
            var toggle = event.target.closest('[data-live]');
            var link = event.target.closest('a[href^="#"]');

            if (summary && summary.parentElement.hasAttribute('data-section')) {
                toggled[summary.parentElement.id] = !summary.parentElement.open;
            }

            if (button) {
                event.preventDefault();
                run(button);
            }

            if (toggle) {
                event.preventDefault();
                toggleLive(toggle);
            }

            if (link) {
                // Open the section a linked row is in, so the browser can scroll to it
                var target = document.getElementById(decodeURIComponent(link.getAttribute('href').slice(1)));
                var details = target && target.closest('details[data-section]');

                if (details && !details.open) {
                    details.open = true;
                    toggled[details.id] = true;
                }
            }
        });

        document.querySelector('[data-refresh-now]').addEventListener('click', function (event) {
            event.preventDefault();
            refresh();
        });

        if (seconds > 0) {
            // Skip refreshes while the tab is hidden, and catch up when it is shown again
            setInterval(function () {
                if (document.hidden) {
                    missed = true;
                } else {
                    refresh();
                }
            }, seconds * 1000);

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden && missed) {
                    missed = false;
                    refresh();
                }
            });
        }

        if (liveSeconds > 0) {
            new URLSearchParams(window.location.search).getAll('live').forEach(function (value) {
                if (value === 'all') {
                    live.all = true;
                } else if (find('data-monitor', value).length > 0) {
                    live.ids[value] = true;
                }
            });

            setInterval(function () {
                runLive();
            }, liveSeconds * 1000);

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden && missedLive) {
                    missedLive = false;
                    runLive();
                }
            });

            showLive();
            runLive();
        }

        showCheckedAt();
        showLocalTimes();
        setInterval(function () {
            showCheckedAt();
            showLocalTimes();
        }, 5000);
    })();
</script>
</body>
</html>
