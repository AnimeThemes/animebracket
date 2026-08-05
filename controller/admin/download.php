<?php

namespace Controller\Admin {

    use Api;
    use Lib;

    class Download extends \Controller\Me {

        public static function generate(array $params) {

            $bracket = self::_getBracket(array_shift($params));

            if ($bracket) {

                $handle = fopen('php://output', 'wb');
                
                if ($handle) {
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename=' . $bracket->perma . '.csv');

                    // Two queries on purpose: indexes need all distinct user_ids first, but
                    // buffering ~hundreds of thousands of vote rows in PHP is too heavy.
                    // Build a small index map, then stream votes ordered by date.
                    $userIndexes = self::_getUserIndexesForBracket($bracket->id);

                    $query  = 'SELECT v.vote_date, v.user_id, c.character_name, r.round_tier, r.round_group ';
                    $query .= 'FROM `votes` v INNER JOIN `round` r ON r.round_id = v.round_id ';
                    $query .= 'INNER JOIN `character` c ON c.character_id = v.character_id ';
                    $query .= 'WHERE v.bracket_id = :bracketId ';
                    $query .= 'ORDER BY v.vote_date ASC';

                    fputcsv($handle, [ 'Date', 'Post-ID-sort User Index', 'Entrant', 'Round', 'Group' ]);
                    Lib\Db::StreamQuery($query, [ ':bracketId' => $bracket->id ], function ($row) use ($handle, $userIndexes) {
                        fputcsv($handle, [
                            date('c', $row->vote_date),
                            $userIndexes[(int) $row->user_id],
                            $row->character_name,
                            $row->round_tier,
                            $row->round_group
                        ]);
                    });

                    fclose($handle);

                }


            }

            exit;

        }

        // 0-based index by ascending user_id among voters in this bracket (order only, no raw ids).
        private static function _getUserIndexesForBracket($bracketId) {
            $indexes = [];
            $result = Lib\Db::Query(
                'SELECT DISTINCT user_id FROM `votes` WHERE bracket_id = :bracketId ORDER BY user_id ASC',
                [ ':bracketId' => $bracketId ]
            );

            if ($result && $result->count) {
                for ($index = 0; $row = Lib\Db::Fetch($result); $index++) {
                    $indexes[(int) $row->user_id] = $index;
                }
            }

            return $indexes;
        }

    }

}
