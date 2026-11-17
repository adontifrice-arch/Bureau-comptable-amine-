{{-- Styles des déclarations officielles en arabe (bandes de titre, colonnes de montants, cases à cocher). --}}
<style>
    .brd .cases { display: inline-flex; direction: ltr; unicode-bidi: isolate; vertical-align: middle; }
    .brd h2.bande { background: #e5e7eb; text-align: center; font-weight: 700; padding: .35rem; margin: 1.2rem 0 .5rem; }
    .brd table.g td.lib { text-align: right; }
    .brd table.g td.mt { text-align: left; direction: ltr; font-family: 'Courier New', monospace; white-space: nowrap; min-width: 7rem; }
    .brd table.g td.tx { white-space: nowrap; font-size: 11px; }
    .brd table.g th { background: #f3f4f6; }
    .brd table.g tfoot td { font-weight: 700; }
    .brd table.g td { height: 1.7rem; }      /* lignes vides du modèle à remplir à la main */
    .brd td.coche { height: 2.4rem; }
    .brd td.coche .bx { font-size: 1.1rem; font-weight: 700; }
    @media print { @page { size: A4; margin: 10mm; } .brd table.g td, .brd table.g th { padding: .2rem .35rem; } }
</style>
