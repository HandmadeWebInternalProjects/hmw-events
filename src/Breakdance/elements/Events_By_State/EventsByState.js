(function(){
  class EducatorsByState {
    constructor(el, options) {
      this.el = el;
      this.options = options;
      this.init();
    }

    init() {
      this.mapElement = this.el.querySelector('.educators-by-state-map');
      console.log('Map Element:', this.mapElement);
      this.stateBoxes = this.el.querySelectorAll('.state-box');
      this.attachEventListeners();
    }

    attachEventListeners() {
      // when hovering a state box, highlight the corresponding state on the map
      this.stateBoxes.forEach(box => {
        box.addEventListener('mouseenter', (e) => {
          const state = e.currentTarget.getAttribute('data-state');
          this.highlightStateOnMap(state);
        });
        box.addEventListener('mouseleave', (e) => {
          const state = e.currentTarget.getAttribute('data-state');
          this.unhighlightStateOnMap(state);
        });
      });

      // When clicking a state on the map, go to the corresponding state box url
      const statesOnMap = this.mapElement.querySelectorAll('.educators-by-state-map__state');
      statesOnMap.forEach(stateEl => {
        stateEl.addEventListener('click', (e) => {
          const state = e.currentTarget.getAttribute('data-state');
          const correspondingBox = this.el.querySelector(`.state-box[data-state="${state}"]`);
          if (correspondingBox) {
            const url = correspondingBox.getAttribute('href');
            if (url) {
              window.location.href = url;
            }
          }
        });
      });
    }

    highlightStateOnMap(state) {
      const stateElement = this.mapElement.querySelector(`[data-state="${state}"]`);
      if (stateElement) {
        stateElement.classList.add('highlighted');
      }
    }

    unhighlightStateOnMap(state) {
      const stateElement = this.mapElement.querySelector(`[data-state="${state}"]`);
      if (stateElement) {
        stateElement.classList.remove('highlighted');
      }
    }
  }
  window.EducatorsByState = EducatorsByState;
})()