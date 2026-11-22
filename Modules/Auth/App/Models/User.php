<?php

namespace Modules\Auth\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Contract\App\Models\Contract;
use Spatie\Permission\Traits\HasRoles;
use Modules\Auth\Notifications\ResetPasswordNotification;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'mobile',
        'email',
        'password',
        'national_code',
        'personnel_code',
        'otp_code',
        'otp_expires_at',
        'settings',
        'avatar',
        'is_active',
        'employee_type',
        'party_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp_code',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }


    /**
     * آیا کاربر اجازه ورود به سیستم را دارد؟
     */
    public function isContractor(): bool
    {
        return $this->employee_type === 'contractor' || $this->hasRole('contractor');
    }

    public function isLinkedContractor(): bool
    {
        return $this->isContractor() && !is_null($this->party_id);
    }

    public function canLogin(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // پیمانکاران باید به یک Party متصل باشند
        if ($this->isContractor()) {
            return !is_null($this->party_id);
        }

        return true;
    }






}
