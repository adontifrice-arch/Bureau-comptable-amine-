<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'status'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function estChef(): bool { return $this->role === 'chef'; }
    public function roleLibelle(): string { return $this->role === 'chef' ? 'Chef comptable' : 'Collaborateur'; }
    public function initiales(): string
    {
        $w = preg_split('/\s+/u', trim($this->name));
        return mb_strtoupper(mb_substr($w[0] ?? '', 0, 1).mb_substr($w[1] ?? '', 0, 1));
    }
}
