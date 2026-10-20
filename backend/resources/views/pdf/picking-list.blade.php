{{-- Bon de préparation consolidé (RG-A04, EF-ADV-05, I-84) : quantités validées par groupe et par produit, palettes. --}}
@use('App\Domain\Documents\Formats')
@use('App\Domain\Documents\PickingBy')
@php($t = fn (string $key, array $replace = []) => __('documents.picking.'.$key, $replace))
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $t('title') }} {{ Formats::date($report->day) }}</title>
    @include('pdf.partials.style')
</head>
<body>
<div class="footer">
    <table>
        <tr>
            <td>{{ $t('title') }} · {{ Formats::date($report->day) }}</td>
            <td class="right">{{ __('documents.generated_at', ['date' => Formats::dateTime($report->generatedAt)]) }}</td>
        </tr>
    </table>
</div>

<table class="head">
    <tr>
        <td class="brand">{{ config('app.name') }}</td>
        <td class="right"><h1>{{ $t('title') }}</h1></td>
    </tr>
</table>

<div class="box">
    <table class="kv">
        <tr><td class="k">{{ $t('head.day') }}</td><td><strong>{{ Formats::date($report->day) }}</strong></td></tr>
        <tr><td class="k">{{ $t('head.delivery') }}</td><td>{{ Formats::date($report->deliveryDate) }}</td></tr>
        <tr><td class="k">{{ $t('head.by') }}</td><td>{{ $report->by->label() }}</td></tr>
        <tr><td class="k">{{ $t('head.statuses') }}</td><td>{{ $report->statusLabels() }}</td></tr>
        <tr><td class="k">{{ $t('head.generated') }}</td><td>{{ Formats::dateTime($report->generatedAt) }}</td></tr>
    </table>
</div>

@if ($report->isEmpty())
    <p class="empty">{{ $t('empty') }}</p>
@else
    @foreach ($report->groups as $group)
        <h2>
            {{ $group->label }}@if ($group->code) <span class="muted">({{ $group->code }})</span>@endif
            @if ($report->by === PickingBy::Depot && $group->regions)
                <div class="small muted" style="font-weight: normal;">{{ $t('regions') }} {{ implode(', ', $group->regions) }}</div>
            @endif
        </h2>
        <table class="grid">
            <thead>
            <tr>
                <th style="width: 10%;">{{ $t('pdf_columns.sku') }}</th>
                <th style="width: 27%;">{{ $t('pdf_columns.product') }}</th>
                <th style="width: 19%;">{{ $t('pdf_columns.unit') }}</th>
                <th class="num">{{ $t('pdf_columns.quantity') }}</th>
                <th class="num">{{ $t('pdf_columns.orders') }}</th>
                <th class="num">{{ $t('pdf_columns.full') }}</th>
                <th class="num">{{ $t('pdf_columns.remainder') }}</th>
                <th class="num">{{ $t('pdf_columns.pallets') }}</th>
            </tr>
            </thead>
            <tbody>
            @php($family = null)
            @foreach ($group->lines as $line)
                @if ($line->family !== $family)
                    @php($family = $line->family)
                    <tr class="sub"><td colspan="8">{{ $family }}</td></tr>
                @endif
                <tr>
                    <td>{{ $line->sku }}</td>
                    <td>{{ $line->name }}</td>
                    <td>{{ $line->unitLabel }}</td>
                    <td class="num">{{ Formats::integer($line->quantity) }}</td>
                    <td class="num">{{ Formats::integer($line->orders) }}</td>
                    @if ($line->pallets)
                        <td class="num">{{ Formats::integer($line->pallets->full) }}</td>
                        <td class="num">{{ Formats::integer($line->pallets->remainder) }}</td>
                        <td class="num">{{ Formats::pallets($line->pallets->decimal) }}</td>
                    @else
                        <td class="num">—</td>
                        <td class="num">—</td>
                        <td class="num">—</td>
                    @endif
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">{{ $t('total_group', ['group' => $group->label]) }}</td>
                <td class="num">{{ Formats::integer($group->totals->quantity) }}</td>
                <td class="num">{{ Formats::integer($group->totals->orders) }}</td>
                <td class="num" colspan="2"></td>
                <td class="num">{{ $group->totals->pallets === null ? '—' : Formats::pallets($group->totals->pallets) }}</td>
            </tr>
            </tbody>
        </table>
    @endforeach

    <h2>{{ $t('total') }}</h2>
    <table class="grid">
        <tr class="total">
            <td>{{ $t('total_products', ['count' => $report->totals->products]) }}</td>
            <td class="num">{{ $t('total_quantity') }} {{ Formats::integer($report->totals->quantity) }}</td>
            <td class="num">{{ $t('total_orders') }} {{ Formats::integer($report->totals->orders) }}</td>
            <td class="num">{{ $t('total_pallets') }} {{ $report->totals->pallets === null ? '—' : Formats::pallets($report->totals->pallets) }}</td>
        </tr>
    </table>
@endif
</body>
</html>
