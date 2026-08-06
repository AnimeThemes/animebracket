<?php

/**
 * Purge tournament votes for a list of users in one bracket, recount finalized
 * tallies, and conditionally cascade corrected winners into later rounds when
 * the displaced character has zero votes there (after the purge).
 *
 * Optional --tier / --group limit which votes are deleted and reported;
 * cascade and recount still walk the whole bracket.
 *
 * Before delete, matchups whose winner would flip into a next-round entrant
 * that still has votes (after later-round purges) are protected and skipped.
 *
 * Dry-run by default. Pass --apply to write.
 *
 * Usage:
 *   php scripts/purge-bracket-votes.php --bracket=<id|perma> --users=12,34,56
 *   php scripts/purge-bracket-votes.php --bracket=<id|perma> --tier=2 --users=12,34,56
 *   php scripts/purge-bracket-votes.php --bracket=<id|perma> --tier=2 --group=0 --users=12,34,56
 *   php scripts/purge-bracket-votes.php --bracket=<id|perma> --tier=2 --group=0 --users=12,34,56 --apply
 *
 * From the compose web container (DB_HOST=db required for CLI):
 *   docker compose exec -e DB_HOST=db web php scripts/purge-bracket-votes.php --bracket=<id|perma> --users=12,34,56
 */

require_once __DIR__ . '/../app-config.php';
chdir(CORE_LOCATION);
require_once 'config.php';
require_once './lib/aal.php';

Lib\Cache::getInstance()->setDisabled(true);

$options = getopt('', [ 'bracket:', 'tier:', 'group:', 'users:', 'apply' ]);
$apply = array_key_exists('apply', $options);

if (empty($options['bracket']) || empty($options['users'])) {
    fwrite(STDERR, "Usage: php scripts/purge-bracket-votes.php --bracket=<id|perma> [--tier=N] [--group=N] --users=12,34,56 [--apply]\n");
    exit(1);
}

$userIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $options['users'])))));
if (count($userIds) === 0) {
    fwrite(STDERR, "Error: --users must be a comma-separated list of numeric user_id values.\n");
    exit(1);
}

$deleteScope = parseDeleteScope($options);

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
echo 'Delete scope: ' . formatDeleteScope($deleteScope) . "\n";
echo 'Users: ' . implode(', ', array_map(function ($user) {
    return $user->id . ' (u/' . $user->name . ')';
}, $users)) . "\n\n";

$rounds = loadTournamentRounds($bracket->id);
$slotMap = [];
foreach ($rounds as $round) {
    $slotMap[(int) $round->id] = [
        (int) $round->character1Id,
        (int) $round->character2Id,
    ];
}

$voteMaps = loadVoteCountMaps($bracket->id, $userIds);

$feederRoundIdsWithUserVotes = loadFeederRoundIdsWithUserVotes($bracket->id, $userIds, $deleteScope);
$protectedRoundIds = planProtectedFeeders(
    $rounds,
    $slotMap,
    $userIds,
    $deleteScope,
    $feederRoundIdsWithUserVotes,
    $voteMaps
);
printProtectionReport($protectedRoundIds, $rounds);

$deleteQuery = buildDeleteQuery($bracket->id, $userIds, $deleteScope, $protectedRoundIds);
$pendingDelete = (int) fetchScalar($deleteQuery['countSql'], $deleteQuery['params']);
echo "Tournament votes to delete: {$pendingDelete}\n\n";

// Cascades and recounts use post-purge vote tallies. In dry-run, exclude these users
// on rounds that would actually be deleted.
$excludeUserIdsByRound = [];

if ($apply) {
    $deleted = Lib\Db::Query($deleteQuery['deleteSql'], $deleteQuery['params']);
    if ($deleted === false) {
        fwrite(STDERR, "Error: failed to delete votes.\n");
        exit(1);
    }
    echo "Deleted vote rows: {$deleted}\n\n";
    $voteMaps = loadVoteCountMaps($bracket->id, []);
} else {
    $excludeUserIdsByRound = buildExcludeUserIdsByRound(
        $rounds,
        $userIds,
        $deleteScope,
        $protectedRoundIds
    );
}

$cascadeActions = planCascades($rounds, $slotMap, $excludeUserIdsByRound, $voteMaps);
$recountActions = planRecounts($rounds, $slotMap, $excludeUserIdsByRound, $voteMaps);
printRecountReport($recountActions);
printOpenRoundsReport($rounds, $slotMap);

