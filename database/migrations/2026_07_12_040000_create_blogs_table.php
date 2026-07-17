<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema: blogs
     * ─────────────────────────────────────────────────────────────────────────
     * id                bigint  PK, auto-increment
     * title             varchar(255)   Post headline
     * slug              varchar(255)   URL-friendly, UNIQUE
     * excerpt           text nullable  Short teaser shown in listings
     * content           longtext       Full HTML content from rich editor
     * featured_image    varchar(500)   Relative path to uploaded image
     * status            enum           'draft' | 'published'
     * category          varchar(100)   Optional free-text category
     * tags              text nullable  CSV list of tags
     * author_id         bigint         FK → admins.id
     * views             int            Hit counter, default 0
     * published_at      timestamp nullable
     *
     * SEO fields
     * meta_title        varchar(160) nullable
     * meta_description  varchar(320) nullable
     * meta_keywords     varchar(500) nullable
     * canonical_url     varchar(500) nullable
     * og_title          varchar(160) nullable
     * og_description    varchar(320) nullable
     * og_image          varchar(500) nullable
     * schema_markup     text nullable  JSON-LD schema block
     *
     * timestamps  created_at / updated_at
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function up(): void
    {
        Schema::create('blogs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Core
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('featured_image', 500)->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->string('category', 100)->nullable();
            $table->text('tags')->nullable();               // stored as comma-separated

            // Author
            $table->unsignedBigInteger('author_id')->nullable();

            // Stats
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('published_at')->nullable();

            // SEO
            $table->string('meta_title', 160)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('meta_keywords', 500)->nullable();
            $table->string('canonical_url', 500)->nullable();
            $table->string('og_title', 160)->nullable();
            $table->string('og_description', 320)->nullable();
            $table->string('og_image', 500)->nullable();
            $table->text('schema_markup')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['status', 'published_at']);
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blogs');
    }
};
