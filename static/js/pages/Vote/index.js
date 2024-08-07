import classnames from 'classnames';
import React, { useState, useMemo } from 'react';
import ReactDOM from 'react-dom';
import ReCAPTCHA from 'react-google-recaptcha';
import { Route } from 'molecule-router';

import { AuthContextProvider, useAuth } from '@src/hooks/useAuth';
import { BracketState } from '@src/constants';

import { BallotEntrant } from './components/BallotEntrant';
import { useVoteForm } from './useVoteForm';

const Vote = ({ rounds, bracket, showCaptcha, meetsAgeRequirement }) => {
  const [ messageText, setMessageText ] = useState('');
  const [ messageError, setMessageError ] = useState(false);
  const [ hasCastVotes, setHasCastVotes ] = useState(false);
  const { csrfToken } = useAuth();
  const {
    ballot,
    loading,
    selectEntrant,
    submitVotes,
    setCaptchaResponse,
  } = useVoteForm({ rounds, bracket });

  const isVoting = bracket.state === BracketState.Voting;
  const isEliminations = bracket.state === BracketState.Eliminations;

  useMemo(() => {
    setHasCastVotes(Object.keys(ballot).find(roundId => ballot[roundId].voted));
  }, [ ballot ]);

  const handleSubmitClick = async () => {
    try {
      const response = await submitVotes(csrfToken);
      setMessageError(!response.success);
      setMessageText(response.message);
    } catch (err) {
      setMessageText('Encountered an error submitting votes');
      setMessageError(true);
      console.error(err);
    }
    window.scroll({ top: 0, behavior: 'smooth' });
  };

  const handleCopyClick = async () => {
    let markdownStr = Object.keys(ballot).reduce((acc, roundId) => {
      const { character1, character2 } = ballot[roundId];
      const character1Md = `[${character1.voted ? '**' : '~~'}${character1.name}${character1.voted ? '**' : '~~'}](${character1.image})`;
      const character2Md = `[${character2.voted ? '**' : '~~'}${character2.name}${character2.voted ? '**' : '~~'}](${character2.image})`;
      return `${acc}- ${character1Md} - ${character2Md}\n`;
    }, '');

    // try to share via the browser's share function, otherwise straight to
    // clipboard if allowed
    if (navigator.canShare) {
      navigator.share(markdownStr);
    } else {
      const { state } = await navigator.permissions.query({ name: 'clipboard-write' });
      if (state === 'granted' || state === 'prompt') {
        navigator.clipboard.writeText(markdownStr);
        alert('Vote markdown copied to clipboard!');
      }
    }
  };

  return (
    <>
      <p
        className={classnames(
          'message',
          {
            'hidden': !messageText,
            'success': !messageError,
            'error': messageError,
          },
        )}
        dangerouslySetInnerHTML={{ __html: messageText }}
      />
      {isEliminations && (
        <p class="info">Select all entrants you want to move into the bracket.</p>
      )}
      {(hasCastVotes && isVoting) && (
        <div className="votes-code">
          <button
            type="button"
            className="small-button"
            onClick={handleCopyClick}
          >
            Share Votes as Markdown
          </button>
        </div>
      )}
      <div id="vote-form">
        <ul
          className={classnames(
            'mini-card-container',
            {
              'voting': isVoting,
              'eliminations': isEliminations,
            },
          )}
        >
          {Object.keys(ballot).map(roundId => {
            const { character1, character2 } = ballot[roundId];
            return (
              <>
                <li
                  className={classnames(
                    'mini-card',
                    {
                      'mini-card--left entrant1': isVoting,
                    },
                  )}
                  key={`entrant-${character1.id}`}
                  onClick={() => selectEntrant({ roundId, entrantId: character1.id })}
                >
                  <BallotEntrant roundId={roundId} {...character1} />
                </li>
                {isVoting && (
                  <li
                    className="mini-card mini-card--right entrant2"
                    key={`entrant-${character2.id}`}
                    onClick={() => selectEntrant({ roundId, entrantId: character2.id })}
                  >
                    <BallotEntrant roundId={roundId} {...character2} />
                  </li>
                )}
              </>
            );
          })}
        </ul>
        {showCaptcha && (
          <div className="captcha">
            <ReCAPTCHA
              sitekey="6LfAX2ApAAAAANuGFcQ-wVU7rgYW04SnKZJdNXFp"
              onChange={setCaptchaResponse}
            />
          </div>
        )}
        <button
          type="button"
          className="button"
          onClick={handleSubmitClick}
          disabled={loading}
        >
          Submit Votes
        </button>
      </div>
      {!meetsAgeRequirement && (
        <div className="overlay">
          <aside className="overlay__content">
            <h1 className="overlay__header">Oh dear...</h1>
            <p className="overlay__body">
              Your reddit account does not meet the minimum age requirements for this bracket :(
            </p>
          </aside>
        </div>
      )}
    </>
  );
};

// ...I hate this route nonsense...
export default Route('vote', {
  initRoute() {
    const { bracket, round, userId, csrfToken, showCaptcha, meetsAgeRequirement } = window._appData;
    ReactDOM.render((
      <AuthContextProvider value={{ userId, csrfToken }}>
        <Vote bracket={bracket} rounds={round} showCaptcha={showCaptcha} meetsAgeRequirement={meetsAgeRequirement} />
      </AuthContextProvider>
    ), document.getElementById('reactApp'));
  }
});
