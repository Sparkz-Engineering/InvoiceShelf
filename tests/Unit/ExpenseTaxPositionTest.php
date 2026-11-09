<?php

use App\Domains\Purchases\Application\ExpenseTaxPosition;

test('a wholly business expense claims all its tax and deducts the cost without it', function () {
    $position = ExpenseTaxPosition::from(11000, 1000, 100, true);

    expect($position->net())->toBe(10000)
        ->and($position->claimableTax())->toBe(1000)
        ->and($position->privateTax())->toBe(0)
        ->and($position->deductibleAmount())->toBe(10000);
});

test('a part business expense claims its share, rounding down', function () {
    $position = ExpenseTaxPosition::from(11001, 1001, '60', true);

    expect($position->claimableTax())->toBe(600)
        ->and($position->privateTax())->toBe(401)
        ->and($position->deductibleAmount())->toBe(6000);
});

test('a non-deductible expense still claims its tax', function () {
    $position = ExpenseTaxPosition::from(11000, 1000, 100, false);

    expect($position->claimableTax())->toBe(1000)
        ->and($position->deductibleAmount())->toBe(0);
});

test('an expense with no tax deducts its whole business share', function () {
    expect(ExpenseTaxPosition::from(2500, 0, 50, true)->deductibleAmount())->toBe(1250);
});

test('a share is kept between none and all', function (mixed $percent, int $expected) {
    expect(ExpenseTaxPosition::basisPoints($percent))->toBe($expected);
})->with([
    [null, 10000],
    ['', 10000],
    [0, 0],
    [33.333, 3333],
    [150, 10000],
    [-5, 0],
]);

test('separately rounded figures never make the net negative', function () {
    expect(ExpenseTaxPosition::from(10, 11, 100, true)->net())->toBe(0);
});
