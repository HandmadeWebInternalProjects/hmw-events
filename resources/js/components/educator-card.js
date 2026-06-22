// Handle "Load More Dates" functionality
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.educator-card .load-more-courses').forEach(function (button) {
    button.addEventListener('click', function (e) {
      e.preventDefault();
      const educatorId = this.getAttribute('data-educator-id');
      const loaded = parseInt(this.getAttribute('data-loaded'));
      const total = parseInt(this.getAttribute('data-total'));
      const coursesList = document.querySelector('.courses-list[data-educator-id="' + educatorId + '"]');

      // Load next 5 courses via REST API
      const url = `${hmwevents_params.hmwevents_rest_base_url}/courses/educator/${educatorId}?offset=${loaded}&limit=5`;

      fetch(url, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json'
        }
      })
        .then(response => response.json())
        .then(data => {
          console.log(data);
          if (data.success && data.courses) {
            // Append new courses to list
            data.courses.forEach(function (course) {
              const li = document.createElement('li');
              li.className = 'course-item';
              const typeHtml = course.course_type ? ' - ' + course.course_type : '';
              li.innerHTML = course.date + typeHtml;
              coursesList.appendChild(li);
            });

            // Update loaded count
            const newLoaded = loaded + data.courses.length;
            button.setAttribute('data-loaded', newLoaded);

            // Update or hide button
            const remaining = total - newLoaded;
            if (remaining > 0) {
              button.textContent = 'View ' + remaining + ' more date' + (remaining !== 1 ? 's' : '');
            } else {
              button.style.display = 'none';
            }
          }
        })
        .catch(error => {
          console.error('Error loading more courses:', error);
        });
    });
  });
});