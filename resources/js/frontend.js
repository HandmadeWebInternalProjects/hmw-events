import "./components/educator-card.js";
import "./components/event-listings.js";

document.addEventListener("click", (event) => {
  const toggle = event.target.closest(".hmw-results-bar__toggle");
  if (!toggle) {
    return;
  }

  const targetId = toggle.getAttribute("aria-controls");
  const filters = targetId ? document.getElementById(targetId) : null;
  if (!filters) {
    return;
  }

  const isOpen = filters.classList.toggle("is-open");
  toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
});
