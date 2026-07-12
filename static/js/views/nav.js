import $ from 'jquery';

import Singleton from 'molecule-singleton';

const CLICK = 'click';
const SHOW_NAV = 'show';
const SITE_HEADER_HEIGHT = '--site-header-height';
const BRACKET_NAV_HEIGHT = '--bracket-nav-height';

export default Singleton('nav', {
  navClick(evt) {
    this._$siteNav.toggleClass(SHOW_NAV);
    this._$bracketNav.removeClass(SHOW_NAV);
    this._syncBracketToggleExpanded();
  },

  bracketNavClick(evt) {
    if ($(evt.target).closest('.bracket-nav__link').length) {
      return;
    }
    this._$bracketNav.toggleClass(SHOW_NAV);
    this._$siteNav.removeClass(SHOW_NAV);
    this._syncBracketToggleExpanded();
  },

  bodyClick(evt) {
    const $target = $(evt.target);
    if (!$target.closest('#nav-header nav').length) {
      this._$siteNav.removeClass(SHOW_NAV);
    }
    if (!$target.closest('.bracket-nav').length) {
      this._$bracketNav.removeClass(SHOW_NAV);
      this._syncBracketToggleExpanded();
    }
  },

  _syncBracketToggleExpanded() {
    if (!this._$bracketToggle.length) {
      return;
    }
    this._$bracketToggle.attr(
      'aria-expanded',
      this._$bracketNav.hasClass(SHOW_NAV) ? 'true' : 'false',
    );
  },

  _labelBracketToggle() {
    if (!this._$bracketToggle.length) {
      return;
    }

    const path = window.location.pathname.replace(/\/$/, '') || '/';
    let label = 'Pages';

    this._$bracketNav.find('.bracket-nav__link').each((_, link) => {
      const href = (link.getAttribute('href') || '').replace(/\/$/, '') || '/';
      if (href === path) {
        link.classList.add('is-active');
        label = link.textContent.trim() || label;
      } else {
        link.classList.remove('is-active');
      }
    });

    this._$bracketToggle.text(label);
  },

  syncStickyOffsets() {
    const header = document.getElementById('nav-header');
    const bracketNav = document.querySelector('.bracket-nav');
    const root = document.documentElement;

    if (header) {
      root.style.setProperty(SITE_HEADER_HEIGHT, `${header.offsetHeight}px`);
    }
    if (bracketNav) {
      root.style.setProperty(BRACKET_NAV_HEIGHT, `${bracketNav.offsetHeight}px`);
    }
  },

  init() {
    this._$siteNav = $('#nav-header nav').on(CLICK, this.navClick.bind(this));
    this._$bracketNav = $('.bracket-nav').on(CLICK, this.bracketNavClick.bind(this));
    this._$bracketToggle = this._$bracketNav.find('.bracket-nav__toggle');
    $('body').on(CLICK, this.bodyClick.bind(this));

    this._labelBracketToggle();
    this._syncBracketToggleExpanded();
    this.syncStickyOffsets();

    if (typeof ResizeObserver !== 'undefined') {
      this._resizeObserver = new ResizeObserver(() => this.syncStickyOffsets());
      const header = document.getElementById('nav-header');
      const bracketNav = document.querySelector('.bracket-nav');
      if (header) {
        this._resizeObserver.observe(header);
      }
      if (bracketNav) {
        this._resizeObserver.observe(bracketNav);
      }
    } else {
      $(window).on('resize', this.syncStickyOffsets.bind(this));
    }
  }
});
