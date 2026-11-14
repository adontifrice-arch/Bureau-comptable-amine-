<?php

namespace App\Models;

use App\Support\CabinetCourant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cabinet d'expertise comptable : chaque cabinet ne voit que ses propres données. */
class Cabinet extends Model
{
    use HasFactory;

    protected $table = 'cabinets';
    protected $guarded = [];
    protected $hidden = ['code_invitation'];

    public function users() { return $this->hasMany(User::class); }

    /** Lien d'invitation des collaborateurs (régénérable par le chef comptable pour révoquer l'ancien). */
    public static function nouveauCode(): string
    {
        return Str::random(40);
    }

    public function regenererCode(): void
    {
        $this->update(['code_invitation' => self::nouveauCode()]);
    }

    /** Création d'un cabinet avec ses données de départ : plan comptable tunisien et catalogue de services. */
    public static function creer(string $nom): self
    {
        return DB::transaction(function () use ($nom) {
            $c = self::create(['nom' => $nom, 'code_invitation' => self::nouveauCode()]);
            CabinetCourant::pour($c->id, function () use ($c) {
                Compte::installerPlan($c->id);
                foreach (config('cabinet.SVC0') as $s) Service::create(['nom' => $s, 'prix' => 0]);
                Setting::put('cabinet', ['nom' => $c->nom]);
            });
            return $c;
        });
    }
}
