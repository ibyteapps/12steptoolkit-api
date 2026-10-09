<?php

namespace App\Http\Controllers\Site;

use App\Services\Site\BlogLibrary;
use App\Services\Site\Schema;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The blog — 52 posts and an index, 53 of the site's indexed URLs. */
class BlogController extends Controller
{
    public function __construct(
        private readonly BlogLibrary $blog,
        private readonly Schema $schema,
    ) {}

    public function index(): View
    {
        $posts = $this->blog->posts();

        return view('site.blog.index', [
            'posts' => $posts,
            'schema' => [$this->schema->blogList($posts)],
        ]);
    }

    public function show(string $slug): View
    {
        $post = $this->blog->find($slug);

        if ($post === null) {
            throw new NotFoundHttpException;
        }

        // Three more to read: same category first, topped up with the most
        // recent of anything else. The production site carried a related-posts
        // rail under every article and the first Laravel cut dropped it, which
        // left 52 articles with one outbound link each.
        $others = $this->blog->posts()->reject(fn (array $p): bool => $p['slug'] === $slug);
        $related = $others
            ->filter(fn (array $p): bool => $p['category'] === $post['category'])
            ->take(3)
            ->concat($others->filter(fn (array $p): bool => $p['category'] !== $post['category']))
            ->unique('slug')
            ->take(3)
            ->values();

        return view('site.blog.show', [
            'post' => $post,
            'body' => $this->blog->render($slug),
            'neighbours' => $this->blog->neighbours($slug),
            'related' => $related,
            'schema' => [$this->schema->article($post)],
        ]);
    }
}
