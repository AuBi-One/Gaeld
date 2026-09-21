<?php

namespace Plugins\ExpenseClaims\Models;

use App\Domains\Payroll\Models\Employee;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $employee_id
 * @property int|null $contact_id
 * @property bool $is_owner
 * @property string|null $home_place_id
 */
class Person extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'ec_people';

    protected $fillable = ['organization_id', 'name', 'employee_id', 'contact_id', 'is_owner', 'home_place_id'];

    protected function casts(): array
    {
        return ['is_owner' => 'boolean'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Place, $this> */
    public function homePlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'home_place_id');
    }
}
