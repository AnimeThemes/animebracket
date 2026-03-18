import { Route, Router } from 'molecule-router';
import $ from 'jquery';

import Characters from './admin/characters';
import Nominee from './admin/nominee';
import StartBracket from './admin/start-bracket';
import Stats from './admin/stats';

export default Route('admin', {

  initRoute() {
    const $brackets = $('.brackets');

    // The "waiting" overlay
    document.documentElement.addEventListener('click', this.showDisableOverlay);

    // If we're on the main brackets page, do that stuff, otherwise
    // kick in the admin routes
    if ($brackets.length) {
      $brackets.on('click', '.button.open', this.openActions.bind(this));
      $brackets.on('click', '.button.delete', this.confirmDelete.bind(this));
      $brackets.on('click', '.lock-voting-link', this.toggleLockVoting.bind(this));
      $('.lock-voting-row').each((i, el) => this.renderLockLink($(el)));
    } else {
      Router.addRoutes({
        '/me/process/:perma/characters/': Characters,
        '/me/process/:perma/nominees/': Characters,
        '/me/counts/:perma/': Characters,
        '/me/process/:perma/nominations/': Nominee,
        '/me/start/:perma/voting/': StartBracket,
        '/me/stats/:perma/': Stats
      });
      Router.go(window.location.pathname);
    }
  },

  showDisableOverlay(evt) {
    if (evt.target.classList.contains('disable-on-click')) {
      document.querySelector('body').classList.add('disabled');
    }
  },

  openActions(evt) {
    $(evt.currentTarget).closest('li').toggleClass('open');
  },

  confirmDelete(evt) {
    if (!confirm('All data related to this bracket will be PERMENENTLY DELETED! Do you wish to continue?')) {
      evt.preventDefault();
      return false;
    }
  },

  renderLockLink($row) {
    const locked = $row.data('locked');
    const action = locked ? 'unlock' : 'lock';
    const label = locked ? 'Unlock Voting 🔒 → 🔓' : 'Lock Voting 🔓 → 🔒';
    $row.html(`<a href="#" class="lock-voting-link" data-action="${action}">${label}</a>`);
  },
  async toggleLockVoting(evt) {
    evt.preventDefault();
    const $link = $(evt.currentTarget);
    if ($link.data('loading')) {
      return;
    }
    const $row = $link.closest('.lock-voting-row');
    const perma = $row.data('perma');
    const action = $link.data('action');
    const csrfToken = $row.data('csrf');

    $link.data('loading', true).addClass('disabled');
    try {
      const body = new URLSearchParams({ action, _auth: csrfToken });
      const res = await fetch(`/me/process/${perma}/lock-voting/`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      const data = await res.json();
      if (data && data.success) {
        $row.data('locked', data.locked);
        this.renderLockLink($row);
      } else {
        window.alert((data && data.message) ? data.message : 'Unable to update voting lock. Please try again.');
      }
    } catch (err) {
      window.alert('There was an error updating voting lock. Please try again.');
    } finally {
      $link.data('loading', false).removeClass('disabled');
    }
  }

});