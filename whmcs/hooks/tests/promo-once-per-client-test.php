<?php
/**
 * Plain-PHP test for the once-per-customer promotion rule (promo_once_per_client.php). Loads the hook outside
 * WHMCS, so nothing registers and no database is used; the order count is a stub. Expected values are written
 * from the rule, not from the code's output.
 * Run: php whmcs/hooks/tests/promo-once-per-client-test.php (exit 0 = all pass).
 */
declare(strict_types=1);

require __DIR__ . '/../promo_once_per_client.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $name): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $name\n";
    }
}

/** A held-count stub returning $n and recording the codes it was asked about. */
function holds(int $n, array &$asked = []): callable
{
    return function (string $code) use ($n, &$asked): int {
        $asked[] = $code;
        return $n;
    };
}

$codes = ['SPRING-ONE', 'SPRING-TWO'];

ok(pm_poc_decide($codes, 'OTHER-CODE', 20, holds(3)) === null, 'unmapped code passes even with orders held');
ok(pm_poc_decide($codes, '', 20, holds(3)) === null, 'empty cart code passes');
ok(pm_poc_decide($codes, null, 20, holds(3)) === null, 'no promo in the cart passes');
ok(pm_poc_decide($codes, 'SPRING-ONE', 0, holds(3)) === null, 'mapped code, guest: passes');
ok(pm_poc_decide($codes, 'SPRING-ONE', -1, holds(3)) === null, 'mapped code, negative client id: passes');
ok(pm_poc_decide($codes, 'SPRING-ONE', 20, holds(0)) === null, 'mapped code, client holds 0: passes');
ok(pm_poc_decide($codes, 'SPRING-ONE', 20, holds(1)) === ['code' => 'SPRING-ONE', 'held' => 1], 'mapped code, client holds 1: refused');
ok(pm_poc_decide($codes, 'SPRING-TWO', 20, holds(2)) === ['code' => 'SPRING-TWO', 'held' => 2], 'second mapped code, holds 2: refused with the count');
ok(pm_poc_decide([], 'SPRING-ONE', 20, holds(1)) === null, 'empty code list: nothing enforced');

// Case-insensitive: cart code typed in any case or with spaces still matches; the stub is asked in normalized form.
$asked = [];
$row = pm_poc_decide($codes, '  spring-one ', 20, holds(1, $asked));
ok($row !== null && $row['code'] === 'SPRING-ONE' && $asked === ['SPRING-ONE'], 'lower-case code with spaces matches, counted in normalized form');

// Held count is not even queried for guests and unmapped codes.
$asked = [];
pm_poc_decide($codes, 'SPRING-ONE', 0, holds(1, $asked));
pm_poc_decide($codes, 'NOPE', 20, holds(1, $asked));
ok($asked === [], 'no database count for guests or unmapped codes');

// Config reader: missing, malformed, wrong version, wrong shape => no codes; valid => normalized, deduplicated.
ok(pm_poc_codes('/nonexistent/promo-once-per-client.json') === [], 'missing config file reads as no codes');
$dir = sys_get_temp_dir() . '/promo-once-test-' . getmypid();
mkdir($dir, 0700);
$write = function (string $name, string $body) use ($dir): string {
    $path = $dir . '/' . $name;
    file_put_contents($path, $body);
    return $path;
};
$files = [];
$files[] = $p = $write('bad-json.json', '{not json');
ok(pm_poc_codes($p) === [], 'malformed JSON reads as no codes');
$files[] = $p = $write('v2.json', '{"v":2,"codes":["A"]}');
ok(pm_poc_codes($p) === [], 'unknown version reads as no codes');
$files[] = $p = $write('shape.json', '{"v":1,"codes":"A"}');
ok(pm_poc_codes($p) === [], 'codes that is not a list reads as no codes');
$files[] = $p = $write('empty.json', '{"v":1,"codes":[]}');
ok(pm_poc_codes($p) === [], 'empty list reads as no codes');
$files[] = $p = $write('ok.json', '{"v":1,"codes":["spring-one"," Spring-Two ","SPRING-ONE","",5,null]}');
ok(pm_poc_codes($p) === ['SPRING-ONE', 'SPRING-TWO'], 'valid config: trimmed, upper-cased, deduplicated, junk entries dropped');
// Shared caps reader: "caps" optional; groups with a bad max or no usable codes are dropped; codes normalized.
ok(pm_poc_caps('/nonexistent/promo-once-per-client.json') === [], 'missing config file reads as no caps');
ok(pm_poc_caps($p) === [], 'config without a caps key reads as no caps');
$files[] = $p = $write('caps.json', '{"v":1,"codes":["SPRING-ONE"],"caps":['
    . '{"codes":["spring-one"," Spring-Two ","SPRING-ONE"],"max":100},'
    . '{"codes":["X"],"max":"100"},{"codes":["X"],"max":-1},{"codes":"X","max":5},{"codes":["",null],"max":5},"junk"]}');
