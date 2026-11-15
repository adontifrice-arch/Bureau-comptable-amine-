/*
 * Champs de compte (input[data-compte]) : un numéro absent du plan comptable propose de créer le sous-compte
 * sur place (libellé du compte parent proposé), sans passer par l'onglet Plan comptable.
 * Le plan est fourni par la page (window.PLAN = { numero: libellé }) ; l'URL de création par data-url sur <body> ou window.URL_COMPTES.
 */

const plan = () => window.PLAN || {};

/** Compte parent : le plus long préfixe existant (411000 → 411). */
const parent = (n) => {
    for (let i = n.length - 1; i >= 1; i--) if (plan()[n.slice(0, i)]) return n.slice(0, i);
    return null;
};

/**
 * Compte du plan correspondant au numéro : le numéro lui-même, ou ce numéro complété par des zéros
 * (dossier à comptes de 6 chiffres ou plus : 401000 = 401, 436600 = 4366). null si aucun.
 */
const existant = (n) => {
    if (plan()[n]) return n;
    const court = String(n).replace(/0+$/, '');
    return court.length >= 2 && plan()[court] ? court : null;
};

/** Libellé d'un numéro de compte (utilisé aussi par les formulaires : window.libelleCompte). */
window.libelleCompte = (n) => { const c = existant(String(n || '').trim()); return c ? plan()[c] : ''; };

/** Libellé affiché sous le champ (élément [data-compte-libelle] du même bloc). */
const afficherLibelle = (input) => {
    const cible = input.parentElement?.querySelector('[data-compte-libelle]');
    if (!cible) return;
    const v = input.value.trim();
    const p = plan();
    cible.textContent = !v ? '' : existant(v) ? p[existant(v)] : (/^\d+$/.test(v) && parent(v) ? 'Nouveau compte — sous-compte de ' + parent(v) + ' ' + p[parent(v)] : '');
};

let panneau = null;
const fermer = () => { panneau?.remove(); panneau = null; };

const placer = () => {
    if (!panneau) return;
    const r = panneau._input.getBoundingClientRect();
    if (r.width === 0) return fermer();                       // champ masqué (fenêtre fermée)
    panneau.style.top = Math.min(r.bottom + 4, window.innerHeight - panneau.offsetHeight - 8) + 'px';
    panneau.style.left = Math.max(8, Math.min(r.left, window.innerWidth - panneau.offsetWidth - 8)) + 'px';
};

const echapper = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const proposer = (input) => {
    fermer();
    const n = input.value.trim();
    if (!/^\d{1,10}$/.test(n) || existant(n)) return;          // compte existant, éventuellement complété par des zéros
    const par = parent(n);
    panneau = document.createElement('div');
    panneau._input = input;
    panneau.className = 'card fixed z-[60] w-80 p-3 text-sm shadow-xl';
    panneau.setAttribute('role', 'dialog');
    panneau.setAttribute('aria-label', 'Créer le compte ' + n);
    panneau.innerHTML = `
        <div class="mb-2 font-medium">Le compte <span class="font-mono">${echapper(n)}</span> n’existe pas.</div>
        <div class="mb-2 text-xs text-slate-500">${par ? 'Sous-compte de <span class="font-mono">' + echapper(par) + '</span> ' + echapper(plan()[par]) : 'Aucun compte parent dans le plan.'}</div>
        <label class="label" for="nouveau-compte-libelle">Libellé</label>
        <input id="nouveau-compte-libelle" class="input" value="${echapper(par ? plan()[par] : '')}" maxlength="255">
        <div class="mt-1 hidden text-xs text-rose-600" data-erreur></div>
        <div class="mt-3 flex justify-end gap-2">
            <button type="button" class="btn-secondary btn-sm" data-annuler>Annuler</button>
            <button type="button" class="btn-primary btn-sm" data-creer>Créer le compte</button>
        </div>`;
    document.body.appendChild(panneau);
    placer();
    const libelle = panneau.querySelector('input');
    libelle.focus();
    libelle.select();

    const creer = async () => {
        const erreur = panneau.querySelector('[data-erreur]');
        const bouton = panneau.querySelector('[data-creer]');
        if (!libelle.value.trim()) { erreur.textContent = 'Indiquez le libellé du compte.'; erreur.classList.remove('hidden'); return; }
        bouton.disabled = true;
        try {
            const rep = await fetch(window.URL_COMPTES, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ numero: n, libelle: libelle.value.trim() }),
            });
            const j = await rep.json().catch(() => ({}));
            if (!rep.ok) throw new Error(j.errors ? Object.values(j.errors).flat().join(' ') : (j.message || 'Création impossible.'));
            plan()[j.numero] = j.libelle;
            // Le nouveau compte est proposé dans toutes les listes de la page
            document.querySelectorAll('datalist[data-plan]').forEach((d) => {
                const o = document.createElement('option');
                o.value = j.numero; o.textContent = j.libelle;
                d.appendChild(o);
            });
            fermer();
            afficherLibelle(input);
            input.focus();
        } catch (e) {
            erreur.textContent = e.message; erreur.classList.remove('hidden');
            bouton.disabled = false;
        }
    };
    panneau.querySelector('[data-creer]').addEventListener('click', creer);
    panneau.querySelector('[data-annuler]').addEventListener('click', () => { fermer(); input.focus(); });
    libelle.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); creer(); }
        if (e.key === 'Escape') { e.preventDefault(); fermer(); input.focus(); }
    });
};

const estCompte = (el) => el instanceof HTMLInputElement && el.hasAttribute('data-compte');

document.addEventListener('change', (e) => { if (estCompte(e.target)) { afficherLibelle(e.target); proposer(e.target); } });
document.addEventListener('input', (e) => { if (estCompte(e.target)) { fermer(); afficherLibelle(e.target); } });
document.addEventListener('focusin', (e) => { if (estCompte(e.target)) afficherLibelle(e.target); });
document.addEventListener('mousedown', (e) => { if (panneau && !panneau.contains(e.target) && e.target !== panneau._input) fermer(); });
// Le formulaire ne part pas pendant qu'un compte attend sa création
document.addEventListener('submit', (e) => { if (panneau && e.target.contains(panneau._input)) { e.preventDefault(); panneau.querySelector('input')?.focus(); } }, true);
window.addEventListener('resize', placer);
document.addEventListener('scroll', placer, true);
