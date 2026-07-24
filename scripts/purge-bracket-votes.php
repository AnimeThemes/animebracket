<?php

/**
 * Purge tournament votes for a list of users in one bracket, recount finalized
 * tallies, and conditionally cascade corrected winners into later rounds when
 * the displaced character has zero votes there (after the purge).
 *
 * Dry-run by default. Pass --apply to write.
 *
 * Usage:
 *   php scripts/purge-bracket-votes.php --bracket=<id|perma> --users=12,34,56
 *   php scripts/purge-bracket-votes.php --bracket=<id|perma> --users=12,34,56 --apply
 *
 * From the compose web container (DB_HOST=db required for CLI):
 *   docker compose exec -e DB_HOST=db web php scripts/purge-bracket-votes.php --bracket=<id|perma> --users=12,34,56
 */

require_once __DIR__ . '/../app-config.php';
chdir(CORE_LOCATION);
require_once 'config.php';
require_once './lib/aal.php';

Lib\Cache::getInstance()->setDisabled(true);

$options = getopt('', [ 'bracket:', 'users:', 'apply' ]);
$apply = array_key_exists('apply', $options);

if (empty($options['bracket']) || empty($options['users'])) {
    fwrite(STDERR, "Usage: php scripts/purge-bracket-votes.php --bracket=<id|perma> --users=12,34,56 [--apply]\n");
    exit(1);
}

$userIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $options['users'])))));
if (count($userIds) === 0) {
    fwrite(STDERR, "Error: --users must be a comma-separated list of numeric user_id values.\n");
    exit(1);
}

$bracket = resolveBracket($options['bracket']);
if (!$bracket) {
    fwrite(STDERR, "Error: bracket not found for " . $options['bracket'] . "\n");
    exit(1);
}

if ((int) $bracket->state !== BS_VOTING) {
    fwrite(STDERR, "Error: bracket must be in voting state (BS_VOTING). Current state=" . $bracket->state . "\n");
    exit(1);
}

$users = resolveUsers($userIds);
if ($users === null) {
    exit(1);
}

echo $apply ? "MODE: APPLY\n" : "MODE: DRY-RUN (pass --apply to write)\n";
echo "Bracket: {$bracket->name} (id={$bracket->id}, perma={$bracket->perma})\n";
echo 'Users: ' . implode(', ', array_map(function ($user) {
    return $user->id . ' (/u/' . $user->name . ')';
}, $users)) . "\n\n";

$report = reportVotes($bracket->id, $userIds);
printVoteReport($report);

$userPlaceholders = buildInPlaceholders($userIds, 'user');
$userParams = buildInParams($userIds, 'user');

$deleteSql = 'DELETE v FROM votes v'
    . ' INNER JOIN round r ON r.round_id = v.round_id'
    . ' WHERE v.bracket_id = :bracketId'
    . ' AND v.user_id IN (' . $userPlaceholders . ')'
    . ' AND r.round_tier >= 1';

$deleteCountSql = 'SELECT COUNT(1) AS total FROM votes v'
    . ' INNER JOIN round r ON r.round_id = v.round_id'
    . ' WHERE v.bracket_id = :bracketId'
    . ' AND v.user_id IN (' . $userPlaceholders . ')'
    . ' AND r.round_tier >= 1';

$deleteParams = array_merge([ ':bracketId' => $bracket->id ], $userParams);
$pendingDelete = (int) fetchScalar($deleteCountSql, $deleteParams);
echo "Tournament votes to delete: {$pendingDelete}\n\n";

// Cascades and recounts use post-purge vote tallies. In dry-run, exclude these users.
$excludeUserIds = $apply ? [] : $userIds;

if ($apply) {
    $deleted = Lib\Db::Query($deleteSql, $deleteParams);
    if ($deleted === false) {
        fwrite(STDERR, "Error: failed to delete votes.\n");
        exit(1);
    }
    echo "Deleted vote rows: {$deleted}\n\n";
    $excludeUserIds = [];
}

$rounds = loadTournamentRounds($bracket->id);
$slotMap = [];
foreach ($rounds as $round) {
    $slotMap[(int) $round->id] = [
        (int) $round->character1Id,
        (int) $round->character2Id,
    ];
}

