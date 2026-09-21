<?php

namespace Plugins\ExpenseClaims\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $from_place_id
 * @property string $to_place_id
 * @property string $km
 * @property string $provider
 */
class Distance extends Model
{
    use HasUuids;

    protected $table = 'ec_distances';

    protected $fillable = ['from_place_id', 'to_place_id', 'km', 'provider'];
}
