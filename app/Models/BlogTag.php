<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogTag extends Model
{
    use HasFactory;

    protected $table = 'blog_tags';

    protected $fillable = [
        'name',
        'slug',
    ];

    public function blogs()
    {
        return $this->belongsToMany(Blog::class, 'blog_post_tag', 'tag_id', 'blog_id');
    }
}
