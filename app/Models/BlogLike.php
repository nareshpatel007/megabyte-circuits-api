<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogLike extends Model
{
    use HasFactory;

    protected $table = 'blog_likes';

    protected $fillable = [
        'blog_id',
        'user_id',
        'ip_address',
    ];

    public function blog()
    {
        return $this->belongsTo(Blog::class, 'blog_id');
    }
}
