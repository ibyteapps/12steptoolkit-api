<x-site-layout
    :title="$heading.' | A.A. Literature | 12 Step Toolkit'"
    :description="'Read '.$heading.' from the literature of Alcoholics Anonymous, free and without an account.'"
    :crumbs="['A.A. Literature' => '/aa-literature', $heading => null]"
    :schema="$schema"
>
    <h1>{{ $heading }}</h1>
    <p class="lede">{{ $documents->count() }} {{ \Illuminate\Support\Str::plural('page', $documents->count()) }}.</p>

    <ul class="index">
        @foreach($documents as $document)
            <li>
                <a href="{{ $document['path'] }}">
                    {{ $document['label'] }}
                    @isset($document['desc'])<span class="sub">{{ $document['desc'] }}</span>@endisset
                </a>
            </li>
        @endforeach
    </ul>
</x-site-layout>
