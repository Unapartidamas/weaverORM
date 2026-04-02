<?php

declare(strict_types=1);

namespace Weaver\Benchmark\Fixtures;

use Illuminate\Database\Eloquent\Model;

class EloquentUser extends Model
{
    protected $table = 'bench_users';
    public $timestamps = false;
    protected $fillable = ['name', 'email', 'age', 'created_at'];
}
