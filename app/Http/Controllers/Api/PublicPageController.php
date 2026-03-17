<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
//use App\Mail\ContactMessageMail;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Menu;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class PublicPageController extends Controller
{
    public function show(string $slug)
    {
        $page = Page::with([
                'album.banners' => function ($q) {
                    $q->orderBy('order');
                }
            ])
            ->where('slug', $slug)
            ->where('status', 'PUBLISHED')
            ->firstOrFail();

        return response()->json([
            'id'        => $page->id,
            'title'     => $page->name,
            'label'     => $page->label,
            'slug'      => $page->slug,
            'content'   => $page->contents,
            'page_type' => $page->page_type,
            'template'  => $page->template,
            'image_url' => $page->image_url,

            'meta' => [
                'title'       => $page->meta_title,
                'description' => $page->meta_description,
                'keywords'    => $page->meta_keyword,
            ],
            'album' => ($page->album && $page->album->id != 0) ? [
                'id'             => $page->album->id,
                'name'           => $page->album->name,
                'type'           => $page->album->type,
                'banner_type'    => $page->album->banner_type,
                'transition'     => $page->album->transition,
                'transition_in'  => $page->album->transition_in,
                'transition_out' => $page->album->transition_out,
                'banners' => $page->album->banners->map(function ($banner) {
                    return [
                        'id'          => $banner->id,
                        'title'       => $banner->title,
                        'description' => $banner->description,
                        'alt'         => $banner->alt,
                        'image_url'   => $banner->image_path,
                        'button_text' => $banner->button_text,
                        'url'         => $banner->url,
                        'order'       => $banner->order,
                    ];
                })->values(),
            ] : null,
        ]);
    }

    public function active()
{
    $menu = Menu::where('is_active', true)->first();

    if (!$menu) {
        return response()->json(['data' => null]);
    }

    // Decode pages_json — it's stored as a JSON string
    $items = is_string($menu->pages_json)
        ? json_decode($menu->pages_json, true)
        : ($menu->pages_json ?? []);

    $pageIds = $this->collectPageIds($items);
    $pages = \App\Models\Page::whereIn('id', $pageIds)
        ->select('id', 'slug')
        ->get()
        ->keyBy('id');

    return response()->json([
        'data' => [
            'id'    => $menu->id,
            'name'  => $menu->name,
            'items' => $this->normalizeItems($items, $pages),
        ],
    ]);
}

    private function collectPageIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (!empty($item['page_id'])) {
                $ids[] = $item['page_id'];
            }
            if (!empty($item['children'])) {
                $ids = array_merge($ids, $this->collectPageIds($item['children']));
            }
        }
        return $ids;
    }

    private function normalizeItems(array $items, $pages = null): array
    {
        return collect($items)->map(function ($item) use ($pages) {
            $target = $item['target'] ?? '';
            if (empty($target) && !empty($item['page_id']) && $pages) {
                $page = $pages->get($item['page_id']);
                $target = $page ? '/public/' . $page->slug : '#';
            }

            return [
                'id'       => $item['id'] ?? $item['page_id'] ?? null,  // ← fallback
                'label'    => $item['label'] ?? '',
                'type'     => $item['type'] ?? 'page',
                'target'   => $target,
                'children' => $this->normalizeItems($item['children'] ?? [], $pages),
            ];
        })->values()->all();
    }

    public function footer()
    {
        $footer = Page::where('slug', 'footer')
            ->where('status', 'published')
            ->first();

        if (!$footer) {
            return response()->json([
                'message' => 'Footer not found'
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $footer->id,
                'slug' => $footer->slug,
                'contents' => $footer->contents,
            ]
        ]);
    }
    
    public function public_articles(Request $request)
    {
        $query = Article::query()
            ->with(['category:id,name,slug', 'user:id,firstname,lastname'])
            ->where('status', 'published')
            ->orderBy('date', 'desc');

        // 🔍 Search
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('teaser', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('category')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($request->filled('year')) {
            $query->whereYear('date', $request->year);
        }

        if ($request->filled('month')) {
            $query->whereMonth('date', $request->month);
        }

        $articles = $query->paginate(
            $request->get('per_page', 10)
        );

        return response()->json($articles);
    }

    public function public_articles_show(string $slug)
    {
        $article = Article::with(['category:id,name,slug', 'user:id,firstname,lastname'])
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return response()->json($article);
    }

    public function public_article_categories()
    {
        $categories = ArticleCategory::query()
            ->select('id', 'name', 'slug')
            ->withCount([
                'articles as articles_count' => function ($q) {
                    $q->where('status', 'published');
                }
            ])
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function archive()
    {
        $rows = Article::where('status', 'published')
            ->selectRaw('YEAR(date) as year, MONTH(date) as month, COUNT(*) as total')
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        // Transform to nested structure
        $archive = [];

        foreach ($rows as $row) {
            $archive[$row->year][] = [
                'month' => $row->month,
                'total' => $row->total,
            ];
        }

        return response()->json($archive);
    }
    /*
    public function send(Request $request)
    {
        $data = $request->validate([
            'inquiry_type'   => 'required|string',
            'first_name'     => 'required|string|max:100',
            'last_name'      => 'required|string|max:100',
            'email'          => 'required|email',
            'contact_number' => 'required|string|max:30',
            'message'        => 'required|string|max:2000',
        ]);

        Mail::to(config('mail.from.address'))
            ->send(new ContactMessageMail($data));

        return response()->json([
            'message' => 'Message sent successfully'
        ]);
    }
    */
}
