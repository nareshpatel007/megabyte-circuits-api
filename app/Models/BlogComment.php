<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogComment extends Model
{
    use HasFactory;

    protected $table = 'blog_comments';

    protected $fillable = [
        'blog_id',
        'user_id',
        'name',
        'email',
        'content',
        'status',
        'parent_id',
    ];

    public function blog()
    {
        return $this->belongsTo(Blog::class, 'blog_id');
    }

    public function replies()
    {
        return $this->hasMany(BlogComment::class, 'parent_id')->where('status', 'approved');
    }

    public function parent()
    {
        return $this->belongsTo(BlogComment::class, 'parent_id');
    }
}
