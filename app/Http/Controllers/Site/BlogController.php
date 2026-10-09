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

        return view('site.blog.show', [
            'post' => $post,
            'body' => $this->blog->render($slug),
            'neighbours' => $this->blog->neighbours($slug),
            'schema' => [$this->schema->article($post)],
        ]);
    }
}
