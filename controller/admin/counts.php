<?php

namespace Controller\Admin {
  use Api;
  use Lib;

  class Counts extends \Controller\Me {
    public static function generate(array $params) {
      $bracket = self::_getBracket(array_shift($params));
      if ($bracket) {
        $characters = $bracket->getVoteAdjustedEliminationsCharacters();
        $tplData = (object)[
          'characters' => $characters,
          'bracket' => $bracket
        ];
        Lib\Display::renderAndAddKey('content', 'admin/eliminationCounts', $tplData);
      }
    }
  }
}