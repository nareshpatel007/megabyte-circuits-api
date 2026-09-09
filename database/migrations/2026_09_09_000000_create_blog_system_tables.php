<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Categories
        if (!Schema::hasTable('blog_categories')) {
            Schema::create('blog_categories', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('image', 500)->nullable();
                $table->string('seo_title', 160)->nullable();
                $table->string('seo_description', 320)->nullable();
                $table->timestamps();
            });
        }

        // 2. Tags
        if (!Schema::hasTable('blog_tags')) {
            Schema::create('blog_tags', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        // 3. Update blogs table with extra fields
        if (Schema::hasTable('blogs')) {
            Schema::table('blogs', function (Blueprint $table) {
                if (!Schema::hasColumn('blogs', 'reading_time')) {
                    $table->string('reading_time', 50)->nullable()->after('views');
                }
                if (!Schema::hasColumn('blogs', 'is_featured')) {
                    $table->boolean('is_featured')->default(false)->after('reading_time');
                }
                if (!Schema::hasColumn('blogs', 'allow_comments')) {
                    $table->boolean('allow_comments')->default(true)->after('is_featured');
                }
                if (!Schema::hasColumn('blogs', 'category_id')) {
                    $table->unsignedBigInteger('category_id')->nullable()->after('category');
                }
                if (!Schema::hasColumn('blogs', 'twitter_title')) {
                    $table->string('twitter_title', 160)->nullable()->after('og_image');
                }
                if (!Schema::hasColumn('blogs', 'twitter_description')) {
                    $table->string('twitter_description', 320)->nullable()->after('twitter_title');
                }
                if (!Schema::hasColumn('blogs', 'twitter_image')) {
                    $table->string('twitter_image', 500)->nullable()->after('twitter_description');
                }
                if (!Schema::hasColumn('blogs', 'robots_index')) {
                    $table->boolean('robots_index')->default(true)->after('twitter_image');
                }
                if (!Schema::hasColumn('blogs', 'robots_follow')) {
                    $table->boolean('robots_follow')->default(true)->after('robots_index');
                }
                if (!Schema::hasColumn('blogs', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        // 4. Pivot blog_post_tag
        if (!Schema::hasTable('blog_post_tag')) {
            Schema::create('blog_post_tag', function (Blueprint $table) {
                $table->unsignedBigInteger('blog_id');
                $table->unsignedBigInteger('tag_id');
                $table->primary(['blog_id', 'tag_id']);
                $table->foreign('blog_id')->references('id')->on('blogs')->onDelete('cascade');
                $table->foreign('tag_id')->references('id')->on('blog_tags')->onDelete('cascade');
            });
        }

        // 5. Comments
        if (!Schema::hasTable('blog_comments')) {
            Schema::create('blog_comments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('blog_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name');
                $table->string('email');
                $table->text('content');
                $table->enum('status', ['pending', 'approved', 'rejected', 'spam'])->default('pending');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->timestamps();

                $table->foreign('blog_id')->references('id')->on('blogs')->onDelete('cascade');
                $table->index(['blog_id', 'status']);
            });
        }

        // 6. Likes
        if (!Schema::hasTable('blog_likes')) {
            Schema::create('blog_likes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('blog_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();

                $table->foreign('blog_id')->references('id')->on('blogs')->onDelete('cascade');
                $table->index(['blog_id', 'user_id']);
                $table->index(['blog_id', 'ip_address']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_likes');
        Schema::dropIfExists('blog_comments');
        Schema::dropIfExists('blog_post_tag');
        Schema::dropIfExists('blog_tags');
        Schema::dropIfExists('blog_categories');
    }
};
