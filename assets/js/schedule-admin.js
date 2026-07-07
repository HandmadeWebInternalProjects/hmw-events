document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.hmwevents-bulk-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = this.dataset.action;
            var parentId = this.dataset.parent;
            var nonce = this.dataset.nonce;

            if (action === 'trash_all') {
                if (!confirm(hmwScheduleAdmin.confirmMsg)) {
                    return;
                }
            }

            var formData = new FormData();
            formData.append('action', 'hmwevents_bulk_session_action');
            formData.append('parent_id', parentId);
            formData.append('action_type', action);
            formData.append('_wpnonce', nonce);

            fetch(hmwScheduleAdmin.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.data && data.data.message ? data.data.message : 'Error');
                    }
                })
                .catch(function () {
                    alert('Network error');
                });
        });
    });
});
