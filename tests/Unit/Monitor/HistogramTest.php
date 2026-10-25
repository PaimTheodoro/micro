<?php

use Psf\Monitor\Histogram;

function histMass(int $bucket, float $n = 100.0): array
{
    $c = Histogram::empty();
    $c[$bucket] = $n;
    return $c;
}

it('maps durations to buckets on the edges', function () {
    expect(Histogram::bucketCount())->toBe(10);
    foreach ([[0, 0], [9.99, 0], [10, 1], [24.9, 1], [25, 2], [999.9, 6], [1000, 7], [4999, 8], [5000, 9], [1e9, 9], [-3, 0], [NAN, 0], [INF, 9]] as [$ms, $i]) {
        expect(Histogram::bucketIndex((float) $ms))->toBe($i);
    }
});

it('merges and round-trips', function () {
    $a = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];
    $b = [10.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0];
    expect(Histogram::merge($a, $b))->toBe([11.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 11.0]);
    expect(Histogram::merge([1, 2], $b)[0])->toEqual(11.0);
    expect(Histogram::decode(Histogram::encode($a)))->toEqual($a);
});

it('decodes garbage as empty', function ($json) {
    expect(Histogram::decode($json))->toBe(Histogram::empty());
})->with([
    [null], [''], ['oops'], ['[1,2]'], ['{"a":1}'],
    ['[1,2,3,4,5,6,7,8,9,"x"]'], ['[-1,0,0,0,0,0,0,0,0,0]'],
]);

it('estimates percentiles by linear interpolation', function () {
    $c = histMass(3);
    expect(Histogram::percentile($c, 50))->toBe(75.0);
    expect(Histogram::percentile($c, 0))->toBe(50.0);
    expect(Histogram::percentile($c, 100))->toBe(100.0);

    $c = Histogram::empty();
    $c[0] = 50.0;
    $c[4] = 50.0;
    expect(Histogram::percentile($c, 25))->toBe(5.0);
    expect(Histogram::percentile($c, 75))->toBe(175.0);
});

it('handles the open bucket', function () {
    $c = histMass(9);
    expect(Histogram::percentile($c, 95))->toBe(5000.0);
    expect(Histogram::percentile($c, 100, 8000.0))->toBe(8000.0);
    $p50 = Histogram::percentile($c, 50, 8000.0);
    expect($p50)->toBeGreaterThan(5000.0)->toBeLessThan(8000.0);
    expect(Histogram::percentile($c, 100, 3000.0))->toBe(5000.0);
});

it('is safe on empty or out-of-range input', function () {
    expect(Histogram::percentile(Histogram::empty(), 50))->toBe(0.0);
    $c = histMass(3);
    expect(Histogram::percentile($c, -5))->toBe(50.0);
    expect(Histogram::percentile($c, 500))->toBe(100.0);
    expect(Histogram::percentile($c, NAN))->toBeFloat();
});

it('never exceeds maxMs', function () {
    expect(Histogram::percentile(histMass(3), 100, 60.0))->toBe(60.0);
});
