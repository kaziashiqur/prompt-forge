<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable {
    use HasFactory, Notifiable;

    protected $fillable = ['name','email','password'];
    protected $hidden = ['password','remember_token'];

    protected function casts(): array {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance_nanos' => 'integer',
        ];
    }

    public function generations(): HasMany {
        return $this->hasMany(Generation::class);
    }

    public function isAdmin(): bool {
        return in_array(strtolower($this->email), config('pixelforge.admin_emails'), true);
    }
}
