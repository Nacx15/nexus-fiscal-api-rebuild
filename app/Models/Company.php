<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Models\CompanyFiscalProfile;
use App\Models\FiscalCertificate;
use App\Models\PacAccount;
use App\Models\FiscalSequence;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Invoice;

class Company extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'legal_name',
        'trade_name',
        'rfc',
        'status',
        'timezone',
        'currency',
    ];

    // protected static function booted(): void
    // {
    //     static::creating(function (Company $company) {
    //         $company->public_id ??= (string) Str::ulid();
    //     });
    // }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot([
                'role',
                'status',
                'joined_at',
            ])
            ->withTimestamps();
    }

    // public function getRouteKeyName(): string
    // {
    //     return 'public_id';
    // }

    public function fiscalProfile()
    {
        return $this->hasOne(CompanyFiscalProfile::class);
    }

    public function fiscalCertificates()
    {
        return $this->hasMany(FiscalCertificate::class);
    }

    public function pacAccounts()
    {
        return $this->hasMany(PacAccount::class);
    }

    public function fiscalSequences()
    {
        return $this->hasMany(FiscalSequence::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}