if ($apply) {
    foreach ($cascadeActions as $action) {
        $round = $rounds[$action['nextRoundId']];
        if ($action['slot'] === 1) {
            $round->character1Id = $action['expectedCharacterId'];
        } else {
            $round->character2Id = $action['expectedCharacterId'];
        }
        $round->sync();
    }

    $syncedCount = 0;
    foreach ($recountActions as $action) {
        if (!recountNeedsSync($action)) {
            continue;
        }
        $round = $rounds[$action['roundId']];
        $round->character1Id = $slotMap[(int) $round->id][0];
        $round->character2Id = $slotMap[(int) $round->id][1];
        $round->character1Votes = $action['character1Votes'];
        $round->character2Votes = $action['character2Votes'];
        $round->sync();
        $syncedCount++;
    }
    echo "Updated finalized tallies on {$syncedCount} round(s).\n\n";
    bustCaches($bracket, $users);
    echo "Cache refresh complete.\n";
    echo "Done.\n";
} else {
    echo "Dry-run complete. Re-run with --apply to write changes.\n";
}

/**
 * @param array $options
 * @return array{tier:int|null,group:int|null}
 */
function parseDeleteScope(array $options) {
    $scope = [
        'tier' => null,
        'group' => null,
    ];

    if (array_key_exists('tier', $options)) {
        if (!is_numeric($options['tier'])) {
            fwrite(STDERR, "Error: --tier must be numeric.\n");
            exit(1);
        }
        $scope['tier'] = (int) $options['tier'];
        if ($scope['tier'] < 1) {
            fwrite(STDERR, "Error: --tier must be >= 1 (eliminations are never deleted).\n");
            exit(1);
        }
    }

    if (array_key_exists('group', $options)) {
        if (!is_numeric($options['group'])) {
            fwrite(STDERR, "Error: --group must be numeric.\n");
            exit(1);
        }
        if ($scope['tier'] === null) {
            fwrite(STDERR, "Error: --group requires --tier.\n");
            exit(1);
        }
        $scope['group'] = (int) $options['group'];
        if ($scope['group'] < 0) {
            fwrite(STDERR, "Error: --group must be >= 0.\n");
            exit(1);
        }
    }

    return $scope;
}

/**
 * @param array{tier:int|null,group:int|null} $deleteScope
 * @return string
 */
