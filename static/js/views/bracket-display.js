import Handlebars from 'handlebars/runtime';
import { Route, Router } from 'molecule-router';
import $ from 'jquery';

import Entrant from '../model/entrant';
import Round from '../model/round';
import { default as Tier, ENTRANT_HEIGHT } from '../model/tier';

import TPL_GROUP_PICKER from '@views/groupPicker.hbs';
import TPL_ENTRANT from '@views/partials/_entrant.hbs';
import TPL_WINNER from '@views/winner.hbs';
import TIER_TMPL from '@views/tier.hbs';

const SINGLETON_NAME = 'bracket-display';
const COLUMN_WIDTH = 225 + 18;
const THIRD_PLACE_ROW_HEIGHT = 60;
/** Pixels below the title-match cell vertical center to the top of the third-place strip. */
const THIRD_PLACE_GAP_BELOW_TITLE_PX = 180;
const MOBILE_WIDTH_PX = 480;
const MOBILE_THIRD_PLACE_GAP_BELOW_TITLE_PX = 130;

export default Route(SINGLETON_NAME,{

  __construct() {
    this._tiers = [];
    this._thirdPlaceRound = null;
    this._$content = $('.bracket-display');
    this._$body = $('body');
    this._$header = $('header');
    this._groups = 0;
    this._initialized = false;
    this._positionThirdPlaceFrame = null;
  },

  parseQueryString(qs) {
    var
      retVal = {},
      i = null,
      count = 0,
      kvp = null;

    if (!qs) {
      qs = location.href.indexOf('?') !== -1 ? location.href.split('?')[1] : null;
    }

    if (qs) {
      qs = qs.split('&');
      for (i = 0, count = qs.length; i < count; i++) {
        kvp = qs[i].split('=');
        retVal[kvp[0]] = kvp.length === 1 ? true : decodeURIComponent(kvp[1]);
      }
    }

    return retVal;
  },

  _pickShownWinnerEntrant(entrant1, entrant2) {
    if (!entrant1.votes && !entrant2.votes) {
      return new Entrant(null, 0);
    }
    if (entrant1.votes > entrant2.votes) {
      return entrant1;
    }
    if (entrant1.votes < entrant2.votes) {
      return entrant2;
    }
    return entrant1.seed < entrant2.seed ? entrant1 : entrant2;
  },

  renderBracket(group, tier) {
    let left = '';
    let right = '';
    let temp = [];
    let columns = 0;
    let max = this.tiersForGroup(group);
    let lastRound = null;
    let bracketHeight = 0;
    let winner = {};

    tier = tier || 0;
    bracketHeight = Math.pow(2, max - tier - 1) * ENTRANT_HEIGHT;

    for (let i = tier; i < max; i++) {
      temp = this._tiers[i].render(i - tier, group, true);
      left += temp[0];
      right = temp[1] + right;
      columns += 2;
    }

    // Render the winner
    lastRound = this._tiers[max - 1].getRound(0, group);
    if (null !== lastRound && null !== lastRound.entrant1 && null != lastRound.entrant2) {
      winner = {
        entrant: this._pickShownWinnerEntrant(lastRound.entrant1, lastRound.entrant2),
        height: bracketHeight
      };
      left += TPL_WINNER(winner);
    }

    const treeHtml = left + right;
    const thirdRaw = this._thirdPlaceShownForResultsView(group)
      ? this._getThirdPlaceRawForView(group)
      : null;
    const thirdHtml = thirdRaw ? this._renderThirdPlaceBlock(thirdRaw) : '';

    this._$content.width(++columns * COLUMN_WIDTH).html(treeHtml + thirdHtml);
    this._schedulePositionThirdPlaceBelowTitle();
  },

  _schedulePositionThirdPlaceBelowTitle() {
    if (this._positionThirdPlaceFrame) {
      cancelAnimationFrame(this._positionThirdPlaceFrame);
    }

    this._positionThirdPlaceFrame = requestAnimationFrame(() => {
      this._positionThirdPlaceFrame = null;
      this._positionThirdPlaceBelowTitle();
    });
  },

  /**
   * Place third-place strip just under the title-match column; flow layout would leave it after the
   * tallest bracket column (often the bottom of the page).
   */
  _positionThirdPlaceBelowTitle() {
    const $wrap = this._$content;
    const $third = $wrap.children('.bracket-third-place-wrap');
    if (!$third.length) {
      return;
    }

    let $anchor = $wrap.children('ol.tier.left.winner').first();
    if (!$anchor.length) {
      $anchor = $wrap.children('ol.tier.left').last();
    }
    if (!$anchor.length) {
      $third.css({ top: '', position: '', left: '', right: '' });
      return;
    }

    // Use the vertical middle of the title cell, not the bottom of the ol. The winner column ol is
    // as tall as bracketHeight (same as outer rounds), so its bottom is the page bottom — same as
    // clearing after all floats. The actual title row sits at the center of that cell.
    let $midTarget = $anchor.find('> li .winner').first();
    if (!$midTarget.length) {
      $midTarget = $anchor.find('> li.round:last .entrant').first();
    }
    if (!$midTarget.length) {
      $midTarget = $anchor;
    }

    const wrapTop = $wrap.offset().top;
    const targetTop = $midTarget.offset().top - wrapTop;
    const midY = targetTop + $midTarget.outerHeight() / 2;
    const desiredTop = midY + this._thirdPlaceGapBelowTitlePx();
    const $winnerName = $midTarget.find('h2').first();
    const minTopBelowWinnerName = $winnerName.length
      ? ($winnerName.offset().top - wrapTop) + $winnerName.outerHeight(true)
      : desiredTop;
    const topPx = Math.max(desiredTop, minTopBelowWinnerName);

    $third.css({
      position: 'absolute',
      left: 0,
      right: 0,
      top: topPx
    });
  },

  _thirdPlaceGapBelowTitlePx() {
    if (window.matchMedia) {
      return window.matchMedia(`(max-width: ${MOBILE_WIDTH_PX}px)`).matches
        ? MOBILE_THIRD_PLACE_GAP_BELOW_TITLE_PX
        : THIRD_PLACE_GAP_BELOW_TITLE_PX;
    }

    return window.innerWidth <= MOBILE_WIDTH_PX
      ? MOBILE_THIRD_PLACE_GAP_BELOW_TITLE_PX
      : THIRD_PLACE_GAP_BELOW_TITLE_PX;
  },

  _thirdPlaceShownForResultsView(group) {
    const enabled = !!(this._bracketData && this._bracketData.thirdPlaceMatchEnabled);
    return enabled && (group === null || group === undefined);
  },

  /** Return API third-place row, or synthesize a placeholder row. */
  _getThirdPlaceRawForView(group) {
    // return third-place match data if it exists
    if (this._thirdPlaceRound) {
      return this._thirdPlaceRound;
    }

    // otherwise uses placeholder data
    const max = this.tiersForGroup(group);
    if (max < 1) {
      return null;
    }
    const rounds = this._tiers[max - 1].getRoundsForGroup(group);
    if (!rounds.length) {
      return null;
    }
    const anchor = rounds.find((r) => r.order === 0 && !r.isThirdPlaceMatch) || rounds[0];
    return {
      id: 0,
      tier: anchor.tier,
      group: anchor.group,
      order: 1,
      final: false,
      filler: true,
      isThirdPlaceMatch: true
    };
  },

  _renderThirdPlaceBlock(raw) {
    const round = new Round(raw);
    const e1 = round.entrant1;
    const e2 = round.entrant2;
    const isPlaceholder = !!raw.filler;
    const h = THIRD_PLACE_ROW_HEIGHT;
    const roundHead = { id: round.id, tier: round.tier, final: round.final };
    const leftTier = TIER_TMPL({
      side: 'left',
      height: h,
      rounds: [{ ...roundHead, entrant1: e1 }]
    });
    const rightTier = TIER_TMPL({
      side: 'right',
      height: h,
      rounds: [{ ...roundHead, entrant1: e2 }]
    });
    const centerHtml = TPL_WINNER({
      entrant: this._pickShownWinnerEntrant(e1, e2),
      height: h,
      modifierClass: 'winner--third-place'
    });
    const wrapMod = isPlaceholder ? ' bracket-third-place-wrap--placeholder' : '';
    return `
      <div class="bracket-third-place-wrap${wrapMod}">
        <h3 class="bracket-third-place-heading">3rd Place Match</h3>
        <div class="bracket-third-place-match">${leftTier}${centerHtml}${rightTier}</div>
      </div>`;
  },

  /**
   * Returns the number of tiers that will be in a group
   */
  tiersForGroup(group) {
    var rounds = this._tiers[0].getRoundsForGroup(group).length;
    return Math.log(rounds) / Math.LN2 + 1;
  },

  handleMouseOver(evt) {
    let id = evt.currentTarget.getAttribute('data-id');
    if ('1' !== id) {

      $('.highlighted').removeClass('highlighted');
      $('.entrant[data-id="' + id + '"]')
        .addClass('highlighted')
        .parent().addClass('highlighted');
      }
  },

  handleGroupChange(e) {
    this.changeGroup($(e.currentTarget).data('group'));
  },

  changeGroup(group, ignoreHistory) {
    let tier = null;
    let urlGroup = group;

    const bracketData = this._bracketData;

    this._$header.find('.selected').removeClass('selected');
    this._$header.find('[data-group="' + group + '"]').addClass('selected');

    if (group === 'finals') {
      group = null;
      tier = bracketData.results.length - 3;
    } else if (group === 'full') {
      group = null;
      tier = 0;
    } else {
      group = parseInt(group, 10);
      urlGroup = group + 1;
    }
    this.renderBracket(group, tier);

    if (!ignoreHistory) {
      Router.go('results.perma', { perma: bracketData.perma, group: urlGroup });
    }
  },

  populateGroups() {
    let out = [];

    for (let i = 0; i < this._groups; i++) {
      out.push({ name:String.fromCharCode(i + 65), index:i });
    }

    this._$header
      .find('ul.groups')
      .html(TPL_GROUP_PICKER({ groups:out }))
      .on('click', 'li', this.handleGroupChange.bind(this));
  },

  initRoute() {

    let qs = this.parseQueryString();
    let group = qs.hasOwnProperty('group') ? qs.group : 1;
    let groups = 0;

    this._bracketData = window.bracketData || null;


    if (this._bracketData && !this._initialized) {

      const bracketData = this._bracketData;

      Handlebars.registerPartial('entrant', TPL_ENTRANT);
      Handlebars.registerHelper('userVoted', function(entrant, options) {
        var retVal = '',
          id = '' + this.id;
        if (bracketData.userVotes && bracketData.userVotes.hasOwnProperty(id)) {
          retVal = bracketData.userVotes[id] == entrant.id ? options.fn(this) : '';
        }
        return retVal;
      });

      this._thirdPlaceRound = null;
      for (let i = 0, count = bracketData.results.length; i < count; i++) {
        const tierRows = bracketData.results[i];
        for (let r = 0; r < tierRows.length; r++) {
          const row = tierRows[r];
          if (row && row.isThirdPlaceMatch) {
            this._thirdPlaceRound = row;
            break;
          }
        }
        const filtered = tierRows.filter((row) => !row || !row.isThirdPlaceMatch);
        let tier = new Tier(filtered);
        groups = tier.groups > groups ? tier.groups : groups;
        this._tiers.push(tier);
      }

      // Increment by 1 because group IDs are 0 based
      this._groups = groups + 1;

      this._$body.on('mouseover', '.entrant-info', this.handleMouseOver.bind(this));
      $(window)
        .off('resize.bracketDisplayThirdPlace')
        .on('resize.bracketDisplayThirdPlace', this._schedulePositionThirdPlaceBelowTitle.bind(this));
      this._$header.find('.title').text(window.bracketData.name);

      group = isNaN(group) ? group : group - 1;
      this.populateGroups();
      this.changeGroup(group, true);
    } else if (this._initialized) {
      this.changeGroup(group, true);
    }
  }
});
