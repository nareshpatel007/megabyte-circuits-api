<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    // ─── List blogs (paginated) ──────────────────────────────────────────────
    public function index(Request $request)
    {
        $perPage = 15;
        $page    = max(1, (int) $request->input('page', 1));
        $search  = $request->input('search', '');
        $status  = $request->input('status', '');

        $query = DB::table('blogs')
            ->leftJoin('admins', 'blogs.author_id', '=', 'admins.id')
            ->select(
                'blogs.id', 'blogs.title', 'blogs.slug', 'blogs.status',
                'blogs.category', 'blogs.views', 'blogs.published_at',
                'blogs.created_at', 'blogs.featured_image',
                'admins.name as author_name'
            );

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('blogs.title', 'like', "%{$search}%")
                  ->orWhere('blogs.category', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('blogs.status', $status);
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

    // ─── Get single blog ─────────────────────────────────────────────────────
    public function show($id)
    {
        $blog = DB::table('blogs')->where('id', $id)->first();

        if (!$blog) {
            return response()->json(['status' => false, 'message' => 'Blog not found.'], 404);
        }

        return response()->json(['status' => true, 'blog' => $blog]);
    }

    // ─── Create blog ─────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $title = $request->input('title');
        if (!$title) {
            return response()->json(['status' => false, 'message' => 'Title is required.'], 422);
        }

        $slug = $this->uniqueSlug(Str::slug($title));

        $id = DB::table('blogs')->insertGetId([
            'title'            => $title,
            'slug'             => $slug,
            'excerpt'          => $request->input('excerpt'),
            'content'          => $request->input('content', ''),
            'featured_image'   => $request->input('featured_image'),
            'status'           => $request->input('status', 'draft'),
            'category'         => $request->input('category'),
            'tags'             => $request->input('tags'),
            'author_id'        => $request->input('author_id'),
            'published_at'     => $request->input('status') === 'published' ? now() : null,
            // SEO
            'meta_title'       => $request->input('meta_title'),
            'meta_description' => $request->input('meta_description'),
            'meta_keywords'    => $request->input('meta_keywords'),
            'canonical_url'    => $request->input('canonical_url'),
            'og_title'         => $request->input('og_title'),
            'og_description'   => $request->input('og_description'),
            'og_image'         => $request->input('og_image'),
            'schema_markup'    => $request->input('schema_markup'),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Blog created successfully.',
            'blog_id' => $id,
            'slug'    => $slug,
        ]);
    }

    // ─── Update blog ─────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $blog = DB::table('blogs')->where('id', $id)->first();
        if (!$blog) {
            return response()->json(['status' => false, 'message' => 'Blog not found.'], 404);
        }

        $wasPublished = $blog->status === 'published';
        $newStatus    = $request->input('status', $blog->status);
        $publishedAt  = $blog->published_at;
        if ($newStatus === 'published' && !$wasPublished) {
            $publishedAt = now();
        }

        $fields = [
            'title'            => $request->input('title', $blog->title),
            'excerpt'          => $request->input('excerpt'),
            'content'          => $request->input('content', $blog->content),
            'featured_image'   => $request->input('featured_image', $blog->featured_image),
            'status'           => $newStatus,
            'category'         => $request->input('category'),
            'tags'             => $request->input('tags'),
            'published_at'     => $publishedAt,
            // SEO
            'meta_title'       => $request->input('meta_title'),
            'meta_description' => $request->input('meta_description'),
            'meta_keywords'    => $request->input('meta_keywords'),
            'canonical_url'    => $request->input('canonical_url'),
            'og_title'         => $request->input('og_title'),
            'og_description'   => $request->input('og_description'),
            'og_image'         => $request->input('og_image'),
            'schema_markup'    => $request->input('schema_markup'),
            'updated_at'       => now(),
        ];

        // Update slug if title changed
        $newTitle = $request->input('title', $blog->title);
        if ($newTitle !== $blog->title) {
            $fields['slug'] = $this->uniqueSlug(Str::slug($newTitle), $id);
        }

        DB::table('blogs')->where('id', $id)->update($fields);

        return response()->json([
            'status'  => true,
            'message' => 'Blog updated successfully.',
        ]);
    }

    // ─── Delete blog ─────────────────────────────────────────────────────────
    public function destroy($id)
    {
        $blog = DB::table('blogs')->where('id', $id)->first();
        if (!$blog) {
            return response()->json(['status' => false, 'message' => 'Blog not found.'], 404);
        }

        DB::table('blogs')->where('id', $id)->delete();

        return response()->json(['status' => true, 'message' => 'Blog deleted.']);
    }

    // ─── Upload image ─────────────────────────────────────────────────────────
    public function uploadImage(Request $request)
    {
        if (!$request->hasFile('image')) {
            return response()->json(['status' => false, 'message' => 'No image provided.'], 422);
        }

        $file = $request->file('image');

        // Validate
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array(strtolower($file->getClientOriginalExtension()), $allowed)) {
            return response()->json(['status' => false, 'message' => 'Invalid image type.'], 422);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return response()->json(['status' => false, 'message' => 'Image must be under 5MB.'], 422);
        }

        $filename = time() . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
        $path     = $file->storeAs('blogs', $filename, 'public');

        return response()->json([
            'status' => true,
            'url'    => '/storage/' . $path,
            'path'   => $path,
        ]);
    }

    // ─── Helper: unique slug ──────────────────────────────────────────────────
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

    // ─── Generate with AI ──────────────────────────────────────────────────────
    public function generateAI(Request $request)
    {
        try {
            $prompt = $request->input('prompt');
            if (empty($prompt)) {
                return response()->json(['status' => false, 'message' => 'Prompt is required.'], 400);
            }

            $apiKey = config('services.openai.key') ?: env('OPENAI_API_KEY');
            $aiData = null;

            if ($apiKey) {
                $sysInstruction = "You are a professional blog writer. Generate a complete blog article matching the user's prompt. You MUST return ONLY a JSON object matching this structure:
{
  \"title\": \"Post Title\",
  \"excerpt\": \"A short teaser excerpt\",
  \"content\": \"<p>HTML formatted article contents...</p>\",
  \"meta_title\": \"SEO Meta Title\",
  \"meta_description\": \"SEO Meta Description\",
  \"meta_keywords\": \"key1, key2\",
  \"og_title\": \"Social Share Title\",
  \"og_description\": \"Social Share Description\"
}";

                $url = "https://api.openai.com/v1/chat/completions";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'model' => 'gpt-4o-mini',
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $sysInstruction],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.7
                ]));
                curl_setopt($ch, CURLOPT_TIMEOUT, 45);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $res = curl_exec($ch);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($res) {
                    $jsonRes = json_decode($res, true);
                    if (isset($jsonRes['error'])) {
                        return response()->json([
                            'status' => false,
                            'message' => 'OpenAI API Error: ' . $jsonRes['error']['message']
                        ], 400);
                    }
                    $text = $jsonRes['choices'][0]['message']['content'] ?? '';
                    $decoded = json_decode(trim($text), true);
                    if (is_array($decoded) && isset($decoded['title'])) {
                        $aiData = $decoded;
                    } else {
                        return response()->json([
                            'status' => false,
                            'message' => 'Failed to parse JSON response from OpenAI. Raw text: ' . substr($text, 0, 500)
                        ], 400);
                    }
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'No response from OpenAI API. Curl error: ' . $curlErr
                    ], 500);
                }
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'OpenAI API Key is missing. Please define OPENAI_API_KEY in your .env file.'
                ], 400);
            }

            return response()->json([
                'status' => true,
                'data' => $aiData
            ]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()], 500);
        }
    }

    // ─── Public API: List published blogs ─────────────────────────────────────
    public function publicIndex(Request $request)
    {
        $perPage = max(1, (int) $request->input('limit', 9));
        $page    = max(1, (int) $request->input('page', 1));

        $query = DB::table('blogs')
            ->leftJoin('admins', 'blogs.author_id', '=', 'admins.id')
            ->where('blogs.status', 'published')
            ->select(
                'blogs.id', 'blogs.title', 'blogs.slug',
                'blogs.category', 'blogs.excerpt', 'blogs.views', 'blogs.published_at',
                'blogs.created_at', 'blogs.featured_image',
                'admins.name as author_name'
            );

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

    // ─── Public API: Show single blog and increment views ────────────────────
    public function publicShow($slug)
    {
        $blog = DB::table('blogs')
            ->leftJoin('admins', 'blogs.author_id', '=', 'admins.id')
            ->select('blogs.*', 'admins.name as author_name')
            ->where('blogs.slug', $slug)
            ->first();

        // Fallback to query by ID if slug not found and is numeric
        if (!$blog && is_numeric($slug)) {
            $blog = DB::table('blogs')
                ->leftJoin('admins', 'blogs.author_id', '=', 'admins.id')
                ->select('blogs.*', 'admins.name as author_name')
                ->where('blogs.id', $slug)
                ->first();
        }

        if (!$blog) {
            return response()->json(['status' => false, 'message' => 'Blog post not found.'], 404);
        }

        // Increment views
        DB::table('blogs')->where('id', $blog->id)->increment('views');

        return response()->json(['status' => true, 'blog' => $blog]);
    }
}
