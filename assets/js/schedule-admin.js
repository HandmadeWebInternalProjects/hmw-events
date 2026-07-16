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

    document.querySelectorAll('.hmwevents-clear-excluded').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var eventId = this.dataset.eventId;
            var nonce = this.dataset.nonce || (hmwScheduleAdmin && hmwScheduleAdmin.clearExcludedNonce);

            if (!confirm(hmwScheduleAdmin.clearExcludedConfirm)) {
                return;
            }

            this.disabled = true;
            this.textContent = 'Clearing...';

            var formData = new FormData();
            formData.append('action', 'hmwevents_clear_excluded_dates');
            formData.append('event_id', eventId);
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
                        alert(data.data && data.data.message ? data.data.message : 'Failed to clear excluded dates.');
                        btn.disabled = false;
                        btn.textContent = 'Clear Excluded Dates';
                    }
                })
                .catch(function () {
                    alert('Request failed.');
                    btn.disabled = false;
                    btn.textContent = 'Clear Excluded Dates';
                });
        });
    });

    document.querySelectorAll('.hmwevents-cascade-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var nonce = this.dataset.nonce;

            if (!confirm(hmwScheduleAdmin.cascadeConfirm)) {
                return;
            }

            this.disabled = true;
            this.textContent = 'Updating...';

            var formData = new FormData();
            formData.append('action', 'hmwevents_cascade_to_children');
            formData.append('parent_id', this.dataset.parent);
            formData.append('_wpnonce', nonce);

            var self = this;
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
                        alert(data.data && data.data.message ? data.data.message : 'Failed to update sessions.');
                        self.disabled = false;
                        self.textContent = 'Update All Sessions';
                    }
                })
                .catch(function () {
                    alert('Request failed.');
                    self.disabled = false;
                    self.textContent = 'Update All Sessions';
                });
        });
    });
});
