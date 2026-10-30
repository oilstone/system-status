@php
    /**
     * @var string $title
     * @var string $state operational, degraded, failing, empty or invalid
     * @var string $headline
     * @var int $refresh Seconds between refreshes; 0 when off
     * @var bool $exposeErrors
     * @var string|null $error Why the report could not be produced
     * @var \Oilstone\SystemStatus\Rating $failAt
     * @var \Oilstone\SystemStatus\Report|null $report
     * @var array<string, array{label: string, state: string, results: list<\Oilstone\SystemStatus\Result>}> $categories
     * @var int $failures
     * @var float $slowest Milliseconds taken by the slowest check
     * @var list<array{name: string, label: string, state: string, state_label: string, detail: string|null, forced: string|null, forced_until: \DateTimeImmutable|null, checked_at: \DateTimeImmutable|null, schedule: string, cron: string|null, next_run_at: \DateTimeImmutable|null, overdue_since: \DateTimeImmutable|null, error: string|null, last_check: array{rating: string, duration: float|null}|null}> $watches
     *     state is up, down, pending (never run) or unknown (unreadable); forced is up or down; schedule is in words where it can be;
     *     overdue_since is the scheduled run that hasn't happened; last_check is set when the last check rated degraded or worse without an error
     * @var array{state: string, label: string} $watchSummary up, down, overdue or unknown
     */
    $tones = [
        'operational' => 'green', 'degraded' => 'amber', 'failing' => 'red', 'empty' => 'grey', 'invalid' => 'grey',
        'optimal' => 'green', 'acceptable' => 'teal', 'failed' => 'red',
        'up' => 'green', 'down' => 'red', 'pending' => 'grey', 'unknown' => 'grey', 'overdue' => 'amber',
    ];
    $colours = ['green' => '#12b76a', 'teal' => '#15b79e', 'amber' => '#f79009', 'red' => '#f04438', 'grey' => '#98a2b3'];
    $stateLabels = ['operational' => 'Operational', 'degraded' => 'Degraded', 'failing' => 'Failing'];
    $duration = fn (float $ms) => $ms < 1000
        ? number_format($ms, $ms < 10 ? 1 : 0) . ' ms'
        : number_format($ms / 1000, 2) . ' s';
    // Times are shown in the viewer's own time by the script; this is what shows without it
    $clock = fn (\DateTimeInterface $time) => $time->format($time->format('Y-m-d') === date('Y-m-d') ? 'H:i' : 'j M H:i');
    $favicon = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><circle cx='8' cy='8' r='7' fill='" . $colours[$tones[$state]] . "'/></svg>";
    $checks = $report === null ? 0 : count($report);
    $summary = sprintf(
        '%d %s across %d %s%s',
        $checks,
        \Illuminate\Support\Str::plural('check', $checks),
        count($categories),
        \Illuminate\Support\Str::plural('category', count($categories)),
        $failAt === \Oilstone\SystemStatus\Rating::Failed ? '' : ', counting ' . $failAt->label() . ' or worse as failing',
    );
