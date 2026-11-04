import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Format TND 3 décimales
window.tnd = (v) => (Number(v) || 0).toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' TND';

// Thème clair / sombre
window.toggleTheme = () => {
    const d = document.documentElement.classList.toggle('dark');
    try { localStorage.setItem('theme', d ? 'dark' : 'light'); } catch (e) {}
};

// Éditeur de lignes de facture
Alpine.data('facture', (init) => ({
    lignes: init.lignes.length ? init.lignes : [{ designation: '', quantite: 1, prix_unitaire: 0 }],
    tva: init.tva, timbre: init.timbre, devis: init.devis, services: init.services,
    add(d = '', pu = 0) { this.lignes.push({ designation: d, quantite: 1, prix_unitaire: pu }); },
    remove(i) { this.lignes.splice(i, 1); if (!this.lignes.length) this.add(); },
    pick(i, ev) {
        const s = this.services.find(x => x.id == ev.target.value); if (!s) return;
        this.lignes[i].designation = s.nom; if (!(+this.lignes[i].prix_unitaire > 0)) this.lignes[i].prix_unitaire = +s.prix; ev.target.value = '';
    },
    get ht() { return this.lignes.reduce((s, l) => s + (+l.quantite || 0) * (+l.prix_unitaire || 0), 0); },
    get mtva() { return this.ht * (+this.tva || 0) / 100; },
    get ttc() { return this.ht + this.mtva + (this.devis ? 0 : (+this.timbre || 0)); },
}));

Alpine.start();
