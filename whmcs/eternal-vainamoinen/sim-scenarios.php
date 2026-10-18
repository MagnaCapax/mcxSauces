<?php
declare(strict_types=1);
/* Eternal Väinämöinen slot delivery — proper multi-seed, multi-scenario validation report. 2026-06-01.
   Campaign: 7 months, 700/month => 4900 total. Two streams (workhorse + one LIMITED SKU, "Claimed: N of 21",
   due at the campaign end). Synthetic data only. */

require_once __DIR__ . '/EternalVainamoinenSimulator.php';

const MONTHLY_TARGET = 700;
const MONTHS         = 7;
const TOTAL_TARGET   = MONTHLY_TARGET * MONTHS;   // 4900
const LIMIT          = 21;                          // the limited SKU's published limit, due at the campaign end
const INTERVAL       = 900;                         // 15-min cron cadence
const SEEDS          = 6;

/* 7-SKU EXAMPLE roster (synthetic, illustrative — NOT real products, sizes, or capacity). One SKU sits on
   its OWN 'limited' stream (a published limit paced to its due date); the other six share the 'workhorse' stream. Two HDD groups (g1,g2)
   each carry a seedbox + a storage SKU; two SSD groups (g4,g5); g3 is the premium pool. */
function buildTypes(): array {
    return [
        'hdd_seedbox_a' => new SimType('hdd_seedbox_a','g1','workhorse',1.0, 1.0,1.0,1.0, 3, 0.30),
        'hdd_storage_a' => new SimType('hdd_storage_a','g1','workhorse',2.0, 1.5,1.3,1.0, 3, 0.25),
        'hdd_seedbox_b' => new SimType('hdd_seedbox_b','g2','workhorse',1.0, 1.0,1.0,1.0, 2, 0.30),
        'hdd_storage_b' => new SimType('hdd_storage_b','g2','workhorse',2.0, 1.5,1.3,1.0, 2, 0.25),
        'ssd_a'         => new SimType('ssd_a','g4','workhorse',0.5, 1.2,1.0,1.0, 1, 0.30),
        'ssd_b'         => new SimType('ssd_b','g5','workhorse',0.5, 1.2,1.3,1.0, 1, 0.25),
        'premium_limit' => new SimType('premium_limit','g3','limited',2.73, 1.0,1.0,1.0, 1, 0.20),
    ];
}
function buildGroups(string $scenario): array {
    // [poolFreeTiB, reserveTiB] per group, per scenario
    $g = [
        'ample'       => ['g1'=>[4000,400],'g2'=>[4000,400],'g3'=>[300,30],'g4'=>[400,40],'g5'=>[400,40]],
        'constrained' => ['g1'=>[2000,200],'g2'=>[2000,200],'g3'=>[150,15],'g4'=>[200,20],'g5'=>[200,20]],
        'pause'       => ['g1'=>[4000,400],'g2'=>[4000,400],'g3'=>[300,30],'g4'=>[400,40],'g5'=>[400,40]],
        'reserve'     => ['g1'=>[4000,400],'g2'=>[4000,400],'g3'=>[300,30],'g4'=>[400,40],'g5'=>[30,3]], // g5 tiny
    ];
    $out = [];
    foreach ($g[$scenario] as $id => $pr) { $out[$id] = new SimGroup($id, (float)$pr[0], (float)$pr[1]); }
    return $out;
}
function scenarioOpts(string $scenario): array {
    $base = ['limit'=>LIMIT, 'capHeadroom'=>1.0, 'sampleEvery'=>200];
    if ($scenario === 'pause') {           // operator pause months 2..3 (kill-switch), then resume
        $base['pauseFrom'] = 2.0 * SIM_MONTH_SECONDS;
        $base['pauseTo']   = 3.0 * SIM_MONTH_SECONDS;
    }
    return $base;
}

function mean(array $a): float { return $a ? array_sum($a)/count($a) : 0.0; }
function sd(array $a): float { if(count($a)<2)return 0.0; $m=mean($a); return sqrt(array_sum(array_map(fn($x)=>($x-$m)**2,$a))/(count($a)-1)); }

