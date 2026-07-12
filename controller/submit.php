<?php

namespace Controller {

    use Api;
    use Lib;
    use stdClass;

    define('FLOOD_CONTROL', 3);

    class Submit extends Page {

        public static function generate(array $params) {

            $action = Lib\Url::Get('action', null);
            $out = new stdClass;
            $out->success = false;

            $user = Api\User::getCurrentUser();
            if ($user) {
                if (!self::_verifyCsrf($user)) {
                    $out->message = 'There was an error authenticating your account. Please logout and log back in.';
                } else if (self::_isFlooding($user)) {
                    $out->message = 'You\'re doing that too fast!';
                } else {
                    switch ($action) {
                        case 'nominate':
                            $out = self::_nominate($user);
                            break;
                        case 'vote':
                            $out = self::_vote($user);
                            break;
                        default:
                            $out->message = 'No action specified';
                            break;
                    }

                    if ($out->success) {
                        self::_setFloodMarker($user);
                    }

                }

            } else {
                $out->message = 'You must be logged in';
            }

            Lib\Display::renderJson($out);

        }

        /**
         * Checks to see if the user is flooding the server with requests too quickly
         */
        private static function _isFlooding($user) {
            $cacheKey = 'FloodGuard_' . $user->id;
            $retVal = Lib\Cache::getInstance()->get($cacheKey, true);
            return $retVal && $retVal + FLOOD_CONTROL > time();
        }

        private static function _setFloodMarker($user) {
            $cacheKey = 'FloodGuard_' . $user->id;
            Lib\Cache::getInstance()->set($cacheKey, time(), FLOOD_CONTROL);
        }

        private static function _nominate(Api\User $user) {

            $out = new stdClass;
            $out->success = false;

            $bracketId = Lib\Url::Post('bracketId', true);
            $bracket = Api\Bracket::getById($bracketId);
            $nomineeName = Lib\Url::Post('nomineeName');
            $nomineeSource = Lib\Url::Post('nomineeSource');
            $verified = Lib\Url::Post('verified') === 'true';
            $image = Lib\Url::Post('image');

            if ($bracket && $nomineeName && $image) {

                if (self::_verifyAccountAge($user, $bracket)) {

                    // Verify the image first
                    if (self::_verifyImage($image)) {
                        $nominee = new Api\Nominee();
                        $nominee->bracketId = $bracket->id;
                        $nominee->name = $nomineeName;
                        $nominee->source = $nomineeSource;
                        $nominee->created = time();
                        $nominee->image = $image;
                        $nominee->processed = $verified ? 1 : null;

                        if ($nominee->sync()) {
                            $out->success = true;
                        } else {
                            $out->message = 'Unable to save to database';
                        }
                    } else {
                        $out->message = 'Invalid image';
                    }
                } else {
                    $out->message = 'Your reddit account is not old enough to nominate in this bracket';
                    $out->date = $_POST;
                }

            } else {
                $out->message = 'Missing fields';
                $out->data = $_POST;
            }

            return $out;

        }