$cascadeActions = planCascades($rounds, $slotMap, $excludeUserIds);
printCascadeReport($cascadeActions);

if ($apply) {
    foreach ($cascadeActions as $action) {
        if ($action['type'] !== 'cascade') {
            continue;
        }
        $round = $rounds[$action['nextRoundId']];
        if ($action['slot'] === 1) {
            $round->character1Id = $action['expectedCharacterId'];
        } else {
            $round->character2Id = $action['expectedCharacterId'];
        }
        $round->sync();
        echo "Cascaded round {$round->id}: entrant {$action['slot']} "
            . formatEntrantLabel($action['actualCharacterId'])
            . ' -> '
            . formatEntrantLabel($action['expectedCharacterId'])
            . "\n";
    }
    if (count(array_filter($cascadeActions, function ($action) {
        return $action['type'] === 'cascade';
    })) > 0) {
        echo "\n";
    }
}

$recountActions = planRecounts($rounds, $slotMap, $excludeUserIds);
printRecountReport($recountActions);
printOpenRoundsReport($rounds, $slotMap);

if ($apply) {
    foreach ($recountActions as $action) {
        $round = $rounds[$action['roundId']];
        $round->character1Id = $slotMap[(int) $round->id][0];
        $round->character2Id = $slotMap[(int) $round->id][1];
        $round->character1Votes = $action['character1Votes'];
        $round->character2Votes = $action['character2Votes'];
        $round->sync();
    }
    echo 'Updated finalized tallies on ' . count($recountActions) . " round(s).\n\n";
    bustCaches($bracket, $users);
    echo "Cache refresh complete.\n";
    echo "Done.\n";
} else {
    echo "Dry-run complete. Re-run with --apply to write changes.\n";
}

/**
 * @param string|int $bracketArg
 * @return Api\Bracket|null
 */
function resolveBracket($bracketArg) {
    if (is_numeric($bracketArg)) {
        $bracket = Api\Bracket::getById((int) $bracketArg);
        if ($bracket && (int) $bracket->id > 0) {
            return $bracket;
        }
    }
    return Api\Bracket::getBracketByPerma((string) $bracketArg, true) ?: null;
}

/**
 * @param int[] $userIds
 * @return Api\User[]|null
 */
function resolveUsers(array $userIds) {
    $users = [];
    foreach ($userIds as $userId) {
        $user = Api\User::getById($userId);
        if (!$user || !(int) $user->id) {
            fwrite(STDERR, "Error: user_id {$userId} not found.\n");
            return null;
        }
        $users[] = $user;
    }
    return $users;
}

/**
 * @param int $bracketId
 * @param int[] $userIds
 * @return object
 */
function reportVotes($bracketId, array $userIds) {
    $placeholders = buildInPlaceholders($userIds, 'user');
    $params = array_merge([ ':bracketId' => $bracketId ], buildInParams($userIds, 'user'));

    $sql = 'SELECT'
        . ' SUM(CASE WHEN r.round_tier = 0 THEN 1 ELSE 0 END) AS elim_total,'
        . ' SUM(CASE WHEN r.round_tier >= 1 THEN 1 ELSE 0 END) AS tournament_total'
        . ' FROM votes v'
        . ' INNER JOIN round r ON r.round_id = v.round_id'
        . ' WHERE v.bracket_id = :bracketId'
        . ' AND v.user_id IN (' . $placeholders . ')';

    $row = null;
    $result = Lib\Db::Query($sql, $params);
    if ($result && $result->count) {
        $row = Lib\Db::Fetch($result);
    }

    return (object) [
        'elimTotal' => (int) ($row->elim_total ?? 0),
        'tournamentTotal' => (int) ($row->tournament_total ?? 0),
    ];
}

/**
 * @param object $report
 */
function printVoteReport($report) {
    if ($report->elimTotal > 0) {
        echo "Note: listed users also have {$report->elimTotal} elimination vote(s); those are left untouched.\n\n";
    }
}

