<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['first_name', 'last_name', 'phone_e164', 'locale'])]
class Guest extends Model
{
    use Replicated;
}
