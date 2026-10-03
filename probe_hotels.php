<?php

use App\Models\Hotel;

// Reproduce exactly what the hotels page now requests.
function pageQuery(array $params, int $page)
{
    $q = Hotel::withCount(['users', 'rooms', 'bookings']);
    if (! empty($params['status'])) {
        $q->where('status', $params['status']);
    }
    if (! empty($params['search'])) {
        $q->where(function ($q) use ($params) {
            $q->where('name', 'like', "%{$params['search']}%")
                ->orWhere('slug', 'like', "%{$params['search']}%")
                ->orWhere('city', 'like', "%{$params['search']}%");
        });
    }
    return $q->orderBy('name')->orderBy('id')->paginate((int) $params['per_page'], ['*'], 'page', $page);
}

$p = pageQuery(['page' => 1, 'per_page' => 25, 'sort' => 'name'], 1);
echo "All tab, page 1: total={$p->total()} pages={$p->lastPage()}".PHP_EOL;
foreach ($p->items() as $h) {
    echo '   '.$h->name.' ['.$h->status.']'.PHP_EOL;
}

echo PHP_EOL.'Pending tab (was previously listed first by default):'.PHP_EOL;
$pend = pageQuery(['page' => 1, 'per_page' => 25, 'sort' => 'name', 'status' => 'pending'], 1);
echo "total={$pend->total()}".PHP_EOL;
foreach ($pend->items() as $h) {
    echo '   '.$h->name.PHP_EOL;
}

echo PHP_EOL.'With search "Dakar":'.PHP_EOL;
$s = pageQuery(['page' => 1, 'per_page' => 25, 'sort' => 'name', 'search' => 'Dakar'], 1);
echo "total={$s->total()}".PHP_EOL;
foreach ($s->items() as $h) {
    echo '   '.$h->name.PHP_EOL;
}

// Page boundaries must not overlap or skip.
$names = [];
for ($i = 1; $i <= $p->lastPage(); $i++) {
    foreach (pageQuery(['page' => 1, 'per_page' => 25, 'sort' => 'name'], $i)->items() as $h) {
        $names[] = $h->name;
    }
}
$sorted = $names;
sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
echo PHP_EOL.'all 5 pages concatenate to a sorted list: '
    . (implode('|', $names) === implode('|', $sorted) ? 'YES' : 'NO').PHP_EOL;
echo 'rows walked: ' . count($names) . ' of ' . $p->total() . PHP_EOL;
echo 'any row repeated: ' . (count($names) !== count(array_unique($names)) ? 'YES' : 'NO') . PHP_EOL;
