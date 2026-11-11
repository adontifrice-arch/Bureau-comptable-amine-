<?php

namespace App\Http\Requests;

use App\Models\Associe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Fiche client (spécification §3.2) : identification, mission, capital, gérant et associés,
 * avec les contrôles de cohérence du capital et de la numérotation des parts sociales.
 */
class ClientRequest extends FormRequest
{
    /**
     * Matricule fiscal : 7 chiffres + 1 lettre de contrôle, puis éventuellement code TVA, catégorie et n° d'établissement
     * (1234567A, 1234567/A/M/000, 1234567/A/A/M/000, 1234567AAM000).
     */
    public const MF_REGEX = '/^\d{7}\/?[A-Z](\/?[A-Z]){0,2}(\/?\d{3})?$/';

    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $mf = $this->input('matricule_fiscal');
        if (is_string($mf)) $this->merge(['matricule_fiscal' => mb_strtoupper(preg_replace('/\s+/u', '', $mf)) ?: null]);

        $associes = [];
        foreach ((array) $this->input('associes', []) as $a) {
            if (! is_array($a) || trim((string) ($a['nom'] ?? '')) === '') continue;   // lignes vides ignorées
            $a = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, array_intersect_key($a, array_flip(Associe::CHAMPS)));
            $a['nom'] = trim($a['nom']);
            $a['apport'] = $a['apport'] ?? 'numeraire';
            $associes[] = $a;
        }
        $this->merge(['associes' => $associes]);
    }

    public function rules(): array
    {
        $date = 'nullable|date';
        return [
            'nom' => 'required|max:255', 'forme' => 'nullable|max:100', 'type_personne' => 'nullable|in:morale,physique',
            'denomination_commerciale' => 'nullable|max:255', 'residence' => 'nullable|in:residente,non_residente',
            'regime_export' => 'nullable|in:non,totale,partielle', 'mission' => 'nullable|max:255', 'honoraires_mensuels' => 'nullable|numeric|min:0',
            'matricule_fiscal' => ['nullable', 'max:20', 'regex:'.self::MF_REGEX], 'compte_comptable' => ['nullable', 'regex:/^411\d{0,5}$/'],
            'matricule_cnss' => 'nullable|max:50', 'matricule_independant' => 'nullable|max:50',
            'capital' => 'nullable|numeric|min:0', 'nb_parts' => 'nullable|integer|min:1', 'valeur_nominale' => 'nullable|numeric|gt:0',
            'adresse' => 'nullable|max:255', 'email' => 'nullable|email|max:255', 'telephone' => 'nullable|max:50',

            'gerant_nom' => 'nullable|max:255', 'gerant_cin' => 'nullable|max:50', 'gerant_cin_date' => $date, 'gerant_cin_lieu' => 'nullable|max:100',
            'gerant_carte_sejour' => 'nullable|max:50', 'gerant_passeport' => 'nullable|max:50', 'gerant_passeport_date' => $date,
            'gerant_passeport_validite' => 'nullable|date|after_or_equal:gerant_passeport_date', 'gerant_nationalite' => 'nullable|max:100',
            'gerant_adresse' => 'nullable|max:255', 'gerant_naissance_date' => 'nullable|date|before:today', 'gerant_naissance_lieu' => 'nullable|max:100',
            'gerant_nomination_date' => $date, 'gerant_nomination_duree' => 'nullable|max:100', 'gerant_associe' => 'nullable|in:0,1',
            'gerant_parts' => 'nullable|integer|min:1', 'gerant_parts_de' => 'nullable|integer|min:1', 'gerant_parts_a' => 'nullable|integer|min:1',
            'gerant_apport' => 'nullable|in:numeraire,nature',

            'associes' => 'array',
            'associes.*.nom' => 'required|max:255', 'associes.*.cin' => 'nullable|max:50', 'associes.*.cin_date' => $date,
            'associes.*.cin_lieu' => 'nullable|max:100', 'associes.*.carte_sejour' => 'nullable|max:50', 'associes.*.passeport' => 'nullable|max:50',
            'associes.*.passeport_date' => $date, 'associes.*.passeport_validite' => $date, 'associes.*.nationalite' => 'nullable|max:100',
            'associes.*.adresse' => 'nullable|max:255', 'associes.*.naissance_date' => $date, 'associes.*.naissance_lieu' => 'nullable|max:100',
            'associes.*.parts' => 'nullable|integer|min:1', 'associes.*.parts_de' => 'nullable|integer|min:1', 'associes.*.parts_a' => 'nullable|integer|min:1',
            'associes.*.apport' => 'in:numeraire,nature',
        ];
    }

    public function messages(): array
    {
        return [
            'matricule_fiscal.regex' => 'Le matricule fiscal doit comporter 7 chiffres suivis d’une lettre (ex. 1234567/A/M/000).',
            'compte_comptable.regex' => 'Le compte comptable client doit commencer par 411 (ex. 411001).',
            'gerant_passeport_validite.after_or_equal' => 'La date de validité du passeport doit suivre sa date de délivrance.',
            'associes.*.nom.required' => 'Le nom de chaque associé est obligatoire.',
        ];
    }

    public function attributes(): array
    {
        return ['nb_parts' => 'nombre de parts', 'valeur_nominale' => 'valeur nominale', 'gerant_naissance_date' => 'date de naissance du gérant'];
    }

    /** Contrôles croisés : capital = parts × valeur nominale, numérotation des parts continue et sans chevauchement. */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) return;
            $d = $v->getData();
            $np = (int) ($d['nb_parts'] ?? 0);
            $vn = (float) ($d['valeur_nominale'] ?? 0);
            $cap = (float) ($d['capital'] ?? 0);

            if ($np > 0 && $vn > 0 && $cap > 0 && abs($cap - $np * $vn) > 0.0005) {
                $v->errors()->add('capital', 'Le capital ('.tnd($cap).') doit être égal au nombre de parts × valeur nominale ('.tnd($np * $vn).').');
            }

            // Détenteurs de parts : le gérant s'il est associé, puis chaque associé.
            $holders = [];
            if (($d['gerant_associe'] ?? null) === '1' || ($d['gerant_associe'] ?? null) === 1) {
                $holders[] = ['key' => 'gerant_', 'nom' => $d['gerant_nom'] ?? 'le gérant', 'parts' => $d['gerant_parts'] ?? null, 'de' => $d['gerant_parts_de'] ?? null, 'a' => $d['gerant_parts_a'] ?? null];
            }
            foreach ($d['associes'] ?? [] as $i => $a) {
                $holders[] = ['key' => "associes.$i.", 'nom' => $a['nom'], 'parts' => $a['parts'] ?? null, 'de' => $a['parts_de'] ?? null, 'a' => $a['parts_a'] ?? null];
            }

            $plages = [];
            $total = 0;
            foreach ($holders as $h) {
                [$k, $de, $a] = [$h['key'], $h['de'], $h['a']];
                if (($de === null) !== ($a === null)) {
                    $v->errors()->add($k.'parts_a', "Numéros de parts de {$h['nom']} : indiquez le premier et le dernier numéro.");
                    continue;
                }
                $n = $h['parts'] !== null ? (int) $h['parts'] : null;
                if ($de !== null) {
                    if ((int) $de > (int) $a) {
                        $v->errors()->add($k.'parts_a', "Numéros de parts de {$h['nom']} : le dernier numéro ($a) est inférieur au premier ($de).");
                        continue;
                    }
                    $nr = (int) $a - (int) $de + 1;
                    if ($n !== null && $n !== $nr) {
                        $v->errors()->add($k.'parts', "{$h['nom']} détient $n parts mais la plage n° $de à $a en compte $nr.");
                    }
                    if ($np > 0 && (int) $a > $np) {
                        $v->errors()->add($k.'parts_a', "Numéros de parts de {$h['nom']} : le n° $a dépasse le nombre total de parts ($np).");
                    }
                    $plages[] = [(int) $de, (int) $a, $h['nom']];
                    $n ??= $nr;
                }
                $total += (int) $n;
            }

            usort($plages, fn ($x, $y) => $x[0] <=> $y[0]);
            for ($i = 1; $i < count($plages); $i++) {
                [$de, $a, $nom] = $plages[$i];
                [$dePrec, $aPrec, $nomPrec] = $plages[$i - 1];
                if ($de <= $aPrec && mb_strtolower($nom) !== mb_strtolower($nomPrec)) {
                    $v->errors()->add('associes', "Les parts n° $dePrec à $aPrec ($nomPrec) et n° $de à $a ($nom) se chevauchent.");
                }
            }

            if ($np > 0 && $total > $np) {
                $v->errors()->add('nb_parts', "Les associés détiennent $total parts au total, plus que les $np parts du capital.");
            }
        }];
    }

    /** Données de la fiche client (sans les associés), avec les valeurs déduites. */
    public function client(): array
    {
        $d = collect($this->validated())->except('associes')->all();
        $d['honoraires_mensuels'] = $d['honoraires_mensuels'] ?? 0;
        if (empty($d['capital']) && ! empty($d['nb_parts']) && ! empty($d['valeur_nominale'])) {
            $d['capital'] = r3($d['nb_parts'] * $d['valeur_nominale']);
        }
        $d['capital'] = $d['capital'] ?? 0;
        $d['gerant_associe'] = isset($d['gerant_associe']) ? (bool) $d['gerant_associe'] : null;
        if (! $d['gerant_associe']) {
            $d = array_merge($d, ['gerant_parts' => null, 'gerant_parts_de' => null, 'gerant_parts_a' => null, 'gerant_apport' => null]);
        }
        return $d;
    }

    /** Associés validés, avec le nombre de parts déduit de la plage de numéros si absent. */
    public function associes(): array
    {
        return array_map(function ($a) {
            if (empty($a['parts']) && ! empty($a['parts_de']) && ! empty($a['parts_a'])) $a['parts'] = $a['parts_a'] - $a['parts_de'] + 1;
            $a['parts'] = (int) ($a['parts'] ?? 0);
            return $a;
        }, $this->validated()['associes'] ?? []);
    }
}
