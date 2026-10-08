<?php

use App\Domain\Auth\TemporaryPassword;

it('generates readable 10-character passwords with letters and digits', function () {
    foreach (range(1, 200) as $i) {
        $password = TemporaryPassword::generate();

        expect($password)->toMatch('/^[A-HJ-NP-Za-km-z2-9]{10}$/')
            ->toMatch('/[A-Za-z]/')
            ->toMatch('/[2-9]/');
    }
});

it('does not repeat itself', function () {
    $passwords = array_map(fn () => TemporaryPassword::generate(), range(1, 500));

    expect(array_unique($passwords))->toHaveCount(500);
});