@endphp
<!DOCTYPE html>
<html lang="en" data-state="{{ $state }}" data-refresh="{{ $refresh }}">
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
            --track: #eaecf0;
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
                --track: #262d3b;
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
        [data-state="degraded"] .banner__icon::after {
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
            margin: 16px 0 28px;
        }

        .stat { padding: 14px 16px; }
        .stat dt { color: var(--muted); font-size: 13px; }
        .stat dd { margin: 2px 0 0; font-size: 20px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .stat--alert dd { color: var(--red-ink); }

        .category { margin-top: 20px; overflow: hidden; }

        .category__header {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
        }

        .category__header h2 { margin: 0; font-size: 16px; }

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

        .checks { margin: 0; padding: 0; list-style: none; }

        .check {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 4px 14px;
            align-items: start;
            padding: 16px 20px;
        }

        .check + .check { border-top: 1px solid var(--divider); }

        .check__dot {
            width: 10px;
            height: 10px;
            margin-top: 7px;
            border-radius: 50%;
            background: var(--tone);
            box-shadow: 0 0 0 4px var(--tone-soft);
        }

        .is-failing .check__dot { animation: pulse 1.6s ease-in-out infinite; }

        .check__name { font-weight: 600; overflow-wrap: anywhere; }

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
        .check__schedule { margin: 2px 0 0; color: var(--muted); font-size: 13px; }
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

        .check__timing {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px 10px;
            align-items: center;
            justify-items: end;
            width: 176px;
            padding-top: 1px;
        }

        .check__duration { color: var(--muted); font: 13px var(--mono); font-variant-numeric: tabular-nums; }

        .check__duration { min-width: 52px; text-align: right; }

        /* Every bar shares a width, so lengths compare across rows */
        .bar { grid-column: 1 / -1; width: 100%; height: 4px; border-radius: 4px; background: var(--track); overflow: hidden; }
        .bar span { display: block; height: 100%; border-radius: inherit; background: var(--tone); }

        .empty { margin-top: 20px; padding: 32px 24px; color: var(--muted); text-align: center; }
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

        .is-refreshing #system-status { opacity: .7; transition: opacity .2s; }

        @keyframes pulse { 50% { box-shadow: 0 0 0 7px var(--tone-soft); } }
        @keyframes ripple { 0% { opacity: .6; transform: scale(.85); } 100% { opacity: 0; transform: scale(1.35); } }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }

        @media (max-width: 560px) {
            .page { padding-top: 24px; }
            .banner { padding: 18px; }
            .banner h1 { font-size: 20px; }
            .stat dd { font-size: 18px; }
            .check { grid-template-columns: auto minmax(0, 1fr); }
            .check__timing { grid-column: 2; grid-template-columns: auto auto minmax(0, 1fr); justify-items: start; width: auto; margin-top: 6px; }
            .check__duration { min-width: 0; text-align: left; }
            .bar { grid-column: auto; justify-self: stretch; }
        }
    </style>
