<?php
/**
 * Plain-PHP test for the blacklist-group checkout rule (payer_blocklist.php, pm_pbl_group_blocks) and the shared
 * per-customer cap answer (product_per_client_cap.php, pm_ppc_at_cap). Loads both hooks outside WHMCS, so nothing
 * registers and no database is used. Run: php whmcs/hooks/tests/blacklist-and-cap-test.php (exit 0 = all pass).
 */
declare(strict_types=1);

require __DIR__ . '/../payer_blocklist.php';
require __DIR__ . '/../product_per_client_cap.php';

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

$newProduct = ['products' => [['pid' => 374, 'qty' => 1]]];
$newAddon = ['addons' => [['id' => 5]]];
$renewalOnly = ['renewals' => [12345 => 'Monthly']];

// pm_pbl_group_blocks: only the blacklist group, only new purchases.
ok(PM_BLACKLIST_GROUP_ID === 9, 'blacklist group id is 9, as in autoclose_blacklisted_tickets.php');
ok(pm_pbl_group_blocks(9, $newProduct), 'blacklisted client buying a product: refused');
ok(pm_pbl_group_blocks(9, $newAddon), 'blacklisted client buying an addon: refused');
ok(!pm_pbl_group_blocks(9, $renewalOnly), 'blacklisted client renewing only: allowed');
ok(!pm_pbl_group_blocks(9, []), 'blacklisted client with an empty cart: allowed');
ok(!pm_pbl_group_blocks(0, $newProduct), 'client in no group: allowed');
ok(!pm_pbl_group_blocks(3, $newProduct), 'client in another group: allowed');
ok(!pm_pbl_group_blocks(9, ['products' => []]), 'empty product list counts as nothing new');

// pm_pbl_provision_group_blocks: a new service of a blacklisted client is not provisioned (PreModuleCreate).
ok(pm_pbl_provision_group_blocks(9), 'blacklisted client, new service: provisioning aborted');
ok(!pm_pbl_provision_group_blocks(0), 'client in no group: provisioned');
ok(!pm_pbl_provision_group_blocks(3), 'client in another group: provisioned');
$note = pm_pbl_group_provision_note(40213, '23/10/2026');
ok(strpos($note, 'PAYER-BLOCKLIST BLOCK at provisioning: service 40213,') === 0, 'note starts with the once-per-service marker');
ok(strpos($note, 'PAYER-BLOCKLIST BLOCK at provisioning: service 4021,') === false, 'marker of another service id does not match');
ok(strpos($note, 'return the payment') === false, 'group note gives no refund instruction');
ok(strpos($note, '-payer_blocklist 23/10/2026') !== false, 'note carries the PM signature and date');

// pm_pbl_name_tokens: a one-letter Han surname is a name part (ADR-0005); a Latin initial is not. Synthetic names.
ok(pm_pbl_name_tokens('王 小明') === ['王', '小明'], 'Han: one-letter surname kept with the given name');
ok(count(pm_pbl_name_keys('李 明', 'salt-for-tests-0123456789')) === 1, 'Han: one-letter surname and given name make one pair key');
ok(pm_pbl_name_keys('王', 'salt-for-tests-0123456789') === [], 'Han: a lone surname makes no key');
ok(count(pm_pbl_name_tokens('김 민준')) === 2, 'Hangul: one-syllable surname kept');
ok(pm_pbl_name_tokens('J. R. Tolkien') === ['tolkien'], 'Latin initials still dropped');
ok(pm_pbl_name_keys('小明 王', 'salt-for-tests-0123456789') === pm_pbl_name_keys('王 小明', 'salt-for-tests-0123456789'), 'Han: word order does not change keys');

// pm_ppc_at_cap: same arithmetic as checkout, one more service on top of what is held.
$caps = [374 => 1, 375 => 2];
$held = function (int $n): callable {
    return function (int $cid, int $pid) use ($n): int {
        return $n;
    };
};
ok(pm_ppc_at_cap(20, 374, $caps, $held(1)) === ['pid' => 374, 'cap' => 1, 'held' => 1], 'holds 1 of a cap-1 product: at cap');
ok(pm_ppc_at_cap(20, 374, $caps, $held(0)) === null, 'holds none: room for one');
ok(pm_ppc_at_cap(20, 375, $caps, $held(1)) === null, 'holds 1 of a cap-2 product: room for one');
ok(pm_ppc_at_cap(20, 375, $caps, $held(2)) !== null, 'holds 2 of a cap-2 product: at cap');
ok(pm_ppc_at_cap(20, 999, $caps, $held(5)) === null, 'uncapped product: never at cap');
ok(pm_ppc_at_cap(20, 374, [], $held(5)) === null, 'no caps configured: nothing capped');
ok(pm_ppc_caps('/nonexistent/product-per-client-cap.json') === [], 'missing config file reads as no caps');

echo "blacklist-and-cap: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
