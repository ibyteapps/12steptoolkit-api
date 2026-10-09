<x-site-layout
    :title="\App\Services\Site\LiteratureLibrary::SECTION_META[$section]['title']"
    :description="\App\Services\Site\LiteratureLibrary::SECTION_META[$section]['description']"
    :crumbs="['A.A. Literature' => '/aa-literature', $heading => null]"
    :schema="$schema"
>
    <h1>{{ $heading }}</h1>
    <p class="lede">{{ \App\Services\Site\LiteratureLibrary::countLabel($section, $documents->count()) }}.</p>

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
