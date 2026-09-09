<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogCategory extends Model
{
    use HasFactory;

    protected $table = 'blog_categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'image',
        'seo_title',
        'seo_description',
    ];

    public function blogs()
    {
        return $this->hasMany(Blog::class, 'category_id');
    }
}