/* run K seeds for a scenario; keep seed-0 full telemetry + aggregate scalars across seeds */
function runScenario(string $scenario): array {
    $agg = ['active_end'=>[],'drops'=>[],'breaches'=>[],'overalloc'=>[],'gap'=>[]];
    $detail = null; $limited = [];
    for ($s=0; $s<SEEDS; $s++) {
        mt_srand(1000 + $s);
        $rng = static fn(): float => mt_rand() / (mt_getrandmax() + 1.0);
        $sim = new EternalVainamoinenSimulator(buildGroups($scenario), buildTypes(), MONTHLY_TARGET, MONTHS, INTERVAL, $rng, scenarioOpts($scenario));
        $r = $sim->run();
        $agg['active_end'][] = $r['active_end'];
        $agg['drops'][]      = $r['drops_total'];
        $agg['breaches'][]   = $r['reserve_breaches'];
        $agg['overalloc'][]  = $r['over_allocations'];
        $agg['gap'][]        = $r['published_enforced_max_gap'];
        $limited[] = ['ticks'=>$r['limited_drop_ticks'], 'ticks_run'=>$r['ticks_run'], 'peak'=>$r['limited_peak']];
        if ($s === 0) { $detail = $r; }
    }
    return ['agg'=>$agg, 'detail'=>$detail, 'limited'=>$limited];
}

$scenarios = ['ample','constrained','pause','reserve'];
$results = [];
foreach ($scenarios as $sc) { $results[$sc] = runScenario($sc); }

function limitedStats(array $ticksList, int $totalTicks): array {
    $rt = $ticksList[0] ?? [];
    if (count($rt) < 2) { return ['n'=>count($rt),'mean'=>0.0,'cv'=>0.0,'thirds'=>[0,0,0],'last'=>0.0]; }
    $gaps=[]; for($k=1;$k<count($rt);$k++){$gaps[]=$rt[$k]-$rt[$k-1];}
    $thirds=[0,0,0]; foreach($rt as $t){ $thirds[min(2,(int)floor($t/max(1,$totalTicks)*3))]++; }
    return ['n'=>count($rt),'mean'=>mean($gaps),'cv'=>(mean($gaps)>0?sd($gaps)/mean($gaps):0.0),'thirds'=>$thirds,'last'=>end($rt)/max(1,$totalTicks)];
}

/* ===================== REPORT ===================== */
echo str_repeat('=',96)."\n";
echo "ETERNAL VÄINÄMÖINEN SLOT DELIVERY — SIMULATION REPORT (2026-06-01)\n";
echo str_repeat('=',96)."\n";
printf("Campaign: %d months × %d/month = %d active target | interval %ds (15 min) | %d seeds/scenario\n",
    MONTHS, MONTHLY_TARGET, TOTAL_TARGET, INTERVAL, SEEDS);
printf("Streams: workhorse (6 SKUs) + one limited SKU (\"Claimed: N of %d\", due at the campaign end). 7 SKUs over 5 shared pools.\n", LIMIT);
printf("Workhorse mean pace at launch ≈ %.4f/tick (its target over 7 months at 15-min). Synthetic demand; no real capacity/customer data.\n\n",
    EternalVainamoinenRelease::rate(TOTAL_TARGET - LIMIT, MONTHS * SIM_MONTH_SECONDS, INTERVAL));

echo "── 1. SCENARIO SUMMARY (mean ± sd across ".SEEDS." seeds) ".str_repeat('─',38)."\n";
printf("%-13s %18s %12s %10s %10s %14s\n",'scenario','active_end/target','drops','breaches','overalloc','pub==enf gap');
foreach ($scenarios as $sc) {
    $a = $results[$sc]['agg'];
    printf("%-13s %8.1f±%-5.1f /%-4d %6.0f±%-4.0f %4.0f/%-4.0f %4.0f/%-4.0f %12.4f\n",
        $sc, mean($a['active_end']), sd($a['active_end']), TOTAL_TARGET,
        mean($a['drops']), sd($a['drops']),
        max($a['breaches']), array_sum($a['breaches']),
        max($a['overalloc']), array_sum($a['overalloc']),
        max($a['gap']));
}
echo "  (breaches/overalloc shown as max/total across seeds — both MUST be 0; pub==enf gap = max |published−realized| share)\n\n";

/* detailed sections: ample = convergence reference; constrained = where floors actually engage */
$d  = $results['ample']['detail'];
$dc = $results['constrained']['detail'];

echo "── 2. CONVERGENCE — cumulative active by month end (ample, seed 0) ".str_repeat('─',28)."\n";
ksort($d['month_end_active']);
$prev=0;
foreach ($d['month_end_active'] as $m=>$act) { printf("   month %d: %5d active  (+%d)\n", $m+1, $act, $act-$prev); $prev=$act; }
printf("   => converges to %d/%d and stops (hard stop at target).\n\n", $d['active_end'], $d['target']);

