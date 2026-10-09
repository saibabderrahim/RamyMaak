{{-- Fiches d'accès des comptes clients (EF-ADM-02) : une page par compte créé. --}}
@php($t = fn (string $key, array $replace = []) => __('clients.access_sheet.'.$key, $replace))
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $t('document_title') }}</title>
    <style>
        @page { margin: 16mm 16mm 14mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #1f2933; line-height: 1.45; }
        .sheet { position: relative; }
        .break { page-break-after: always; }
        table { border-collapse: collapse; width: 100%; }
        .header td { padding: 0 0 6mm; vertical-align: bottom; }
        .brand { font-size: 20pt; font-weight: bold; color: #b42318; letter-spacing: 0.5pt; }
        .confidential { text-align: right; font-size: 8.5pt; color: #b42318; font-weight: bold; text-transform: uppercase; }
        h1 { font-size: 16pt; margin: 0 0 5mm; padding-bottom: 2mm; border-bottom: 1.5pt solid #1f2933; }
        h2 { font-size: 12pt; margin: 7mm 0 3mm; }
        .client { background: #f4f5f7; border-radius: 3pt; padding: 4mm 5mm; }
        .client-label { font-size: 8.5pt; text-transform: uppercase; color: #52606d; }
        .client-name { font-size: 14pt; font-weight: bold; margin: 1mm 0; }
        .credentials th, .credentials td { border: 1pt solid #9aa5b1; padding: 3.5mm 4mm; text-align: left; vertical-align: middle; }
        .credentials th { width: 38%; background: #f4f5f7; font-weight: normal; color: #3e4c59; }
        .secret { font-family: 'DejaVu Sans Mono', monospace; font-size: 17pt; font-weight: bold; letter-spacing: 2pt; }
        .url { font-family: 'DejaVu Sans Mono', monospace; font-size: 12pt; }
        .notice { font-size: 9.5pt; color: #3e4c59; margin-top: 3mm; }
        .install td { width: 50%; vertical-align: top; padding: 0 4mm 0 0; }
        .install td + td { padding: 0 0 0 4mm; }
        .install h3 { font-size: 10.5pt; margin: 0 0 2mm; }
        .install ol { margin: 0; padding-left: 5mm; }
        .install li { margin-bottom: 1.5mm; }
        .help { margin-top: 8mm; font-size: 9.5pt; }
        .footer { margin-top: 10mm; padding-top: 2mm; border-top: 0.5pt solid #cbd2d9; font-size: 8pt; color: #7b8794; }
    </style>
</head>
<body>
@foreach ($sheets as $sheet)
    <div class="sheet{{ $loop->last ? '' : ' break' }}">
        <table class="header">
            <tr>
                <td class="brand">{{ config('app.name') }}</td>
                <td class="confidential">{{ $t('confidential') }}</td>
            </tr>
        </table>

        <h1>{{ $t('title') }}</h1>

        <div class="client">
            <div class="client-label">{{ $t('client') }}</div>
            <div class="client-name">{{ $sheet['name'] }}</div>
            @if ($sheet['trade_name'])
                <div>{{ $t('trade_name') }}&nbsp;: {{ $sheet['trade_name'] }}</div>
            @endif
            <div>{{ $t('code') }}&nbsp;: <strong>{{ $sheet['code'] }}</strong></div>
        </div>

        <h2>{{ $t('credentials') }}</h2>
        <table class="credentials">
            <tr>
                <th>{{ $t('address') }}</th>
                <td class="url">{{ $appUrl }}</td>
            </tr>
            <tr>
                <th>{{ $t('username') }}</th>
                <td class="secret">{{ $sheet['username'] }}</td>
            </tr>
            <tr>
                <th>{{ $t('password') }}</th>
                <td class="secret">{{ $sheet['password'] }}</td>
            </tr>
        </table>
        <p class="notice">{{ $t('password_notice') }}</p>

        <h2>{{ $t('install') }}</h2>
        <table class="install">
            <tr>
                <td>
                    <h3>{{ $t('android_title') }}</h3>
                    <ol>
                        @foreach ($t('android_steps') as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                </td>
                <td>
                    <h3>{{ $t('ios_title') }}</h3>
                    <ol>
                        @foreach ($t('ios_steps') as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                </td>
            </tr>
        </table>

        <p class="help">{{ $t('help') }}</p>

        <div class="footer">{{ $t('issued_on', ['date' => $issuedOn->format('d/m/Y')]) }}</div>
    </div>
@endforeach
</body>
</html>
