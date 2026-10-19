<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = ['name', 'description', 'timezone'];
}
