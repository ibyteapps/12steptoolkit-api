{{--
    One form for all seven types.

    The fields come from `RecordFields`, which carries the app's own wording,
    and the controller builds its update by walking the same list — so a field
    that is not defined there cannot be rendered and cannot be written.
--}}
<x-my-layout :title="$record ? 'Edit' : 'New '.$type['label']">
<div class="wrap" style="max-width:46rem">
    <a class="back" href="{{ $record ? route('my.records.show', [$slug, $record->getKey()]) : route('my.records.index', $slug) }}">
        &larr; {{ $record ? 'Back to the entry' : $type['plural'] }}
    </a>

    <h1>{{ $record ? 'Edit' : 'New '.$type['label'] }}</h1>

    @if($errors->any())
        <div class="errors"><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form method="POST"
          action="{{ $record ? route('my.records.update', [$slug, $record->getKey()]) : route('my.records.store', $slug) }}">
        @csrf
        @if($record)@method('PUT')@endif

        <div class="card">
            @foreach($fields as $field)
                @php($column = $field['column'])
                @php($value = old('f.'.$column, $values[$column] ?? ''))
                <div class="field {{ ($field['quiet'] ?? false) ? 'quiet' : '' }}">
                    @switch($field['kind'])
                        @case('switch')
                            <label class="row">
                                <input type="hidden" name="f[{{ $column }}]" value="0">
                                <input type="checkbox" name="f[{{ $column }}]" value="1"
                                       @checked(old('f.'.$column, $values[$column] ?? false))>
                                <span>{{ $field['label'] }}</span>
                            </label>
                            @break

                        @case('done')
                            <label class="row">
                                <input type="hidden" name="f[{{ $column }}]" value="0">
                                <input type="checkbox" name="f[{{ $column }}]" value="1"
                                       @checked(old('f.'.$column, $values[$column] ?? false))>
                                <span>{{ $field['label'] }}</span>
                            </label>
                            @break

                        @case('tags')
                            <dt>{{ $field['label'] }}</dt>
                            <div class="tags">
                                @foreach($field['options'] as $option)
                                    <label>
                                        <input type="checkbox" name="f[{{ $column }}][]" value="{{ $option }}"
                                               @checked(in_array($option, (array) old('f.'.$column, $values[$column] ?? []), true))>
                                        <span>{{ $option }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @break

                        @case('mood')
                            <dt>{{ $field['label'] }}</dt>
                            <div class="tags">
                                @foreach($field['options'] as $option)
                                    <label>
                                        <input type="radio" name="f[{{ $column }}]" value="{{ $option }}"
                                               @checked((string) $value === $option)>
                                        <span>{{ $option }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @break

                        @case('textarea')
                            <label for="f-{{ $column }}">{{ $field['label'] }}</label>
                            <textarea id="f-{{ $column }}" name="f[{{ $column }}]"
                                      rows="{{ $field['rows'] ?? 4 }}"
                                      @required($field['required'] ?? false)>{{ $value }}</textarea>
                            @break

                        @default
                            <label for="f-{{ $column }}">{{ $field['label'] }}</label>
                            <input id="f-{{ $column }}" type="text" name="f[{{ $column }}]"
                                   value="{{ $value }}" @required($field['required'] ?? false)>
                    @endswitch
                </div>
            @endforeach
        </div>

        <button class="primary" type="submit" style="max-width:18rem">Save</button>
    </form>
</div>
</x-my-layout>