/**
 * @param int $bracketId
 * @return array<int, Api\Round> keyed by round id
 */
function loadTournamentRounds($bracketId) {
    $rounds = Api\Round::queryReturnAll([
        'bracketId' => $bracketId,
        'tier' => [ 'gt' => 0 ],
        'deleted' => 0,
    ], [
        'tier' => 'asc',
        'group' => 'asc',
        'order' => 'asc',
    ]) ?: [];

    $byId = [];
    foreach ($rounds as $round) {
        $byId[(int) $round->id] = $round;
    }
    return $byId;
}

/**
 * @param array<int, Api\Round> $rounds
 * @param array<int, array{0:int,1:int}> $slotMap
 * @param int[] $excludeUserIds
 * @return array<int, array>
 */
function planCascades(array $rounds, array &$slotMap, array $excludeUserIds) {
    $actions = [];
    $roundsByTier = [];
    foreach ($rounds as $round) {
        if (!empty($round->isThirdPlaceMatch)) {
            continue;
        }
        $tier = (int) $round->tier;
        if (!isset($roundsByTier[$tier])) {
            $roundsByTier[$tier] = [];
        }
        $roundsByTier[$tier][] = $round;
    }

    $tiers = array_keys($roundsByTier);
    sort($tiers, SORT_NUMERIC);

    foreach ($tiers as $tier) {
        foreach ($roundsByTier[$tier] as $feeder) {
            if ((int) $feeder->final !== 1) {
                continue;
            }

            $slots = $slotMap[(int) $feeder->id];
            $expectedWinner = computeWinnerId(
                $feeder->id,
                $slots[0],
                $slots[1],
                $excludeUserIds
            );
            $expectedLoser = computeLoserId($slots[0], $slots[1], $expectedWinner);
            if (!$expectedWinner) {
                continue;
            }

            $nextTier = $tier + 1;

            foreach ($rounds as $nextRound) {
                if ((int) $nextRound->tier !== $nextTier) {
                    continue;
                }

                $nextSlots = $slotMap[(int) $nextRound->id];
                $feederCharacterIds = array_values(array_filter($slots, function ($characterId) {
                    return (int) $characterId > 1;
                }));

                foreach ([ 1 => $nextSlots[0], 2 => $nextSlots[1] ] as $slotNumber => $actualCharacterId) {
                    if (!in_array((int) $actualCharacterId, $feederCharacterIds, true)) {
                        continue;
                    }

                    $isThird = !empty($nextRound->isThirdPlaceMatch);
                    $expectedCharacterId = $isThird ? $expectedLoser : $expectedWinner;
                    if (!$expectedCharacterId) {
                        continue;
                    }

                    if ((int) $actualCharacterId === (int) $expectedCharacterId) {
                        continue;
                    }

                    $votesForActual = countCharacterVotes(
                        (int) $nextRound->id,
                        (int) $actualCharacterId,
                        $excludeUserIds
                    );

                    $action = [
                        'type' => $votesForActual === 0 ? 'cascade' : 'accept',
                        'feederRoundId' => (int) $feeder->id,
                        'feederTier' => (int) $feeder->tier,
                        'nextRoundId' => (int) $nextRound->id,
                        'nextTier' => (int) $nextRound->tier,
                        'slot' => $slotNumber,
                        'actualCharacterId' => (int) $actualCharacterId,
                        'expectedCharacterId' => (int) $expectedCharacterId,
                        'votesForActual' => $votesForActual,
                        'isThirdPlaceMatch' => $isThird,
                    ];
                    $actions[] = $action;

                    if ($action['type'] === 'cascade') {
                        $slotMap[(int) $nextRound->id][$slotNumber - 1] = (int) $expectedCharacterId;
                    }
                }
            }
        }
    }

    return $actions;
}

/**
 * @param array $actions
 */
