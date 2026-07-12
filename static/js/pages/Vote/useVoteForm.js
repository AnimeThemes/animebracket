import React, { useState, useMemo } from 'react';

export const useVoteForm = ({ rounds, bracket }) => {
  const [ ballot, setBallot ] = useState({});
  const [ loading, setLoading ] = useState(false);
  const [ captchaResponse, setCaptchaResponse ] = useState(null);

  // re-map the initial data as { ...roundId: roundData }
  useMemo(() => {
    setBallot(rounds.reduce((acc, round) => {
      acc[round.id] = {
        ...round,
        character1: {
          ...round.character1,
          selected: round.character1.voted,
        },
        character2: {
          ...round.character2,
          selected: round.character2.voted,
        },
      };
      return acc;
    }, {}));
  }, rounds);

  const selectEntrant = ({ roundId, entrantId }) => {
    const { character1, character2, ...roundProps } = ballot[roundId];
    const clickingSelected =
      (character1.selected && character1.id === entrantId) ||
      (character2.selected && character2.id === entrantId);

    // Saved votes cannot be cleared — clicking the current pick is a no-op
    if (roundProps.voted && clickingSelected) {
      return;
    }

    // Saved: select clicked entrant. Unsaved: toggle (click again to clear).
    const selectClicked = (character) => (
      roundProps.voted
        ? character.id === entrantId
        : character.id === entrantId && !character.selected
    );

    const updatedRound = {
      ...roundProps,
      character1: {
        ...character1,
        selected: selectClicked(character1),
      },
      character2: {
        ...character2,
        selected: selectClicked(character2),
      },
    };

    setBallot({ ...ballot, [roundId]: updatedRound });
  };
  // round:312787: 231636
  // bracketId: 6433
  // _auth: 64ede22054670f1b
  const submitVotes = async (csrfToken) => {
    const formData = new FormData();
    setLoading(true);

    Object.keys(ballot).forEach(roundId => {
      const round = ballot[roundId];
      const selectedId = round.character1.selected
        ? round.character1.id
        : (round.character2.selected ? round.character2.id : null);
      if (selectedId == null) {
        return;
      }

      const votedId = round.character1.voted
        ? round.character1.id
        : (round.character2.voted ? round.character2.id : null);

      // New votes and changed votes; skip unchanged already-voted picks
      if (votedId !== selectedId) {
        formData.append(`round:${roundId}`, selectedId);
      }
    });

    formData.append('bracketId', bracket.id);
    formData.append('_auth', csrfToken);

    if (captchaResponse) {
      formData.append('g-recaptcha-response', captchaResponse);
    }

    const response = await fetch('/submit/?action=vote', {
      method: 'POST',
      body: formData,
    }).then(res => res.json());

    // if the vote went through successfully, update the submitted vote info
    if (response && response.success) {
      setBallot(Object.keys(ballot).reduce((acc, roundId) => {
        const { character1, character2, ...roundProps } = ballot[roundId];
        acc[roundId] = {
          ...roundProps,
          voted: character1.selected || character2.selected,
          character1: {
            ...character1,
            voted: character1.selected,
          },
          character2: {
            ...character2,
            voted: character2.selected,
          },
        };
        return acc;
      }, {}));
    }

    setLoading(false);
    return response;
  };

  return {
    ballot,
    loading,
    selectEntrant,
    submitVotes,
    setCaptchaResponse,
  }
};
