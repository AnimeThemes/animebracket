<?php

namespace Controller\Admin {
  use Api;
  use Lib;

  class Counts extends \Controller\Me {
    public static function generate(array $params) {
      $bracket = self::_getBracket(array_shift($params));
      if ($bracket) {
        $characters = $bracket->getVoteAdjustedEliminationsCharacters();

        // if adjusted votes is numeric, round to 2 decimal places
        foreach ($characters as $character) {
          $character->adjustedVotesDisplay = is_numeric($character->adjustedVotes)
            ? number_format((float) $character->adjustedVotes, 2, '.', '')
            : $character->adjustedVotes;
        }
        
        $tplData = (object)[
          'characters' => $characters,
          'bracket' => $bracket
        ];
        Lib\Display::renderAndAddKey('content', 'admin/eliminationCounts', $tplData);
      }
    }
  }
}