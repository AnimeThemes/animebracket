<?php

namespace Api {
    
    use Lib;
    use stdClass;

    class Votes {

        /**
         * Returns non-deleted rounds in the bracket keyed by round id.
         * Each entry has character1Id, character2Id, and final.
         *
         * @return array<int, stdClass>
         */
        public static function getValidRoundEntrants($votes, $bracketId) {
            $retVal = [];
            $params = self::_roundParams($votes);
            if (!$params) {
                return $retVal;
            }

            $params[':bracketId'] = $bracketId;
            $roundKeys = implode(',', array_keys(array_diff_key($params, [ ':bracketId' => true ])));
            $query = 'SELECT round_id, round_character1_id, round_character2_id, round_final'
                . ' FROM round'
                . ' WHERE bracket_id = :bracketId AND round_deleted = 0 AND round_id IN (' . $roundKeys . ')';
            $result = Lib\Db::Query($query, $params);
            if ($result && $result->count > 0) {
                while ($row = Lib\Db::Fetch($result)) {
                    $round = new stdClass;
                    $round->character1Id = (int) $row->round_character1_id;
                    $round->character2Id = (int) $row->round_character2_id;
                    $round->final = (int) $row->round_final === 1;
                    $retVal[(int) $row->round_id] = $round;
                }
            }

            return $retVal;
        }

        /**
         * @return array<string, int>
         */
        private static function _roundParams($votes) {
            $params = [];
            for ($i = 0, $count = count($votes); $i < $count; $i++) {
                $params[':round' . $i] = $votes[$i]->roundId;
            }
            return $params;
        }

    }

}