ok(pm_poc_caps($p) === [['codes' => ['SPRING-ONE', 'SPRING-TWO'], 'max' => 100]], 'valid cap group kept and normalized, malformed groups dropped');
ok(pm_poc_codes($p) === ['SPRING-ONE'], 'once-per-customer codes still read when caps are present');
foreach ($files as $f) {
    unlink($f);
}
rmdir($dir);

/** A use-count stub returning $n and recording the code lists it was asked about. */
function uses(int $n, array &$asked = []): callable
{
    return function (array $codes) use ($n, &$asked): int {
        $asked[] = $codes;
        return $n;
    };
}
$caps = [['codes' => ['DISC-A', 'DISC-B', 'DISC-C'], 'max' => 100]];
$asked = [];
ok(pm_poc_cap_decide($caps, 'OTHER', uses(500, $asked)) === null && $asked === [], 'code outside every cap group passes without a count');
ok(pm_poc_cap_decide($caps, '', uses(500)) === null, 'empty cart code passes the cap');
ok(pm_poc_cap_decide($caps, 'DISC-B', uses(99)) === null, '99 of 100 used: the 100th order still passes');
ok(pm_poc_cap_decide($caps, 'DISC-B', uses(100)) === ['code' => 'DISC-B', 'used' => 100, 'max' => 100], '100 of 100 used: refused');
ok(pm_poc_cap_decide($caps, 'DISC-C', uses(140)) === ['code' => 'DISC-C', 'used' => 140, 'max' => 100], 'over the cap: refused with the count');
$asked = [];
$row = pm_poc_cap_decide($caps, ' disc-a ', uses(100, $asked));
ok($row !== null && $row['code'] === 'DISC-A' && $asked === [['DISC-A', 'DISC-B', 'DISC-C']], 'any case matches and the whole group is counted together');
ok(pm_poc_cap_decide([['codes' => ['Z'], 'max' => 0]], 'Z', uses(0)) !== null, 'max 0 refuses every order');
ok(pm_poc_cap_decide([], 'DISC-A', uses(1000)) === null, 'no caps configured: nothing enforced');
$cm = pm_poc_cap_message(['code' => '<b>X</b>', 'used' => 100, 'max' => 100]);
ok(strpos($cm, '100 orders') !== false && strpos($cm, '<b>') === false && strpos($cm, '&lt;b&gt;') !== false, 'cap message names the limit and escapes HTML');

// Message: names the code, says once per customer, escapes HTML.
$msg = pm_poc_message(['code' => 'SPRING-ONE', 'held' => 1]);
ok(strpos($msg, 'SPRING-ONE') !== false && strpos($msg, 'once per customer') !== false, 'message names the code and the rule');
$evil = pm_poc_message(['code' => '<script>alert("x")</script>', 'held' => 1]);
ok(strpos($evil, '<script>') === false && strpos($evil, '&lt;script&gt;') !== false, 'message escapes HTML in the code');

echo "promo-once-per-client: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
