<?php

namespace App\Models;

use Database\Factories\LuantiServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'notes', 'domain', 'game_port', 'api_base_url', 'api_token'])]
#[Hidden(['api_token'])]
class LuantiServer extends Model
{
    /** @use HasFactory<LuantiServerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['game_port' => 'integer', 'api_token' => 'encrypted'];
    }
}
