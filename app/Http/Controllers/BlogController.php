<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogLike;
use App\Models\BlogTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    // ─── ADMIN: Dashboard Stats ──────────────────────────────────────────────
    public function adminStats()
    {
        $total     = DB::table('blogs')->whereNull('deleted_at')->count();
        $published = DB::table('blogs')->whereNull('deleted_at')->where('status', 'published')->count();
        $draft     = DB::table('blogs')->whereNull('deleted_at')->where('status', 'draft')->count();
        $scheduled = DB::table('blogs')->whereNull('deleted_at')->where('status', 'scheduled')->count();
        $comments  = DB::table('blog_comments')->count();
        $pending   = DB::table('blog_comments')->where('status', 'pending')->count();
        $likes     = DB::table('blog_likes')->count();
        $views     = DB::table('blogs')->whereNull('deleted_at')->sum('views');

        return response()->json([
            'status' => true,
            'stats'  => [
                'total_blogs'      => $total,
                'published_blogs'  => $published,
                'draft_blogs'      => $draft,
                'scheduled_blogs'  => $scheduled,
                'total_comments'   => $comments,
                'pending_comments' => $pending,
                'total_likes'      => $likes,
                'total_views'      => (int) $views,
            ],
        ]);
    }

    // ─── ADMIN: List blogs (paginated) ──────────────────────────────────────
    public function index(Request $request)
    {
        $perPage  = max(1, (int) $request->input('per_page', 10));
        $page     = max(1, (int) $request->input('page', 1));
        $search   = $request->input('search', '');
        $status   = $request->input('status', '');
        $category = $request->input('category', '');

        $query = DB::table('blogs')
            ->leftJoin('admins', 'blogs.author_id', '=', 'admins.id')
            ->leftJoin('blog_categories', 'blogs.category_id', '=', 'blog_categories.id')
            ->whereNull('blogs.deleted_at')
            ->select(
                'blogs.id',
                'blogs.title',
                'blogs.slug',
                'blogs.status',
                'blogs.category',
                'blogs.category_id',
                'blog_categories.name as category_name',
                'blogs.views',
                'blogs.is_featured',
                'blogs.published_at',
                'blogs.created_at',
                'blogs.featured_image',
                DB::raw('COALESCE(blogs.author_name, admins.name) as author_name'),
                DB::raw('(SELECT COUNT(*) FROM blog_comments WHERE blog_comments.blog_id = blogs.id) as comments_count'),
                DB::raw('(SELECT COUNT(*) FROM blog_likes WHERE blog_likes.blog_id = blogs.id) as likes_count')
            );

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('blogs.title', 'like', "%{$search}%")
                  ->orWhere('blogs.excerpt', 'like', "%{$search}%")
                  ->orWhere('blogs.category', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('blogs.status', $status);
        }

        if ($category) {
            $query->where(function($q) use ($category) {
                $q->where('blogs.category', $category)
                  ->orWhere('blogs.category_id', $category);
            });
        }

        $total  = $query->count();
        $offset = ($page - 1) * $perPage;
        $items  = $query->orderBy('blogs.created_at', 'desc')->skip($offset)->take($perPage)->get();

        return response()->json([
            'status' => true,
            'blogs'  => [
                'data'         => $items,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
                'total'        => $total,
            ],
        ]);
    }

    // ─── ADMIN: Single Blog ──────────────────────────────────────────────────
    public function show($id)
    {
        $blog = DB::table('blogs')
            ->leftJoin('blog_categories', 'blogs.category_id', '=', 'blog_categories.id')
            ->where('blogs.id', $id)
            ->whereNull('blogs.deleted_at')
            ->select('blogs.*', 'blog_categories.name as category_name')
            ->first();

        if (!$blog) {
            return response()->json(['status' => false, 'message' => 'Blog not found.'], 404);
        }

        // Attach tags using subquery/join
        $tags = DB::table('blog_post_tag')
            ->join('blog_tags', 'blog_post_tag.tag_id', '=', 'blog_tags.id')
            ->where('blog_post_tag.blog_id', $id)
            ->select('blog_tags.id', 'blog_tags.name', 'blog_tags.slug')
            ->get();

        $blog->tags_list = $tags;
        $blog->tag_ids = $tags->pluck('id')->toArray();

        return response()->json(['status' => true, 'blog' => $blog]);
    }

    // ─── ADMIN: Store Blog ──────────────────────────────────────────────────
    public function store(Request $request)
    {
        $title = $request->input('title');
        if (!$title) {
            return response()->json(['status' => false, 'message' => 'Title is required.'], 422);
        }

        $rawSlug = $request->input('slug') ? Str::slug($request->input('slug')) : Str::slug($title);
        $slug    = $this->uniqueSlug($rawSlug);

        $status      = $request->input('status', 'draft');
        $publishedAt = $request->input('published_at') ? $request->input('published_at') : ($status === 'published' ? now() : null);

        $blogId = DB::table('blogs')->insertGetId([
            'title'               => $title,
            'slug'                => $slug,
            'excerpt'             => $request->input('excerpt'),
            'content'             => $request->input('content', ''),
            'featured_image'      => $request->input('featured_image'),
            'status'              => $status,
            'category'            => $request->input('category'),
            'category_id'         => $request->input('category_id'),
            'tags'                => is_array($request->input('tags')) ? implode(',', $request->input('tags')) : $request->input('tags'),
            'author_id'           => $request->input('author_id'),
            'author_name'         => $request->input('author_name'),
            'reading_time'        => $request->input('reading_time', '5 min read'),
            'is_featured'         => (bool) $request->input('is_featured', false),
            'allow_comments'      => (bool) $request->input('allow_comments', true),
            'published_at'        => $publishedAt,
            'meta_title'          => $request->input('meta_title'),
            'meta_description'    => $request->input('meta_description'),
            'meta_keywords'       => $request->input('meta_keywords'),
            'canonical_url'       => $request->input('canonical_url'),
            'og_title'            => $request->input('og_title'),
            'og_description'      => $request->input('og_description'),
            'og_image'            => $request->input('og_image'),
            'twitter_title'       => $request->input('twitter_title'),
            'twitter_description' => $request->input('twitter_description'),
            'twitter_image'       => $request->input('twitter_image'),
            'robots_index'        => (bool) $request->input('robots_index', true),
            'robots_follow'       => (bool) $request->input('robots_follow', true),
            'schema_markup'       => $request->input('schema_markup'),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        if ($request->has('tag_ids') && is_array($request->input('tag_ids'))) {
            $tagInserts = [];
            foreach ($request->input('tag_ids') as $tId) {
                $tagInserts[] = ['blog_id' => $blogId, 'tag_id' => $tId];
            }
            if (!empty($tagInserts)) {
                DB::table('blog_post_tag')->insert($tagInserts);
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Blog created successfully.',
            'blog_id' => $blogId,
            'slug'    => $slug,
        ]);
    }

    // ─── ADMIN: Update Blog ──────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $blog = DB::table('blogs')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$blog) {
            return response()->json(['status' => false, 'message' => 'Blog not found.'], 404);
        }

        $newTitle   = $request->input('title', $blog->title);
        $customSlug = $request->input('slug');
        $slug       = $blog->slug;

        if ($customSlug && $customSlug !== $blog->slug) {
            $slug = $this->uniqueSlug(Str::slug($customSlug), $id);
        } elseif ($newTitle !== $blog->title) {
            $slug = $this->uniqueSlug(Str::slug($newTitle), $id);
        }

        $status      = $request->input('status', $blog->status);
        $publishedAt = $blog->published_at;
        if ($request->has('published_at') && $request->input('published_at')) {
            $publishedAt = $request->input('published_at');
        } elseif ($status === 'published' && !$blog->published_at) {
            $publishedAt = now();
        }

        DB::table('blogs')->where('id', $id)->update([
            'title'               => $newTitle,
            'slug'                => $slug,
            'excerpt'             => $request->input('excerpt', $blog->excerpt),
            'content'             => $request->input('content', $blog->content),
            'featured_image'      => $request->input('featured_image', $blog->featured_image),
            'status'              => $status,
            'category'            => $request->input('category', $blog->category),
            'category_id'         => $request->input('category_id', $blog->category_id),
            'tags'                => is_array($request->input('tags')) ? implode(',', $request->input('tags')) : $request->input('tags', $blog->tags),
            'author_name'         => $request->input('author_name', $blog->author_name),
            'reading_time'        => $request->input('reading_time', $blog->reading_time),
            'is_featured'         => $request->has('is_featured') ? (bool) $request->input('is_featured') : $blog->is_featured,
            'allow_comments'      => $request->has('allow_comments') ? (bool) $request->input('allow_comments') : $blog->allow_comments,
            'published_at'        => $publishedAt,
            'meta_title'          => $request->has('meta_title') ? $request->input('meta_title') : $blog->meta_title,
            'meta_description'    => $request->has('meta_description') ? $request->input('meta_description') : $blog->meta_description,
            'meta_keywords'       => $request->has('meta_keywords') ? $request->input('meta_keywords') : $blog->meta_keywords,
            'canonical_url'       => $request->has('canonical_url') ? $request->input('canonical_url') : $blog->canonical_url,
            'og_title'            => $request->has('og_title') ? $request->input('og_title') : $blog->og_title,
            'og_description'      => $request->has('og_description') ? $request->input('og_description') : $blog->og_description,
            'og_image'            => $request->has('og_image') ? $request->input('og_image') : $blog->og_image,
            'twitter_title'       => $request->has('twitter_title') ? $request->input('twitter_title') : $blog->twitter_title,
            'twitter_description' => $request->has('twitter_description') ? $request->input('twitter_description') : $blog->twitter_description,
            'twitter_image'       => $request->has('twitter_image') ? $request->input('twitter_image') : $blog->twitter_image,
            'robots_index'        => $request->has('robots_index') ? (bool) $request->input('robots_index') : $blog->robots_index,
            'robots_follow'       => $request->has('robots_follow') ? (bool) $request->input('robots_follow') : $blog->robots_follow,
            'schema_markup'       => $request->has('schema_markup') ? $request->input('schema_markup') : $blog->schema_markup,
            'updated_at'          => now(),
        ]);

        if ($request->has('tag_ids') && is_array($request->input('tag_ids'))) {
            DB::table('blog_post_tag')->where('blog_id', $id)->delete();
            $tagInserts = [];
            foreach ($request->input('tag_ids') as $tId) {
                $tagInserts[] = ['blog_id' => $id, 'tag_id' => $tId];
            }
            if (!empty($tagInserts)) {
                DB::table('blog_post_tag')->insert($tagInserts);
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Blog updated successfully.',
            'slug'    => $slug,
        ]);
    }

    // ─── ADMIN: Delete Blog ──────────────────────────────────────────────────
    public function destroy($id)
    {
        $blog = DB::table('blogs')->where('id', $id)->first();
        if (!$blog) {
            return response()->json(['status' => false, 'message' => 'Blog not found.'], 404);
        }

        // Clean up linked data since DB foreign keys were removed
        DB::table('blog_post_tag')->where('blog_id', $id)->delete();
        DB::table('blog_comments')->where('blog_id', $id)->delete();
        DB::table('blog_likes')->where('blog_id', $id)->delete();

        // Soft delete blog post
        DB::table('blogs')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['status' => true, 'message' => 'Blog post deleted successfully.']);
    }

    // ─── ADMIN: Upload Image ─────────────────────────────────────────────────
    public function uploadImage(Request $request)
    {
        if (!$request->hasFile('image')) {
            return response()->json(['status' => false, 'message' => 'No image file provided.'], 422);
        }

        $file    = $request->file('image');
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
        $ext     = strtolower($file->getClientOriginalExtension());

        if (!in_array($ext, $allowed)) {
            return response()->json(['status' => false, 'message' => 'Invalid image format.'], 422);
        }

        $filename = time() . '_' . Str::random(8) . '.' . $ext;
        $path     = $file->storeAs('blogs', $filename, 'public');

        return response()->json([
            'status' => true,
            'url'    => '/storage/' . $path,
            'path'   => '/storage/' . $path,
        ]);
    }

    // ─── CATEGORY MANAGEMENT ─────────────────────────────────────────────────
    public function listCategories()
    {
        $categories = DB::table('blog_categories')
            ->select(
                'blog_categories.*',
                DB::raw('(SELECT COUNT(*) FROM blogs WHERE (blogs.category_id = blog_categories.id OR blogs.category = blog_categories.name) AND blogs.deleted_at IS NULL AND blogs.status = "published") as blogs_count')
            )
            ->orderBy('blog_categories.name', 'asc')
            ->get();

        return response()->json(['status' => true, 'categories' => $categories]);
    }

    public function storeCategory(Request $request)
    {
        $name = $request->input('name');
        if (!$name) {
            return response()->json(['status' => false, 'message' => 'Category name is required.'], 422);
        }

        $slug = Str::slug($request->input('slug') ?: $name);

        $id = DB::table('blog_categories')->insertGetId([
            'name'            => $name,
            'slug'            => $slug,
            'description'     => $request->input('description'),
            'image'           => $request->input('image'),
            'seo_title'       => $request->input('seo_title'),
            'seo_description' => $request->input('seo_description'),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $category = DB::table('blog_categories')->where('id', $id)->first();

        return response()->json(['status' => true, 'message' => 'Category created.', 'category' => $category]);
    }

    public function updateCategory(Request $request, $id)
    {
        $category = DB::table('blog_categories')->where('id', $id)->first();
        if (!$category) {
            return response()->json(['status' => false, 'message' => 'Category not found.'], 404);
        }

        DB::table('blog_categories')->where('id', $id)->update([
            'name'            => $request->input('name', $category->name),
            'slug'            => Str::slug($request->input('slug', $category->slug)),
            'description'     => $request->input('description', $category->description),
            'image'           => $request->input('image', $category->image),
            'seo_title'       => $request->input('seo_title', $category->seo_title),
            'seo_description' => $request->input('seo_description', $category->seo_description),
            'updated_at'      => now(),
        ]);

        $updatedCategory = DB::table('blog_categories')->where('id', $id)->first();

        return response()->json(['status' => true, 'message' => 'Category updated.', 'category' => $updatedCategory]);
    }

    public function destroyCategory($id)
    {
        DB::table('blogs')->where('category_id', $id)->update(['category_id' => null]);
        DB::table('blog_categories')->where('id', $id)->delete();

        return response()->json(['status' => true, 'message' => 'Category deleted.']);
    }

    // ─── TAG MANAGEMENT ──────────────────────────────────────────────────────
    public function listTags()
    {
        $tags = DB::table('blog_tags')
            ->select(
                'blog_tags.*',
                DB::raw('(SELECT COUNT(*) FROM blog_post_tag JOIN blogs ON blog_post_tag.blog_id = blogs.id WHERE blog_post_tag.tag_id = blog_tags.id AND blogs.deleted_at IS NULL AND blogs.status = "published") as blogs_count')
            )
            ->orderBy('blog_tags.name', 'asc')
            ->get();

        return response()->json(['status' => true, 'tags' => $tags]);
    }

    public function storeTag(Request $request)
    {
        $name = $request->input('name');
        if (!$name) {
            return response()->json(['status' => false, 'message' => 'Tag name is required.'], 422);
        }

        $id = DB::table('blog_tags')->insertGetId([
            'name'       => $name,
            'slug'       => Str::slug($request->input('slug') ?: $name),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tag = DB::table('blog_tags')->where('id', $id)->first();

        return response()->json(['status' => true, 'message' => 'Tag created.', 'tag' => $tag]);
    }

    public function updateTag(Request $request, $id)
    {
        $tag = DB::table('blog_tags')->where('id', $id)->first();
        if (!$tag) {
            return response()->json(['status' => false, 'message' => 'Tag not found.'], 404);
        }
        $name = $request->input('name');
        if (!$name) {
            return response()->json(['status' => false, 'message' => 'Tag name is required.'], 422);
        }

        DB::table('blog_tags')->where('id', $id)->update([
            'name'       => $name,
            'slug'       => Str::slug($request->input('slug') ?: $name),
            'updated_at' => now(),
        ]);

        $updatedTag = DB::table('blog_tags')->where('id', $id)->first();

        return response()->json(['status' => true, 'message' => 'Tag updated.', 'tag' => $updatedTag]);
    }

    public function destroyTag($id)
    {
        DB::table('blog_post_tag')->where('tag_id', $id)->delete();
        DB::table('blog_tags')->where('id', $id)->delete();

        return response()->json(['status' => true, 'message' => 'Tag deleted.']);
    }

    // ─── COMMENT MODERATION (ADMIN) ─────────────────────────────────────────
    public function adminComments(Request $request)
    {
        $status  = $request->input('status');
        $blogId  = $request->input('blog_id');
        $perPage = max(1, (int) $request->input('per_page', 20));
        $page    = max(1, (int) $request->input('page', 1));

        $query = DB::table('blog_comments')
            ->leftJoin('blogs', 'blog_comments.blog_id', '=', 'blogs.id')
            ->select(
                'blog_comments.*',
                'blogs.title as blog_title',
                'blogs.slug as blog_slug'
            );

        if ($status) {
            $query->where('blog_comments.status', $status);
        }
        if ($blogId) {
            $query->where('blog_comments.blog_id', $blogId);
        }

        $total    = $query->count();
        $offset   = ($page - 1) * $perPage;
        $comments = $query->orderBy('blog_comments.created_at', 'desc')->skip($offset)->take($perPage)->get();

        return response()->json([
            'status'   => true,
            'comments' => [
                'data'         => $comments,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
                'total'        => $total,
            ],
        ]);
    }

    public function updateCommentStatus(Request $request, $id)
    {
        $comment = DB::table('blog_comments')->where('id', $id)->first();
        if (!$comment) {
            return response()->json(['status' => false, 'message' => 'Comment not found.'], 404);
        }

        $status = $request->input('status');
        if (!in_array($status, ['approved', 'rejected', 'spam', 'pending'])) {
            return response()->json(['status' => false, 'message' => 'Invalid status.'], 422);
        }

        DB::table('blog_comments')->where('id', $id)->update([
            'status'     => $status,
            'updated_at' => now(),
        ]);

        return response()->json(['status' => true, 'message' => "Comment marked as {$status}."]);
    }

    public function destroyComment($id)
    {
        DB::table('blog_comments')->where('id', $id)->delete();
        return response()->json(['status' => true, 'message' => 'Comment deleted.']);
    }

    // ─── PUBLIC: List Published Blogs ────────────────────────────────────────
    public function publicIndex(Request $request)
    {
        $perPage  = max(1, (int) $request->input('limit', 9));
        $page     = max(1, (int) $request->input('page', 1));
        $category = $request->input('category');
        $tag      = $request->input('tag');
        $search   = $request->input('search');

        $query = DB::table('blogs')
            ->leftJoin('admins', 'blogs.author_id', '=', 'admins.id')
            ->leftJoin('blog_categories', 'blogs.category_id', '=', 'blog_categories.id')
            ->where('blogs.status', 'published')
            ->whereNull('blogs.deleted_at')
            ->where(function($q) {
                $q->whereNull('blogs.published_at')
                  ->orWhere('blogs.published_at', '<=', now());
            })
            ->select(
                'blogs.id',
                'blogs.title',
                'blogs.slug',
                'blogs.excerpt',
                'blogs.category',
                'blogs.category_id',
                'blog_categories.name as category_name',
                'blogs.views',
                'blogs.reading_time',
                'blogs.is_featured',
                'blogs.published_at',
                'blogs.created_at',
                'blogs.featured_image',
                DB::raw('COALESCE(blogs.author_name, admins.name) as author_name'),
                DB::raw('(SELECT COUNT(*) FROM blog_comments WHERE blog_comments.blog_id = blogs.id AND blog_comments.status = "approved") as comments_count'),
                DB::raw('(SELECT COUNT(*) FROM blog_likes WHERE blog_likes.blog_id = blogs.id) as likes_count')
            );

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('blogs.title', 'like', "%{$search}%")
                  ->orWhere('blogs.excerpt', 'like', "%{$search}%")
                  ->orWhere('blogs.content', 'like', "%{$search}%")
                  ->orWhere('blogs.tags', 'like', "%{$search}%");
            });
        }

        if ($category) {
            $query->where(function($q) use ($category) {
                $q->where('blogs.category', $category)
                  ->orWhere('blogs.category_id', $category)
                  ->orWhere('blog_categories.slug', $category);
            });
        }

        if ($tag) {
            $query->where('blogs.tags', 'like', "%{$tag}%");
        }

        $total  = $query->count();
        $offset = ($page - 1) * $perPage;
        $items  = $query->orderBy('blogs.published_at', 'desc')->skip($offset)->take($perPage)->get();

        return response()->json([
            'status' => true,
            'blogs'  => [
                'data'         => $items,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
                'total'        => $total,
            ],
        ]);
    }

    // ─── PUBLIC: Show Single Blog ────────────────────────────────────────────
    public function publicShow(Request $request, $slug)
    {
        $blog = DB::table('blogs')
            ->leftJoin('blog_categories', 'blogs.category_id', '=', 'blog_categories.id')
            ->where('blogs.slug', $slug)
            ->where('blogs.status', 'published')
            ->whereNull('blogs.deleted_at')
            ->select('blogs.*', 'blog_categories.name as category_name', 'blog_categories.slug as category_slug')
            ->first();

        if (!$blog && is_numeric($slug)) {
            $blog = DB::table('blogs')
                ->leftJoin('blog_categories', 'blogs.category_id', '=', 'blog_categories.id')
                ->where('blogs.id', $slug)
                ->where('blogs.status', 'published')
                ->whereNull('blogs.deleted_at')
                ->select('blogs.*', 'blog_categories.name as category_name', 'blog_categories.slug as category_slug')
                ->first();
        }

        if (!$blog) {
            return response()->json(['status' => false, 'message' => 'Blog post not found.'], 404);
        }

        // Fetch author
        $adminAuthor = DB::table('admins')->where('id', $blog->author_id)->first();
        $authorName  = $blog->author_name ?: ($adminAuthor ? $adminAuthor->name : 'MegaByte Circuits');

        // Fetch approved comments
        $comments = DB::table('blog_comments')
            ->where('blog_id', $blog->id)
            ->where('status', 'approved')
            ->whereNull('parent_id')
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($comments as $comment) {
            $comment->replies = DB::table('blog_comments')
                ->where('parent_id', $comment->id)
                ->where('status', 'approved')
                ->orderBy('created_at', 'asc')
                ->get();
        }

        // Count likes
        $likesCount = DB::table('blog_likes')->where('blog_id', $blog->id)->count();

        // User liked status check
        $userIp   = $request->ip();
        $hasLiked = DB::table('blog_likes')
            ->where('blog_id', $blog->id)
            ->where('ip_address', $userIp)
            ->exists();

        // Fetch related blogs
        $related = DB::table('blogs')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->where('id', '!=', $blog->id)
            ->where(function($q) use ($blog) {
                if ($blog->category_id) {
                    $q->orWhere('category_id', $blog->category_id);
                }
                if ($blog->category) {
                    $q->orWhere('category', $blog->category);
                }
            })
            ->take(3)
            ->get(['id', 'title', 'slug', 'excerpt', 'featured_image', 'published_at', 'category', 'reading_time']);

        if ($related->count() < 3) {
            $extra = DB::table('blogs')
                ->where('status', 'published')
                ->whereNull('deleted_at')
                ->where('id', '!=', $blog->id)
                ->whereNotIn('id', $related->pluck('id')->toArray())
                ->take(3 - $related->count())
                ->get(['id', 'title', 'slug', 'excerpt', 'featured_image', 'published_at', 'category', 'reading_time']);
            $related = $related->merge($extra);
        }

        return response()->json([
            'status'      => true,
            'blog'        => $blog,
            'author'      => [
                'name'   => $authorName,
                'avatar' => strtoupper(substr($authorName, 0, 2)),
            ],
            'comments'    => $comments,
            'likes_count' => $likesCount,
            'has_liked'   => $hasLiked,
            'related'     => $related,
        ]);
    }

    // ─── PUBLIC: Increment View Count ────────────────────────────────────────
    public function incrementView(Request $request, $id)
    {
        $blog = DB::table('blogs')->where('id', $id)->first();
        if (!$blog && !is_numeric($id)) {
            $blog = DB::table('blogs')->where('slug', $id)->first();
        }

        if ($blog) {
            DB::table('blogs')->where('id', $blog->id)->increment('views');
            return response()->json([
                'status' => true,
                'views'  => (int) $blog->views + 1,
            ]);
        }

        return response()->json(['status' => false, 'message' => 'Blog post not found.'], 404);
    }

    // ─── PUBLIC: Like / Unlike Blog ──────────────────────────────────────────
    public function toggleLike(Request $request, $id)
    {
        $blog = DB::table('blogs')->where('id', $id)->first();
        if (!$blog) {
            return response()->json(['status' => false, 'message' => 'Blog not found.'], 404);
        }

        $userIp   = $request->ip();
        $existing = DB::table('blog_likes')
            ->where('blog_id', $id)
            ->where('ip_address', $userIp)
            ->first();

        if ($existing) {
            DB::table('blog_likes')->where('id', $existing->id)->delete();
            $liked = false;
        } else {
            DB::table('blog_likes')->insert([
                'blog_id'    => $id,
                'ip_address' => $userIp,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $liked = true;
        }

        $totalLikes = DB::table('blog_likes')->where('blog_id', $id)->count();

        return response()->json([
            'status'      => true,
            'liked'       => $liked,
            'likes_count' => $totalLikes,
        ]);
    }

    // ─── PUBLIC: Submit Comment ──────────────────────────────────────────────
    public function submitComment(Request $request, $id)
    {
        $blog = DB::table('blogs')->where('id', $id)->first();
        if (!$blog || !$blog->allow_comments) {
            return response()->json(['status' => false, 'message' => 'Comments are closed for this post.'], 403);
        }

        $name    = trim($request->input('name'));
        $email   = trim($request->input('email'));
        $content = trim($request->input('content'));

        if (!$name || !$email || !$content) {
            return response()->json(['status' => false, 'message' => 'Name, email, and comment content are required.'], 422);
        }

        $commentId = DB::table('blog_comments')->insertGetId([
            'blog_id'    => $id,
            'name'       => $name,
            'email'      => $email,
            'content'    => $content,
            'status'     => 'pending',
            'parent_id'  => $request->input('parent_id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $comment = DB::table('blog_comments')->where('id', $commentId)->first();

        return response()->json([
            'status'  => true,
            'message' => 'Thank you! Your comment has been submitted and is awaiting moderation.',
            'comment' => $comment,
        ]);
    }

    // ─── HELPER: Unique Slug ─────────────────────────────────────────────────
    private function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $slug = $base ?: 'blog-post';
        $i    = 0;
        while (true) {
            $candidate = $i === 0 ? $slug : $slug . '-' . $i;
            $q = DB::table('blogs')->where('slug', $candidate);
            if ($excludeId) $q->where('id', '!=', $excludeId);
            if (!$q->exists()) return $candidate;
            $i++;
        }
    }
}