function printCascadeReport(array $actions) {
    echo "Cascade plan:\n";
    if (count($actions) === 0) {
        echo "  (no mismatches between feeder winners and later-round entrants)\n\n";
        return;
    }

    foreach ($actions as $action) {
        $label = $action['type'] === 'cascade' ? 'CASCADE' : 'NO CASCADE';
        $third = $action['isThirdPlaceMatch'] ? ' [third-place]' : '';
        $line = "  {$label}: feeder round {$action['feederRoundId']}"
            . " (tier {$action['feederTier']})"
            . " -> next round {$action['nextRoundId']}"
            . " (tier {$action['nextTier']}){$third}"
            . " entrant {$action['slot']}:"
            . ' ' . formatEntrantLabel($action['actualCharacterId']);
        if ($action['type'] === 'cascade') {
            $line .= ' => ' . formatEntrantLabel($action['expectedCharacterId']);
        }
        echo $line . "\n";
    }
    echo "\n";
}

/**
 * @param array<int, Api\Round> $rounds
 * @param array<int, array{0:int,1:int}> $slotMap
 * @param int[] $excludeUserIds
 * @return array<int, array>
 */
function planRecounts(array $rounds, array $slotMap, array $excludeUserIds) {
    $actions = [];
    foreach ($rounds as $round) {
        if ((int) $round->final !== 1) {
            continue;
        }
        $slots = $slotMap[(int) $round->id];
        $character1Votes = countCharacterVotes((int) $round->id, $slots[0], $excludeUserIds);
        $character2Votes = countCharacterVotes((int) $round->id, $slots[1], $excludeUserIds);
        $actions[] = [
            'roundId' => (int) $round->id,
            'tier' => (int) $round->tier,
            'character1Id' => $slots[0],
            'character2Id' => $slots[1],
            'character1Votes' => $character1Votes,
            'character2Votes' => $character2Votes,
            'previousCharacter1Votes' => $round->character1Votes,
            'previousCharacter2Votes' => $round->character2Votes,
        ];
    }
    return $actions;
}

/**
 * @param array $actions
 */
function printRecountReport(array $actions) {
    echo "Finalized tally recount:\n";
    if (count($actions) === 0) {
        echo "  (no finalized tournament rounds)\n\n";
        return;
    }
    foreach ($actions as $action) {
        $changed = ((int) $action['previousCharacter1Votes'] !== (int) $action['character1Votes'])
            || ((int) $action['previousCharacter2Votes'] !== (int) $action['character2Votes']);
        $flag = $changed ? ' *' : '';
        echo "  round {$action['roundId']} (tier {$action['tier']}):"
            . ' ' . formatEntrantLabel($action['character1Id'])
            . " {$action['previousCharacter1Votes']}->{$action['character1Votes']},"
            . ' ' . formatEntrantLabel($action['character2Id'])
            . " {$action['previousCharacter2Votes']}->{$action['character2Votes']}"
            . "{$flag}\n";
    }
    echo "\n";
}

/**
 * @param array<int, Api\Round> $rounds
 * @param array<int, array{0:int,1:int}> $slotMap
 */
function printOpenRoundsReport(array $rounds, array $slotMap) {
    echo "Open (non-finalized) matchups:\n";
    $any = false;
    foreach ($rounds as $round) {
        if ((int) $round->final === 1) {
            continue;
        }
        $any = true;
        $slots = $slotMap[(int) $round->id];
        $changed = ((int) $slots[0] !== (int) $round->character1Id)
            || ((int) $slots[1] !== (int) $round->character2Id);
        $flag = $changed ? ' *' : '';
        echo "  round {$round->id} (tier {$round->tier}):"
            . ' ' . formatEntrantLabel($slots[0])
            . ' vs '
            . formatEntrantLabel($slots[1])
            . "{$flag}\n";
    }
    if (!$any) {
        echo "  (none)\n";
    }
    echo "\n";
}

/**
 * @param int $roundId
 * @param int $character1Id
 * @param int $character2Id
 * @param int[] $excludeUserIds
 * @return int|null
 */
