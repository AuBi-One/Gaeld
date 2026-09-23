<?php

namespace Plugins\Offers\Models;

use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Offer settings of an organisation: default validity and the sender's e-mail
 * and phone for the From box (the organisation record has neither).
 *
 * @property string $id
 * @property string $organization_id
 * @property int $validity_months
 * @property string|null $sender_email
 * @property string|null $sender_phone
 */
class OfferSetting extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $table = 'of_settings';

    protected $fillable = ['organization_id', 'validity_months', 'sender_email', 'sender_phone'];

    protected function casts(): array
    {
        return ['validity_months' => 'integer'];
    }

    public static function for(string $organizationId): self
    {
        return self::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->first()
            ?? new self(['organization_id' => $organizationId, 'validity_months' => (int) config('offers.default_validity_months', 2)]);
    }
}
