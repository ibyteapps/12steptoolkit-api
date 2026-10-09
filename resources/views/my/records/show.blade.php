<x-my-layout :title="$type['label']">
<div class="wrap" style="max-width:48rem">
    <a class="back" href="{{ route('my.records.index', $slug) }}">&larr; {{ $type['plural'] }}</a>

    <h1>
        @php($title = $type['title'] ? trim((string) $record->getRawOriginal($type['title'])) : '')
        {{ $title !== '' ? $title : $type['label'] }}
    </h1>
    <p style="color:var(--ink-faint);margin:0 0 26px">{{ \App\Services\My\RecordTypes::writtenOn($record) }}</p>

    <div class="card">
        @forelse($fields as $label => $value)
            <div class="field">
                <dt>{{ $label }}</dt>
                <dd>{{ $value }}</dd>
            </div>
        @empty
            <p class="empty">This entry is empty.</p>
        @endforelse
    </div>

    <div class="rowbtns">
        <a href="{{ route('my.records.edit', [$slug, $record->getKey()]) }}">Edit this entry</a>
    </div>

    <form class="danger" method="POST" action="{{ route('my.records.destroy', [$slug, $record->getKey()]) }}"
          onsubmit="return confirm('Delete this permanently? It cannot be undone.')">
        @csrf
        @method('DELETE')
        <button class="remove" type="submit">Delete this entry</button>
    </form>
</div>
</x-my-layout>