function computeWinnerId($roundId, $character1Id, $character2Id, array $excludeUserIds) {
    $character1Id = (int) $character1Id;
    $character2Id = (int) $character2Id;

    if ($character2Id === 1) {
        return $character1Id ?: null;
    }
    if ($character1Id <= 0 || $character2Id <= 0) {
        return null;
    }

    $votes1 = countCharacterVotes($roundId, $character1Id, $excludeUserIds);
    $votes2 = countCharacterVotes($roundId, $character2Id, $excludeUserIds);

    if ($votes1 > $votes2) {
        return $character1Id;
    }
    if ($votes2 > $votes1) {
        return $character2Id;
    }

    $seed1 = getCharacterSeed($character1Id);
    $seed2 = getCharacterSeed($character2Id);
    if ($seed1 < $seed2) {
        return $character1Id;
    }
    return $character2Id;
}

/**
 * @param int $character1Id
 * @param int $character2Id
 * @param int|null $winnerId
 * @return int|null
 */
function computeLoserId($character1Id, $character2Id, $winnerId) {
    if (!$winnerId || (int) $character2Id === 1) {
        return null;
    }
    return (int) $winnerId === (int) $character1Id
        ? (int) $character2Id
        : (int) $character1Id;
}

/**
 * @param int $characterId
 * @return string
 */
function formatEntrantLabel($characterId) {
    $characterId = (int) $characterId;
    if ($characterId === 1) {
        return 'bye';
    }
    $seed = getCharacterSeed($characterId);
    if ($seed === PHP_INT_MAX) {
        return 'seed ?';
    }
    return 'seed ' . $seed;
}

/**
 * @param int $characterId
 * @return int
 */
function getCharacterSeed($characterId) {
    static $seeds = [];
    $characterId = (int) $characterId;
    if (!array_key_exists($characterId, $seeds)) {
        $character = Api\Character::getById($characterId);
        $seeds[$characterId] = $character && $character->seed !== null
            ? (int) $character->seed
            : PHP_INT_MAX;
    }
    return $seeds[$characterId];
}

/**
 * @param int $roundId
 * @param int $characterId
 * @param int[] $excludeUserIds
 * @return int
 */
function countCharacterVotes($roundId, $characterId, array $excludeUserIds) {
    $characterId = (int) $characterId;
    if ($characterId <= 0) {
        return 0;
    }

    $params = [
        ':roundId' => (int) $roundId,
        ':characterId' => $characterId,
    ];
    $sql = 'SELECT COUNT(1) AS total FROM votes WHERE round_id = :roundId AND character_id = :characterId';
    if (count($excludeUserIds) > 0) {
        $sql .= ' AND user_id NOT IN (' . buildInPlaceholders($excludeUserIds, 'ex') . ')';
        $params = array_merge($params, buildInParams($excludeUserIds, 'ex'));
    }

    return (int) fetchScalar($sql, $params);
}

/**
 * @param string $sql
 * @param array $params
 * @return mixed
 */
function fetchScalar($sql, array $params) {
    $result = Lib\Db::Query($sql, $params);
    if (!$result || !$result->count) {
        return 0;
    }
    $row = Lib\Db::Fetch($result);
    if (isset($row->total)) {
        return $row->total;
    }
    $values = array_values((array) $row);
    return $values[0] ?? 0;
}

/**
 * @param int[] $ids
 * @param string $prefix
 * @return string
 */
function buildInPlaceholders(array $ids, $prefix) {
    $parts = [];
    foreach (array_values($ids) as $index => $id) {
        $parts[] = ':' . $prefix . $index;
    }
    return implode(',', $parts);
}

/**
 * @param int[] $ids
 * @param string $prefix
 * @return array
 */
function buildInParams(array $ids, $prefix) {
    $params = [];
    foreach (array_values($ids) as $index => $id) {
        $params[':' . $prefix . $index] = (int) $id;
    }
    return $params;
}

/**
 * @param Api\Bracket $bracket
 * @param Api\User[] $users
 */
function bustCaches(Api\Bracket $bracket, array $users) {
    $bracket->getResults(true);
    Api\Round::getCurrentRounds($bracket->id, true);
    Lib\Cache::getInstance()->set('Api:Round:getVotingStates_' . $bracket->id, false, 1);

    foreach ($users as $user) {
        $bracket->getVotesForUser($user, true);
    }

    Api\Stats::getEntrantPerformanceStats($bracket, true);
}
