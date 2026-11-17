{{-- Styles communs des formulaires DGI en arabe (bordereaux, liste détaillée, reçu de dépôt). --}}
<style>
    .brd { font-family: 'Arial', 'Tahoma', sans-serif; color: #000; }
    .brd .bx { display: inline-block; width: 1.6rem; height: 1.6rem; line-height: 1.6rem; border: 1px solid #000; text-align: center; font-weight: 600; margin-inline-start: -1px; direction: ltr; }
    .brd .cases { display: inline-flex; direction: ltr; unicode-bidi: isolate; vertical-align: middle; }   /* chiffres, dates et matricule de gauche à droite */
    .brd .cadre { border: 1px solid #000; padding: .9rem 1rem; margin-top: 1rem; }
    .brd table.g { border-collapse: collapse; width: 100%; }
    .brd table.g td, .brd table.g th { border: 1px solid #000; padding: .35rem .5rem; text-align: center; }
    .brd .pt { border-bottom: 1px dotted #000; display: inline-block; min-width: 12rem; padding: 0 .4rem; }
    .brd .saut { break-before: page; page-break-before: always; }
    @media print { .brd { font-size: 12px; } }
</style>