</head>
<body>
<div class="page">
    <p class="eyebrow">{{ $title }}</p>

    <p class="notice" data-notice hidden></p>

    <main id="system-status">
        <section class="card banner tone-{{ $tones[$state] }}" role="status" aria-live="polite">
            <span class="banner__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                    @switch($state)
                        @case('operational')<path d="M5 12.5l4.5 4.5L19 7.5"/>@break
                        @case('degraded')<path d="M12 6.5v7"/><path d="M12 17.5h.01"/>@break
                        @case('failing')<path d="M7 7l10 10M17 7L7 17"/>@break
                        @default<path d="M7 12h10"/>
                    @endswitch
                </svg>
            </span>
            <div>
                <h1>{{ $headline }}</h1>
                @if ($error !== null)
                    <p>{{ $error }}</p>
                @elseif ($state === 'empty')
                    <p>There is nothing to check yet.</p>
                @else
                    <p>{{ $summary }}</p>
                @endif
            </div>
        </section>

        @if ($report !== null && $checks > 0)
            <dl class="stats">
                <div class="card stat">
                    <dt>Checks</dt>
                    <dd>{{ $checks }}</dd>
                </div>
                <div class="card stat @if ($failures > 0) stat--alert @endif">
                    <dt>Failing</dt>
                    <dd>{{ $failures }}</dd>
                </div>
                <div class="card stat">
                    <dt>Slowest check</dt>
                    <dd>{{ $duration($slowest) }}</dd>
                </div>
                <div class="card stat">
                    <dt>Checked</dt>
                    <dd><time datetime="{{ $report->checkedAt()->format(DATE_ATOM) }}" data-checked-at>{{ $report->checkedAt()->format('H:i:s') }}</time></dd>
                </div>
            </dl>

            @foreach ($categories as $category)
                <section class="card category">
                    <header class="category__header">
                        <h2>{{ $category['label'] }}</h2>
                        <span class="pill tone-{{ $tones[$category['state']] }}">{{ $stateLabels[$category['state']] }}</span>
                    </header>
                    <ul class="checks">
                        @foreach ($category['results'] as $result)
                            @php($rating = $result->rating->label())
                            <li class="check tone-{{ $tones[$rating] }} @if (! $result->passes($failAt)) is-failing @endif">
                                <span class="check__dot" aria-hidden="true"></span>
                                <div>
                                    <div class="check__name">{{ $result->name }}</div>
                                    @if ($result->error !== null)
                                        <p class="check__error">{{ $exposeErrors ? $result->errorMessage() : 'Check failed' }}</p>
                                    @endif
                                    @foreach ($result->notes as $note)
                                        <p class="check__note">{{ $note }}</p>
                                    @endforeach
                                    @if ($result->meta !== [])
                                        <dl class="check__meta">
                                            @foreach ($result->meta as $key => $value)
                                                <div><dt>{{ $key }}</dt><dd>{{ is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</dd></div>
                                            @endforeach
                                        </dl>
                                    @endif
                                </div>
                                <div class="check__timing">
                                    <span class="pill">{{ ucfirst($rating) }}</span>
                                    <span class="check__duration">{{ $duration($result->duration) }}</span>
                                    <span class="bar" aria-hidden="true"><span style="width: {{ $slowest > 0 ? max(2, (int) round($result->duration / $slowest * 100)) : 0 }}%"></span></span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @elseif ($state === 'empty')
            <div class="card empty">
                Add monitors to the <code>monitors</code> section of <code>config/system-status.php</code>.
            </div>
        @endif

        @if ($watches !== [])
            <section class="card category" data-watches>
                <header class="category__header">
                    <h2>Watches</h2>
                    <span class="pill tone-{{ $tones[$watchSummary['state']] }}">{{ $watchSummary['label'] }}</span>
                </header>
                <ul class="checks">
                    @foreach ($watches as $watch)
                        @php($overdue = $watch['overdue_since'] !== null)
                        <li class="check tone-{{ $tones[$overdue && $watch['state'] !== 'down' ? 'overdue' : $watch['state']] }} @if ($watch['state'] === 'down') is-failing @endif">
                            <span class="check__dot" aria-hidden="true"></span>
                            <div>
                                <div class="check__name">{{ $watch['label'] }}</div>
                                <p class="check__schedule" @if ($watch['cron'] !== null) title="{{ $watch['cron'] }}" @endif>
                                    {{ $watch['schedule'] }}
                                    @if ($watch['next_run_at'] !== null)
                                        · next <time datetime="{{ $watch['next_run_at']->format(DATE_ATOM) }}" data-local-time>{{ $clock($watch['next_run_at']) }}</time>
                                    @endif
                                </p>
                                @if ($overdue)
                                    <p class="check__note check__note--overdue">
                                        Overdue since <time datetime="{{ $watch['overdue_since']->format(DATE_ATOM) }}" data-local-time>{{ $clock($watch['overdue_since']) }}</time>. Is the scheduler running?
                                    </p>
                                @endif
                                @if ($watch['error'] !== null)
                                    <p class="check__error">{{ $exposeErrors ? $watch['error'] : 'Check failed' }}</p>
                                @elseif ($watch['last_check'] !== null)
                                    <p class="check__note">Last check rated {{ $watch['last_check']['rating'] . ($watch['last_check']['duration'] === null ? '' : ' in ' . $duration($watch['last_check']['duration'])) }}</p>
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
                            </div>
                            <div class="check__timing">
                                <span class="pill">{{ $watch['state_label'] }}</span>
                                <span class="check__duration">
                                    @if ($watch['checked_at'] !== null)
                                        <time datetime="{{ $watch['checked_at']->format(DATE_ATOM) }}" data-time-ago="Last checked">{{ $clock($watch['checked_at']) }}</time>
                                    @endif
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
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
        var notice = document.querySelector('[data-notice]');
        var busy = false;
        var missed = false;

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

        // Watch times are shown in the viewer's own time too, with the date when it isn't today
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
                    var main = next.getElementById('system-status');
                    var icon = next.querySelector('link[rel="icon"]');

                    if (!main) {
                        throw new Error('Unexpected response');
                    }

                    document.getElementById('system-status').replaceWith(main);
                    document.title = next.title;
                    root.setAttribute('data-state', next.documentElement.getAttribute('data-state'));

                    if (icon) {
                        document.querySelector('link[rel="icon"]').href = icon.href;
                    }

                    notice.hidden = true;
                    showCheckedAt();
                    showLocalTimes();
                })
                .catch(function () {
                    notice.textContent = 'Couldn’t refresh at ' + new Date().toLocaleTimeString() + '. Showing the last result.';
                    notice.hidden = false;
                })
                .then(function () {
                    busy = false;
                    root.classList.remove('is-refreshing');
                });
        }

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
