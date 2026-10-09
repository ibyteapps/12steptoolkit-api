<x-my-layout :title="$type['plural']">
<div class="wrap">
    <a class="back" href="{{ route('my.home') }}">&larr; My Toolkit</a>

    @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif

    <h1>{{ $type['plural'] }}</h1>
    <p style="color:var(--ink-soft);margin:0 0 22px">{{ $type['blurb'] }}</p>

    <a class="newentry" href="{{ route('my.records.create', $slug) }}">New {{ $type['label'] }}</a>

    <div class="card">
        @if($records->isEmpty())
            <p class="empty">Nothing here yet. Write one here, or in the app — it is the same account either way.</p>
        @else
            <ul class="rows">
                @foreach($records as $record)
                    <li>
                        <a href="{{ route('my.records.show', [$slug, $record->getKey()]) }}">
                            <span class="t">
                                @php($title = $type['title'] ? trim((string) $record->getRawOriginal($type['title'])) : '')
                                {{ $title !== '' ? $title : $type['label'] }}
                            </span>
                            <span class="d">{{ \App\Services\My\RecordTypes::writtenOn($record) }}</span>
                            @if($type['summary'])
                                @php($summary = trim((string) $record->getRawOriginal($type['summary'])))
                                @if($summary !== '')
                                    <span class="x">{{ \Illuminate\Support\Str::limit($summary, 140) }}</span>
                                @endif
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if($records->hasPages())
        <nav class="pages" aria-label="Pages">
            @if($records->onFirstPage())<span>Newer</span>@else<a href="{{ $records->previousPageUrl() }}">Newer</a>@endif
            <span>Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</span>
            @if($records->hasMorePages())<a href="{{ $records->nextPageUrl() }}">Older</a>@else<span>Older</span>@endif
        </nav>
    @endif
</div>
</x-my-layout>
