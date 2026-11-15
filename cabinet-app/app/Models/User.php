<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['cabinet_id', 'name', 'email', 'password', 'role', 'status'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'cabinet_id' => 'integer', 'droit_ecritures' => 'boolean'];
    }

    public function cabinet() { return $this->belongsTo(Cabinet::class); }

    /** Même cabinet que l'utilisateur donné : les utilisateurs ne sont pas filtrés automatiquement (l'authentification les charge avant de connaître le cabinet). */
    public function memeCabinet(?User $u): bool { return $u !== null && $this->cabinet_id !== null && $this->cabinet_id === $u->cabinet_id; }

    public function estChef(): bool { return $this->role === 'chef'; }
    public function roleLibelle(): string { return $this->role === 'chef' ? 'Chef comptable' : 'Collaborateur'; }
    public function initiales(): string
    {
        $w = preg_split('/\s+/u', trim($this->name));
        return mb_strtoupper(mb_substr($w[0] ?? '', 0, 1).mb_substr($w[1] ?? '', 0, 1));
    }
}
