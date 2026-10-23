<?php
/**
 * Plain-PHP test for onboardingTipOncePerClient.php: the send/abort decision and the subject pattern. Loads the
 * hook outside WHMCS, so nothing registers and no database is used. The LIKE matcher below is written independently
 * of the hook. Run: php whmcs/hooks/tests/onboardingTipOncePerClientTest.php (exit 0 = all pass).
 */
declare(strict_types=1);

require __DIR__ . '/../onboardingTipOncePerClient.php';

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

/** MySQL LIKE with backslash escapes, evaluated by hand: % any run, _ one character, \x literal x. */
function likeMatches(string $pattern, string $text): bool
{
    $re = '';
    for ($i = 0, $n = strlen($pattern); $i < $n; $i++) {
        $c = $pattern[$i];
        if ($c === '\\' && $i + 1 < $n) {
            $re .= preg_quote($pattern[++$i], '/');
        } elseif ($c === '%') {
            $re .= '.*';
        } elseif ($c === '_') {
            $re .= '.';
        } else {
            $re .= preg_quote($c, '/');
        }
    }
    return (bool) preg_match('/^' . $re . '$/is', $text);
}

$never = static function (int $cid): bool { return false; };
$always = static function (int $cid): bool { return true; };
$tip = 'DidYouKnow 03 - Where answers live';

// The decision.
$seen = [];
ok(pmOnboardingTipDecision($tip, 101, $seen, $never) === 'send', 'first copy for a client is sent');
ok(pmOnboardingTipDecision($tip, 101, $seen, $never) === 'abort', 'second copy in the same run is aborted');
ok(pmOnboardingTipDecision($tip, 102, $seen, $never) === 'send', 'another client is not affected');
ok(pmOnboardingTipDecision('DidYouKnow onboarding 18d - what support can do', 101, $seen, $never) === 'send', 'a different tip to the same client is sent');
$seen = [];
ok(pmOnboardingTipDecision($tip, 101, $seen, $always) === 'abort', 'tip already in the email log within the window: aborted');
ok($seen === [], 'an aborted copy is not remembered as sent');
ok(pmOnboardingTipDecision('Hosting Account Welcome Email', 101, $seen, $always) === 'skip', 'welcome email is never touched');
ok(pmOnboardingTipDecision('Invoice Payment Confirmation', 101, $seen, $always) === 'skip', 'invoice email is never touched');
ok(pmOnboardingTipDecision('Z Marketing Free 25d', 101, $seen, $always) === 'skip', 'trial template shared by two rules is not in the list');
ok(pmOnboardingTipDecision($tip, 0, $seen, $always) === 'skip', 'no client: skipped (sent as before)');
$asked = 0;
$counting = static function (int $cid) use (&$asked): bool { $asked++; return false; };
pmOnboardingTipDecision('Hosting Account Welcome Email', 101, $seen, $counting);
ok($asked === 0, 'the email log is not queried for non-tip emails');
ok(count(PM_ONBOARDING_TIP_TEMPLATES) === 7 && PM_ONBOARDING_TIP_REPEAT_WINDOW_DAYS === 30, 'seven tip templates, 30-day window');

// The subject pattern, against the live tip subjects and rendered examples.
$tips = [
    'Did you know? Your seedbox has its own control panel',
    'Did you know? Everything you download lands in one folder',
    'Did you know? Most seedbox questions are already answered in writing',
    'Did you know? Your {$service_product_name} can run a full media stack',
    'Did you know? A longer billing cycle costs less',
    'Did you know? Your trial has about a week left',
    'Did you know? Support looks at your actual service',
];
$rendered = $tips;
$rendered[3] = 'Did you know? Your M1000 SSD 2TB can run a full media stack';
foreach ($tips as $i => $subject) {
    $like = pmOnboardingTipSubjectLikePattern($subject);
    ok(likeMatches($like, $rendered[$i]), "tip $i pattern matches its own sent subject");
    foreach ($rendered as $j => $other) {
        if ($j !== $i) {
            ok(!likeMatches($like, $other), "tip $i pattern does not match tip $j");
        }
    }
}
$media = pmOnboardingTipSubjectLikePattern($tips[3]);
ok(likeMatches($media, 'Did you know? Your Eternal Storage Box 1TB 10G can run a full media stack'), 'merge field matches any product name');
ok(!likeMatches($media, 'Did you know? Your seedbox does more than torrents'), 'media pattern does not match a drip article');
ok(pmOnboardingTipSubjectLikePattern('100% off_now') === '100\\% off\\_now', 'literal % and _ are escaped');
ok(!likeMatches(pmOnboardingTipSubjectLikePattern('50% more'), '50 more'), 'escaped % is literal');
// Drip articles that share a prefix with a tip. The one with the identical subject is the same tip and counts as received.
$drip = [
    'Did you know? Your control panel is only a few clicks away',
    'Did you know? Your box has its own public web folder',
    'Did you know? Support can check a setup you built',
    'Did you know? Your service has an address that never moves',
];
foreach ($tips as $i => $subject) {
    foreach ($drip as $d) {
        ok(!likeMatches(pmOnboardingTipSubjectLikePattern($subject), $d), "tip $i pattern does not match drip '$d'");
    }
}
ok(likeMatches(pmOnboardingTipSubjectLikePattern($tips[6]), 'Did you know? Support looks at your actual service'), 'identical drip subject counts as the same tip');

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
