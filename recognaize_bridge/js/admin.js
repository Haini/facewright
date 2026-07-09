(function () {
	document.addEventListener('DOMContentLoaded', function () {
		var checkbox = document.getElementById('recognaize-people-enabled');
		var status = document.getElementById('recognaize-people-status');
		if (!checkbox) {
			return;
		}
		checkbox.addEventListener('change', function () {
			var enabled = checkbox.checked;
			fetch(OC.generateUrl('/apps/recognaize_bridge/people-enabled'), {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify({ enabled: enabled }),
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('Request failed');
				}
				status.textContent = enabled ? 'Enabled.' : 'Disabled.';
			}).catch(function () {
				checkbox.checked = !enabled;
				status.textContent = 'Failed to save - see the Nextcloud log.';
			});
		});
	});
})();
