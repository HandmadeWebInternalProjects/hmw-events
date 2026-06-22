/**
 * Upcoming Course Card Accordion
 * Handles expand/collapse functionality for course cards
 */

document.addEventListener('DOMContentLoaded', function() {
  const courseCards = document.querySelectorAll('.upcoming-course-card');
  
  courseCards.forEach(card => {
    const accordionIcon = card.querySelector('.course-accordion-icon');
    const revealContent = card.querySelector('.course-reveal-content');
    
    if (!accordionIcon || !revealContent) return;
    
    // Set initial state - collapsed
    revealContent.style.maxHeight = '0';
    revealContent.classList.add('collapsed');
    
    // Add click event to accordion icon
    accordionIcon.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      
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
    });
    
    // Make the entire card head clickable
    const courseHead = card.querySelector('.course-head');
    if (courseHead) {
      courseHead.style.cursor = 'pointer';
      courseHead.addEventListener('click', function(e) {
        accordionIcon.click();
      });
    }
  });
});
