<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Site restriction of a grant (replicated row, soft-deleted when removed).
 */
#[Fillable(['grant_id', 'site_id'])]
class GrantSite extends Model
{
    use Replicated;

    protected $table = 'grant_site';
}
