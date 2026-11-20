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

// Cart: a code the logged-in client already holds is taken out of the cart; everything else stays as it is.
$hasCart = function_exists('pm_poc_cart_strip') && function_exists('pm_poc_session_client');
ok($hasCart, 'cart-time functions exist');
if ($hasCart) {
    $cart = ['promo' => 'spring-one', 'products' => [['pid' => 7, 'qty' => 1]]];
    $out = pm_poc_cart_strip($codes, $cart, 20, holds(1));
    ok($out !== null && !array_key_exists('promo', $out['cart']), 'client holding the code: code taken out of the cart');
    ok($out !== null && $out['cart']['products'] === $cart['products'], 'client holding the code: the products stay in the cart');
    ok($out !== null && $out['row'] === ['code' => 'SPRING-ONE', 'held' => 1], 'client holding the code: the row names the code for the message');
    ok(pm_poc_cart_strip($codes, $cart, 0, holds(1)) === null, 'guest: code stays');
    ok(pm_poc_cart_strip($codes, $cart, 20, holds(0)) === null, 'client who never used the code: code stays');
    ok(pm_poc_cart_strip($codes, ['promo' => 'OTHER-CODE'], 20, holds(3)) === null, 'code not on the list: code stays');
    ok(pm_poc_cart_strip($codes, ['products' => []], 20, holds(3)) === null, 'cart without a code: nothing to take out');
    $session = function (array $s): callable {
        return function (string $k) use ($s) {
            return $s[$k] ?? null;
        };
    };
    ok(pm_poc_session_client($session(['uid' => '54'])) === 54, 'logged-in session: its client id');
    ok(pm_poc_session_client($session([])) === 0, 'no session client: guest');
    ok(pm_poc_session_client($session(['uid' => 'x'])) === 0 && pm_poc_session_client($session(['uid' => -3])) === 0, 'junk or negative client id: guest');
}
// Command-line fixture for live checks: held counts come from it, and nothing touches the database.
$GLOBALS['pm_poc_held_fixture'] = [20 => ['SPRING-ONE' => 2]];
ok(pm_poc_held(20, 'spring-one') === 2, 'fixture: the held count for the client and code, any case');
ok(pm_poc_held(20, 'SPRING-TWO') === 0 && pm_poc_held(21, 'SPRING-ONE') === 0, 'fixture: other code or other client holds nothing');
unset($GLOBALS['pm_poc_held_fixture']);
$hasSubmit = function_exists('pm_poc_is_checkout_submit');
ok($hasSubmit, 'checkout-submit check exists');
if ($hasSubmit) {
    ok(pm_poc_is_checkout_submit(['a' => 'checkout', 'submit' => 'true']), 'checkout form submitted: left to the checkout refusal');
    ok(pm_poc_is_checkout_submit(['a' => 'checkout', 'checkout' => '1']), 'checkout flag submitted: left to the checkout refusal');
    ok(!pm_poc_is_checkout_submit(['a' => 'checkout']), 'checkout page view: code taken out as in the cart');
    ok(!pm_poc_is_checkout_submit(['a' => 'view']) && !pm_poc_is_checkout_submit([]), 'cart view or no request: code taken out');
}

// Per-code limits (ADR-0013): a code limited to 5 allows a customer's 1st to 5th order and refuses the 6th.
$limits = ['MULTI-FIVE' => 5];
$lcodes = ['SPRING-ONE', 'MULTI-FIVE'];
ok(pm_poc_decide($lcodes, 'MULTI-FIVE', 20, holds(0), $limits) === null, 'limit 5, holds 0: passes');
ok(pm_poc_decide($lcodes, 'MULTI-FIVE', 20, holds(4), $limits) === null, 'limit 5, holds 4: the 5th order passes');
ok(pm_poc_decide($lcodes, 'MULTI-FIVE', 20, holds(5), $limits) === ['code' => 'MULTI-FIVE', 'held' => 5, 'limit' => 5], 'limit 5, holds 5: refused, row carries the limit');
ok(pm_poc_decide($lcodes, 'multi-five', 20, holds(7), $limits) === ['code' => 'MULTI-FIVE', 'held' => 7, 'limit' => 5], 'limit 5, holds 7, lower case: refused');
ok(pm_poc_decide($lcodes, 'MULTI-FIVE', 0, holds(9), $limits) === null, 'limit 5, guest: passes');
ok(pm_poc_decide(['ONE'], 'ONE', 20, holds(1), ['ONE' => 1]) === ['code' => 'ONE', 'held' => 1], 'limit 1 behaves as the one-order rule');
ok(pm_poc_decide($lcodes, 'SPRING-ONE', 20, holds(1), $limits) === ['code' => 'SPRING-ONE', 'held' => 1], 'code without a limit keeps the one-order rule');
ok(pm_poc_decide(['SPRING-ONE'], 'MULTI-FIVE', 20, holds(9), $limits) === null, 'a limit for a code not in the code list enforces nothing');
$lm = pm_poc_message(['code' => 'MULTI-FIVE', 'held' => 5, 'limit' => 5]);
ok(strpos($lm, 'up to 5 orders') !== false && strpos($lm, 'already has 5 orders') !== false && strpos($lm, 'once per customer') === false, 'limit message names the limit and the held count');
if ($hasCart) {
    ok(pm_poc_cart_strip($lcodes, ['promo' => 'MULTI-FIVE'], 20, holds(4), $limits) === null, 'cart: 4 of 5 held, code stays');
    $out = pm_poc_cart_strip($lcodes, ['promo' => 'MULTI-FIVE', 'products' => []], 20, holds(5), $limits);
    ok($out !== null && !array_key_exists('promo', $out['cart']) && $out['row']['limit'] === 5, 'cart: 5 of 5 held, code taken out');
}
// Config reader for limits: optional; values must be whole numbers of at least 1; limited codes join the code list.
$dir = sys_get_temp_dir() . '/promo-once-limits-' . getmypid();
mkdir($dir, 0700);
$lp = $dir . '/limits.json';
file_put_contents($lp, '{"v":1,"codes":["SPRING-ONE"],"limits":{" multi-five ":5,"BAD-STR":"5","BAD-ZERO":0,"ONE":1,"BAD-FLOAT":2.5}}');
ok(pm_poc_limits($lp) === ['MULTI-FIVE' => 5, 'ONE' => 1], 'limits read: normalized, non-integer and below-1 values dropped');
ok(pm_poc_codes($lp) === ['SPRING-ONE', 'MULTI-FIVE', 'ONE'], 'codes read: the list plus every valid limited code, bad entries dropped');
ok(in_array('MULTI-FIVE', pm_poc_codes($lp), true) && in_array('SPRING-ONE', pm_poc_codes($lp), true), 'code list holds both the listed and the limited code');
file_put_contents($lp, '{"v":1,"codes":["SPRING-ONE"]}');
ok(pm_poc_limits($lp) === [] && pm_poc_codes($lp) === ['SPRING-ONE'], 'config without limits: unchanged behaviour');
unlink($lp);
rmdir($dir);

echo "promo-once-per-client: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
