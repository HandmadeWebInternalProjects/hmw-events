(function(){
  class EducatorUpcomingCourses {
    constructor(el, options) {
      this.el = el;
      this.options = options;
      this.init();
    }

    init() {
      this.courseCards = this.el.querySelectorAll('.upcoming-course-card');
      this.hiddenContainer = this.el.querySelector('.upcoming-courses-hidden');
      this.loadMoreBtn = this.el.querySelector('.upcoming-courses-load-more');
      this.attachEventListeners();
      this.initializeCards();
      this.initLoadMore();
    }

    initLoadMore() {
      if (!this.loadMoreBtn || !this.hiddenContainer) return;

      this.perPage = parseInt(this.loadMoreBtn.dataset.perPage, 10) || 5;
      // Collect hidden cards in order
      this.hiddenCards = Array.from(this.hiddenContainer.querySelectorAll('.upcoming-course-card'));
      this.shownCount = 0;

      this.loadMoreBtn.addEventListener('click', () => {
        const next = this.hiddenCards.splice(0, this.perPage);

        next.forEach(card => {
          // Move card out of hidden container, into main list before the load-more wrap
          const wrap = this.el.querySelector('.upcoming-courses-load-more-wrap');
          this.el.insertBefore(card, wrap);
          // Reinitialise accordion state for the newly visible card
          const revealContent = card.querySelector('.course-reveal-content');
          if (revealContent) {
            revealContent.style.maxHeight = '0';
            revealContent.classList.add('collapsed');
          }
          // this.attachCardListeners(card);
        });

        if (this.hiddenCards.length === 0) {
          this.loadMoreBtn.closest('.upcoming-courses-load-more-wrap').remove();
        }
      });
    }

    initializeCards() {
      // Set initial state for all cards - collapsed
      this.courseCards.forEach(card => {
        const revealContent = card.querySelector('.course-reveal-content');
        if (revealContent) {
          revealContent.style.maxHeight = '0';
          revealContent.classList.add('collapsed');
        }
      });
    }

    attachEventListeners() {
      this.courseCards.forEach(card => this.attachCardListeners(card));
    }

    attachCardListeners(card) {
      const accordionIcon = card.querySelector('.course-accordion-icon');
      const courseHead = card.querySelector('.course-head');
      const revealContent = card.querySelector('.course-reveal-content');

      if (!accordionIcon || !revealContent) return;

      accordionIcon.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        this.toggleCard(card, revealContent);
      });

      if (courseHead) {
        courseHead.style.cursor = 'pointer';
        courseHead.addEventListener('click', (e) => {
          e.preventDefault();
          this.toggleCard(card, revealContent);
        });
      }
    }

    toggleCard(card, revealContent) {
      const isCollapsed = revealContent.classList.contains('collapsed');
      
      if (isCollapsed) {
        // Expand
        revealContent.style.maxHeight = revealContent.scrollHeight + 'px';
        revealContent.classList.remove('collapsed');
        card.classList.add('expanded');
      } else {
        // Collapse
        revealContent.style.maxHeight = '0';
        revealContent.classList.add('collapsed');
        card.classList.remove('expanded');
      }
    }
  }
  window.EducatorUpcomingCourses = EducatorUpcomingCourses;
})()