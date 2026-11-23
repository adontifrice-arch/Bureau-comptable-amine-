{{--
    Recherche d'un compte (loupe ou F1) pour les écrans de facture par modèle.
    Le composant hôte est créé par window.avecRecherche({...}) et définit compteChoisi(cible, numero).
--}}
@once
<script>
    window.rechercheCompte = () => ({
        rech: { ouvert: false, q: '', cible: null },
        get resultats() {
            const q = this.rech.q.trim().toLowerCase(); const plan = window.PLAN || {};
            return Object.keys(plan).filter(n => ! q || n.startsWith(q) || plan[n].toLowerCase().includes(q)).slice(0, 80).map(n => [n, plan[n]]);
        },
        /** cible : identifiant libre rendu à compteChoisi ; valeur : numéro déjà saisi, point de départ de la recherche. */
        chercher(cible, valeur = '') { this.rech = { ouvert: true, q: valeur || '', cible }; this.$nextTick(() => this.$refs.rech?.focus()); },
        choisir(n) { const c = this.rech.cible; this.rech.ouvert = false; this.compteChoisi(c, n); },
    });
    /** Ajoute la recherche à un composant en gardant ses accesseurs (resultats) dynamiques. */
    window.avecRecherche = (o) => Object.defineProperties(o, Object.getOwnPropertyDescriptors(window.rechercheCompte()));
    /** Montant affiché à la tunisienne : 1 234,500 ; lu en acceptant la virgule. */
    window.lireMontant = (v) => { const n = parseFloat(String(v ?? '').replace(/[\s  ]/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };
    window.ecrireMontant = (v) => (Math.round((+v || 0) * 1000) / 1000).toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
</script>
@endonce
<div x-show="rech.ouvert" x-cloak class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/40 p-4 pt-24" @click.self="rech.ouvert = false" @keydown.escape.window="rech.ouvert = false">
    <div class="w-full max-w-xl rounded-xl bg-white shadow-xl dark:bg-slate-900">
        <div class="border-b border-slate-200 p-3 dark:border-slate-700"><input x-ref="rech" x-model="rech.q" class="input" placeholder="Numéro ou libellé du compte…" @keydown.enter.prevent="resultats.length && choisir(resultats[0][0])"></div>
        <ul class="max-h-96 overflow-y-auto py-1 text-sm">
            <template x-for="[n, lib] in resultats" :key="n"><li><button type="button" class="flex w-full gap-3 px-4 py-1.5 text-left hover:bg-sky-50 dark:hover:bg-slate-800" @click="choisir(n)"><span class="w-24 font-mono" x-text="n"></span><span x-text="lib"></span></button></li></template>
            <li x-show="! resultats.length" class="px-4 py-3 text-slate-400">Aucun compte.</li>
        </ul>
    </div>
</div>
