<?php
/**
 * Plain-PHP test for loginRememberMeDefault.php. Loads the hook outside WHMCS, so nothing registers.
 * Run: php whmcs/hooks/tests/loginRememberMeDefaultTest.php (exit 0 = all pass).
 */
declare(strict_types=1);

require __DIR__ . '/../loginRememberMeDefault.php';

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

$script = pmLoginRememberMeDefaultScript();
ok(strpos($script, '<script>') === 0 && substr($script, -9) === '</script>', 'one complete script element');
ok(strpos($script, 'name="rememberme"') !== false, 'targets the rememberme checkbox by name');
ok(strpos($script, 'type="checkbox"') !== false, 'targets checkboxes only');
ok(strpos($script, '.checked=true') !== false, 'ticks the box');
ok(strpos($script, 'DOMContentLoaded') !== false, 'runs after the form exists');
ok(substr_count($script, '<script') === 1 && stripos($script, 'src=') === false, 'inline, loads nothing external');
ok(!preg_match('/[\r\n]/', $script), 'single line');
ok($script === pmLoginRememberMeDefaultScript(), 'constant output (nothing from the request)');
ok(!function_exists('add_hook'), 'nothing registered outside WHMCS');

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