        private static function _vote($user) {

            $out = new stdClass;
            $out->success = false;

            $bracketId = Lib\Url::Post('bracketId', true);
            $bracket = Api\Bracket::getById($bracketId);

            if (!$bracket) {
                $out->message = 'Invalid parameters';
            } else if ($bracket->isLocked()) {
                $out->message = 'Voting is closed for this round. Please refresh to see the latest round.';
            } else if ($bracket->isVotingLocked()) {
                $out->message = 'Voting has temporarily been locked by contest admins.';
                return $out;
            } else if ($bracket->state !== BS_ELIMINATIONS && $bracket->state !== BS_VOTING) {
                $out->message = 'Voting is closed on this bracket';
                $out->code = 'closed';
            } else if (!self::_verifyAccountAge($user, $bracket)) {
                $out->message = 'Your reddit account is not old enough to vote in this bracket';
            } else if (!self::_verifyCaptcha($bracket)) {
                $out->message = 'Captcha verification failed.';
            } else {
                // Break the votes down into an array of round/character objects
                $votes = [];
                foreach($_POST as $key => $val) {
                    if (strpos($key, 'round:') === 0) {
                        $key = str_replace('round:', '', $key);
                        $obj = new stdClass;
                        $obj->roundId = (int) $key;
                        $obj->characterId = (int) $val;
                        $votes[] = $obj;
                    }
                }

                $count = count($votes);
                if ($count > 0) {

                    $validRounds = Api\Votes::getValidRoundEntrants($votes, $bracketId);
                    $writeVotes = [];

                    for ($i = 0; $i < $count; $i++) {
                        $roundId = $votes[$i]->roundId;
                        $characterId = $votes[$i]->characterId;

                        if (!isset($validRounds[$roundId])) {
                            continue;
                        }

                        $roundInfo = $validRounds[$roundId];
                        if ($roundInfo->final) {
                            continue;
                        }

                        if ($characterId !== $roundInfo->character1Id && $characterId !== $roundInfo->character2Id) {
                            continue;
                        }

                        $writeVotes[] = $votes[$i];
                    }

                    if (count($writeVotes) === 0) {
                        $out->message = 'Voting for this round has closed';
                        $out->code = 'closed';
                    } else {
                        $query = 'INSERT INTO `votes` (`user_id`, `vote_date`, `round_id`, `character_id`, `bracket_id`) VALUES ';
                        $params = [];
                        $voteDate = time();
                        for ($i = 0, $writeCount = count($writeVotes); $i < $writeCount; $i++) {
                            $query .= '(:user' . $i . ', :date' . $i . ', :round' . $i . ', :character' . $i . ', :bracket' . $i . '),';
                            $params[':user' . $i] = $user->id;
                            $params[':date' . $i] = $voteDate;
                            $params[':round' . $i] = $writeVotes[$i]->roundId;
                            $params[':character' . $i] = $writeVotes[$i]->characterId;
                            $params[':bracket' . $i] = $bracketId;
                        }
                        $query = substr($query, 0, strlen($query) - 1);
                        $query .= ' ON DUPLICATE KEY UPDATE'
                            . ' `character_id` = VALUES(`character_id`),'
                            . ' `vote_date` = VALUES(`vote_date`)';

                        if (!Lib\Db::Query($query, $params)) {
                            $out->message = 'There was an unexpected error. Please try again in a few moments.';
                        } else {
                            $out->success = true;

                            // Clear any user related caches using a validated open round from this ballot
                            $round = Api\Round::getById($writeVotes[0]->roundId);
                            $cache = Lib\Cache::getInstance();
                            $cache->set('GetBracketRounds_' . $bracketId . '_' . $round->tier . '_' . $round->group . '_' . $user->id, false);
                            $cache->set('GetBracketRounds_' . $bracketId . '_' . $round->tier . '_all_' . $user->id, false);
                            $cache->set('CurrentRound_' . $bracketId . '_' . $user->id, false);
                            $bracket->getVotesForUser($user, true);

                            $out->message = 'Your votes were successfully submitted!';

                            // View Results only during bracket voting — elim tallies are not public
                            if ($bracket->state === BS_VOTING) {
                                $results = $bracket->getResults();
                                $tierCount = is_array($results) ? count($results) : 0;
                                $resultsGroup = ($tierCount > 0 && ((int) $round->tier - 1) >= $tierCount - 4)
                                    ? 'finals'
                                    : ((int) $round->group + 1);

                                // I am vehemently against putting markup in the controller, but there's much refactor needed to make this right
                                // So, that's a note that it will be changed in the future
                                $out->message .= ' <a href="/' . $bracket->perma . '/results?group=' . $resultsGroup . '">View Results</a>';
                            }

                            // Oops, I did it again...
                            if ($bracket->externalId) {
                                $prefix = $bracket->state === BS_VOTING ? ' or ' : ' ';
                                $out->message .= $prefix . '<a href="http://redd.it/' . $bracket->externalId . '" target="_blank">discuss on reddit</a>.';
                            }
                        }
                    }

                } else {
                    $out->message = 'No votes were submitted';
                }

            }

            return $out;

        }

        private static function _verifyImage($url) {
            $headers = @get_headers($url, true);
            $contentType = isset($headers['Content-Type']) ? $headers['Content-Type'] : (
                isset($headers['content-type']) ? $headers['content-type'] : ''
            );
            return strpos($contentType, 'image/') !== false;
        }

        private static function _verifyAccountAge(Api\User $user, Api\Bracket $bracket) {
            return (int) $bracket->minAge === 0 || $user->age <= time() - $bracket->minAge;
        }

        private static function _verifyCaptcha(Api\Bracket $bracket) {
            $retVal = !$bracket->captcha;

            if (!$retVal) {
                $c = curl_init('https://www.google.com/recaptcha/api/siteverify');
                curl_setopt_array($c, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => [
                        'secret' => RECAPTCHA_SECRET,
                        'response' => Lib\Url::Post('g-recaptcha-response'),
                        'remoteip' => $_SERVER['REMOTE_ADDR']
                    ]
                ]);
                $data = json_decode(@curl_exec($c));

                $retVal = $data && isset($data->success) && $data->success === true;
            }

            return $retVal;
        }

    }

}