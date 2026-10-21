{{-- Bon de commande (RG-A04, I-86) : une commande, montants sur les quantités validées, mention RG-P05. --}}
@use('App\Domain\Documents\Formats')
@php($t = fn (string $key, array $replace = []) => __('documents.order.'.$key, $replace))
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $t('title') }} {{ $order->number }}</title>
    @include('pdf.partials.style')
</head>
<body>
<div class="footer">
    <table>
        <tr>
            <td>{{ Formats::amountNotice() }}</td>
            <td class="right">{{ $order->number }} · {{ __('documents.generated_at', ['date' => Formats::dateTime($generatedAt)]) }}</td>
        </tr>
    </table>
</div>

<table class="head">
    <tr>
        <td class="brand">{{ config('app.name') }}</td>
        <td class="right"><h1>{{ $t('title') }}</h1><div>{{ $order->number }}</div></td>
    </tr>
</table>

@if ($cancelled)
    <div class="banner">{{ $t('cancelled') }}</div>
    @if ($cancellation)
        <p class="center muted">{{ $t('cancelled_reason', ['reason' => $cancellation]) }}</p>
    @endif
@endif

<table>
    <tr>
        <td style="width: 50%; padding-right: 3mm; vertical-align: top;">
            <div class="box">
                <strong>{{ $t('client') }}</strong>
                <table class="kv">
                    <tr><td class="k">{{ $t('client_name') }}</td><td><strong>{{ $client->name }}</strong>@if ($client->trade_name) ({{ $client->trade_name }})@endif</td></tr>
                    <tr><td class="k">{{ $t('client_code') }}</td><td>{{ $client->code }}</td></tr>
                    <tr><td class="k">{{ $t('nif') }}</td><td>{{ $client->nif ?? '—' }}</td></tr>
                    <tr><td class="k">{{ $t('rc') }}</td><td>{{ $client->rc ?? '—' }}</td></tr>
                    <tr><td class="k">{{ $t('address') }}</td><td>{{ collect([$client->address, $client->commune, $client->wilaya])->filter()->implode(', ') ?: '—' }}</td></tr>
                    <tr><td class="k">{{ $t('phone') }}</td><td>{{ collect([$client->phone, $client->phone2])->filter()->implode(' / ') ?: '—' }}</td></tr>
                </table>
            </div>
        </td>
        <td style="width: 50%; padding-left: 3mm; vertical-align: top;">
            <div class="box">
                <strong>{{ $t('order') }}</strong>
                <table class="kv">
                    <tr><td class="k">{{ $t('day') }}</td><td>{{ Formats::date($day) }}</td></tr>
                    <tr><td class="k">{{ $t('delivery') }}</td><td>{{ Formats::date($deliveryDate) }}</td></tr>
                    <tr><td class="k">{{ $t('status') }}</td><td>{{ $order->status->label() }}</td></tr>
                    <tr><td class="k">{{ $t('region') }}</td><td>{{ $region->name }}@if ($depot) · {{ $depot->name }}@endif</td></tr>
                    <tr><td class="k">{{ $t('submitted_at') }}</td><td>{{ Formats::dateTime($order->submitted_at) }}</td></tr>
                    <tr><td class="k">{{ $t('decided_at') }}</td><td>{{ Formats::dateTime($order->decided_at) }}@if ($order->decidedBy) · {{ $order->decidedBy->name }}@endif</td></tr>
                    @if ($order->shipped_at || $order->delivery_note_ref)
                        <tr><td class="k">{{ $t('shipped_at') }}</td><td>{{ Formats::dateTime($order->shipped_at) }}</td></tr>
                        <tr><td class="k">{{ $t('delivery_note') }}</td><td>{{ $order->delivery_note_ref ?? '—' }}</td></tr>
                    @endif
                </table>
            </div>
        </td>
    </tr>
</table>

<h2>{{ $t('lines') }}</h2>
<table class="grid">
    <thead>
    <tr>
        <th style="width: 8%;">{{ $t('columns.sku') }}</th>
        <th style="width: 22%;">{{ $t('columns.product') }}</th>
        <th style="width: 13%;">{{ $t('columns.unit') }}</th>
        <th class="num">{{ $t('columns.requested') }}</th>
        <th class="num">{{ $t('columns.validated') }}</th>
        <th>{{ $t('columns.reason') }}</th>
        @if ($shipping)
            <th class="num">{{ $t('columns.shipped') }}</th>
            <th>{{ $t('columns.gap') }}</th>
        @endif
        <th class="num">{{ $t('columns.price') }}</th>
        <th class="num">{{ $t('columns.vat') }}</th>
        <th class="num">{{ $t('columns.amount') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($lines as $line)
        <tr>
            <td>{{ $line['sku'] }}</td>
            <td>{{ $line['name'] }}</td>
            <td>{{ $line['unit'] }}</td>
            <td class="num">{{ Formats::integer($line['requested']) }}</td>
            <td class="num">{{ $line['validated'] === null ? '—' : Formats::integer($line['validated']) }}</td>
            <td>
                {{ $line['reason'] ?? '—' }}
                @if ($line['comment'])
                    <div class="small muted">{{ $line['comment'] }}</div>
                @endif
            </td>
            @if ($shipping)
                <td class="num">{{ $line['shipped'] === null ? '—' : Formats::integer($line['shipped']) }}</td>
                <td>
                    @if ($line['gap'] !== null)
                        −{{ Formats::integer($line['gap']) }}{{ $line['gapReason'] ? ' · '.$line['gapReason'] : '' }}
                    @else
                        —
                    @endif
                </td>
            @endif
            <td class="num">{{ Formats::decimal($line['price']) }}</td>
            <td class="num">{{ Formats::rate($line['vat']) }}&nbsp;%</td>
            <td class="num">{{ Formats::decimal($line['amount']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table style="margin-top: 4mm;">
    <tr>
        <td style="width: 55%; vertical-align: top;" class="small muted">
            {{ $t('amounts_basis') }}<br>
            <strong>{{ Formats::amountNotice() }}</strong>
            @if ($order->client_comment)
                <div style="margin-top: 2mm;"><strong>{{ $t('client_comment') }}</strong> {{ $order->client_comment }}</div>
            @endif
        </td>
        <td style="width: 45%; vertical-align: top;">
            <table class="grid">
                <tr><td>{{ $t('totals.ht') }}</td><td class="num">{{ Formats::amount($order->total_ht) }}</td></tr>
                <tr><td>{{ $t('totals.vat') }}</td><td class="num">{{ Formats::amount($order->total_vat) }}</td></tr>
                <tr class="total"><td>{{ $t('totals.ttc') }}</td><td class="num">{{ Formats::amount($order->total_ttc) }}</td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
