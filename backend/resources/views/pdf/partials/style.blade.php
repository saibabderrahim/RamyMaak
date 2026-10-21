{{-- Feuille de style commune des documents ADV (dompdf : CSS 2.1, polices DejaVu, UTF-8). --}}
<style>
    @page { margin: 14mm 12mm 20mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2933; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }
    h1 { font-size: 15pt; margin: 0; }
    h2 { font-size: 10.5pt; margin: 5mm 0 2mm; padding-bottom: 1mm; border-bottom: 1pt solid #9aa5b1; }
    .head td { vertical-align: bottom; padding: 0 0 3mm; }
    .brand { font-size: 17pt; font-weight: bold; color: #b42318; letter-spacing: 0.5pt; }
    .right { text-align: right; }
    .center { text-align: center; }
    .muted { color: #52606d; }
    .small { font-size: 7.5pt; }
    .box { background: #f4f5f7; padding: 2.5mm 3.5mm; }
    .kv td { padding: 0.6mm 0; vertical-align: top; }
    .kv td.k { width: 30%; color: #52606d; }
    .grid { margin-top: 2mm; }
    .grid th, .grid td { border: 0.5pt solid #9aa5b1; padding: 1.4mm 1.6mm; vertical-align: top; }
    .grid th { background: #e4e7eb; font-weight: bold; text-align: left; font-size: 8pt; }
    .grid td.num, .grid th.num { text-align: right; }
    .grid td.num { white-space: nowrap; }
    .grid tr.sub td { background: #f4f5f7; font-weight: bold; }
    .grid tr.total td { background: #e4e7eb; font-weight: bold; }
    .banner { border: 2.5pt solid #b42318; color: #b42318; text-align: center; font-size: 20pt; font-weight: bold; letter-spacing: 3pt; padding: 2mm 0; margin: 0 0 4mm; }
    .footer { position: fixed; left: 0; right: 0; bottom: -13mm; border-top: 0.5pt solid #cbd2d9; padding-top: 1.5mm; font-size: 7.5pt; color: #52606d; }
    .empty { margin-top: 12mm; font-size: 14pt; font-weight: bold; text-align: center; color: #52606d; }
</style>
