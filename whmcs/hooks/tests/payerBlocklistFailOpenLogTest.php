<?php
/**
 * Plain-PHP test: when a payer_blocklist.php handler fails (here: no WHMCS database layer is loaded, so the first query
 * throws), it still fails open (order allowed, service provisioned) AND writes the reason to the error log, for both
 * hooks. A failure with nowhere to go would silently switch the ban off. Registers the real hooks through a fake
 * add_hook, so nothing needs WHMCS. Run: php whmcs/hooks/tests/payerBlocklistFailOpenLogTest.php (exit 0 = pass).
 */
declare(strict_types=1);

define('ROOTDIR', sys_get_temp_dir());
$hooks = [];
function add_hook(string $name, int $priority, callable $fn): void
{
    global $hooks;
    $hooks[$name] = $fn;
}

require __DIR__ . '/../payer_blocklist.php';

$logFile = tempnam(sys_get_temp_dir(), 'pbl');
ini_set('error_log', $logFile);

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

ok(isset($hooks['ShoppingCartValidateCheckout'], $hooks['PreModuleCreate']), 'both hooks register');

$checkout = $hooks['ShoppingCartValidateCheckout'];
$provision = $hooks['PreModuleCreate'];

ok($checkout(['clientId' => 7]) === '', 'checkout error: order allowed (fail-open)');
$log = (string) file_get_contents($logFile);
ok(strpos($log, 'payer_blocklist: checkout check failed, order allowed:') !== false, 'checkout error is logged');

ok($provision(['params' => ['serviceid' => 5, 'userid' => 7]]) === [], 'provisioning error: service allowed (fail-open)');
$log = (string) file_get_contents($logFile);
ok(strpos($log, 'payer_blocklist: provisioning check failed, service allowed:') !== false, 'provisioning error is logged');

unlink($logFile);
echo $fail === 0 ? "ok: $pass passed\n" : "$fail failed, $pass passed\n";
exit($fail === 0 ? 0 : 1);
