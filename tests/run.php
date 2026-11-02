<?php
/**
 * Purpose: exercise blacklist ingress through registered WHMCS callbacks with synthetic storage.
 * Run: php tests/run.php; optional --hook=FILE verifies a mutated hook on the testing machine.
 * Callers: contributors and the remote PHP suite runner. No WHMCS installation or API calls.
 * SPDX-License-Identifier: Apache-2.0
 */
declare(strict_types=1);

namespace WHMCS\Database {
    final class Capsule
    {
        public static array $clients = [];
        public static array $notes = [];
        public static bool $lookupFails = false;
        public static bool $noteFails = false;
        public static int $ticketWrites = 0;
        public static function table(string $name): object { return new Query($name); }
        public static function raw(string $value): string { return $value; }
    }
    final class Query
    {
        private int $clientId = 0;
        public function __construct(private string $table) {}
        public function where(string $field, mixed $value): self { $this->clientId = (int) $value; return $this; }
        public function first(array $fields): ?object
        {
            if (Capsule::$lookupFails) { throw new \RuntimeException('synthetic lookup failure'); }
            return Capsule::$clients[$this->clientId] ?? null;
        }
        public function insert(array $row): bool
        {
            if ($this->table !== 'tblticketnotes') { throw new \RuntimeException('unexpected insert'); }
            if (Capsule::$noteFails) { throw new \RuntimeException('synthetic note failure'); }
            Capsule::$notes[] = $row;
            return true;
        }
        public function update(array $row): int { Capsule::$ticketWrites++; return 1; }
    }
}

namespace {
    use WHMCS\Database\Capsule;
    $hooks = [];
    $activity = [];
    $passed = 0;
    function add_hook(string $event, int $priority, callable $callback): void
    {
        $GLOBALS['hooks'][$event][] = $callback;
    }
    function logActivity(string $message): void { $GLOBALS['activity'][] = $message; }
    function localAPI(...$args): never { throw new RuntimeException('hook must not send independently'); }
    function verify(bool $condition, string $label): void
    {
        if (!$condition) { throw new RuntimeException($label); }
    }
    function journey(string $label, string $event, array $vars, bool $expectNote,
        bool $lookupFails = false, bool $noteFails = false): void
    {
        global $hooks, $activity, $passed;
        Capsule::$notes = $activity = [];
        Capsule::$ticketWrites = 0;
        Capsule::$lookupFails = $lookupFails;
        Capsule::$noteFails = $noteFails;
        foreach ($hooks[$event] as $callback) { $callback($vars); }
        verify(Capsule::$ticketWrites === 0, "$label: ticket status/lastreply/flags must stay native");
        verify(count(Capsule::$notes) === (int) $expectNote, "$label: correct pending-note count");
        verify(count($activity) === (int) $expectNote, "$label: success activity only after recorded handoff");
        if ($expectNote) {
            $note = Capsule::$notes[0];
            verify($note['ticketid'] === $vars['ticketid'], "$label: note belongs to event ticket");
            verify(str_contains($note['message'], 'BLACKLIST HANDOFF:'), "$label: handoff, never delivery evidence");
            verify(str_contains($note['message'], 'Prior delivery not evaluated.'), "$label: no prior-delivery assumption");
            verify(str_contains($note['message'], "Event=$event"), "$label: event audit");
            verify(str_contains($note['message'], 'No customer-facing reply sent by this hook.'), "$label: no send claim");
            verify(!str_contains($note['message'], 'Ticket closed'), "$label: no closure claim");
        }
        echo 'PASS ' . ++$passed . ": $label\n";
    }

    $hookFile = dirname(__DIR__) . '/whmcs/hooks/autoclose_blacklisted_tickets.php';
    $outside = in_array('--outside', $argv, true);
    $mutationChild = in_array('--mutation-child', $argv, true);
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--hook=')) { $hookFile = substr($argument, 7); }
        elseif (in_array($argument, ['--outside', '--mutation-child'], true)) { continue; }
        else { throw new RuntimeException('unknown argument'); }
    }
    if ($outside) {
        require $hookFile;
        verify($hooks === [], 'outside WHMCS does not register callbacks');
        exit(0);
    }
    // Separate PHP processes load the whole deployed file in each real registration mode.
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    passthru($command . ' --outside --hook=' . escapeshellarg($hookFile), $outsideExit);
    verify($outsideExit === 0, 'outside-WHMCS load succeeded');
    define('ROOTDIR', dirname(__DIR__));
    define('WHMCS', true);
    require $hookFile;
    verify(array_keys($hooks) === ['TicketOpen', 'TicketUserReply'], 'both native events registered');
    Capsule::$clients = [21 => (object) ['id' => 21, 'groupid' => 9],
        22 => (object) ['id' => 22, 'groupid' => 1]];
    $open = ['ticketid' => 31, 'userid' => 21, 'status' => 'Open', 'subject' => 'ordinary request'];
    journey('new banned ticket stays Open for first notice', 'TicketOpen', $open, true);
    journey('banned reply stays Customer-Reply', 'TicketUserReply', array_replace($open, ['status' => 'Customer-Reply']), true);
    journey('existing notice reply leaves duplicate handling to processor', 'TicketUserReply', $open + ['noticeSent' => true], true);
    journey('On Hold is never overwritten', 'TicketUserReply', array_replace($open, ['status' => 'On Hold']), true);
    journey('legal/privacy request stays available for review', 'TicketOpen', array_replace($open, ['subject' => 'data subject request']), true);
    journey('ordinary client new ticket untouched', 'TicketOpen', array_replace($open, ['userid' => 22]), false);
    journey('ordinary client reply untouched', 'TicketUserReply', array_replace($open, ['userid' => 22]), false);
    journey('guest attribution untouched', 'TicketOpen', array_replace($open, ['userid' => 0]), false);
    journey('unknown account is not blacklist proof', 'TicketOpen', array_replace($open, ['userid' => 23]), false);
    journey('missing event user is not blacklist proof', 'TicketUserReply', ['ticketid' => 31], false);
    journey('missing ticket cannot create an audit note', 'TicketOpen', ['userid' => 21], false);
    journey('failed group lookup leaves ticket visible', 'TicketOpen', $open, false, true);
    journey('failed audit does not close or log success', 'TicketUserReply', $open, false, false, true);
    echo "Blacklist hook: $passed journeys passed\n";
    if (!$mutationChild) {
        // Restoring the old Closed write must fail the actual callback journey.
        $source = file_get_contents($hookFile);
        $mutated = str_replace('    if (!$ticketid) {',
            "    Capsule::table('tbltickets')->update(['status' => 'Closed']);\n    if (!\$ticketid) {", $source, $changed);
        verify($changed === 1, 'exact mutation site exists');
        $mutationFile = tempnam(sys_get_temp_dir(), 'blacklist-hook-');
        verify($mutationFile !== false && file_put_contents($mutationFile, $mutated) === strlen($mutated), 'write own mutation fixture');
        try {
            $mutationOutput = [];
            exec($command . ' --mutation-child --hook=' . escapeshellarg($mutationFile) . ' 2>&1', $mutationOutput, $mutationExit);
            verify($mutationExit !== 0 && str_contains(implode("\n", $mutationOutput), 'ticket status/lastreply/flags must stay native'),
                'restored premature Closed write is caught by the journey');
            echo "Mutation: premature closure rejected\n";
        } finally { unlink($mutationFile); }
        foreach (['blacklist-and-cap-test.php', 'loginRememberMeDefaultTest.php'] as $test) {
            passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/whmcs/hooks/tests/' . $test), $exit);
            verify($exit === 0, "$test regression passed");
        }
    }
}
