<?php

use App\Support\Csv;

it('neutralizes cells that Excel would read as formulas (§8.4)', function (mixed $value, string $expected) {
    expect(Csv::sanitize($value))->toBe($expected);
})->with([
    'equals' => ['=SUM(A1:A9)', "'=SUM(A1:A9)"],
    'plus' => ['+33 6', "'+33 6"],
    'minus' => ['-2+3', "'-2+3"],
    'at' => ['@cmd', "'@cmd"],
    'tab' => ["\t=1", "'\t=1"],
    'plain text' => ['Client A', 'Client A'],
    'null' => [null, ''],
    'boolean' => [true, '1'],
    'integer' => [42, '42'],
    'array' => [['a' => 'é'], '{"a":"é"}'],
]);
