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

export default Route(SINGLETON_NAME,{

  __construct() {
    this._tiers = [];
    this._thirdPlaceByGroup = {};
    this._$content = $('.bracket-display');
    this._$body = $('body');
    this._$header = $('header');
    this._groups = 0;
    this._initialized = false;
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
      if (!lastRound.entrant1.votes && !lastRound.entrant2.votes) {
        winner = { entrant:new Entrant(null, 0) };
      } else {
        if (lastRound.entrant1.votes > lastRound.entrant2.votes) {
          winner = { entrant: lastRound.entrant1 };
        } else if (lastRound.entrant1.votes < lastRound.entrant2.votes) {
          winner = { entrant: lastRound.entrant2 };
        } else {
          // In a tie scenario, use seed to determine winner.
          winner = {
            entrant: (
              lastRound.entrant1.seed < lastRound.entrant2.seed ?
                lastRound.entrant1 : lastRound.entrant2
            )
          }
        }
      }
      winner.height = bracketHeight;
      left += TPL_WINNER(winner);
    }

    const treeHtml = left + right;
    const thirdRaw = this._thirdPlaceShownForResultsView(group)
      ? this._getThirdPlaceRawForView(group)
      : null;
    const thirdHtml = thirdRaw ? this._renderThirdPlaceBlock(thirdRaw) : '';

    // Add an additional column for the winner; optional third-place row below
    this._$content
      .width(++columns * COLUMN_WIDTH)
      .css('position', 'relative')
      .html(`<div class="bracket-main-tree">${treeHtml}</div>${thirdHtml}`);

    this._$content.find('.bracket-third-connectors').remove();
    if (thirdRaw) {
      requestAnimationFrame(() => {
        requestAnimationFrame(() => this._drawThirdPlaceConnectors());
      });
    }
  },

  _thirdPlaceShownForResultsView(group) {
    const enabled = !!(this._bracketData && this._bracketData.thirdPlaceMatchEnabled);
    return enabled && (group === null || group === undefined);
  },

  /** Return API third-place row, or synthesize a placeholder row. */
  _getThirdPlaceRawForView(group) {
    // return third-place match data if it exists
    const byG = this._thirdPlaceByGroup;
    const raw = byG[Object.keys(byG)[0]] ?? null;
    if (raw) {
      return raw;
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
    const cellH = ENTRANT_HEIGHT;
    const isPlaceholder = !!raw.filler;
    const r1 = {
      id: round.id,
      tier: round.tier,
      entrant1: e1,
      entrant2: e2,
      final: round.final
    };
    const sideHtml = TIER_TMPL({
      side: 'left',
      height: cellH,
      rounds: [r1]
    });
    const wrapMod = isPlaceholder ? ' bracket-third-place-wrap--placeholder' : '';
    return `
      <div class="bracket-third-place-wrap${wrapMod}">
        <h3 class="bracket-third-place-heading">3rd place match</h3>
        <div class="bracket-third-place-match">${sideHtml}</div>
      </div>`;
  },

  _drawThirdPlaceConnectors() {
    const $wrap = this._$content;
    const $tree = $wrap.find('.bracket-main-tree');
    const $third = $wrap.find('.bracket-third-place-match');
    const $svg = $wrap.find('svg.bracket-third-connectors');
    if (!$tree.length || !$third.length) {
      return;
    }

    let maxT = 0;
    $tree.find('.round[data-tier]').each((_, el) => {
      const t = +$(el).data('tier');
      if (t > maxT) {
        maxT = t;
      }
    });
    if (maxT < 2) {
      return;
    }

    const semiEls = $tree.find('.round[data-tier]').filter((_, el) => +$(el).data('tier') === maxT - 1).get();
    if (semiEls.length !== 2) {
      return;
    }

    semiEls.sort((a, b) => a.getBoundingClientRect().left - b.getBoundingClientRect().left);

    const cRect = $wrap[0].getBoundingClientRect();
    const w = Math.max($wrap.outerWidth(), 1);
    const h = Math.max($wrap[0].scrollHeight, 1);

    let svg = $svg[0];
    if (!svg) {
      svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'bracket-third-connectors');
      svg.setAttribute('pointer-events', 'none');
      $wrap.prepend(svg);
    }
    svg.setAttribute('width', w);
    svg.setAttribute('height', h);
    svg.style.position = 'absolute';
    svg.style.left = '0';
    svg.style.top = '0';
    svg.innerHTML = '';

    const thirdRect = $third[0].getBoundingClientRect();
    const yJoin = thirdRect.top - cRect.top - 10;

    const entrants = $third.find('.entrant');
    const xLeftEnt = entrants.length ? entrants.eq(0)[0].getBoundingClientRect() : thirdRect;
    const xRightEnt = entrants.length > 1 ? entrants.eq(1)[0].getBoundingClientRect() : thirdRect;
    const xTargetL = xLeftEnt.left + xLeftEnt.width / 2 - cRect.left;
    const xTargetR = xRightEnt.left + xRightEnt.width / 2 - cRect.left;

    const mkPath = (semiEl, toX) => {
      const r = semiEl.getBoundingClientRect();
      const midY = r.top + r.height / 2 - cRect.top;
      const isLeft = semiEl === semiEls[0];
      const x0 = isLeft ? r.right - cRect.left : r.left - cRect.left;
      return `M ${x0} ${midY} L ${x0} ${yJoin} L ${toX} ${yJoin}`;
    };

    const pathL = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    pathL.setAttribute('d', mkPath(semiEls[0], xTargetL));
    pathL.setAttribute('fill', 'none');
    pathL.setAttribute('class', 'bracket-third-connector-path');

    const pathR = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    pathR.setAttribute('d', mkPath(semiEls[1], xTargetR));
    pathR.setAttribute('fill', 'none');
    pathR.setAttribute('class', 'bracket-third-connector-path');

    svg.appendChild(pathL);
    svg.appendChild(pathR);
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

      this._thirdPlaceByGroup = {};
      for (let i = 0, count = bracketData.results.length; i < count; i++) {
        const tierRows = bracketData.results[i];
        for (let r = 0; r < tierRows.length; r++) {
          const row = tierRows[r];
          if (row && row.isThirdPlaceMatch) {
            this._thirdPlaceByGroup[row.group] = row;
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
      this._$header.find('.title').text(window.bracketData.name);

      group = isNaN(group) ? group : group - 1;
      this.populateGroups();
      this.changeGroup(group, true);
    } else if (this._initialized) {
      this.changeGroup(group, true);
    }
  }
});
