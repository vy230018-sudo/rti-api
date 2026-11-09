<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Newspaper extends Model
{
    protected $fillable = ['title', 'published_for_date', 'thumbnail', 'pdf', 'description', 'is_published', 'is_active', 'is_deleted'];
    protected function casts(): array { return ['published_for_date' => 'date', 'is_published' => 'boolean', 'is_active' => 'boolean', 'is_deleted' => 'boolean']; }
}