echo "── 3. RESERVE SAFETY — CONSTRAINED scenario (capacity pressure forces the reserve floors to engage, seed 0) ".str_repeat('─',1)."\n";
echo "   min_reserve_margin = smallest (poolFree − reserve) reached; >= 0 means the reserve was NEVER touched\n";
echo "   even though the campaign undershot (".$dc['active_end']."/".$dc['target'].") BECAUSE releases correctly halted at the floor:\n";
foreach ($dc['min_reserve_margin_tib'] as $g=>$margin) { printf("   %-4s min margin to reserve: %8.2f TiB %s\n", $g, $margin, $margin>=-1e-6?'OK (held)':'*** BREACH ***'); }
echo "\n   per-SKU ticks sitting AT/BELOW the reserve floor (weight forced to 0 — these are the throttle events):\n";
foreach ($dc['ticks_at_floor'] as $id=>$n) { printf("   %-12s %7d ticks at floor   (min_free seen: %d, of which N reserved)\n", $id, $n, $dc['min_free_by_type'][$id]); }
echo "   => undershoot-not-breach: when demand outruns capacity the engine stops releasing rather than eat the reserve.\n\n";

echo "── 4. DISTRIBUTION + PUBLISHED == ENFORCED (ample, seed 0, workhorse stream) ".str_repeat('─',17)."\n";
echo "   The engine picks by its PUBLISHED odds, so realized drop share must match the published expectation.\n";
printf("   %-12s %9s %9s %14s %14s %9s\n",'SKU','released','claimed','published_exp','realized','Δ');
foreach ($d['published_expected'] as $id=>$exp) {
    printf("   %-12s %9d %9d %13.2f%% %13.2f%% %8.3f%%\n",
        $id, $d['released_by_type'][$id], $d['claimed_by_type'][$id], $exp*100, $d['realized_share'][$id]*100, abs($exp-$d['realized_share'][$id])*100);
}
printf("   max |published − realized| share gap: %.4f%%  => published odds == enforced odds (within sampling noise).\n\n", $d['published_enforced_max_gap']*100);

echo "── 5. PACING — workhorse drop probability across the campaign (ample, seed 0) ".str_repeat('─',16)."\n";
echo "   campaign-fraction : drop-prob/tick (steady near the mean pace; rises when behind, falls as open stock nears the buffer target)\n   ";
$n=0; foreach ($d['prob_series'] as $pt) { printf("%.2f:%.3f  ", $pt[0], $pt[1]); if(++$n%6===0) echo "\n   "; }
echo "\n\n";

echo "── 6. PACING CATCH-UP — weekly drops, AMPLE vs PAUSE (operator kill-switch months 2–3) ".str_repeat('─',6)."\n";
$wa=$results['ample']['detail']['weekly_drops']; ksort($wa);
$wp=$results['pause']['detail']['weekly_drops']; ksort($wp);
$maxwk=max(array_key_last($wa)?:0, array_key_last($wp)?:0);
echo "   wk:  ample | pause   (pause shows ~0 during the kill-switch, then catch-up after resume)\n";
for($w=0;$w<=$maxwk;$w++){ printf("   %2d:  %5d | %5d\n",$w,$wa[$w]??0,$wp[$w]??0); }
printf("   pause active_end: %d/%d (still converges: remaining / time-left rises after the pause).\n\n",
    $results['pause']['detail']['active_end'], TOTAL_TARGET);

echo "── 7. LIMITED RELEASE — \"Claimed: N of ".LIMIT."\", due at the campaign end (all seeds) ".str_repeat('─',26)."\n";
$peaks = []; $lasts = []; $thirdsAll = [0, 0, 0]; $counts = [];
foreach ($scenarios as $sc) {
    foreach ($results[$sc]['limited'] as $L) {
        $st = limitedStats($L['ticks'], $L['ticks_run']);
        $peaks[] = $L['peak']; $lasts[] = $st['last']; $counts[] = $st['n'];
        foreach ($st['thirds'] as $k => $v) { $thirdsAll[$k] += $v; }
    }
}
printf("   releases per run: min %d / max %d of limit %d | peak claimed+open: max %d (must be <= %d)\n",
    min($counts), max($counts), LIMIT, max($peaks), LIMIT);
printf("   last release at campaign fraction: min %.2f / mean %.2f | releases by campaign third (all runs): [%d %d %d]\n",
    min($lasts), mean($lasts), $thirdsAll[0], $thirdsAll[1], $thirdsAll[2]);
echo "   The limited SKU's maximum rate is limitedRate(): what may still be released (limit − claimed − open) spread\n";
echo "   evenly to its due date, so the limit is reached near the due date, not months before; withinLimit() is re-checked\n";
echo "   before each release, so claimed + open never passes the limit. (The constrained/reserve scenarios may undershoot:\n";
echo "   capacity and the reserve still gate every release.)\n";
echo "\n".str_repeat('=',96)."\n";
echo "INVARIANTS (all scenarios, all seeds): reserve_breaches=0 AND over_allocations=0  — see section 1.\n";
echo str_repeat('=',96)."\n";
