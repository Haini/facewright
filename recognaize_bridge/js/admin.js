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

		var pathsField = document.getElementById('recognaize-included-paths');
		var pathsSaveButton = document.getElementById('recognaize-included-paths-save');
		var pathsStatus = document.getElementById('recognaize-included-paths-status');
		if (pathsSaveButton) {
			pathsSaveButton.addEventListener('click', function () {
				fetch(OC.generateUrl('/apps/recognaize_bridge/included-paths'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ paths: pathsField.value }),
				}).then(function (response) {
					if (!response.ok) {
						throw new Error('Request failed');
					}
					return response.json();
				}).then(function (data) {
					pathsField.value = data.paths.join('\n');
					pathsStatus.textContent = 'Saved.';
				}).catch(function () {
					pathsStatus.textContent = 'Failed to save - see the Nextcloud log.';
				});
			});
		}
	});
})();
