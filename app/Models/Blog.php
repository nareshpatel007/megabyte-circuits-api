<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Blog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'blogs';

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image',
        'status',
        'category',
        'category_id',
        'tags',
        'author_id',
        'author_name',
        'views',
        'reading_time',
        'is_featured',
        'allow_comments',
        'published_at',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'twitter_title',
        'twitter_description',
        'twitter_image',
        'robots_index',
        'robots_follow',
        'schema_markup',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'is_featured'  => 'boolean',
        'allow_comments' => 'boolean',
        'robots_index' => 'boolean',
        'robots_follow' => 'boolean',
        'views'        => 'integer',
    ];

    public function categoryRef()
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    public function tagsRef()
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag', 'blog_id', 'tag_id');
    }

    public function comments()
    {
        return $this->hasMany(BlogComment::class, 'blog_id');
    }

    public function likes()
    {
        return $this->hasMany(BlogLike::class, 'blog_id');
    }
}
