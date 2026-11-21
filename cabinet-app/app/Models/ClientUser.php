<?php

namespace App\Models;

use App\Support\CabinetCourant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * Compte d'accès d'un client à l'espace client (garde « client »), distinct des utilisateurs du cabinet.
 *
 * Volontairement sans filtre automatique par cabinet : la connexion charge le compte avant de connaître
 * le cabinet (comme User). Côté cabinet, passer par $client->accesEspace() ou la portée duCabinet().
 */
class ClientUser extends Authenticatable
{
    /** Durée de validité d'un lien d'invitation ou de réinitialisation. */
    public const VALIDITE_JETON_JOURS = 7;

    protected $table = 'client_users';
    protected $fillable = ['cabinet_id', 'client_id', 'name', 'email', 'password', 'actif', 'invite_par'];
    protected $hidden = ['password', 'remember_token', 'jeton'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'actif' => 'boolean', 'jeton_expire_at' => 'datetime', 'derniere_connexion_at' => 'datetime',
            'cabinet_id' => 'integer', 'client_id' => 'integer'];
    }

    /** Le client du compte (filtré par le cabinet courant : dans l'espace client, c'est le cabinet du compte). */
    public function client() { return $this->belongsTo(Client::class); }
    public function cabinet() { return $this->belongsTo(Cabinet::class); }

    /** Adresses enregistrées en minuscules : la connexion ne dépend pas de la casse saisie. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn ($v) => mb_strtolower(trim((string) $v)));
    }

    public static function normaliserEmail(?string $v): string
    {
        return mb_strtolower(trim((string) $v));
    }

    public function scopeDuCabinet(Builder $q): Builder
    {
        return $q->where('cabinet_id', CabinetCourant::exiger());
    }

    public function estActive(): bool { return $this->password !== null; }

    public function statut(): string
    {
        if (! $this->actif) return 'Désactivé';
        if (! $this->estActive()) return $this->jetonValide() ? 'Invitation envoyée' : 'Invitation expirée';
        return 'Actif';
    }

    /** Nouveau lien d'invitation / de réinitialisation : seule l'empreinte est conservée, le jeton en clair part dans l'e-mail. */
    public function nouveauJeton(): string
    {
        $jeton = Str::random(48);
        $this->forceFill(['jeton' => hash('sha256', $jeton), 'jeton_expire_at' => now()->addDays(self::VALIDITE_JETON_JOURS)])->save();
        return $jeton;
    }

    public function jetonValide(): bool
    {
        return $this->jeton !== null && $this->jeton_expire_at?->isFuture();
    }

    public static function parJeton(string $jeton): ?self
    {
        if (strlen($jeton) < 20) return null;
        $u = self::where('jeton', hash('sha256', $jeton))->first();
        return $u && $u->actif && $u->jetonValide() ? $u : null;
    }

    public function initiales(): string
    {
        $w = preg_split('/\s+/u', trim($this->name));
        return mb_strtoupper(mb_substr($w[0] ?? '', 0, 1).mb_substr($w[1] ?? '', 0, 1));
    }
}
