/*
 * Formulaire de facture d'achat ou de vente ventilée sur plusieurs comptes : lignes « compte · HT · taux · TVA ».
 * cfg : { modeles: {id: valeurs}, defaut: [{compte, tva_taux}] (paramétrage), taux: taux par défaut, vide: valeurs d'une nouvelle facture }.
 * Les boutons rapides (comptes du paramétrage, puis du modèle appliqué) s'appliquent à la ligne en cours.
 */
export default (cfg) => ({
    e: {},
    lignes: [],
    active: 0,
    modeles: cfg.modeles || {},
    defaut: cfg.defaut || [],
    propositions: cfg.defaut || [],
    deModele: false,

    ligne(compte = '', taux = cfg.taux, ht = '') {
        const l = { compte: compte || '', ht, tva_taux: taux ?? cfg.taux, tva: '' };
        this.calc(l);
        return l;
    },

    /** Ouverture du formulaire : nouvelle facture (valeurs du paramétrage) ou modification (lignes enregistrées). */
    ouvrir(detail = {}) {
        this.propositions = this.defaut;
        this.deModele = false;
        this.e = Object.assign({}, cfg.vide, detail);
        const l = detail.lignes && detail.lignes.length ? detail.lignes : [{ compte: detail.compte ?? cfg.vide.compte, ht: detail.ht ?? '', tva_taux: detail.tva_taux ?? cfg.taux, tva: detail.tva }];
        this.lignes = l.map((x) => ({ compte: x.compte || '', ht: x.ht ?? '', tva_taux: x.tva_taux ?? cfg.taux, tva: x.tva ?? '' }));
        this.lignes.forEach((x) => { if (x.tva === '' || x.tva === null) this.calc(x); });
        this.active = 0;
    },

    calc(l) {
        l.tva = l.ht === '' || l.ht === null ? '' : (Math.round((+l.ht || 0) * (+l.tva_taux || 0) * 10) / 1000).toFixed(3);
    },

    tauxDe(c) {
        const p = this.propositions.find((x) => x.compte === c) || this.defaut.find((x) => x.compte === c);
        return p ? +p.tva_taux : undefined;
    },

    /** Compte saisi ou choisi sur une ligne : son taux proposé s'applique. */
    compteChange(i) {
        this.active = i;
        const l = this.lignes[i];
        const t = this.tauxDe(l.compte);
        if (t !== undefined) { l.tva_taux = t; this.calc(l); }
    },

    /** Bouton rapide : compte et taux appliqués à la ligne en cours. */
    choisirCompte(c) {
        const i = this.lignes[this.active] ? this.active : 0;
        this.lignes[i].compte = c;
        this.compteChange(i);
    },

    ajouter() {
        this.lignes.push(this.ligne('', cfg.taux));
        this.active = this.lignes.length - 1;
    },

    retirer(i) {
        if (this.lignes.length > 1) this.lignes.splice(i, 1);
        this.active = Math.min(this.active, this.lignes.length - 1);
    },

    /** Modèle : tiers et valeurs repris ; une ligne par compte du modèle (le montant HT déjà saisi reste sur la première). */
    appliquer(id) {
        const m = this.modeles[id];
        if (!m) return;
        const { comptes, compte, tva_taux, ...v } = m;
        Object.assign(this.e, v);
        this.deModele = !!(comptes && comptes.length);
        this.propositions = this.deModele ? comptes : this.defaut;
        const source = this.deModele ? comptes : [{ compte, tva_taux }];
        const ht = this.lignes.length === 1 ? this.lignes[0].ht : '';
        this.lignes = source.map((c, i) => this.ligne(c.compte, c.tva_taux ?? tva_taux, i === 0 ? ht : ''));
        this.active = 0;
    },

    get totalHt() { return this.lignes.reduce((s, l) => s + (+l.ht || 0), 0); },
    get totalTva() { return this.lignes.reduce((s, l) => s + (+l.tva || 0), 0); },
    get ttc() { return this.totalHt + this.totalTva + (+this.e.timbre || 0); },
    pourcent(t) { return String(+t).replace('.', ',') + ' %'; },
});