function formatDeleteScope(array $deleteScope) {
    if ($deleteScope['tier'] === null) {
        return 'all tournament tiers';
    }
    if ($deleteScope['group'] === null) {
        return 'tier ' . $deleteScope['tier'];
    }
    return 'tier ' . $deleteScope['tier'] . ', group ' . $deleteScope['group'];
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
 * @param int $bracketId
 * @param int[] $userIds
 * @param array{tier:int|null,group:int|null} $deleteScope
 * @return int[]
 */
function loadFeederRoundIdsWithUserVotes($bracketId, array $userIds, array $deleteScope) {
    $placeholders = buildInPlaceholders($userIds, 'user');
    $params = array_merge([ ':bracketId' => $bracketId ], buildInParams($userIds, 'user'));

    $sql = 'SELECT DISTINCT r.round_id AS round_id FROM votes v'
        . ' INNER JOIN round r ON r.round_id = v.round_id'
        . ' WHERE v.bracket_id = :bracketId'
        . ' AND v.user_id IN (' . $placeholders . ')'
        . ' AND r.round_tier >= 1'
        . buildDeleteScopeSql('r', $deleteScope, $params);

    $result = Lib\Db::Query($sql, $params);
    if (!$result || !$result->count) {
        return [];
    }

    $roundIds = [];
    while ($row = Lib\Db::Fetch($result)) {
        $roundIds[] = (int) $row->round_id;
    }
    return $roundIds;
}

/**
 * Latest tier to earliest: protect feeders whose winner would flip into a
 * next-round entrant that still has votes after later-round purges.
 *
 * @param array<int, Api\Round> $rounds
 * @param array<int, array{0:int,1:int}> $slotMap
 * @param int[] $userIds
 * @param array{tier:int|null,group:int|null} $deleteScope
 * @param int[] $feederRoundIdsWithUserVotes
 * @param array{full:array,excluding:array} $voteMaps
 * @return int[]
 */
function planProtectedFeeders(
    array $rounds,
    array $slotMap,
    array $userIds,
    array $deleteScope,
    array $feederRoundIdsWithUserVotes,
    array $voteMaps
) {
    if (count($feederRoundIdsWithUserVotes) === 0) {
        return [];
    }

    $candidateIds = array_fill_keys($feederRoundIdsWithUserVotes, true);
    $protectedLookup = [];
    $roundsByTier = groupRoundsByTier($rounds, false);
    $tiers = array_keys($roundsByTier);
    rsort($tiers, SORT_NUMERIC);

    foreach ($tiers as $tier) {
        foreach ($roundsByTier[$tier] as $feeder) {
            $feederId = (int) $feeder->id;
            if (!isset($candidateIds[$feederId]) || (int) $feeder->final !== 1) {
                continue;
            }

            $slots = $slotMap[$feederId];
            $excludeUserIds = usersToExcludeForRound(
                $feederId,
                $userIds,
                $deleteScope,
                $protectedLookup,
                $rounds
            );
            $simulatedWinner = computeWinnerId(
                $feederId,
                $slots[0],
                $slots[1],
                $excludeUserIds,
                $voteMaps
            );
            if (!$simulatedWinner) {
                continue;
            }

            $simulatedLoser = computeLoserId($slots[0], $slots[1], $simulatedWinner);
            foreach (findFeederAdvancementMismatches($feeder, $rounds, $slotMap, $simulatedWinner, $simulatedLoser) as $mismatch) {
                $nextRoundExcludeUserIds = usersToExcludeForRound(
                    $mismatch['nextRoundId'],
                    $userIds,
                    $deleteScope,
                    $protectedLookup,
                    $rounds
                );
                $votesForActual = countCharacterVotesFromMap(
                    $voteMaps,
                    $mismatch['nextRoundId'],
                    $mismatch['actualCharacterId'],
                    $nextRoundExcludeUserIds
                );

                if ($votesForActual > 0) {
                    $protectedLookup[$feederId] = true;
                    break;
                }
            }
        }
    }

    return array_map('intval', array_keys($protectedLookup));
}

/**
 * @param int[] $protectedRoundIds
 * @param array<int, Api\Round> $rounds
 */
function printProtectionReport(array $protectedRoundIds, array $rounds) {
    echo "Protected matchups (delete skipped; winner flip would displace a voted-on next-round entrant):\n";
    if (count($protectedRoundIds) === 0) {
        echo "  (none)\n\n";
        return;
    }

    sort($protectedRoundIds, SORT_NUMERIC);
    foreach ($protectedRoundIds as $roundId) {
        $round = $rounds[$roundId];
        echo "  round {$roundId} (tier {$round->tier}, group {$round->group}):"
            . ' ' . formatEntrantLabel($round->character1Id)
            . ' vs '
            . formatEntrantLabel($round->character2Id)
            . "\n";
    }
    echo "\n";
}

/**
 * @param int $bracketId
 * @param int[] $userIds
 * @param array{tier:int|null,group:int|null} $deleteScope
 * @param int[] $protectedRoundIds
 * @return array{deleteSql:string,countSql:string,params:array}
 */
function buildDeleteQuery($bracketId, array $userIds, array $deleteScope, array $protectedRoundIds) {
    $userPlaceholders = buildInPlaceholders($userIds, 'user');
    $params = array_merge([ ':bracketId' => (int) $bracketId ], buildInParams($userIds, 'user'));

    $where = ' WHERE v.bracket_id = :bracketId'
        . ' AND v.user_id IN (' . $userPlaceholders . ')'
        . ' AND r.round_tier >= 1'
        . buildDeleteScopeSql('r', $deleteScope, $params);

    if (count($protectedRoundIds) > 0) {
        $where .= ' AND r.round_id NOT IN (' . buildInPlaceholders($protectedRoundIds, 'prot') . ')';
        $params = array_merge($params, buildInParams($protectedRoundIds, 'prot'));
    }

    return [
        'deleteSql' => 'DELETE v FROM votes v INNER JOIN round r ON r.round_id = v.round_id' . $where,
        'countSql' => 'SELECT COUNT(1) AS total FROM votes v INNER JOIN round r ON r.round_id = v.round_id' . $where,
        'params' => $params,
    ];
}

/**
 * @param array<int, Api\Round> $rounds
 * @param int[] $userIds
 * @param array{tier:int|null,group:int|null} $deleteScope
 * @param int[] $protectedRoundIds
 * @return array<int, int[]>
 */
function buildExcludeUserIdsByRound(array $rounds, array $userIds, array $deleteScope, array $protectedRoundIds) {
    $protectedLookup = array_fill_keys($protectedRoundIds, true);
    $excludeByRound = [];

    foreach ($rounds as $round) {
        $roundId = (int) $round->id;
        $exclude = usersToExcludeForRound($roundId, $userIds, $deleteScope, $protectedLookup, $rounds);
        if (count($exclude) > 0) {
            $excludeByRound[$roundId] = $exclude;
        }
    }

    return $excludeByRound;
}

/**
 * Users whose votes should be treated as deleted when simulating this round.
 *
 * @param int $roundId
 * @param int[] $userIds
 * @param array{tier:int|null,group:int|null} $deleteScope
 * @param array<int, bool> $protectedLookup
 * @param array<int, Api\Round> $rounds
 * @return int[]
 */
function usersToExcludeForRound($roundId, array $userIds, array $deleteScope, array $protectedLookup, array $rounds) {
    if (isset($protectedLookup[$roundId]) || !isset($rounds[$roundId])) {
        return [];
    }
    if (!roundMatchesDeleteScope($rounds[$roundId], $deleteScope)) {
        return [];
    }
    return $userIds;
}

/**
 * @param Api\Round $round
 * @param array{tier:int|null,group:int|null} $deleteScope
 * @return bool
 */
function roundMatchesDeleteScope(Api\Round $round, array $deleteScope) {
    if ($deleteScope['tier'] === null) {
        return true;
    }
    if ((int) $round->tier !== $deleteScope['tier']) {
        return false;
    }
    if ($deleteScope['group'] === null) {
        return true;
    }
    return (int) $round->group === $deleteScope['group'];
}

/**
 * @param string $tableAlias
 * @param array{tier:int|null,group:int|null} $deleteScope
 * @param array $params
 * @return string
 */
function buildDeleteScopeSql($tableAlias, array $deleteScope, array &$params) {
    if ($deleteScope['tier'] === null) {
        return '';
    }

    $sql = ' AND ' . $tableAlias . '.round_tier = :scopeTier';
    $params[':scopeTier'] = $deleteScope['tier'];

    if ($deleteScope['group'] !== null) {
        $sql .= ' AND ' . $tableAlias . '.round_group = :scopeGroup';
        $params[':scopeGroup'] = $deleteScope['group'];
    }

    return $sql;
}

/**
 * @param array<int, Api\Round> $rounds
 * @param bool $skipThirdPlace
 * @return array<int, Api\Round[]>
 */
function groupRoundsByTier(array $rounds, $skipThirdPlace) {
    $roundsByTier = [];
    foreach ($rounds as $round) {
        if ($skipThirdPlace && !empty($round->isThirdPlaceMatch)) {
            continue;
        }
        $tier = (int) $round->tier;
        if (!isset($roundsByTier[$tier])) {
            $roundsByTier[$tier] = [];
        }
        $roundsByTier[$tier][] = $round;
    }
    return $roundsByTier;
}

/**
 * Next-tier slots currently occupied by a feeder entrant that should be someone else.
 *
 * @param Api\Round $feeder
 * @param array<int, Api\Round> $rounds
 * @param array<int, array{0:int,1:int}> $slotMap
 * @param int $expectedWinner
 * @param int|null $expectedLoser
 * @return array<int, array{nextRoundId:int,nextTier:int,slot:int,actualCharacterId:int,expectedCharacterId:int,isThirdPlaceMatch:bool}>
 */
function findFeederAdvancementMismatches(
    Api\Round $feeder,
    array $rounds,
    array $slotMap,
    $expectedWinner,
    $expectedLoser
) {
    $slots = $slotMap[(int) $feeder->id];
    $feederCharacterIds = array_values(array_filter($slots, function ($characterId) {
        return (int) $characterId > 1;
    }));
    $nextTier = (int) $feeder->tier + 1;
    $mismatches = [];

    foreach ($rounds as $nextRound) {
        if ((int) $nextRound->tier !== $nextTier) {
            continue;
        }

        $nextRoundId = (int) $nextRound->id;
        $nextSlots = $slotMap[$nextRoundId];

        foreach ([ 1 => $nextSlots[0], 2 => $nextSlots[1] ] as $slotNumber => $actualCharacterId) {
            if (!in_array((int) $actualCharacterId, $feederCharacterIds, true)) {
                continue;
            }

            $isThird = !empty($nextRound->isThirdPlaceMatch);
            $expectedCharacterId = $isThird ? $expectedLoser : $expectedWinner;
            if (!$expectedCharacterId || (int) $actualCharacterId === (int) $expectedCharacterId) {
                continue;
            }

            $mismatches[] = [
                'nextRoundId' => $nextRoundId,
                'nextTier' => (int) $nextRound->tier,
                'slot' => $slotNumber,
                'actualCharacterId' => (int) $actualCharacterId,
                'expectedCharacterId' => (int) $expectedCharacterId,
                'isThirdPlaceMatch' => $isThird,
            ];
        }
    }

    return $mismatches;
}

/**
 * @param array<int, Api\Round> $rounds
 * @param array<int, array{0:int,1:int}> $slotMap
 * @param array<int, int[]> $excludeUserIdsByRound
 * @param array{full:array,excluding:array} $voteMaps
 * @return array<int, array>
 */
function planCascades(array $rounds, array &$slotMap, array $excludeUserIdsByRound, array $voteMaps) {
    $actions = [];
    $roundsByTier = groupRoundsByTier($rounds, true);
    $tiers = array_keys($roundsByTier);
    sort($tiers, SORT_NUMERIC);

    foreach ($tiers as $tier) {
        foreach ($roundsByTier[$tier] as $feeder) {
            if ((int) $feeder->final !== 1) {
                continue;
            }

            $feederId = (int) $feeder->id;
            $slots = $slotMap[$feederId];
            $feederExcludeUserIds = $excludeUserIdsByRound[$feederId] ?? [];
            $expectedWinner = computeWinnerId(
                $feederId,
                $slots[0],
                $slots[1],
                $feederExcludeUserIds,
                $voteMaps
            );
            if (!$expectedWinner) {
                continue;
            }

            $expectedLoser = computeLoserId($slots[0], $slots[1], $expectedWinner);
            foreach (findFeederAdvancementMismatches($feeder, $rounds, $slotMap, $expectedWinner, $expectedLoser) as $mismatch) {
                $nextRoundExcludeUserIds = $excludeUserIdsByRound[$mismatch['nextRoundId']] ?? [];
                $votesForActual = countCharacterVotesFromMap(
                    $voteMaps,
                    $mismatch['nextRoundId'],
                    $mismatch['actualCharacterId'],
                    $nextRoundExcludeUserIds
                );

                if ($votesForActual !== 0) {
                    continue;
                }

                $actions[] = [
                    'feederRoundId' => $feederId,
                    'feederTier' => (int) $feeder->tier,
                    'nextRoundId' => $mismatch['nextRoundId'],
                    'nextTier' => $mismatch['nextTier'],
                    'slot' => $mismatch['slot'],
                    'actualCharacterId' => $mismatch['actualCharacterId'],
                    'expectedCharacterId' => $mismatch['expectedCharacterId'],
                    'isThirdPlaceMatch' => $mismatch['isThirdPlaceMatch'],
                ];
                $slotMap[$mismatch['nextRoundId']][$mismatch['slot'] - 1] = $mismatch['expectedCharacterId'];
            }
        }
    }

    return $actions;
}

/**
 * @param array<int, Api\Round> $rounds
 * @param array<int, array{0:int,1:int}> $slotMap
 * @param array<int, int[]> $excludeUserIdsByRound
 * @param array{full:array,excluding:array} $voteMaps
 * @return array<int, array>
 */
function planRecounts(array $rounds, array $slotMap, array $excludeUserIdsByRound, array $voteMaps) {
    $actions = [];
    foreach ($rounds as $round) {
        if ((int) $round->final !== 1) {
            continue;
        }
        $roundId = (int) $round->id;
        $slots = $slotMap[$roundId];
        $roundExcludeUserIds = $excludeUserIdsByRound[$roundId] ?? [];
        $character1Votes = countCharacterVotesFromMap($voteMaps, $roundId, $slots[0], $roundExcludeUserIds);
        $character2Votes = countCharacterVotesFromMap($voteMaps, $roundId, $slots[1], $roundExcludeUserIds);
        $actions[] = [
            'roundId' => $roundId,
            'tier' => (int) $round->tier,
            'character1Id' => $slots[0],
            'character2Id' => $slots[1],
            'previousCharacter1Id' => (int) $round->character1Id,
            'previousCharacter2Id' => (int) $round->character2Id,
            'character1Votes' => $character1Votes,
            'character2Votes' => $character2Votes,
            'previousCharacter1Votes' => $round->character1Votes,
            'previousCharacter2Votes' => $round->character2Votes,
        ];
    }
    return $actions;
}

/**
 * @param array $action
 * @return bool
 */
function recountNeedsSync(array $action) {
    $votesChanged = ((int) $action['previousCharacter1Votes'] !== (int) $action['character1Votes'])
        || ((int) $action['previousCharacter2Votes'] !== (int) $action['character2Votes']);
    $entrantsChanged = ((int) $action['previousCharacter1Id'] !== (int) $action['character1Id'])
        || ((int) $action['previousCharacter2Id'] !== (int) $action['character2Id']);
    return $votesChanged || $entrantsChanged;
}

/**
 * @param array $actions
 */
function printRecountReport(array $actions) {
    echo "Finalized tally recount (* = winner changed):\n";
    if (count($actions) === 0) {
        echo "  (no finalized tournament rounds)\n\n";
        return;
    }

    $any = false;
    foreach ($actions as $action) {
        $votesChanged = ((int) $action['previousCharacter1Votes'] !== (int) $action['character1Votes'])
            || ((int) $action['previousCharacter2Votes'] !== (int) $action['character2Votes']);
        if (!$votesChanged) {
            continue;
        }

        $any = true;
        $previousWinnerId = computeWinnerFromVoteCounts(
            (int) $action['previousCharacter1Id'],
            (int) $action['previousCharacter2Id'],
            (int) $action['previousCharacter1Votes'],
            (int) $action['previousCharacter2Votes']
        );
        $newWinnerId = computeWinnerFromVoteCounts(
            (int) $action['character1Id'],
            (int) $action['character2Id'],
            (int) $action['character1Votes'],
            (int) $action['character2Votes']
        );
        $winnerChanged = (int) $previousWinnerId !== (int) $newWinnerId;
        $flag = $winnerChanged ? ' *' : '';

        echo "  round {$action['roundId']} (tier {$action['tier']})"
            . ' ' . formatEntrantLabel($action['character1Id'])
            . " {$action['previousCharacter1Votes']}->{$action['character1Votes']},"
            . ' ' . formatEntrantLabel($action['character2Id'])
            . " {$action['previousCharacter2Votes']}->{$action['character2Votes']}"
            . "{$flag}\n";
    }

    if (!$any) {
        echo "  (no tally changes)\n";
    }
    echo "\n";
}

/**
 * @param array<int, Api\Round> $rounds
 * @param array<int, array{0:int,1:int}> $slotMap
 */
function printOpenRoundsReport(array $rounds, array $slotMap) {
    echo "Open matchups with entrant changes:\n";
    $any = false;
    foreach ($rounds as $round) {
        if ((int) $round->final === 1) {
            continue;
        }
        $slots = $slotMap[(int) $round->id];
        $changed = ((int) $slots[0] !== (int) $round->character1Id)
            || ((int) $slots[1] !== (int) $round->character2Id);
        if (!$changed) {
            continue;
        }
        $any = true;
        echo "  round {$round->id} (tier {$round->tier}):"
            . ' ' . formatEntrantLabel($round->character1Id)
            . ' -> ' . formatEntrantLabel($slots[0])
            . ' vs '
            . formatEntrantLabel($round->character2Id)
            . ' -> ' . formatEntrantLabel($slots[1])
            . "\n";
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
 * @param array{full:array,excluding:array} $voteMaps
 * @return int|null
 */
function computeWinnerId($roundId, $character1Id, $character2Id, array $excludeUserIds, array $voteMaps) {
    $character1Id = (int) $character1Id;
    $character2Id = (int) $character2Id;

    if ($character2Id === 1) {
        return $character1Id ?: null;
    }
    if ($character1Id <= 0 || $character2Id <= 0) {
        return null;
    }

    $votes1 = countCharacterVotesFromMap($voteMaps, $roundId, $character1Id, $excludeUserIds);
    $votes2 = countCharacterVotesFromMap($voteMaps, $roundId, $character2Id, $excludeUserIds);

    return computeWinnerFromVoteCounts($character1Id, $character2Id, $votes1, $votes2);
}

/**
 * @param int $character1Id
 * @param int $character2Id
 * @param int $votes1
 * @param int $votes2
 * @return int|null
 */
function computeWinnerFromVoteCounts($character1Id, $character2Id, $votes1, $votes2) {
    $character1Id = (int) $character1Id;
    $character2Id = (int) $character2Id;
    $votes1 = (int) $votes1;
    $votes2 = (int) $votes2;

    if ($character2Id === 1) {
        return $character1Id ?: null;
    }
    if ($character1Id <= 0 || $character2Id <= 0) {
        return null;
    }
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
    $character = getCharacter($characterId);
    if ($character && $character->name) {
        return (string) $character->name;
    }
    return 'character ' . $characterId;
}

/**
 * @param int $characterId
 * @return int
 */
function getCharacterSeed($characterId) {
    $character = getCharacter($characterId);
    if ($character && $character->seed !== null) {
        return (int) $character->seed;
    }
    return PHP_INT_MAX;
}

/**
 * @param int $characterId
 * @return Api\Character|null
 */
function getCharacter($characterId) {
    static $characters = [];
    $characterId = (int) $characterId;
    if (!array_key_exists($characterId, $characters)) {
        $character = Api\Character::getById($characterId);
        $characters[$characterId] = $character && (int) $character->id > 0 ? $character : null;
    }
    return $characters[$characterId];
}

/**
 * Load per-round character vote totals for the bracket.
 * When $excludeUserIds is non-empty, also builds an "excluding" map that omits those users.
 *
 * @param int $bracketId
 * @param int[] $excludeUserIds
 * @return array{full:array<int,array<int,int>>,excluding:array<int,array<int,int>>}
 */
function loadVoteCountMaps($bracketId, array $excludeUserIds) {
    $params = [ ':bracketId' => (int) $bracketId ];
    $sql = 'SELECT v.round_id AS round_id, v.character_id AS character_id, COUNT(1) AS total';

    if (count($excludeUserIds) > 0) {
        $sql .= ', SUM(CASE WHEN v.user_id NOT IN ('
            . buildInPlaceholders($excludeUserIds, 'ex')
            . ') THEN 1 ELSE 0 END) AS total_excluding';
        $params = array_merge($params, buildInParams($excludeUserIds, 'ex'));
    }

    $sql .= ' FROM votes v'
        . ' INNER JOIN round r ON r.round_id = v.round_id'
        . ' WHERE v.bracket_id = :bracketId'
        . ' AND r.round_tier >= 1'
        . ' GROUP BY v.round_id, v.character_id';

    $full = [];
    $excluding = [];
    $result = Lib\Db::Query($sql, $params);
    if ($result && $result->count) {
        while ($row = Lib\Db::Fetch($result)) {
            $roundId = (int) $row->round_id;
            $characterId = (int) $row->character_id;
            if (!isset($full[$roundId])) {
                $full[$roundId] = [];
            }
            $full[$roundId][$characterId] = (int) $row->total;

            if (count($excludeUserIds) > 0) {
                if (!isset($excluding[$roundId])) {
                    $excluding[$roundId] = [];
                }
                $excluding[$roundId][$characterId] = (int) $row->total_excluding;
            }
        }
    }

    if (count($excludeUserIds) === 0) {
        $excluding = $full;
    }

    return [
        'full' => $full,
        'excluding' => $excluding,
    ];
}

/**
 * @param array{full:array,excluding:array} $voteMaps
 * @param int $roundId
 * @param int $characterId
 * @param int[] $excludeUserIds
 * @return int
 */
function countCharacterVotesFromMap(array $voteMaps, $roundId, $characterId, array $excludeUserIds) {
    $characterId = (int) $characterId;
    if ($characterId <= 0) {
        return 0;
    }
    $bucket = count($excludeUserIds) > 0 ? 'excluding' : 'full';
    return (int) ($voteMaps[$bucket][(int) $roundId][$characterId] ?? 0);
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
